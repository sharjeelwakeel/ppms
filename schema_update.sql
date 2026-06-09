-- ============================================================
-- PPMS - Staff Attendance and Leave Setup Tables
-- Run this in phpMyAdmin or your MySQL client
-- ============================================================

-- ── Table 1: Leave Setup (Allowed leaves per month per employee) ──────
CREATE TABLE IF NOT EXISTS `tbl_leave_setup` (
  `id`             INT(11)        NOT NULL AUTO_INCREMENT,
  `staff_id`       INT(11)        NOT NULL,
  `allowed_leaves` INT(11)        NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_leave` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Table 2: Staff Attendance (Daily attendance log) ─────────────────
CREATE TABLE IF NOT EXISTS `tbl_staff_attendance` (
  `id`             INT(11)        NOT NULL AUTO_INCREMENT,
  `staff_id`       INT(11)        NOT NULL,
  `date`           DATE           NOT NULL,
  `status`         ENUM('Present', 'Absent', 'Late', 'Leave') NOT NULL,
  `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_date` (`staff_id`, `date`),
  KEY `staff_id_idx` (`staff_id`),
  KEY `date_idx` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
