# Zabbix Event Screening AI Agents

This project is an autonomous system based on AI Agents designed to perform triage and diagnosis of observability incidents from **ServiceNOW** using **Zabbix**.

The system automates the initial incident analysis process, connecting to ServiceNOW to extract error details, identifying affected servers in Zabbix, and executing safe diagnostic commands to identify the root cause.

## 🚀 Features

- **Intelligent Extraction**: Analyzes ServiceNOW incident descriptions to identify hosts and operating systems.
- **Automatic Triage**: Generates custom diagnostic commands (Shell/PowerShell) based on the incident type (CPU, Memory, Disk, Services, etc.).
- **Safe Execution**: Executes only read-only commands through the Zabbix API, ensuring environment integrity.
- **Root Cause Analysis**: Consolidates execution logs and generates a structured report for operational teams.
- **Multi-platform**: Support for Linux, Windows, Middleware, Databases, Networking, and SAP.

## 🏗️ System Architecture

The project uses the `NeuronAI` library to orchestrate several specialized agents:

### AI Agents (`AI\Agents`)

1.  **ItsmIncidentManager**: ServiceNOW specialist. Fetches incident details and correlates data to create an executive summary.
2.  **ZabbixHostFinderAgent**: Identifies and maps hostnames mentioned in the incident to actual IDs within Zabbix.
3.  **ZabbixAutoScreening**: The "brain" of the triage. Decides which diagnostic commands are necessary and safe for the reported problem.
4.  **ZabbixScanner**: Responsible for execution orchestration and generating the final root cause report.

### Tools (`AI\Core\Tools`)

-   **GetServiceNowIncidentTool**: Technical interface with the ServiceNOW API.
-   **ZabbixHostFinder**: Technical search for hosts in the Zabbix inventory.
-   **ZabbixAdHocRunner**: Executes scripts via Zabbix API and manages the creation/cleanup of temporary scripts.

## 🔄 Workflow

The main flow is implemented in `src/incident_screening/main.php`:

1.  **Incident Lookup**: Retrieves the ServiceNOW ticket.
2.  **Host Mapping**: The Agent locates the server in Zabbix.
3.  **Diagnosis Generation**: The Screening Agent defines the data collection strategy.
4.  **Execution**: The Scanner executes commands on the affected servers via Zabbix.
5.  **Reporting**: The result is processed and formatted with a root cause suggestion.

## 🛠️ Technologies Used

- **PHP 8.x**
- **Composer** (PSR-4 Autoloading)
- **NeuronAI Framework** (LLM Orchestration)
- **Azure OpenAI** (AI Provider)
- **Zabbix API**
- **ServiceNOW API**

## ⚙️ Configuration

API settings and credentials are managed through the `config/settings.php` file (not included in the repository for security) and use the following constants:

- `ZABBIX_ENDPOINT`, `ZABBIX_USER`, `ZABBIX_PASSWORD`
- `SERVICENOW_INSTANCE_CUSTOM_URL`, `SERVICENOW_INSTANCE_USER`, `SERVICENOW_INSTANCE_PASSWORD`
- `AZURE_OPENAI_KEY`, `AZURE_OPENAI_BASE_URL`, `AZURE_OPENAI_MODEL`

---
*This system was developed to assist operations teams (SRE/NOC), reducing mean time to repair (MTTR) through automated initial analysis.*
