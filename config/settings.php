<?php

declare(strict_types=1);

/*
 * Central configuration. Every value comes from the environment (real process
 * variables or a local .env file), so the same image can be deployed anywhere.
 *
 * Constants are kept for backwards compatibility with agents/tools that read them.
 */

use AI\Core\Config\Env;

// --- Application -----------------------------------------------------------
define('APP_ENV', Env::get('APP_ENV', 'production'));
define('APP_TIMEZONE', Env::get('APP_TIMEZONE', 'UTC'));
define('APP_STORAGE_PATH', rtrim(Env::get('APP_STORAGE_PATH', dirname(__DIR__) . '/storage'), '/\\'));
define('LOG_LEVEL', strtolower(Env::get('LOG_LEVEL', 'info')));
define('LOG_FORMAT', strtolower(Env::get('LOG_FORMAT', 'text')));

// --- Azure OpenAI ----------------------------------------------------------
define('AZURE_OPENAI_KEY', Env::get('AZURE_OPENAI_KEY'));
define('AZURE_OPENAI_MODEL', Env::get('AZURE_OPENAI_MODEL'));
define('AZURE_OPENAI_API_VERSION', Env::get('AZURE_OPENAI_API_VERSION'));
define('AZURE_OPENAI_BASE_URL', Env::get('AZURE_OPENAI_BASE_URL'));
define('AZURE_OPENAI_RESOURCE_NAME', Env::get('AZURE_OPENAI_RESOURCE_NAME'));
define('AZURE_OPENAI_EMBEDDINGS_MODEL', Env::get('AZURE_OPENAI_EMBEDDINGS_MODEL'));
define('AZURE_OPENAI_EMBEDDINGS_API_VERSION', Env::get('AZURE_OPENAI_EMBEDDINGS_API_VERSION'));
define('AZURE_OPENAI_CA_BUNDLE', Env::firstOf(['AZURE_OPENAI_CA_BUNDLE', 'SSL_CA_BUNDLE']));

// --- ServiceNOW ------------------------------------------------------------
define('SERVICENOW_INSTANCE_NAME', Env::get('SERVICENOW_INSTANCE_NAME'));
define('SERVICENOW_INSTANCE_CUSTOM_URL', Env::get('SERVICENOW_INSTANCE_CUSTOM_URL'));
define('SERVICENOW_INSTANCE_USER', Env::get('SERVICENOW_INSTANCE_USER'));
define('SERVICENOW_INSTANCE_PASSWORD', Env::get('SERVICENOW_INSTANCE_PASSWORD'));
define('SERVICENOW_CA_BUNDLE', Env::firstOf(['SERVICENOW_CA_BUNDLE', 'SSL_CA_BUNDLE']));

// Daemon polling: which tickets should be screened automatically.
define('SERVICENOW_POLL_TABLE', Env::get('SERVICENOW_POLL_TABLE', 'incident'));
define('SERVICENOW_POLL_QUERY', Env::get('SERVICENOW_POLL_QUERY'));
define('SERVICENOW_POLL_LIMIT', Env::int('SERVICENOW_POLL_LIMIT', 20));
define('SERVICENOW_WRITE_BACK', Env::bool('SERVICENOW_WRITE_BACK', false));
define('SERVICENOW_WRITE_BACK_FIELD', Env::get('SERVICENOW_WRITE_BACK_FIELD', 'work_notes'));

// --- Daemon ----------------------------------------------------------------
define('DAEMON_POLL_INTERVAL', Env::int('DAEMON_POLL_INTERVAL', 60));
define('DAEMON_MAX_ATTEMPTS', Env::int('DAEMON_MAX_ATTEMPTS', 3));
define('DAEMON_RETRY_FAILED', Env::bool('DAEMON_RETRY_FAILED', true));
define('DAEMON_RUN_ONCE', Env::bool('DAEMON_RUN_ONCE', false));
define('DAEMON_HEARTBEAT_FILE', Env::get('DAEMON_HEARTBEAT_FILE', APP_STORAGE_PATH . '/daemon.heartbeat'));
define('DAEMON_OUTPUT_DIR', Env::get('DAEMON_OUTPUT_DIR', APP_STORAGE_PATH . '/screenings'));

// --- Database --------------------------------------------------------------
// DB_DRIVER: sqlite | mysql | pgsql. When omitted, mysql/pgsql is used only if
// DB_HOST is set; otherwise a local SQLite file is used.
define('DB_DRIVER', Env::get('DB_DRIVER'));
define('DB_HOST', Env::get('DB_HOST'));
define('DB_PORT', Env::get('DB_PORT'));
define('DB_NAME', Env::get('DB_NAME', 'zabbix_event_screening'));
define('DB_USER', Env::get('DB_USER'));
define('DB_PASSWORD', Env::get('DB_PASSWORD'));
define('DB_SQLITE_PATH', Env::get('DB_SQLITE_PATH', APP_STORAGE_PATH . '/database.sqlite'));
define('DB_AUTO_CREATE', Env::bool('DB_AUTO_CREATE', true));
define('DB_SSL_CA', Env::get('DB_SSL_CA'));

// --- Network / TLS ---------------------------------------------------------
define('PROXY', Env::get('PROXY'));
define('SSL_CA_BUNDLE', Env::get('SSL_CA_BUNDLE'));

// --- Zabbix ----------------------------------------------------------------
define('ZABBIX_ENDPOINT', Env::get('ZABBIX_ENDPOINT'));
define('ZABBIX_USER', Env::get('ZABBIX_USER'));
define('ZABBIX_PASSWORD', Env::get('ZABBIX_PASSWORD'));
define('ZABBIX_EXECUTION_TYPE', Env::get('ZABBIX_EXECUTION_TYPE', '0'));
define('ZABBIX_CA_BUNDLE', Env::firstOf(['ZABBIX_CA_BUNDLE', 'SSL_CA_BUNDLE']));
