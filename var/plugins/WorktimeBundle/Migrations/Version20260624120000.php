<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: add cancel_requested flag to absence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_worktime_absence ADD cancel_requested TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('kimai2_worktime_absence')->dropColumn('cancel_requested');
    }
}
