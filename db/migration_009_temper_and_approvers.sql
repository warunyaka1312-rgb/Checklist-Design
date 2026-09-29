-- =====================================================================
-- Die Design Checklist Approval System
-- Migration 009:
--   1) "tempers" master table + checklists.temper_id — the alloy temper
--      (T1 / T5 / T6 / ...) that SAP returns alongside the material for a
--      Tech drawing. A table rather than an ENUM so new tempers can be
--      added later without a schema change; the SAP techdrawing-active
--      feed alone already carries O, T4, T64 and H112 besides T1/T5/T6.
--   2) "approvers" — which users may be picked as the approving manager on
--      a checklist, manageable from Admin > Master Data. It only curates
--      that dropdown: approving still requires role = 'manager', and rows
--      here are keyed to users so login and notifications keep working.
--
-- Run ONCE against an existing installation, after prior migrations.
-- A fresh install should use the updated schema.sql/seed.sql instead.
-- =====================================================================

USE checklist_design;

-- ---------------------------------------------------------------------
-- 1) tempers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tempers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tempers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The three the engineers asked for. Anything else SAP returns is created
-- on demand when a Tech drawing is fetched, so this list stays short and
-- only grows with tempers actually used.
INSERT IGNORE INTO tempers (name) VALUES ('T1'), ('T5'), ('T6');

ALTER TABLE checklists ADD COLUMN temper_id INT UNSIGNED NULL AFTER material_id;

-- Existing checklists predate the field, so default them to T5 — by far the
-- most common temper in the SAP feed (4240 of 4341 drawings).
UPDATE checklists c
JOIN tempers t ON t.name = 'T5'
SET c.temper_id = t.id
WHERE c.temper_id IS NULL;

-- Left NULLable: a checklist may legitimately have no temper recorded.
ALTER TABLE checklists
    ADD KEY idx_checklists_temper (temper_id),
    ADD CONSTRAINT fk_checklists_temper FOREIGN KEY (temper_id)
        REFERENCES tempers (id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 2) approvers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approvers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_approvers_user (user_id),
    CONSTRAINT fk_approvers_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed with every active manager, so the checklist dropdown shows exactly
-- what it showed before this migration and nothing changes until an admin
-- actually curates the list.
INSERT IGNORE INTO approvers (user_id, is_active)
SELECT id, 1 FROM users WHERE role = 'manager' AND is_active = 1;
