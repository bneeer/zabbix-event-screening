<?php

namespace AI\Agents\Zabbix;

use AI\Core\Tools\Zabbix\ZabbixAdHocRunner;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\AzureOpenAI;

class ZabbixScanner extends Agent {

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
                "You are an AI assistant specialized in executing commands via Zabbix to assist infrastructure analysts during incident response.",
                "Your primary role is to identify target servers (host IDs), determine the necessary commands, execute them using the provided tools, and report the results.",
                "IMPORTANT: Your goal is to support the analysis of the INCIDENT itself, not the execution environment (e.g., Zabbix connectivity or agent issues)."
            ],

            steps: [
                "1. Analyze the input to identify the Zabbix host IDs, the commands to be executed, and the customer name.",
                "2. Determine the OS of the servers to choose the appropriate command syntax.",
                "3. Execute the commands using `ZabbixAdHocRunner` (run_zabbix_command):",
                "   - Pass 'hostIds' and 'commands'.",
                "   - Pass 'operating_system' if known.",
                "4. Review the results returned by the runner for each host ID."
            ],

            output: [
                "Only respond after the Zabbix script execution has finished and you have the results.",
                "Provide a clear and structured JSON report matching the following schema:",
                "```json",
                "{",
                '  "status": "######SUCCESS########",',
                '  "root_cause": "Concise interpretation of the probable root cause of the incident.",',
                '  "suggested_next_steps": "Actionable suggestions focused on the original incident problem.",',
                '  "technical_evidence": "Exact raw technical command outputs formatted for auditing.",',
                '  "affected_hosts": ["10001"],',
                '  "command_execution_results": [',
                '    {',
                '      "command": "uptime",',
                '      "output": "14:00:01 up 20 days...",',
                '      "status": "success",',
                '      "host_id": "10001"',
                '    }',
                '  ]',
                "}",
                "```",
                "",
                "Classification rules for 'status':",
                "- ######SUCCESS######## if the Zabbix execution returned valid command results.",
                "- ######FAILED:AGENT_ERROR######## if the output indicates issues with Zabbix agent or script execution on the host.",
                "- ######FAILED:AUTHENTICATION######## if the output contains authentication/authorization errors.",
                "- ######FAILED:NETWORK_ERROR######## if the output indicates connectivity issues or timeout.",
                "- ######SKIPPING: NO HOSTS MATCHED######## if no valid hosts were targeted.",
                "",
                "CRITICAL CONSTRAINTS:",
                "- The response MUST be valid JSON (optionally enclosed in a markdown code block).",
                "- 'root_cause' must contain concise interpretation of results and root cause.",
                "- 'suggested_next_steps' must provide actionable suggestions for the incident.",
                "- 'technical_evidence' must contain EXACT raw execution logs without modification.",
                "- 'affected_hosts' must list the targeted host IDs.",
                "- 'command_execution_results' must list structured results of each command executed."
            ]
        );
    }

    protected function tools(): array
    {
        return [
            ZabbixAdHocRunner::make()
        ];
    }

}
