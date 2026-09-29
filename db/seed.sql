-- =====================================================================
-- Die Design Checklist Approval System
-- Sample seed data
-- Import AFTER schema.sql
-- =====================================================================

USE checklist_design;

-- ---------------------------------------------------------------------
-- Admin user
-- username: admin / password: admin123
-- Hash generated with PHP password_hash() (PASSWORD_DEFAULT / bcrypt)
-- ---------------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES
('admin', '$2y$10$SLdr8fXJqtq1G.I0FVkXXeD.oIlkcNe.oSIir/ZsxJTaYxYW80COO', 'System Administrator', 'admin', 1);

-- ---------------------------------------------------------------------
-- Sample master data
-- ---------------------------------------------------------------------
INSERT INTO customers (name) VALUES
('Alumet Group'),
('Sample Customer Co., Ltd.');

INSERT INTO die_models (name) VALUES
('AL-6063'),
('AL-6061');

INSERT INTO materials (name) VALUES
('3003'),
('6005'),
('6061'),
('6063'),
('6082');

-- ---------------------------------------------------------------------
-- Checklist items: Solid Die (14 items, flat list, no sub-categories)
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
('solid', 'Result FEM', 'ว่า Simulation ผ่านไหม', 1, 14);

-- ---------------------------------------------------------------------
-- Checklist items: Hollow Die (18 items, flat list, no sub-categories)
-- ---------------------------------------------------------------------
INSERT INTO checklist_items (die_type, topic, note, is_active, sort_order) VALUES
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
