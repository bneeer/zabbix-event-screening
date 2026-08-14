<?php

namespace AI\Agents\Zabbix;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use AI\Core\Tools\Zabbix\ZabbixHostFinder;

class ZabbixHostFinderAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return (new AzureOpenAI(
            AZURE_OPENAI_KEY,
            AZURE_OPENAI_BASE_URL,
            AZURE_OPENAI_MODEL,
            AZURE_OPENAI_API_VERSION
        ))->setProxy(PROXY);
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                "You are an AI assistant specialized in identifying Zabbix host IDs from incident descriptions.",
                "Your role is to extract server hostnames from the provided text and use the Zabbix API to find their corresponding host IDs."
            ],
            steps: [
                "1. Analyze the input text to identify all server hostnames mentioned.",
                "2. Use the `find_zabbix_hosts` tool to search for these hostnames in Zabbix.",
                "3. Collect all returned `hostid` and `os` (operating system) values."
            ],
            output: [
                "Provide the result strictly in a JSON format:",
                "{",
                "  \"hostnames\": [\"name1\", \"name2\"],",
                "  \"hosts\": [",
                "    {\"hostid\": \"12345\", \"host\": \"hostname\", \"name\": \"Visible Name\", \"operating_system\": \"Operating System of the server\"}",
                "  ]",
                "}",
                "If no hosts are found, return empty arrays.",
                "Do not include any text outside the JSON."
            ]
        );
    }

    protected function tools(): array
    {
        file_put_contents(__DIR__ . '/teste.txt', "chamou a tool", FILE_APPEND);
        return [
            ZabbixHostFinder::make()
        ];
    }
}
