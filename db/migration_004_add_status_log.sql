-- =====================================================================
-- Migration 004: record before/after status on every checklist_history row
-- (who changed it = action_by, when = created_at, already existed)
-- =====================================================================

ALTER TABLE checklist_history
    ADD COLUMN old_status ENUM('draft', 'pending', 'approved', 'rejected') NULL AFTER note,
    ADD COLUMN new_status ENUM('draft', 'pending', 'approved', 'rejected') NULL AFTER old_status;
