-- =====================================================================
-- Migration 007: keep the note/comment on a checklist_results row as two
-- boxes instead of one — "ก่อนเปลี่ยน" (the result + comment right before
-- the last Pass<->Fail flip) and "หลังเปลี่ยน" (the current result +
-- comment, i.e. the existing result/comment columns). Populated by
-- api/checklist_api.php whenever a save flips an item's result; carried
-- forward unchanged on saves that don't touch that item again.
-- =====================================================================

ALTER TABLE checklist_results
    ADD COLUMN prev_result ENUM('pass', 'fail') NULL AFTER comment,
    ADD COLUMN note_before TEXT NULL AFTER prev_result;
