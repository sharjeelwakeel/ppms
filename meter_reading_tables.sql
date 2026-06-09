-- ============================================================
-- PPMS - Meter Reading Tables
-- Run this in phpMyAdmin or your MySQL client
-- ============================================================

-- ── Step 1: Drop staff_id from the header table (if it exists) ──────────
ALTER TABLE `tbl_meter_readings` DROP COLUMN IF EXISTS `staff_id`;

-- ── Step 2: Header table (one record per reading session) ────────────────
--   staff_id is NOT here — it lives per-nozzle in tbl_meter_reading_details
CREATE TABLE IF NOT EXISTS `tbl_meter_readings` (
  `id`           INT(11)        NOT NULL AUTO_INCREMENT,
  `date`         DATE           NOT NULL,
  `shift_id`     INT(11)        NOT NULL DEFAULT 0,
  `payment_type` VARCHAR(32)    NOT NULL DEFAULT 'Cash',
  `grand_total`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Step 3: Detail table (one record per nozzle per session) ─────────────
--   staff_id here = Sales Executive assigned to this specific nozzle
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_details` (
  `id`                INT(11)        NOT NULL AUTO_INCREMENT,
  `meter_reading_id`  INT(11)        NOT NULL,
  `nozzle_id`         INT(11)        NOT NULL,
  `staff_id`          INT(11)        NOT NULL DEFAULT 0,   -- Sales Executive per nozzle
  `item_type`         VARCHAR(128)   NOT NULL DEFAULT '',
  `price`             DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `last_reading`      DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `current_reading`   DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `sale_reading`      DECIMAL(12,2)  NOT NULL DEFAULT 0.00,  -- current - last
  `test_reading`      DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `net_sale`          DECIMAL(12,2)  NOT NULL DEFAULT 0.00,  -- sale - test
  `amount`            DECIMAL(12,2)  NOT NULL DEFAULT 0.00,  -- net_sale * price
  `payment_type`      VARCHAR(32)    NOT NULL DEFAULT 'Cash',
  `created_at`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `meter_reading_id` (`meter_reading_id`),
  KEY `nozzle_id`        (`nozzle_id`),
  KEY `staff_id`         (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
