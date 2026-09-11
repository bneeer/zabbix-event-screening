<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database\Migrations;

use PDO;

interface Migration
{
    public function up(PDO $pdo, Dialect $dialect): void;

    public function down(PDO $pdo, Dialect $dialect): void;
}
