<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class DiagnosticPlan implements \JsonSerializable
{
    /**
     * @param string $customer
     * @param string $analysis
     * @param array<DiagnosticCommand> $commands
     * @param array<string, string> $hostIds Array mapping hostId to operating_system
     */
    public function __construct(
        public string $customer,
        public string $analysis,
        public array $commands,
        public array $hostIds = []
    ) {
        if (trim($customer) === '') {
            throw new AiContractValidationException('DiagnosticPlan customer cannot be empty.');
        }

        if (trim($analysis) === '') {
            throw new AiContractValidationException('DiagnosticPlan analysis cannot be empty.');
        }

        if (empty($commands)) {
            throw new AiContractValidationException('DiagnosticPlan must contain at least one diagnostic command.');
        }

        foreach ($commands as $index => $command) {
            if (!$command instanceof DiagnosticCommand) {
                throw new AiContractValidationException(sprintf(
                    'DiagnosticPlan commands must contain instances of %s, %s found at index %d.',
                    DiagnosticCommand::class,
                    get_debug_type($command),
                    $index
                ));
            }
        }

        foreach ($hostIds as $hostId => $os) {
            if (!is_string($hostId) && !is_int($hostId)) {
                throw new AiContractValidationException('DiagnosticPlan hostIds keys must be valid host identifiers.');
            }
            if (!is_string($os)) {
                throw new AiContractValidationException('DiagnosticPlan hostIds values must be operating system strings.');
            }
        }
    }

    /**
     * Return list of command strings.
     *
     * @return array<string>
     */
    public function getCommandStrings(): array
    {
        return array_map(fn(DiagnosticCommand $cmd) => $cmd->command, $this->commands);
    }

    /**
     * Returns a new DiagnosticPlan instance with injected hostIds.
     *
     * @param array<string, string> $hostIds
     */
    public function withHostIds(array $hostIds): self
    {
        return new self(
            customer: $this->customer,
            analysis: $this->analysis,
            commands: $this->commands,
            hostIds: $hostIds
        );
    }

    public function jsonSerialize(): array
    {
        $data = [
            'customer' => $this->customer,
            'analysis' => $this->analysis,
            'commands' => array_map(fn(DiagnosticCommand $cmd) => $cmd->command, $this->commands),
        ];

        if (!empty($this->hostIds)) {
            $data['hostIds'] = $this->hostIds;
        }

        return $data;
    }
}
