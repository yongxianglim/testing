-- Migration: Two-Table Record System
-- Run once against the subjective_portal database

-- ── testing_record: add table_number and edited_by ────────────
ALTER TABLE `testing_record`
  ADD COLUMN `table_number` tinyint(1) NOT NULL DEFAULT 1 AFTER `row_number`,
  ADD COLUMN `edited_by` varchar(100) DEFAULT NULL AFTER `table_number`;

-- Set all existing rows to table_number = 1
UPDATE `testing_record` SET `table_number` = 1;

-- Drop old unique key and create new one including table_number
ALTER TABLE `testing_record`
  DROP KEY `uq_testing_field_row`,
  ADD UNIQUE KEY `uq_testing_field_row` (`testing_id`, `field_key`, `row_number`, `table_number`);

-- ── testing: add created_by, checked_by_engineer_t1, checked_by_engineer_t2 ───
ALTER TABLE `testing`
  ADD COLUMN `created_by` varchar(100) DEFAULT NULL AFTER `testing_method`,
  ADD COLUMN `checked_by_engineer_t1` tinyint(1) NOT NULL DEFAULT 0 AFTER `created_by`,
  ADD COLUMN `checked_by_engineer_t2` tinyint(1) NOT NULL DEFAULT 0 AFTER `checked_by_engineer_t1`;

-- Migrate existing checked_by_engineer value into checked_by_engineer_t1
UPDATE `testing` SET `checked_by_engineer_t1` = `checked_by_engineer`;

-- Drop old checked_by_engineer column
ALTER TABLE `testing` DROP COLUMN `checked_by_engineer`;
