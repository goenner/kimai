<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create audit_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_audit_log (
            id INT AUTO_INCREMENT NOT NULL,
            actor_id INT DEFAULT NULL,
            target_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            action VARCHAR(40) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id INT DEFAULT NULL,
            details LONGTEXT DEFAULT NULL,
            INDEX IDX_A16978E010DAF24A (actor_id),
            INDEX IDX_A16978E0158E0B66 (target_id),
            INDEX IDX_worktime_audit_target (target_id, created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_audit_log
            ADD CONSTRAINT FK_A16978E010DAF24A FOREIGN KEY (actor_id)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE kimai2_worktime_audit_log
            ADD CONSTRAINT FK_A16978E0158E0B66 FOREIGN KEY (target_id)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_audit_log');
    }
}
