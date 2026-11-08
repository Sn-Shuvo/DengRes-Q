-- ============================================================
-- DengResQ | Password Reset Feature — DB Migration
-- Run this in phpMyAdmin or via MySQL CLI against dengue_db
-- ============================================================

-- 1. Token table (all roles share this)
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `token`      varchar(64)  NOT NULL,
  `role`       enum('citizen','hospital','institution','ho') NOT NULL,
  `identifier` varchar(100) NOT NULL COMMENT 'bcn / h_system_id / institution_id / badge_number',
  `expires_at` datetime     NOT NULL,
  `used`       tinyint(1)   NOT NULL DEFAULT 0,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token_role` (`token`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Add email column to citizens (if not already present)
--    Citizens register without email — this adds the column for future use.
ALTER TABLE `citizens`
  ADD COLUMN IF NOT EXISTS `email` varchar(100) DEFAULT NULL AFTER `phone`;

ALTER TABLE `citizens`
  ADD UNIQUE KEY IF NOT EXISTS `citizen_email` (`email`);

-- 3. Add email column to health_officers (for future HO password reset via email)
ALTER TABLE `health_officers`
  ADD COLUMN IF NOT EXISTS `email` varchar(100) DEFAULT NULL AFTER `pin`;
