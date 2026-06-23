<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create absence table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_absence (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            decided_by INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            half_day TINYINT(1) NOT NULL,
            status VARCHAR(20) NOT NULL,
            note LONGTEXT DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_worktime_absence_user_start (user_id, start_date),
            INDEX IDX_worktime_absence_status (status),
            INDEX IDX_AE838AC3239DAEC (decided_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_absence
            ADD CONSTRAINT FK_worktime_absence_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_worktime_absence
            ADD CONSTRAINT FK_worktime_absence_decided_by FOREIGN KEY (decided_by)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_absence');
    }
}
