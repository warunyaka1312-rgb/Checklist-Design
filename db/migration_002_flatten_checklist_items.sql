-- =====================================================================
-- Die Design Checklist Approval System
-- Migration 002: flatten checklist_items (drop the checklist_categories
-- grouping layer) and add custom-item support to checklist_results.
-- Run ONCE against an existing installation, after schema.sql/seed.sql
-- were already applied. A fresh install should use the updated
-- schema.sql + seed.sql instead and skip this file.
-- =====================================================================

USE checklist_design;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. checklist_items: replace (category_id, name_en, name_th, description)
--    with a flat (die_type, topic, note) shape.
-- ---------------------------------------------------------------------
ALTER TABLE checklist_items
    DROP FOREIGN KEY fk_items_category;

ALTER TABLE checklist_items
    ADD COLUMN die_type ENUM('solid', 'hollow') NULL AFTER id,
    ADD COLUMN topic    VARCHAR(255) NULL AFTER die_type,
    ADD COLUMN note     TEXT NULL AFTER topic;

-- Backfill die_type/topic/note on existing rows purely so the columns are
-- non-null before the old sample data gets deactivated below.
UPDATE checklist_items ci
    JOIN checklist_categories cc ON cc.id = ci.category_id
    SET ci.die_type = cc.die_type,
        ci.topic = CONCAT(ci.name_en, ' / ', ci.name_th),
        ci.note = ci.description;

ALTER TABLE checklist_items
    MODIFY COLUMN die_type ENUM('solid', 'hollow') NOT NULL,
    MODIFY COLUMN topic VARCHAR(255) NOT NULL,
    DROP COLUMN category_id,
    DROP COLUMN name_en,
    DROP COLUMN name_th,
    DROP COLUMN description,
    ADD KEY idx_items_die_type (die_type);

DROP TABLE checklist_categories;

-- Old sample items are never hard-deleted (a pre-existing checklist could
-- still reference one via checklist_selected_items / checklist_results) —
-- just deactivate them, then insert the real master list below as active.
UPDATE checklist_items SET is_active = 0;

-- ---------------------------------------------------------------------
-- 2. checklist_results: allow a result row that isn't tied to a master
--    item (a checklist-specific custom item, not saved back to master).
-- ---------------------------------------------------------------------
ALTER TABLE checklist_results
    MODIFY COLUMN item_id INT UNSIGNED NULL,
    MODIFY COLUMN result ENUM('pass', 'fail') NULL,
    ADD COLUMN is_custom_item TINYINT(1) NOT NULL DEFAULT 0 AFTER item_id,
    ADD COLUMN custom_topic VARCHAR(255) NULL AFTER image_source,
    ADD COLUMN custom_note TEXT NULL AFTER custom_topic;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 3. Real master checklist items — Solid Die (14) and Hollow Die (18).
-- ---------------------------------------------------------------------
INSERT INTO checklist_items (die_type, topic, note, is_active, sort_order) VALUES
('solid', 'การวางตำแหน่งโปรไฟล์', 'เพื่อดูการวางเหมาะสมไหม', 1, 1),
('solid', 'Pocket, Port', 'billet skin เหมาะสมไหม เกิน billet ของการออกแบบไหม', 1, 2),
('solid', 'Point Wirecut', 'ตำแหน่งการเจาะแม่พิมพ์', 1, 3),
('solid', 'การขยาย % Profile', 'ขยายให้เหมาะสมกัน ค่า +/-', 1, 4),
('solid', 'รีลีฟแม่พิมพ์ + Backer', 'พื้นที่รับแรงเหมาะสมไหม กันหัก', 1, 5),
('solid', 'Bolster ที่ใช้', 'เหมาะสมไหม พัน profile ไหม', 1, 6),
('solid', 'T-Slot', 'กัน Billet หลุด', 1, 7),
('solid', 'แบริ่ง', 'ให้เหมาะสมกับความหนา Profile', 1, 8),
('solid', 'การให้ตำแหน่งตรวจวัด', 'การจับขนาดให้ถูกต้อง', 1, 9),
('solid', 'From Design', 'รายละเอียด Section, น้ำหนัก, part', 1, 10),
('solid', 'Die Set', 'ถ้าเป็นกลุ่มงาน FP+DP ให้ทำนำ', 1, 11),
('solid', 'R ดอกในการ Machine', 'ให้สามารถผลิตงานได้จริง', 1, 12),
('solid', 'ตำแหน่งการวาง Nut-bolt', 'ต้องวางให้เหมาะสมไม่กินเนื้อ Port', 1, 13),
('solid', 'Result FEM', 'ว่า Simulation ผ่านไหม', 1, 14),

('hollow', 'การวางตำแหน่งโปรไฟล์', 'เพื่อดูการวางเหมาะสมไหม', 1, 1),
('hollow', 'Pocket, Port', 'billet skin เหมาะสมไหม เกิน billet ของการออกแบบไหม', 1, 2),
('hollow', 'Point Wirecut', 'ตำแหน่งการเจาะแม่พิมพ์', 1, 3),
('hollow', 'การขยาย % Profile', 'ขยายให้เหมาะสมกัน ค่า +/-', 1, 4),
('hollow', 'รีลีฟแม่พิมพ์ + Backer', 'พื้นที่รับแรงเหมาะสมไหม กันหัก', 1, 5),
('hollow', 'Bolster ที่ใช้', 'เหมาะสมไหม พัน profile ไหม', 1, 6),
('hollow', 'Under Cut', 'ชดเชยในขณะรีดงาน ให้ MD เสนอ DC ให้ Flow', 1, 7),
('hollow', 'Under Cut รูสกรู', 'ลดปัญหารอยเวล', 1, 8),
('hollow', 'Under Cut รูสกรู ด้านบน', 'เพิ่มความแข็งแรงบริเวณรูสกรู', 1, 9),
('hollow', 'แบริ่ง', 'ให้เหมาะสมกับความหนา Profile', 1, 10),
('hollow', 'การให้ตำแหน่งตรวจวัด', 'การจับขนาดให้ถูกต้อง', 1, 11),
('hollow', 'From Design', 'รายละเอียด Section, น้ำหนัก, part', 1, 12),
('hollow', 'web', 'ขาบริเวณ MD พื้นที่รับแรง', 1, 13),
('hollow', 'Detail คอ MD', 'เพิ่มความแข็งแรง', 1, 14),
('hollow', 'ทางไหลรูสกรู', 'การให้ขนาดทางไหล', 1, 15),
('hollow', 'ทางไหล MD', 'detail ให้ถูกต้อง', 1, 16),
('hollow', 'ตำแหน่งการวาง Nut-bolt', 'ต้องวางให้เหมาะสมไม่กินเนื้อ Port', 1, 17),
('hollow', 'Result FEM', 'ว่า Simulation ผ่านไหม', 1, 18);
