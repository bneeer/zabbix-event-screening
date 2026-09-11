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

### Application & Infrastructure

-   **`AI\Application\Kernel`**: Composition root; wires services from the environment.
-   **`AI\Application\Daemon`**: `ScreeningDaemon` (loop, signals, heartbeat), `ServiceNowIncidentPoller` (encoded query polling + write-back), `TicketProcessor` (claim → screen → persist).
-   **`AI\Infrastructure\Database`**: `DatabaseConfig`, `ConnectionFactory` (SQLite/MySQL/PostgreSQL), `Migrator`, `DatabaseBootstrapper`.
-   **`AI\Infrastructure\Persistence`**: `ScreeningRepository` (ticket lifecycle / work queue), `DaemonRunRepository`.
-   **`AI\Infrastructure\Logging\Logger`**: stdout/stderr text or JSON logs.

## 🔄 Workflow

The daemon (`bin/console daemon`, also reachable through `src/incident_screening/main.php`) runs continuously inside the container:

1.  **Poll**: Every `DAEMON_POLL_INTERVAL` seconds, ServiceNOW is queried with the encoded query in `SERVICENOW_POLL_QUERY` (`sysparm_query`).
2.  **Deduplicate**: Each matching ticket is registered in the database; tickets already `completed`/`skipped` (or `failed` beyond `DAEMON_MAX_ATTEMPTS`) are ignored. An atomic *claim* prevents two replicas from screening the same ticket.
3.  **Screen** (per ticket): incident lookup → host mapping in Zabbix → diagnostic plan → security validation → execution via Zabbix → root-cause report.
4.  **Persist**: Summary, hosts, plan, raw evidence and the formatted report are stored in the `screenings` table; the report is also written to `DAEMON_OUTPUT_DIR`.
5.  **Write back** (optional): With `SERVICENOW_WRITE_BACK=true`, the report is posted to the ticket field `SERVICENOW_WRITE_BACK_FIELD` (default `work_notes`).
6.  **Shutdown**: `SIGTERM`/`SIGINT` (container stop) finishes the in-flight ticket and exits cleanly.

## 🐳 Running with Docker

```bash
cp .env.example .env         # fill in credentials and SERVICENOW_POLL_QUERY
docker compose up -d         # daemon + local SQLite in the "screening-data" volume
docker compose logs -f screening
```

Other deployment shapes:

```bash
# Remote PostgreSQL / MySQL bundled with compose (for evaluation)
# In .env set DB_DRIVER=pgsql DB_HOST=postgres (or DB_DRIVER=mysql DB_HOST=mysql), DB_USER and DB_PASSWORD
docker compose --profile postgres up -d
docker compose --profile mysql up -d

# Plain docker run with an external database
docker run -d --name screening --restart unless-stopped \
  --env-file .env \
  -e DB_DRIVER=pgsql -e DB_HOST=pg.internal -e DB_USER=app -e DB_PASSWORD=... \
  -v screening-data:/var/lib/zabbix-event-screening \
  zabbix-event-screening:latest

# One-off commands inside the image
docker compose run --rm screening screen INC0012345   # screen a single ticket
docker compose run --rm screening migrate:status
docker compose run --rm screening db:check
```

Image characteristics:

- `php:8.3-cli-alpine`, multi-stage build (Composer dependencies + NeuronAI patches applied at build time).
- Runs as non-root user `app`; state lives only in the volume mounted at `/var/lib/zabbix-event-screening`.
- `ENTRYPOINT` validates configuration (`config:check`), creates/migrates the database and then `exec`s the daemon, so PID 1 receives `SIGTERM`.
- `HEALTHCHECK` reads the heartbeat file the daemon touches every cycle (`DAEMON_HEARTBEAT_FILE`).
- Logs go to stdout/stderr (`LOG_FORMAT=json` by default in the image) — ready for Docker/Kubernetes log collectors.

## 🖥️ CLI

```
bin/console daemon              Continuous polling daemon (container default)
bin/console screen <INC>        Screen a single ticket and print the report
bin/console migrate             Create the database if missing and apply pending migrations
bin/console migrate:status      List applied/pending migrations
bin/console migrate:rollback    Roll back the last migration
bin/console db:check            Check connectivity and whether the database exists
bin/console config:check        Validate configuration without contacting external services
```

Set `DAEMON_RUN_ONCE=true` to execute a single poll/process cycle and exit (useful for cron or Kubernetes `CronJob`).

## 🗄️ Database & Migrations

The daemon needs a database to remember which tickets were processed and to store results.

| Scenario | Configuration |
|----------|---------------|
| Local SQLite (default) | Leave `DB_HOST` empty. File at `DB_SQLITE_PATH` (inside the volume). |
| MySQL / MariaDB | `DB_DRIVER=mysql` (or just set `DB_HOST`), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` |
| PostgreSQL | `DB_DRIVER=pgsql`, same credential variables. `DB_SSL_CA` enables `sslmode=verify-full`. |

On every start the application:

1. Checks whether the database already exists (file for SQLite, `information_schema`/`pg_database` for remote engines).
2. Creates it **only when missing** (`DB_AUTO_CREATE=true`; set to `false` to fail instead, e.g. when DBAs provision databases).
3. Applies pending migrations from `database/migrations/` — tracked in `schema_migrations`, so restarts are idempotent.

Migrations are plain PHP files named `YYYY_MM_DD_NNNNNN_description.php` returning a `Migration` object. A small `Dialect` helper abstracts type differences so one file targets SQLite, MySQL and PostgreSQL:

```php
return new class implements Migration {
    public function up(PDO $pdo, Dialect $d): void
    {
        $pdo->exec("ALTER TABLE screenings ADD COLUMN priority {$d->string(16)} NULL");
    }
    public function down(PDO $pdo, Dialect $d): void { /* ... */ }
};
```

Tables: `screenings` (one row per ticket: status `pending|running|completed|failed|skipped`, attempts, summary, hosts, plan, report, errors, timestamps) and `daemon_runs` (one row per daemon instance: host, pid, polls, processed/failed counters, last error).

## 🛠️ Technologies Used

- **PHP 8.3** (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `pcntl`)
- **Composer** (PSR-4 Autoloading)
- **NeuronAI Framework** (LLM Orchestration)
- **Azure OpenAI** (AI Provider)
- **Zabbix API** / **ServiceNOW API**
- **Docker** (multi-stage image, compose)

## ⚙️ Configuration

All configuration is read from environment variables (`AI\Core\Config\Env`). A local `.env.old` file is loaded when present but **never overrides** real environment variables, so the same image works with `docker run -e`, compose `env_file`, Kubernetes `ConfigMap`/`Secret`, etc. `.env.example` documents every variable; the main groups are:

| Group | Variables |
|-------|-----------|
| Application | `APP_ENV`, `APP_TIMEZONE`, `APP_STORAGE_PATH`, `LOG_LEVEL`, `LOG_FORMAT` |
| Azure OpenAI | `AZURE_OPENAI_KEY`, `AZURE_OPENAI_BASE_URL`, `AZURE_OPENAI_MODEL`, `AZURE_OPENAI_API_VERSION` |
| ServiceNOW | `SERVICENOW_INSTANCE_CUSTOM_URL`, `SERVICENOW_INSTANCE_USER`, `SERVICENOW_INSTANCE_PASSWORD` |
| Polling rule | `SERVICENOW_POLL_QUERY` **(required)**, `SERVICENOW_POLL_TABLE`, `SERVICENOW_POLL_LIMIT`, `SERVICENOW_WRITE_BACK`, `SERVICENOW_WRITE_BACK_FIELD` |
| Daemon | `DAEMON_POLL_INTERVAL`, `DAEMON_MAX_ATTEMPTS`, `DAEMON_RETRY_FAILED`, `DAEMON_RUN_ONCE`, `DAEMON_HEARTBEAT_FILE`, `DAEMON_OUTPUT_DIR`, `DAEMON_STALE_RUNNING_SECONDS` |
| Database | `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SQLITE_PATH`, `DB_AUTO_CREATE`, `DB_SSL_CA` |
| Zabbix | `ZABBIX_ENDPOINT`, `ZABBIX_USER`, `ZABBIX_PASSWORD`, `ZABBIX_EXECUTION_TYPE` |
| Network / TLS | `PROXY`, `SSL_CA_BUNDLE`, `SERVICENOW_CA_BUNDLE`, `ZABBIX_CA_BUNDLE`, `AZURE_OPENAI_CA_BUNDLE` |

Example polling rule — new incidents assigned to the monitoring group whose description mentions Zabbix, oldest first:

```
SERVICENOW_POLL_QUERY=state=1^assignment_group.name=Monitoring^short_descriptionLIKEZabbix^ORDERBYsys_created_on
```

Run `bin/console config:check` to validate the configuration before starting.

### 🔒 TLS / SSL Security and Custom CA Configuration

All outbound HTTPS connections (ServiceNOW, Zabbix API, Azure OpenAI, Composer) have TLS certificate verification enabled by default (`verify_peer=true`, `verify_host=2`, `allow_self_signed=false`).

In controlled environments requiring custom internal Root or Intermediate Certificate Authorities (CAs) or self-signed certificates signed by a private CA:
- Do **not** disable TLS verification.
- Specify the path to your PEM-formatted CA certificate bundle using the environment variables or settings constants:
  - `SSL_CA_BUNDLE`: Global CA bundle used by all clients if specific overrides are not provided.
  - `SERVICENOW_CA_BUNDLE`: Custom CA certificate path for ServiceNOW API calls.
  - `ZABBIX_CA_BUNDLE`: Custom CA certificate path for Zabbix API calls.
  - `AZURE_OPENAI_CA_BUNDLE`: Custom CA certificate path for Azure OpenAI API calls.

If a CA bundle path is configured, the application validates its existence and will abort immediately if the bundle file is not found, preventing silent fallback to unencrypted or unverified communication.

---
*This system was developed to assist operations teams (SRE/NOC), reducing mean time to repair (MTTR) through automated initial analysis.*
