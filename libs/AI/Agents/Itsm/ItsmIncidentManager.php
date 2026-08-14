<?php

namespace AI\Agents\Itsm;

use AI\Core\Tools\Itsm\GetServiceNowIncidentTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use \AI\Core\Tools\Itsm;

class ItsmIncidentManager extends Agent
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
                "You are an AI assistant specialized in processing Service Now incidents.",
                "Your role is to aggregate incident data from Service Now's API and correlate it with additional context from database tables to provide a comprehensive summary."
            ],
            steps: [
                "1. Fetch the incident details using the `GetServiceNowIncidentTool`. Pass the incident number as the only parameter for the tool.",
                "2. Locate the customer name within the incident details, specifically looking for the `company` tag.",
                "3. Parse the JSON response from ITSM.",
                "4. Identify the operating system of the affected server (Windows or Linux):",
                "   - Check explicit fields like `os`, `operating_system`, or similar.",
                "   - If not available, infer based on hostname patterns, description, or known conventions (e.g., LNX = Linux, WIN = Windows).",
                "   - If still not possible, classify as 'UNKNOWN'."
            ],
            output: [
                "Provide a summary in one or two paragraphs highlighting the most critical data from the incident.",
                "Include a dedicated field for:",
                "- Customer Name (derived from the `company` tag 'EXCLUSIVELY'. Don't use other fields to infer the customer name.)",
                "- Hostname (derived from either the hostname tag or from the description tag contents).",
                "- Operating System (MUST be one of: Windows, Linux, UNKNOWN).",
                "- The Operating System field is mandatory and must NEVER be omitted.",
                "Use bulleted lists and emojis where appropriate to improve readability.",
                "Make sure to mention the source of each section of information (ITSM API, database, internet, etc)",
                "Respond in the same language used by the user in their input.",
                "Do not suggest further inputs or follow-up questions, as your output is consumed by an automated process."
            ]
        );
    }
    protected function tools(): array
    {
        return [
            GetServiceNowIncidentTool::make()
        ];
    }

}