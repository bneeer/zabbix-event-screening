<?php

declare(strict_types=1);

use AI\Infrastructure\Database\Migrations\Dialect;
use AI\Infrastructure\Database\Migrations\Migration;

return new class implements Migration {
    public function up(PDO $pdo, Dialect $d): void
    {
        $pdo->exec(sprintf(
            <<<SQL
            CREATE TABLE screenings (
                id                %s,
                incident_number   %s NOT NULL,
                incident_sys_id   %s NULL,
                source_table      %s NOT NULL DEFAULT 'incident',
                status            %s NOT NULL DEFAULT 'pending',
                attempts          %s NOT NULL DEFAULT 0,
                short_description %s NULL,
                incident_summary  %s NULL,
                host_ids          %s NULL,
                diagnostic_plan   %s NULL,
                itsm_output       %s NULL,
                raw_output        %s NULL,
                error_message     %s NULL,
                output_file       %s NULL,
                written_back_at   %s NULL,
                started_at        %s NULL,
                finished_at       %s NULL,
                created_at        %s NOT NULL,
                updated_at        %s NOT NULL,
                CONSTRAINT uq_screenings_incident UNIQUE (incident_number)
            )%s
            SQL,
            $d->primaryKey(),
            $d->string(64),
            $d->string(64),
            $d->string(64),
            $d->string(16),
            $d->integer(),
            $d->text(),
            $d->text(),
            $d->json(),
            $d->json(),
            $d->text(),
            $d->text(),
            $d->text(),
            $d->string(512),
            $d->datetime(),
            $d->datetime(),
            $d->datetime(),
            $d->datetime(),
            $d->datetime(),
            $d->tableOptions()
        ));

        $pdo->exec('CREATE INDEX idx_screenings_status ON screenings (status)');
        $pdo->exec('CREATE INDEX idx_screenings_created_at ON screenings (created_at)');
    }

    public function down(PDO $pdo, Dialect $d): void
    {
        $pdo->exec('DROP TABLE IF EXISTS screenings');
    }
};
