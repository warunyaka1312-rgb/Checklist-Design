-- =====================================================================
-- Die Design Checklist Approval System
-- Migration 005: promote "material" from a fixed ENUM on checklists to a
-- proper master-data table (materials), matching customers/die_models —
-- manageable from Admin > Master Data instead of being a hardcoded list.
-- Run ONCE against an existing installation, after schema.sql/seed.sql
-- and prior migrations were already applied. A fresh install should use
-- the updated schema.sql/seed.sql instead and skip this file.
-- =====================================================================

USE checklist_design;

CREATE TABLE IF NOT EXISTS materials (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_materials_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the previously-hardcoded alloy list so existing checklists have
-- something to map onto below.
INSERT IGNORE INTO materials (name) VALUES
('3003'), ('6005'), ('6061'), ('6063'), ('6082');

ALTER TABLE checklists ADD COLUMN material_id INT UNSIGNED NULL AFTER material;

UPDATE checklists c
JOIN materials m ON m.name = c.material
SET c.material_id = m.id;

-- Every existing row's old `material` enum value has a same-named row in
-- `materials` from the INSERT IGNORE above, so this is safe.
ALTER TABLE checklists MODIFY COLUMN material_id INT UNSIGNED NOT NULL;

ALTER TABLE checklists
    ADD KEY idx_checklists_material (material_id),
    ADD CONSTRAINT fk_checklists_material FOREIGN KEY (material_id)
        REFERENCES materials (id) ON DELETE RESTRICT;

ALTER TABLE checklists DROP COLUMN material;
