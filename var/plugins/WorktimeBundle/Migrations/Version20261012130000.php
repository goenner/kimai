<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Stichtag des Zeitkontos. Bestehende Verträge bekommen den Tag des Einspielens (= Rollout-Tag),
 * damit die Zeit vor Einführung der Stempeluhr keine Minusstunden erzeugt.
 */
final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: add account_start to contract, set to rollout day for existing contracts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_worktime_contract ADD account_start DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
        // Datum in deutscher Zeit (die DB-Zeitzone kann UTC sein: zwischen 0 und 2 Uhr wäre CURRENT_DATE der Vortag)
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $this->addSql('UPDATE kimai2_worktime_contract SET account_start = ? WHERE account_start IS NULL', [$today]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('kimai2_worktime_contract')->dropColumn('account_start');
    }
}
