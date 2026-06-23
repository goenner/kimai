<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create balance_correction table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_balance_correction (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            created_by INT DEFAULT NULL,
            date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            account VARCHAR(20) NOT NULL,
            seconds INT NOT NULL,
            reason LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_worktime_correction_user_date (user_id, date),
            INDEX IDX_B308F16CDE12AB56 (created_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_balance_correction
            ADD CONSTRAINT FK_worktime_correction_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_worktime_balance_correction
            ADD CONSTRAINT FK_worktime_correction_created_by FOREIGN KEY (created_by)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_balance_correction');
    }
}
