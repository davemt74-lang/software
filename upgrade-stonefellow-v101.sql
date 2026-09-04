-- Stonefellow v101 — shared Stem REGION notes in Agent Chat
ALTER TABLE track_notes
  ADD COLUMN region_start_seconds DECIMAL(12,4) NULL AFTER note,
  ADD COLUMN region_end_seconds DECIMAL(12,4) NULL AFTER region_start_seconds,
  ADD INDEX idx_track_notes_region (track_id,region_start_seconds,created_at);
