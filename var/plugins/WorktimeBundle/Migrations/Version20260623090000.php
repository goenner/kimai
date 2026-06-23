<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create contract table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_contract (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            work_hours_mon INT NOT NULL,
            work_hours_tue INT NOT NULL,
            work_hours_wed INT NOT NULL,
            work_hours_thu INT NOT NULL,
            work_hours_fri INT NOT NULL,
            work_hours_sat INT NOT NULL,
            work_hours_sun INT NOT NULL,
            holidays_per_year DOUBLE PRECISION NOT NULL,
            vacation_carryover DOUBLE PRECISION NOT NULL,
            initial_overtime INT NOT NULL,
            employment_start DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            employment_end DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            holiday_region VARCHAR(50) NOT NULL,
            daily_end_time VARCHAR(5) DEFAULT NULL,
            UNIQUE INDEX UNIQ_worktime_contract_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_contract
            ADD CONSTRAINT FK_worktime_contract_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_contract');
    }
}
