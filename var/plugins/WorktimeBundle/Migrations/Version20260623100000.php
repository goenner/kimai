<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create work_block table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_work_block (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            start_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            end_time DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            source VARCHAR(10) NOT NULL,
            needs_review TINYINT(1) NOT NULL,
            INDEX IDX_worktime_block_user_start (user_id, start_time),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_work_block
            ADD CONSTRAINT FK_worktime_block_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_work_block');
    }
}
