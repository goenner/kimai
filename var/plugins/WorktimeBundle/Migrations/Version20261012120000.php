<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create free_day table (admin-defined full/half days off)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_free_day (
            id INT AUTO_INCREMENT NOT NULL,
            day DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            name VARCHAR(100) NOT NULL,
            half_day TINYINT(1) NOT NULL,
            region VARCHAR(2) DEFAULT NULL,
            INDEX IDX_worktime_free_day_day (day),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_free_day');
    }
}
