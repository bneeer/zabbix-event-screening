<?php

declare(strict_types=1);

use AI\Infrastructure\Database\Migrations\Dialect;
use AI\Infrastructure\Database\Migrations\Migration;

return new class implements Migration {
    public function up(PDO $pdo, Dialect $d): void
    {
        $pdo->exec(sprintf(
            <<<SQL
            CREATE TABLE daemon_runs (
                id             %s,
                hostname       %s NOT NULL,
                pid            %s NOT NULL,
                started_at     %s NOT NULL,
                last_poll_at   %s NULL,
                stopped_at     %s NULL,
                polls          %s NOT NULL DEFAULT 0,
                processed      %s NOT NULL DEFAULT 0,
                failed         %s NOT NULL DEFAULT 0,
                last_error     %s NULL
            )%s
            SQL,
            $d->primaryKey(),
            $d->string(255),
            $d->integer(),
            $d->datetime(),
            $d->datetime(),
            $d->datetime(),
            $d->integer(),
            $d->integer(),
            $d->integer(),
            $d->text(),
            $d->tableOptions()
        ));
    }

    public function down(PDO $pdo, Dialect $d): void
    {
        $pdo->exec('DROP TABLE IF EXISTS daemon_runs');
    }
};
