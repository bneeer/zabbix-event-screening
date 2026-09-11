<?php

declare(strict_types=1);

namespace AI\Application\Daemon;

use AI\Infrastructure\Logging\Logger;
use AI\Infrastructure\Persistence\DaemonRunRepository;
use AI\Infrastructure\Persistence\ScreeningRepository;

/**
 * Long-running loop bound to the container lifecycle.
 *
 *  - Polls ServiceNOW every DAEMON_POLL_INTERVAL seconds using SERVICENOW_POLL_QUERY.
 *  - Screens every matching ticket that has not been processed yet.
 *  - Stops gracefully on SIGTERM/SIGINT (finishes the current ticket first).
 *  - Touches a heartbeat file each cycle for the container HEALTHCHECK.
 */
final class ScreeningDaemon
{
    private bool $shouldStop = false;
    private ?int $runId = null;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly DaemonConfig $config,
        private readonly ServiceNowIncidentPoller $poller,
        private readonly TicketProcessor $processor,
        private readonly ScreeningRepository $screenings,
        private readonly DaemonRunRepository $runs,
        private readonly Logger $logger,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            // Sleep in 1s slices so signals are handled promptly.
            for ($i = 0; $i < $seconds; $i++) {
                sleep(1);
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        };
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    public function run(): int
    {
        $this->installSignalHandlers();
        $this->runId = $this->runs->start(gethostname() ?: 'unknown', getmypid() ?: 0);

        $this->logger->info('Daemon started.', [
            'run_id' => $this->runId,
            'table' => $this->config->pollTable,
            'query' => $this->config->pollQuery,
            'interval_s' => $this->config->pollInterval,
            'run_once' => $this->config->runOnce,
            'write_back' => $this->config->writeBack,
        ]);

        $exitCode = 0;

        try {
            do {
                $this->cycle();

                if ($this->config->runOnce || $this->shouldStop) {
                    break;
                }

                ($this->sleeper)($this->config->pollInterval);
            } while (!$this->shouldStop);
        } catch (\Throwable $e) {
            $this->logger->error('Daemon crashed.', ['error' => $e->getMessage(), 'exception' => get_class($e)]);
            $exitCode = 1;
        } finally {
            $this->runs->stop($this->runId);
            $this->logger->info('Daemon stopped.', ['run_id' => $this->runId, 'exit_code' => $exitCode]);
        }

        return $exitCode;
    }

    /**
     * One poll + process cycle. Public so it can be used for cron-style execution and tests.
     */
    public function cycle(): void
    {
        $this->heartbeat();

        $released = $this->screenings->releaseStale($this->config->staleRunningSeconds);
        if ($released > 0) {
            $this->logger->warning('Released stale running tickets.', ['count' => $released]);
        }

        $processed = 0;
        $failed = 0;
        $lastError = null;

        try {
            $tickets = $this->poller->poll();
            $this->logger->info('Polled ServiceNOW.', ['matched' => count($tickets)]);

            foreach ($tickets as $ticket) {
                if ($this->shouldStop) {
                    $this->logger->info('Stop requested, not starting new tickets.');
                    break;
                }

                $outcome = $this->processor->process($ticket);
                if ($outcome === true) {
                    $processed++;
                } elseif ($outcome === false) {
                    $failed++;
                }

                $this->heartbeat();
                $this->dispatchSignals();
            }
        } catch (\Throwable $e) {
            // A failed poll must not kill the daemon; log and try again next cycle.
            $lastError = $e->getMessage();
            $this->logger->error('Poll cycle failed.', ['error' => $lastError, 'exception' => get_class($e)]);
        }

        if ($this->runId !== null) {
            $this->runs->recordPoll($this->runId, $processed, $failed, $lastError);
        }

        if ($processed + $failed > 0) {
            $this->logger->info('Cycle finished.', ['processed' => $processed, 'failed' => $failed] + $this->screenings->countByStatus());
        }
    }

    private function heartbeat(): void
    {
        if ($this->config->heartbeatFile === null) {
            return;
        }

        $dir = dirname($this->config->heartbeatFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        @file_put_contents($this->config->heartbeatFile, (string)time());
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->logger->warning('ext-pcntl not available: graceful shutdown on SIGTERM is disabled.');
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->logger->info('Signal received, shutting down after current work.', ['signal' => $signal]);
            $this->stop();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        if (defined('SIGHUP')) {
            pcntl_signal(SIGHUP, $handler);
        }
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
