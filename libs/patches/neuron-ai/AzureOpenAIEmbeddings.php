<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function array_chunk;
use function array_map;
use function array_merge;
use function preg_replace;
use function sprintf;
use function trim;

class AzureOpenAIEmbeddings extends OpenAIEmbeddingsProvider
{
    protected string $baseUri = "https://%s/openai/deployments/%s";

    public function __construct(
        protected string $key,
        protected string $endpoint,
        protected string $model,
        protected string $version,
        protected ?int $dimensions = 1024,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->setBaseUrl();

        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function embedDocuments(array $documents): array
    {
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $response = $this->httpClient->request(HttpRequest::post('embeddings?api-version=' . $this->version, [
                'input' => array_map(fn (Document $document): string => $document->getContent(), $chunk),
                'encoding_format' => 'float',
                ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),
            ]))->json();

            foreach ($response['data'] as $index => $item) {
                $chunk[$index]->embedding = $item['embedding'];
            }
        }

        return array_merge(...$chunks);
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(HttpRequest::post('embeddings?api-version=' . $this->version, [
            'input' => $text,
            'encoding_format' => 'float',
            ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),
        ]))->json();

        return $response['data'][0]['embedding'];
    }

    private function setBaseUrl(): void
    {
        $endpoint = preg_replace('/^https?:\/\/([^\/]*)\/?$/', '$1', $this->endpoint);
        $this->baseUri = sprintf($this->baseUri, $endpoint, $this->model);
        $this->baseUri = trim($this->baseUri, '/').'/';
    }
}
