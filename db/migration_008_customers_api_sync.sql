-- migration_008: เตรียมตาราง customers สำหรับ sync จาก Customer API (SAP)
-- CardCode -> card_code, LicTradNum -> tax_id, MailAddres -> address
-- แถวที่ admin เพิ่มเองเดิม จะมี card_code = NULL (ไม่ถูกแตะต้อง จนกว่าจะ match ชื่อตรงกันตอน sync ครั้งแรก)

ALTER TABLE customers
    ADD COLUMN card_code VARCHAR(30)  NULL AFTER id,
    ADD COLUMN tax_id    VARCHAR(20)  NULL AFTER name,
    ADD COLUMN address   VARCHAR(255) NULL AFTER tax_id,
    ADD COLUMN synced_at DATETIME     NULL,
    ADD UNIQUE KEY uq_customers_card_code (card_code);
