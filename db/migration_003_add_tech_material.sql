-- =====================================================================
-- Die Design Checklist Approval System
-- Migration 003: add "tech" (free-text code, fetch-or-manual) and
-- "material" (fixed alloy list) to checklists.
-- Run ONCE against an existing installation, after schema.sql/seed.sql
-- (and migration_002, if applicable) were already applied. A fresh
-- install should use the updated schema.sql instead and skip this file.
-- =====================================================================

USE checklist_design;

ALTER TABLE checklists
    ADD COLUMN tech VARCHAR(100) NULL AFTER die_no,
    ADD COLUMN material ENUM('3003', '6005', '6061', '6063', '6082') NOT NULL DEFAULT '6063' AFTER die_type;
