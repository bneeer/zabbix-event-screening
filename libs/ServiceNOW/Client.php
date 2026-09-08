<?php
/*
 * Author: Abner Almeida, 2026
 */

namespace ServiceNOW;
class Client
{

    /* Attributes */
    private String $instance;
    private ?string $username = null;
    private ?String $password = null;
    private String $proxy, $base_url;
    private bool $secure = true;
    private ?string $ca_bundle = null;
    private ?String $authentication_type = null, $certificate_file = null, $certificate_key = null, $certificate_ca = null, $certificate_pass = null, $client_id = null, $client_secret = null, $access_token = null, $refresh_token = null, $oauth_url = null;
 
    /*
     * Passing ServiceNOW Native credentials through class constructor.
     * Example: $SNowClient = new AutomationSNOWClient();
    */
    public function __construct(string $instance, string $authentication_type, array $authentication_data, string $proxy = "", bool $secure = true, bool $custom_url = false, ?string $ca_bundle = null)
    {
        $this->instance = $instance;
        $this->proxy = $proxy;
        $this->base_url = $custom_url ? $instance : 'https://' . $instance . '.service-now.com/';
        $this->secure = $secure;
        $this->authentication_type = $authentication_type;

        if ($authentication_type === 'basic') {
            $this->username = $authentication_data['username'] ?? null;
            $this->password = $authentication_data['password'] ?? null;
        } elseif ($authentication_type === 'oauth') {
            $this->username = $authentication_data['username'] ?? null;
            $this->password = $authentication_data['password'] ?? null;
            $this->client_id = $authentication_data['client_id'] ?? null;
            $this->client_secret = $authentication_data['client_secret'] ?? null;
            $this->oauth_url = 'https://' . $instance . '.service-now.com/oauth_token.do';
        } elseif ($authentication_type === 'cert') {
            $this->certificate_file = $authentication_data['certificate_file'] ?? null;
            $this->certificate_key = $authentication_data['certificate_key'] ?? null;
            $this->certificate_ca = $authentication_data['certificate_ca'] ?? null;
            $this->certificate_pass = $authentication_data['certificate_pass'] ?? null;
        }

        $resolvedCa = $ca_bundle
            ?? $this->certificate_ca
            ?? (defined('SERVICENOW_CA_BUNDLE') && SERVICENOW_CA_BUNDLE ? SERVICENOW_CA_BUNDLE : null)
            ?? (defined('SSL_CA_BUNDLE') && SSL_CA_BUNDLE ? SSL_CA_BUNDLE : null);

        if ($resolvedCa !== null && $resolvedCa !== '') {
            if (!file_exists($resolvedCa)) {
                throw new \InvalidArgumentException("ServiceNOW CA bundle file not found: {$resolvedCa}");
            }
            $this->ca_bundle = $resolvedCa;
            $this->certificate_ca = $resolvedCa;
        }
    }

    public function getCaBundle(): ?string
    {
        return $this->ca_bundle;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    private function _oauthLogin($refresh = false): array
    {
        try {
            $ch = curl_init();
            $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
            $payload = [
                'grant_type' => $refresh ? 'refresh_token' : 'password',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'username' => !$refresh ? $this->username : null,
                'password' => !$refresh ? $this->password : null,
                'refresh_token' => $refresh === true && isset($this->refresh_token) ? $this->refresh_token : null
            ];
            curl_setopt($ch, CURLOPT_URL, $this->oauth_url);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_ENCODING, "");
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->secure);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->secure ? 2 : 0);
            if (!empty($this->ca_bundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->ca_bundle);
            }
            $result = curl_exec($ch);
            if ($result === false) {
                throw new \Exception(curl_error($ch));
            }

            $response = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            if (isset($response['status']) && $response['status'] === 'failure') {
                if ($response['error']['message'] == 'User Not Authenticated' && !$refresh) {
                    $this->_oauthLogin(true);
                } else {
                    throw new \Exception($response['error']['message'] . '. ' . $response['error']['detail']);
                }
            } elseif (isset($response['error'])) {
                throw new \Exception($response['error'] . ': ' . $response['error_description']);
            }
            curl_close($ch);
            return ["status" => true, "data" => $response];
        } catch (\Exception $e) {
            $correlationId = bin2hex(random_bytes(16));
            error_log(sprintf(
                '[ServiceNOW\Client][%s] OAuth login error: %s%s',
                $correlationId,
                $e->getMessage(),
                PHP_EOL . $e->getTraceAsString()
            ));

            return [
                "status" => false,
                "ErrorCode" => $e->getCode(),
                "Message" => "Authentication failed with ServiceNow instance.",
                "correlation_id" => $correlationId,
                "Timestamp" => date("Y-m-d H:i:s")
            ];
        }
    }

    private function buildTableUrl(string $table, ?string $sys_id = null, array $queryParameters = []): string
    {
        $url = sprintf("%s/api/now/table/%s", $this->base_url, $table);
        if ($sys_id) {
            $url .= '/' . $sys_id;
        }
        if (!empty($queryParameters)) {
            $url .= '?' . http_build_query($queryParameters);
        }
        return $url;
    }

    private function sendRequest(string $url, array $parameters = [], string $type = 'GET'): array
    {

        try {

            if ($this->authentication_type === 'oauth' && (!isset($this->access_token) || $this->access_token == '')) {
                $getTokens = $this->_oauthLogin();
                if ($getTokens['status']) {
                    $this->access_token = $getTokens['data']['access_token'];
                    $this->refresh_token = $getTokens['data']['refresh_token'];
                } else {
                    throw new \Exception($getTokens['data']);
                }
            }

            $ch = curl_init();
            $headers = ['Accept: */*', 'Accept-Encoding: gzip, deflate, br', 'Connection: keep-alive'];
            curl_setopt($ch, CURLOPT_URL, $url);
            if ($this->authentication_type === 'basic') {
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
            } elseif ($this->authentication_type === 'oauth') {
                array_push($headers, 'Authorization: Bearer ' . $this->access_token);
            } elseif ($this->authentication_type === 'cert') {
                curl_setopt($ch, CURLOPT_SSLKEY, $this->certificate_key);
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certificate_file);
                curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certificate_pass);
            }
            curl_setopt($ch, CURLOPT_POST, $type !== 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
            curl_setopt($ch, CURLOPT_ENCODING, "");
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            if (!empty($parameters)) {
                if (isset($parameters['file_upload']) && $parameters['file_upload'] === true) {
                    $attachmentData = array_slice($parameters, 2);
                    $headers[] = 'Content-Type: multipart/form-data';
//                        array_push($headers, 'Content-Length: ' . strlen($parameters['file_content']));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, ['table_name' => $attachmentData['table_name'], 'table_sys_id' => $attachmentData['table_sys_id'], 'file' => new CURLFILE($attachmentData['file'], $parameters['file_type'])]);
                } else {
                    $payload = json_encode($parameters, JSON_NUMERIC_CHECK);
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Content-Length: ' . strlen($payload);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            } else {
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->secure);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->secure ? 2 : 0);
            if (!empty($this->ca_bundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->ca_bundle);
            }
            $result = curl_exec($ch);
            if ($result === false) {
                throw new \Exception(curl_error($ch));
            }

            $response = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            if (isset($response['status']) && $response['status'] === 'failure') {
                throw new \Exception($response['error']['message'] . '. ' . $response['error']['detail']);
            }
            curl_close($ch);
            return ["status" => true, "data" => $response['result']];
        } catch (\Throwable $e) {
            $message = $e->getMessage() . "\n";
            if (isset($result) && is_string($result) && strlen($result)) {
                $message .= $result . "\n";
            }
            return ["status" => false, "ErrorCode" => $e->getCode(), "Message" => $message, "trace" => $e->getTraceAsString(), "Timestamp" => date("Y-m-d H:i:s"), 'result' => $result ?? null];
        }
    }

    public function downloadAttachment(string $download_link): array
    {
        try {
            $authHeader = 'Authorization: Basic ' . base64_encode($this->username . ":" . $this->password);
            $sslOptions = [
                'verify_peer' => $this->secure,
                'verify_peer_name' => $this->secure,
                'allow_self_signed' => false,
            ];

            if (!empty($this->ca_bundle)) {
                $sslOptions['cafile'] = $this->ca_bundle;
            }

            $context = stream_context_create([
                'http' => [
                    'header' => $authHeader
                ],
                'ssl' => $sslOptions
            ]);

            $handle = fopen($download_link, "rb", false, $context);
            $contents = stream_get_contents($handle);

            return ["status" => true, "data" => $contents];
        } catch (Exception $e) {
            return ["status" => false, "ErrorCode" => $e->getCode(), "Message" => $e->getMessage(), "Timestamp" => date("Y-m-d H:i:s")];
        }
    }

    /**
     * Retrieve records from specified ServiceNOW table
     * @param String $table The name of the table
     * @param array $queryParameters (Optional) Parameters to search in URL-encoded query format
     * @return array
     */
    public function RetrieveAllRecords(string $table, array $queryParameters = []): array
    {
        return $this->sendRequest($this->buildTableUrl($table, null, $queryParameters));
    }

    /**
     * Insert record into the specified ServiceNOW table
     * @param String $table The name of the table
     * @param array $data Data to be inserted
     * @param array|null $url_params
     * @return array
     */
    public function CreateRecord(string $table, array $data, array $url_params = []): array
    {
        return $this->sendRequest($this->buildTableUrl($table, null, (array)$url_params), $data, 'POST');
    }

    /**
     * Fetch a specific record from a ServiceNOW table by ID
     * @param String $table The name of the table
     * @param String $number
     * @return array
     */
    public function RetrieveRecord(string $table, string $number, array $parameters = []): array
    {
        $params = array_merge(['number' => $number], (array)$parameters);
        return $this->sendRequest($this->buildTableUrl($table, null, $params));
    }

    /**
     * Fetch a specific record from a ServiceNOW table by ID
     * @param String $table The name of the table
     * @param String $number
     * @return array
     */
    public function RetrieveRecordBySysId(string $table, string $sys_id, array $parameters = []): array
    {
        return $this->sendRequest($this->buildTableUrl($table, $sys_id, (array)$parameters));
    }


    /**
     * Modify a record by overwriting it completely
     * @param String $table The name of the table
     * @param String $sys_id The ID of the record
     * @param array $data The data to be inserted
     * @return array
     */
    public function ModifyRecord(string $table, string $sys_id, array $data): array
    {
        return $this->sendRequest($this->buildTableUrl($table, $sys_id), $data, 'PUT');
    }

    /**
     * Update only specified attributes of a record
     * @param String $table The name of the table
     * @param String $sys_id The ID of the record
     * @param array $data The data to be inserted
     * @param array|null $url_params
     * @return array
     */
    public function UpdateRecord(string $table, string $sys_id, array $data, array $url_params = []): array
    {
        return $this->sendRequest($this->buildTableUrl($table, $sys_id, (array)$url_params), $data, 'PATCH');
    }

    /**
     * Delete specified record from a ServiceNOW table
     * @param String $table The name of the table
     * @param String $sys_id The ID of the record
     * @return array
     */
    public function DeleteRecord(string $table, string $sys_id): array
    {
        return $this->sendRequest($this->buildTableUrl($table, $sys_id), [], 'DELETE');
    }

    public function FetchIncidentAttachments(string $incident_sys_id, string $table = 'incident'): array
    {
        $params = ['table_name' => $table, 'table_sys_id' => $incident_sys_id];
        $RequestURL = sprintf("%s/api/now/attachment?%s", $this->base_url, http_build_query($params));
        return $this->sendRequest($RequestURL);
    }

    public function UploadAttachmentFile(array $file_data): array
    {
        $RequestURL = sprintf("%s/api/now/attachment/upload", $this->base_url);
        return $this->sendRequest($RequestURL, $file_data, 'POST');
    }


    /**
     * This is a customized endpoint for ATENTO's ServiceNOW
     * @param array $params
     * @return array
     */
    public function createConfigurationItem(array $params): array
    {
        unset($params['action']);
        $RequestURL = sprintf("%s/api/tsba/cmdb_aoop/create", $this->base_url);
        return $this->sendRequest($RequestURL, $params, 'POST');
    }


    /**
     * This is a customized endpoint for ATENTO's ServiceNOW
     * @param array $params
     * @return array
     * return sample:
     * {
     * "status": true,
     *   "data": {
     *    "sys_id": "685c1afc1b5b70102f80dca0f54bcb86",
     *    "number": "INC0038087"
     *  }
     * }
     */
    public function createIncidentWithPkPv(array $params): array
    {
        $RequestURL = sprintf("%s/api/tsba/cmdb_aoop/generate_incident", $this->base_url);
        return $this->sendRequest($RequestURL, $params, 'POST');
    }
}
