<?php

declare(strict_types=1);

namespace AI\Application\Daemon;

final readonly class PolledTicket
{
    public function __construct(
        public string $number,
        public ?string $sysId,
        public ?string $shortDescription,
        public string $table
    ) {
    }
}
