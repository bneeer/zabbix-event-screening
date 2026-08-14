<?php

namespace Zabbix;

/**
 * Author: Abner Muniz de Almeida
 */

class Client
{

    public String $api_endpoint, $username, $password;
    private String $access_token;

    public int $version = 6;

    public bool $secure = false;

    public function __construct($api_endpoint, $username, $password, $version = 6)
    {
        $this->api_endpoint = $api_endpoint;
        $this->username = $username;
        $this->password = $password;
        $this->version = $version;

        $login_params = [
            "jsonrpc" => "2.0",
            "method" => "user.login",
            "params" => [
                ($this->version >= 7 ? "username" : "user") => $this->username,
                "password" => $this->password
            ],
            "id" => 1,
            "auth" => null
        ];

        $api_login = $this->curlRequest($login_params);
        if ($api_login['status'] === true) {
            if (isset($api_login['data']['error'])) {
                die("There as an error: " . $api_login['data']['error']['message'] . ' - ' . $api_login['data']['error']['data']);
            } else {
                if (isset($api_login['data']['result'])) {
                    $this->access_token = $api_login['data']['result'];
                    // echo "token $this->access_token\n";
                } else {
                    die("Zabbix API Connection Error: Unknow error");
                }
            }
        } else {
            die($api_login['Message']);
        }
    }

    public function getAccessToken(){
        return $this->access_token;
    }

    public function curlRequest($params): array
    {
        try {
            $payload = json_encode($params);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json-rpc', 'Content-Length: ' . strlen($payload)]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_PROXY, '');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->secure);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->secure);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            return ["status" => true, "data" => $result];
        } catch (\Throwable $e) {
            return ["status" => false, "ErrorCode: " => $e->getCode(), "Message" => $e->getMessage() === "Syntax error" ?  $response : $e->getMessage(), "Timestamp" => date("Y-m-d H:i:s")];
        }
    }

    private function callMethod($method, array $params = [])
    {
        $requestData = [
            "jsonrpc" => "2.0",
            "method" => $method,
            "params" => $params,
            "auth" => $this->access_token,
            "id" => 1
        ];

        $request = $this->curlRequest($requestData);

        if (isset($request['data']['error'])) {
            return $request['data']['error'];
        } elseif (isset($request['Message'])) {
            return $request['Message'];
        } elseif (isset($request['data']['result'])) {
            return $request['data']['result'];
        }

        return $request;
    }

    public function searchTriggerForIncidents($trigger_id, $event_id)
    {
        $options = [
            "output" => "extend",
            "select_acknowledges" => "extend",
            "selectTags" => "extend",
            "selectSuppressionData" => "extend",
            "objectids" => $trigger_id,
            "sortfield" => ["clock", "eventid"],
            "source" => 0,
            "value" => 1,
            "sortorder" => "DESC"
        ];

        $events = $this->callMethod("event.get", $options);
        $previous_inc = '';
        if (!is_array($events) || count($events) == 0) {
            return false;
        } else {
            foreach ($events as $event) {
                if ($event['eventid'] != $event_id) {
                    if (isset($event['acknowledges'])) {
                        foreach ($event['acknowledges'] as $acknowledge) {
                            if (str_contains($acknowledge['message'], 'SERVICENOW:')) {
                                $previous_inc = explode(":", $acknowledge['message'])[1];
                                echo "\nthere is already an Incident for this. The ID is $previous_inc \n";
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $previous_inc;
    }

    public function addAcknowledgeMessageToEvent($event_id, $message)
    {
        $options = [
            "eventids" => $event_id,
            "action" => 4,
            "message" => $message
        ];
        $result = $this->callMethod("event.acknowledge", $options);
        return is_array($result) && isset($result['eventids']) ? true : $result;
    }

    public function fetchHosts($groupIds = null, $options = [])
    {
        $params = [
            "groupids" => $groupIds ?? null,
            "selectGroups" => isset($options['withGroups']) && $options['withGroups'] === true ? ["name"] : null,
            "output" => ["host", "name"],
            "selectInventory" => ["name", "os"],
            "selectItems" => isset($options['items']) && $options['items'] === true ? ["name", "lastvalue", "lastclock", "key_"] : null,
            "selectTags" => ["tag", "value"],
            "selectMacros" => ["macro", "value"],
        ];

        return $this->callMethod("host.get", $params);
    }

    public function fetchHostsSummary($groupIds = null)
    {
        $params = [
            "groupids" => $groupIds ?? null,
            "output" => ["host", "name"]
        ];

        return $this->callMethod("host.get", $params);
    }

    function fetchInfoByHostIdAndKey($host_id, $key)
    {
        $params = [
            "output" => "extend",
            "hostids" => $host_id,
            "search" => [
                "key_" => $key
            ],
            "sortfield" => "name"
        ];

        return $this->callMethod("item.get", $params);
    }

 function fetchInfoByHostId($host_id, $keys = false)
    {
        $params = [
            "output" => "extend",
            "hostids" => $host_id,
            "sortfield" => "name"
        ];

        $result = $this->callMethod("item.get", $params);

        if ($keys && is_array($result)) {
            $formattedResult = [];
            foreach ($result as $datum) {
                $formattedResult[$datum['key_']] = $datum['lastvalue'];
            }
            return $formattedResult;
        }

        return $result;
    }

    function fetchHostById($host_id)
    {
        $params = [
            "hostids" => $host_id,
            "output" => ["host", "name"],
            "selectInventory" => ["name", "os"],
            "selectItems" => ["name", "lastvalue", "key_"],
            "selectTags" => ["tag", "value"],
            "selectMacros" => ["macro", "value"],
        ];

        return $this->callMethod("host.get", $params);
    }

    function fetchAllHosts($options)
    {
        $params = [
            "output" => $options['output'],
            "selectTags" => $options['withTags'] ? 'extend' : null,
        ];

        return $this->callMethod("host.get", $params);
    }

    public function addHostsToGroups(array $hostIds, array $groupIds)
    {
        $params = [
            "groups" => array_map(function ($id) { return ['groupid' => $id];}, $groupIds),
            "hosts" => array_map(function ($id) { return ['hostid' => $id];}, $hostIds),
        ];

        return $this->callMethod("hostgroup.massadd", $params);
    }

    public function removeHostsFromGroups(array $hostIds, array $groupIds)
    {
        $params = [
            "groupids" => $groupIds,
            "hostids" => $hostIds,
        ];

        return $this->callMethod("hostgroup.massremove", $params);
    }

    public function updateHostsFromGroups(array $hostIds, array $groupIds)
    {
        $params = [
            "groups" => array_map(function ($id) { return ['groupid' => $id];}, $groupIds),
            "hosts" => array_map(function ($id) { return ['hostid' => $id];}, $hostIds),
        ];

        return $this->callMethod("hostgroup.massupdate", $params);
    }

    function fetchHostGroups(String $query = '')
    {
        $params = [
            "output" => "extend",
            "search" => [
                'name' => $query . "*"
            ],
            "sortfield" => "name",
            "searchWildcardsEnabled" => true
        ];

        return $this->callMethod("hostgroup.get", $params);
    }



    function searchEventsByTags(array $tags, int $time_from, array $select = null)
    {
        $params = [
            "output" => $select ?? "extend",
            "tags" => $tags,
            "limit" => 20,
            "time_from" => $time_from
        ];

        return $this->callMethod("event.get", $params);
    }

    function searchEvents(array $options)
    {
        return $this->callMethod("event.get", $options);
    }

    public function createScript($name, $command, $type = 0, $execute_on = "0", $options = [])
    {
        $params = array_merge([
            "name" => $name,
            "command" => $command,
            "scope" => "2",
            "type" => $type,
            "execute_on" => $execute_on
        ], $options);

        return $this->callMethod("script.create", $params);
    }

    public function updateScript($scriptid, $params_to_update)
    {
        $params = array_merge([
            "scriptid" => $scriptid
        ], $params_to_update);

        return $this->callMethod("script.update", $params);
    }

    public function executeScript($scriptid, $hostid, $manualinput = null)
    {
        $params = [
            "scriptid" => $scriptid,
            "hostid" => $hostid
        ];

        if ($manualinput !== null) {
            $params["manualinput"] = $manualinput;
        }

        return $this->callMethod("script.execute", $params);
    }

    public function getScriptByName($name)
    {
        $params = [
            "output" => "extend",
            "filter" => [
                "name" => $name
            ]
        ];

        return $this->callMethod("script.get", $params);
    }

    public function deleteScript($scriptids)
    {
        $params = is_array($scriptids) ? $scriptids : [$scriptids];

        return $this->callMethod("script.delete", $params);
    }
}
