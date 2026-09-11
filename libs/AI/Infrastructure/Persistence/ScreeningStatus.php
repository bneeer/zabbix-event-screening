<?php

declare(strict_types=1);

namespace AI\Infrastructure\Persistence;

enum ScreeningStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
