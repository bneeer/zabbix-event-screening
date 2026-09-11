<?php

declare(strict_types=1);

namespace AI\Application\Daemon;

use AI\Application\Exceptions\IncidentNotFoundException;
use AI\Application\Exceptions\NoHostsFoundException;
use AI\Application\IncidentScreeningService;
use AI\Core\Security\CommandValidationException;
use AI\Infrastructure\Logging\Logger;
use AI\Infrastructure\Persistence\ScreeningRepository;

/**
 * Processes a single polled ticket end to end: claim -> screen -> persist -> write back.
 */
final class TicketProcessor
{
    public function __construct(
        private readonly IncidentScreeningService $service,
        private readonly ScreeningRepository $repository,
        private readonly ServiceNowIncidentPoller $poller,
        private readonly DaemonConfig $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Returns true when the ticket was screened successfully, false on failure/skip,
     * null when it was not eligible (already processed or claimed by another worker).
     */
    public function process(PolledTicket $ticket): ?bool
    {
        $number = $ticket->number;

        $this->repository->register($number, $ticket->sysId, $ticket->table, $ticket->shortDescription);

        if (!$this->repository->isEligible($number, $this->config->maxAttempts, $this->config->retryFailed)) {
            $this->logger->debug('Ticket not eligible, skipping.', ['ticket' => $number]);
            return null;
        }

        if (!$this->repository->claim($number)) {
            $this->logger->debug('Ticket claimed by another worker.', ['ticket' => $number]);
            return null;
        }

        $this->logger->info('Screening ticket.', ['ticket' => $number, 'summary' => $ticket->shortDescription]);
        $startedAt = microtime(true);

        try {
            $result = $this->service->screenIncident(
                incidentNumber: $number,
                outputDirectory: $this->config->outputDirectory,
                onProgress: fn (string $chunk) => $this->logger->debug(rtrim($chunk), ['ticket' => $number])
            );

            $this->repository->markCompleted($number, $result);
            $this->logger->info('Ticket screened.', [
                'ticket' => $number,
                'hosts' => array_keys($result->hostIdsToOsMap),
                'duration_s' => round(microtime(true) - $startedAt, 1),
            ]);

            if ($this->config->writeBack) {
                $this->writeBack($ticket, $result->getItsmOutput());
            }

            return true;
        } catch (NoHostsFoundException | IncidentNotFoundException $e) {
            // Deterministic outcomes: retrying will not change the result.
            $this->repository->markSkipped($number, $e->getMessage());
            $this->logger->warning('Ticket skipped.', ['ticket' => $number, 'reason' => $e->getMessage()]);
            return false;
        } catch (CommandValidationException $e) {
            $this->repository->markFailed($number, 'Command policy violation: ' . $e->getMessage());
            $this->logger->error('Diagnostic plan rejected by security policy.', ['ticket' => $number, 'error' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            $this->repository->markFailed($number, get_class($e) . ': ' . $e->getMessage());
            $this->logger->error('Ticket screening failed.', [
                'ticket' => $number,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return false;
        }
    }

    private function writeBack(PolledTicket $ticket, string $content): void
    {
        try {
            $this->poller->writeBack($ticket, $this->config->writeBackField, $content);
            $this->repository->markWrittenBack($ticket->number);
            $this->logger->info('Report posted to ServiceNOW.', ['ticket' => $ticket->number, 'field' => $this->config->writeBackField]);
        } catch (\Throwable $e) {
            // Screening itself succeeded; do not fail the ticket because of the write-back.
            $this->logger->error('Write-back to ServiceNOW failed.', ['ticket' => $ticket->number, 'error' => $e->getMessage()]);
        }
    }
}
