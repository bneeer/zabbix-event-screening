<?php

declare(strict_types=1);

namespace AI\Application\Daemon;

use AI\Application\Exceptions\IncidentScreeningException;
use ServiceNOW\Client as ServiceNOW;

/**
 * Fetches the tickets matching the configured ServiceNOW encoded query.
 */
class ServiceNowIncidentPoller
{
    public function __construct(
        private readonly ServiceNOW $client,
        private readonly string $table,
        private readonly string $query,
        private readonly int $limit
    ) {
    }

    /**
     * @return PolledTicket[]
     * @throws IncidentScreeningException when ServiceNOW returns an error
     */
    public function poll(): array
    {
        $response = $this->client->RetrieveAllRecords($this->table, [
            'sysparm_query' => $this->query,
            'sysparm_limit' => $this->limit,
            'sysparm_fields' => 'number,sys_id,short_description',
            'sysparm_display_value' => 'false',
            'sysparm_exclude_reference_link' => 'true',
        ]);

        if (!is_array($response) || !($response['status'] ?? false)) {
            $message = is_array($response) ? ($response['Message'] ?? 'Unknown error') : 'No response';
            throw new IncidentScreeningException("ServiceNOW poll failed: " . trim((string)$message));
        }

        $tickets = [];
        foreach ((array)($response['data'] ?? []) as $record) {
            $number = trim((string)($record['number'] ?? ''));
            if ($number === '') {
                continue;
            }

            $tickets[] = new PolledTicket(
                number: $number,
                sysId: isset($record['sys_id']) ? (string)$record['sys_id'] : null,
                shortDescription: isset($record['short_description']) ? (string)$record['short_description'] : null,
                table: $this->table
            );
        }

        return $tickets;
    }

    /**
     * Posts the screening report to the ticket.
     */
    public function writeBack(PolledTicket $ticket, string $field, string $content): void
    {
        if ($ticket->sysId === null) {
            throw new IncidentScreeningException("Cannot write back to {$ticket->number}: sys_id unknown.");
        }

        $response = $this->client->UpdateRecord($ticket->table, $ticket->sysId, [$field => $content]);

        if (!is_array($response) || !($response['status'] ?? false)) {
            $message = is_array($response) ? ($response['Message'] ?? 'Unknown error') : 'No response';
            throw new IncidentScreeningException("ServiceNOW write-back failed for {$ticket->number}: " . trim((string)$message));
        }
    }
}
