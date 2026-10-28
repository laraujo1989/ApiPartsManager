<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027221633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE import_job (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, filename VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, created INT DEFAULT 0 NOT NULL, updated INT DEFAULT 0 NOT NULL, skipped INT DEFAULT 0 NOT NULL, errors JSON NOT NULL, success TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_6FB540788B8E8428 (created_at), INDEX IDX_6FB54078A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE import_job ADD CONSTRAINT FK_6FB54078A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import_job DROP FOREIGN KEY FK_6FB54078A76ED395');
        $this->addSql('DROP TABLE import_job');
    }
}
