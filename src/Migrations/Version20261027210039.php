<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027210039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(60) NOT NULL, entity_type VARCHAR(40) DEFAULT NULL, entity_id INT DEFAULT NULL, description LONGTEXT NOT NULL, changes JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_FD06F647C412EE0281257D5D (entity_type, entity_id), INDEX IDX_FD06F6478B8E8428 (created_at), INDEX IDX_FD06F647A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(120) NOT NULL, email VARCHAR(180) DEFAULT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL, full_name VARCHAR(160) NOT NULL, phone VARCHAR(40) DEFAULT NULL, picture VARCHAR(255) DEFAULT NULL, must_change_password TINYINT NOT NULL, is_active TINYINT NOT NULL, page_size INT DEFAULT 15 NOT NULL, disable_auto_logout TINYINT NOT NULL, enable_urgent_alerts TINYINT NOT NULL, permissions JSON NOT NULL, appearance JSON NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_area (user_id INT NOT NULL, area_id INT NOT NULL, INDEX IDX_AD1571A1A76ED395 (user_id), INDEX IDX_AD1571A1BD0F409C (area_id), PRIMARY KEY (user_id, area_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_vendor (user_id INT NOT NULL, vendor_id INT NOT NULL, INDEX IDX_907028CBA76ED395 (user_id), INDEX IDX_907028CBF603EE73 (vendor_id), PRIMARY KEY (user_id, vendor_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE approval_lock (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, entity_id INT NOT NULL, locked_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, locked_by_id INT NOT NULL, UNIQUE INDEX UNIQ_535F81E13BC4BCD981257D5D (kind, entity_id), INDEX IDX_535F81E17A88E00 (locked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE area (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(255) DEFAULT NULL, plant VARCHAR(80) NOT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cost_center (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(255) DEFAULT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nomenclator (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) NOT NULL, abbreviation VARCHAR(60) NOT NULL, long_name VARCHAR(200) NOT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, message LONGTEXT NOT NULL, type VARCHAR(30) NOT NULL, related_kind VARCHAR(20) DEFAULT NULL, related_id INT DEFAULT NULL, is_read TINYINT NOT NULL, is_urgent TINYINT NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_BF5476CAA76ED395DA46F46 (user_id, is_read), INDEX IDX_BF5476CAA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE part (id INT AUTO_INCREMENT NOT NULL, material_number VARCHAR(60) DEFAULT NULL, sap_part_number VARCHAR(60) DEFAULT NULL, description VARCHAR(255) NOT NULL, additional_text LONGTEXT DEFAULT NULL, manufacturer VARCHAR(160) DEFAULT NULL, manufacturer_part_number VARCHAR(120) DEFAULT NULL, manufacturer_internal_part_number VARCHAR(120) DEFAULT NULL, bin_location VARCHAR(80) DEFAULT NULL, class_label VARCHAR(120) DEFAULT NULL, sub_class_label VARCHAR(160) DEFAULT NULL, characteristics JSON NOT NULL, vendor_name VARCHAR(200) DEFAULT NULL, price NUMERIC(12, 2) NOT NULL, lead_time_days VARCHAR(40) DEFAULT NULL, stock_item TINYINT NOT NULL, minimum_quantity INT DEFAULT 0 NOT NULL, desired_quantity INT DEFAULT 0 NOT NULL, current_quantity INT DEFAULT 0 NOT NULL, plant VARCHAR(40) DEFAULT NULL, storage_location VARCHAR(60) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, replaced_parts_text LONGTEXT DEFAULT NULL, images JSON NOT NULL, manuals JSON NOT NULL, source VARCHAR(20) DEFAULT \'registered\' NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, part_class_id INT DEFAULT NULL, sub_class_id INT DEFAULT NULL, vendor_id INT DEFAULT NULL, last_edited_by_id INT DEFAULT NULL, INDEX IDX_490F70C67A2946F (material_number), INDEX IDX_490F70C6AFD7CBFA (manufacturer_part_number), INDEX IDX_490F70C6AB030D72 (plant), INDEX IDX_490F70C67D528E6F (part_class_id), INDEX IDX_490F70C650AFE549 (sub_class_id), INDEX IDX_490F70C6F603EE73 (vendor_id), INDEX IDX_490F70C6D48D54E8 (last_edited_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE part_equipment (part_id INT NOT NULL, tree_node_id INT NOT NULL, INDEX IDX_1315EBAB4CE34BEC (part_id), INDEX IDX_1315EBAB8C8A3DCA (tree_node_id), PRIMARY KEY (part_id, tree_node_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE part_class (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(500) DEFAULT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE part_request (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, short_description VARCHAR(255) NOT NULL, manufacturer VARCHAR(160) DEFAULT NULL, manufacturer_part_number VARCHAR(120) NOT NULL, manufacturer_internal_part_number VARCHAR(120) DEFAULT NULL, additional_text LONGTEXT DEFAULT NULL, characteristics JSON NOT NULL, price NUMERIC(12, 2) NOT NULL, quote_number VARCHAR(80) DEFAULT NULL, quotes JSON NOT NULL, stock_item TINYINT NOT NULL, minimum_quantity INT DEFAULT 0 NOT NULL, desired_quantity INT DEFAULT 0 NOT NULL, lead_time_days VARCHAR(40) DEFAULT NULL, images JSON NOT NULL, manuals JSON NOT NULL, notes LONGTEXT DEFAULT NULL, change_note LONGTEXT DEFAULT NULL, bin_location VARCHAR(80) DEFAULT NULL, also_create_requisition TINYINT NOT NULL, status VARCHAR(30) NOT NULL, requested_at DATETIME NOT NULL, approvers JSON NOT NULL, is_priority TINYINT NOT NULL, approved_at DATETIME DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, registered_at DATETIME DEFAULT NULL, registration_notes LONGTEXT DEFAULT NULL, updated_at DATETIME DEFAULT NULL, viewed_at DATETIME DEFAULT NULL, viewed_at_stage VARCHAR(20) DEFAULT NULL, part_class_id INT DEFAULT NULL, sub_class_id INT DEFAULT NULL, vendor_id INT DEFAULT NULL, target_part_id INT DEFAULT NULL, also_replaces_part_id INT DEFAULT NULL, resulting_part_id INT DEFAULT NULL, requested_by_id INT NOT NULL, approved_by_id INT DEFAULT NULL, rejected_by_id INT DEFAULT NULL, registered_by_id INT DEFAULT NULL, viewed_by_id INT DEFAULT NULL, INDEX IDX_BBCB78947B00651C (status), INDEX IDX_BBCB78947D528E6F (part_class_id), INDEX IDX_BBCB789450AFE549 (sub_class_id), INDEX IDX_BBCB7894F603EE73 (vendor_id), INDEX IDX_BBCB7894878BF287 (target_part_id), INDEX IDX_BBCB7894898D4F41 (also_replaces_part_id), INDEX IDX_BBCB789471AB4EA0 (resulting_part_id), INDEX IDX_BBCB78944DA1E751 (requested_by_id), INDEX IDX_BBCB78942D234F6A (approved_by_id), INDEX IDX_BBCB7894CBF05FC9 (rejected_by_id), INDEX IDX_BBCB789427E92E18 (registered_by_id), INDEX IDX_BBCB78944ED880FC (viewed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE part_request_equipment (part_request_id INT NOT NULL, tree_node_id INT NOT NULL, INDEX IDX_18217DFB770B3D61 (part_request_id), INDEX IDX_18217DFB8C8A3DCA (tree_node_id), PRIMARY KEY (part_request_id, tree_node_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parts_requisition (id INT AUTO_INCREMENT NOT NULL, description VARCHAR(255) NOT NULL, notes LONGTEXT DEFAULT NULL, use_same_cost_center TINYINT NOT NULL, cost_center_type VARCHAR(20) NOT NULL, global_cost_center VARCHAR(120) DEFAULT NULL, po VARCHAR(60) DEFAULT NULL, requisition_number VARCHAR(60) DEFAULT NULL, work_order VARCHAR(60) DEFAULT NULL, closed TINYINT NOT NULL, po_assigned_at DATETIME DEFAULT NULL, next_notification_at DATETIME DEFAULT NULL, final_cost NUMERIC(14, 2) DEFAULT NULL, status VARCHAR(30) NOT NULL, requested_at DATETIME NOT NULL, approvers JSON NOT NULL, is_priority TINYINT NOT NULL, approved_at DATETIME DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, registered_at DATETIME DEFAULT NULL, registration_notes LONGTEXT DEFAULT NULL, updated_at DATETIME DEFAULT NULL, viewed_at DATETIME DEFAULT NULL, viewed_at_stage VARCHAR(20) DEFAULT NULL, requested_by_id INT NOT NULL, approved_by_id INT DEFAULT NULL, rejected_by_id INT DEFAULT NULL, registered_by_id INT DEFAULT NULL, viewed_by_id INT DEFAULT NULL, INDEX IDX_50DC52DE7B00651C (status), INDEX IDX_50DC52DE4DA1E751 (requested_by_id), INDEX IDX_50DC52DE2D234F6A (approved_by_id), INDEX IDX_50DC52DECBF05FC9 (rejected_by_id), INDEX IDX_50DC52DE27E92E18 (registered_by_id), INDEX IDX_50DC52DE4ED880FC (viewed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parts_requisition_item (id INT AUTO_INCREMENT NOT NULL, source_type VARCHAR(20) NOT NULL, source_id INT DEFAULT NULL, material_number VARCHAR(60) DEFAULT NULL, description VARCHAR(255) NOT NULL, manufacturer_part_number VARCHAR(120) DEFAULT NULL, unit_price NUMERIC(12, 2) NOT NULL, quantity INT DEFAULT 1 NOT NULL, quote_number VARCHAR(80) DEFAULT NULL, quote_document VARCHAR(255) DEFAULT NULL, cost_center_type VARCHAR(20) NOT NULL, cost_center VARCHAR(120) DEFAULT NULL, requisition_id INT NOT NULL, vendor_id INT DEFAULT NULL, INDEX IDX_2BD7C214BCC3E956 (requisition_id), INDEX IDX_2BD7C214F603EE73 (vendor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_change (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, entity_id INT NOT NULL, fields JSON NOT NULL, reason LONGTEXT NOT NULL, created_at DATETIME NOT NULL, is_resolved TINYINT NOT NULL, resolved_at DATETIME DEFAULT NULL, requested_by_id INT NOT NULL, INDEX IDX_B0E3DDB3BC4BCD981257D5D (kind, entity_id), INDEX IDX_B0E3DDB4DA1E751 (requested_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_request (id INT AUTO_INCREMENT NOT NULL, description LONGTEXT NOT NULL, cost_center_type VARCHAR(20) NOT NULL, cost_center VARCHAR(120) DEFAULT NULL, quoted_price NUMERIC(12, 2) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, quote_documents JSON NOT NULL, po VARCHAR(60) DEFAULT NULL, requisition_number VARCHAR(60) DEFAULT NULL, closed TINYINT NOT NULL, completed TINYINT NOT NULL, po_assigned_at DATETIME DEFAULT NULL, next_notification_at DATETIME DEFAULT NULL, final_cost NUMERIC(14, 2) DEFAULT NULL, status VARCHAR(30) NOT NULL, requested_at DATETIME NOT NULL, approvers JSON NOT NULL, is_priority TINYINT NOT NULL, approved_at DATETIME DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, registered_at DATETIME DEFAULT NULL, registration_notes LONGTEXT DEFAULT NULL, updated_at DATETIME DEFAULT NULL, viewed_at DATETIME DEFAULT NULL, viewed_at_stage VARCHAR(20) DEFAULT NULL, vendor_id INT DEFAULT NULL, requested_by_id INT NOT NULL, approved_by_id INT DEFAULT NULL, rejected_by_id INT DEFAULT NULL, registered_by_id INT DEFAULT NULL, viewed_by_id INT DEFAULT NULL, INDEX IDX_F413DD037B00651C (status), INDEX IDX_F413DD03F603EE73 (vendor_id), INDEX IDX_F413DD034DA1E751 (requested_by_id), INDEX IDX_F413DD032D234F6A (approved_by_id), INDEX IDX_F413DD03CBF05FC9 (rejected_by_id), INDEX IDX_F413DD0327E92E18 (registered_by_id), INDEX IDX_F413DD034ED880FC (viewed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting (setting_key VARCHAR(80) NOT NULL, value JSON NOT NULL, PRIMARY KEY (setting_key)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sub_class (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, abbreviation VARCHAR(40) NOT NULL, code VARCHAR(160) NOT NULL, description VARCHAR(500) DEFAULT NULL, is_active TINYINT NOT NULL, part_class_id INT NOT NULL, INDEX IDX_10F7D267D528E6F (part_class_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sub_class_characteristic (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, abbreviation VARCHAR(40) NOT NULL, char_code VARCHAR(60) NOT NULL, sequence INT NOT NULL, description VARCHAR(255) DEFAULT NULL, is_active TINYINT NOT NULL, sub_class_id INT NOT NULL, INDEX IDX_C52944F750AFE549 (sub_class_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tree_node (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, description VARCHAR(255) DEFAULT NULL, node_type VARCHAR(30) NOT NULL, search_tag VARCHAR(80) DEFAULT NULL, is_active TINYINT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_3AFC82725E237E06 (name), INDEX IDX_3AFC8272727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vendor (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, vendor_number VARCHAR(40) DEFAULT NULL, type INT DEFAULT 1 NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(60) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, contract_number VARCHAR(80) DEFAULT NULL, incoterms VARCHAR(40) DEFAULT NULL, currency VARCHAR(10) DEFAULT NULL, purchasing_group VARCHAR(60) DEFAULT NULL, min_value NUMERIC(12, 2) NOT NULL, extra JSON NOT NULL, is_active TINYINT NOT NULL, created_from_request TINYINT NOT NULL, created_at DATETIME NOT NULL, handler_id INT DEFAULT NULL, INDEX IDX_F52233F6A6E82043 (handler_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_area ADD CONSTRAINT FK_AD1571A1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_area ADD CONSTRAINT FK_AD1571A1BD0F409C FOREIGN KEY (area_id) REFERENCES area (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_vendor ADD CONSTRAINT FK_907028CBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_vendor ADD CONSTRAINT FK_907028CBF603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE approval_lock ADD CONSTRAINT FK_535F81E17A88E00 FOREIGN KEY (locked_by_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE part ADD CONSTRAINT FK_490F70C67D528E6F FOREIGN KEY (part_class_id) REFERENCES part_class (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part ADD CONSTRAINT FK_490F70C650AFE549 FOREIGN KEY (sub_class_id) REFERENCES sub_class (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part ADD CONSTRAINT FK_490F70C6F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part ADD CONSTRAINT FK_490F70C6D48D54E8 FOREIGN KEY (last_edited_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_equipment ADD CONSTRAINT FK_1315EBAB4CE34BEC FOREIGN KEY (part_id) REFERENCES part (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE part_equipment ADD CONSTRAINT FK_1315EBAB8C8A3DCA FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB78947D528E6F FOREIGN KEY (part_class_id) REFERENCES part_class (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB789450AFE549 FOREIGN KEY (sub_class_id) REFERENCES sub_class (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB7894F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB7894878BF287 FOREIGN KEY (target_part_id) REFERENCES part (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB7894898D4F41 FOREIGN KEY (also_replaces_part_id) REFERENCES part (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB789471AB4EA0 FOREIGN KEY (resulting_part_id) REFERENCES part (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB78944DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB78942D234F6A FOREIGN KEY (approved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB7894CBF05FC9 FOREIGN KEY (rejected_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB789427E92E18 FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request ADD CONSTRAINT FK_BBCB78944ED880FC FOREIGN KEY (viewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE part_request_equipment ADD CONSTRAINT FK_18217DFB770B3D61 FOREIGN KEY (part_request_id) REFERENCES part_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE part_request_equipment ADD CONSTRAINT FK_18217DFB8C8A3DCA FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE parts_requisition ADD CONSTRAINT FK_50DC52DE4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE parts_requisition ADD CONSTRAINT FK_50DC52DE2D234F6A FOREIGN KEY (approved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE parts_requisition ADD CONSTRAINT FK_50DC52DECBF05FC9 FOREIGN KEY (rejected_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE parts_requisition ADD CONSTRAINT FK_50DC52DE27E92E18 FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE parts_requisition ADD CONSTRAINT FK_50DC52DE4ED880FC FOREIGN KEY (viewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE parts_requisition_item ADD CONSTRAINT FK_2BD7C214BCC3E956 FOREIGN KEY (requisition_id) REFERENCES parts_requisition (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE parts_requisition_item ADD CONSTRAINT FK_2BD7C214F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE request_change ADD CONSTRAINT FK_B0E3DDB4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD03F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD034DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD032D234F6A FOREIGN KEY (approved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD03CBF05FC9 FOREIGN KEY (rejected_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD0327E92E18 FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_request ADD CONSTRAINT FK_F413DD034ED880FC FOREIGN KEY (viewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sub_class ADD CONSTRAINT FK_10F7D267D528E6F FOREIGN KEY (part_class_id) REFERENCES part_class (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sub_class_characteristic ADD CONSTRAINT FK_C52944F750AFE549 FOREIGN KEY (sub_class_id) REFERENCES sub_class (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tree_node ADD CONSTRAINT FK_3AFC8272727ACA70 FOREIGN KEY (parent_id) REFERENCES tree_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vendor ADD CONSTRAINT FK_F52233F6A6E82043 FOREIGN KEY (handler_id) REFERENCES vendor (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_log DROP FOREIGN KEY FK_FD06F647A76ED395');
        $this->addSql('ALTER TABLE user_area DROP FOREIGN KEY FK_AD1571A1A76ED395');
        $this->addSql('ALTER TABLE user_area DROP FOREIGN KEY FK_AD1571A1BD0F409C');
        $this->addSql('ALTER TABLE user_vendor DROP FOREIGN KEY FK_907028CBA76ED395');
        $this->addSql('ALTER TABLE user_vendor DROP FOREIGN KEY FK_907028CBF603EE73');
        $this->addSql('ALTER TABLE approval_lock DROP FOREIGN KEY FK_535F81E17A88E00');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE part DROP FOREIGN KEY FK_490F70C67D528E6F');
        $this->addSql('ALTER TABLE part DROP FOREIGN KEY FK_490F70C650AFE549');
        $this->addSql('ALTER TABLE part DROP FOREIGN KEY FK_490F70C6F603EE73');
        $this->addSql('ALTER TABLE part DROP FOREIGN KEY FK_490F70C6D48D54E8');
        $this->addSql('ALTER TABLE part_equipment DROP FOREIGN KEY FK_1315EBAB4CE34BEC');
        $this->addSql('ALTER TABLE part_equipment DROP FOREIGN KEY FK_1315EBAB8C8A3DCA');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB78947D528E6F');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB789450AFE549');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB7894F603EE73');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB7894878BF287');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB7894898D4F41');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB789471AB4EA0');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB78944DA1E751');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB78942D234F6A');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB7894CBF05FC9');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB789427E92E18');
        $this->addSql('ALTER TABLE part_request DROP FOREIGN KEY FK_BBCB78944ED880FC');
        $this->addSql('ALTER TABLE part_request_equipment DROP FOREIGN KEY FK_18217DFB770B3D61');
        $this->addSql('ALTER TABLE part_request_equipment DROP FOREIGN KEY FK_18217DFB8C8A3DCA');
        $this->addSql('ALTER TABLE parts_requisition DROP FOREIGN KEY FK_50DC52DE4DA1E751');
        $this->addSql('ALTER TABLE parts_requisition DROP FOREIGN KEY FK_50DC52DE2D234F6A');
        $this->addSql('ALTER TABLE parts_requisition DROP FOREIGN KEY FK_50DC52DECBF05FC9');
        $this->addSql('ALTER TABLE parts_requisition DROP FOREIGN KEY FK_50DC52DE27E92E18');
        $this->addSql('ALTER TABLE parts_requisition DROP FOREIGN KEY FK_50DC52DE4ED880FC');
        $this->addSql('ALTER TABLE parts_requisition_item DROP FOREIGN KEY FK_2BD7C214BCC3E956');
        $this->addSql('ALTER TABLE parts_requisition_item DROP FOREIGN KEY FK_2BD7C214F603EE73');
        $this->addSql('ALTER TABLE request_change DROP FOREIGN KEY FK_B0E3DDB4DA1E751');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD03F603EE73');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD034DA1E751');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD032D234F6A');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD03CBF05FC9');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD0327E92E18');
        $this->addSql('ALTER TABLE service_request DROP FOREIGN KEY FK_F413DD034ED880FC');
        $this->addSql('ALTER TABLE sub_class DROP FOREIGN KEY FK_10F7D267D528E6F');
        $this->addSql('ALTER TABLE sub_class_characteristic DROP FOREIGN KEY FK_C52944F750AFE549');
        $this->addSql('ALTER TABLE tree_node DROP FOREIGN KEY FK_3AFC8272727ACA70');
        $this->addSql('ALTER TABLE vendor DROP FOREIGN KEY FK_F52233F6A6E82043');
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE user_area');
        $this->addSql('DROP TABLE user_vendor');
        $this->addSql('DROP TABLE approval_lock');
        $this->addSql('DROP TABLE area');
        $this->addSql('DROP TABLE cost_center');
        $this->addSql('DROP TABLE nomenclator');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE part');
        $this->addSql('DROP TABLE part_equipment');
        $this->addSql('DROP TABLE part_class');
        $this->addSql('DROP TABLE part_request');
        $this->addSql('DROP TABLE part_request_equipment');
        $this->addSql('DROP TABLE parts_requisition');
        $this->addSql('DROP TABLE parts_requisition_item');
        $this->addSql('DROP TABLE request_change');
        $this->addSql('DROP TABLE service_request');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE sub_class');
        $this->addSql('DROP TABLE sub_class_characteristic');
        $this->addSql('DROP TABLE tree_node');
        $this->addSql('DROP TABLE vendor');
    }
}
