<?php

declare(strict_types=1);

/*
 * Container entrypoint: runs the continuous screening daemon.
 *
 * Kept for backwards compatibility; equivalent to `bin/console daemon`.
 * To screen a single ticket use `bin/console screen <INC>`.
 */

$_SERVER['argv'] = [$_SERVER['argv'][0] ?? 'console', 'daemon'];
$argv = $_SERVER['argv'];

require __DIR__ . '/../../bin/console';
