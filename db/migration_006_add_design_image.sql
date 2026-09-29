-- =====================================================================
-- Migration 006: manual "design drawing" image on a checklist.
-- The image shown in the Design Drawing section can come from the
-- (not-yet-connected) drawing API, or be uploaded manually by the
-- engineer in the meantime — this column stores the manually uploaded one.
-- =====================================================================

ALTER TABLE checklists
    ADD COLUMN design_image_path VARCHAR(255) NULL AFTER design_pdf_path;
