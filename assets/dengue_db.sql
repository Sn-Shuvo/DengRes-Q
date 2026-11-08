-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 29, 2026 at 04:34 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dengue_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `alert_condition_registry`
--

DROP TABLE IF EXISTS `alert_condition_registry`;
CREATE TABLE `alert_condition_registry` (
  `id` int(11) NOT NULL,
  `condition_type` enum('Hospital','Suspect','Breeding','Weather') NOT NULL,
  `min_threshold` int(11) NOT NULL,
  `title_template` varchar(255) NOT NULL,
  `msg_template` text NOT NULL,
  `severity_level` enum('Critical','High','Medium','Low') NOT NULL,
  `ui_color_code` varchar(7) DEFAULT '#D32F2F'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `breeding_sites`
--

DROP TABLE IF EXISTS `breeding_sites`;
CREATE TABLE `breeding_sites` (
  `site_id` int(11) NOT NULL,
  `reporter_bcn` varchar(17) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `evidence_img` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `officer_id` varchar(255) DEFAULT NULL,
  `status` enum('Active','Cleared') DEFAULT 'Active',
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `citizens`
--

DROP TABLE IF EXISTS `citizens`;
CREATE TABLE `citizens` (
  `bcn` varchar(17) NOT NULL,
  `age` int(11) NOT NULL,
  `blood_group` varchar(5) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_label` text DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `citizens`
--

INSERT INTO `citizens` (`bcn`, `age`, `blood_group`, `latitude`, `longitude`, `location_label`, `password`, `created_at`) VALUES
('15845054678399230', 23, 'A+', 23.78486700, 90.35896900, 'Sumon Pharmacy, ৪ নং বস্তি রোড, Kallyanpur Slum, South Paikpara, Kallyanpur, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1916, Bangladesh', '$2y$10$huvNnVnGEDgdyG1LB.jhh.rD2./vWcP1yuJOvr2PIvwqOitVzrHEm', '2026-04-26 04:02:15'),
('20012692531105421', 25, 'A+', 23.76549800, 90.44503400, 'North South Road, Jahurul Islam City, Angarjora, Aftabnagar, Badda, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1214, Bangladesh', '$2y$10$wugPVwwo8WpfLfd4u6rZO.0BL73lGjsEX.DS7/1gdotwZyAFGlSve', '2026-04-26 03:58:45'),
('78546735469866543', 20, 'AB+', 23.81309300, 90.41343200, '382, Lane 6 East, Baridhara DOHS, Joar Sahara, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1229, Bangladesh', '$2y$10$xtm7uOeao0duiZd2mquYhu1UXZOcDqKdcYFIsUGJgNWvJELXwa4Vm', '2026-04-26 05:13:27');

-- --------------------------------------------------------

--
-- Table structure for table `citizen_platforms`
--

DROP TABLE IF EXISTS `citizen_platforms`;
CREATE TABLE `citizen_platforms` (
  `id` int(11) NOT NULL,
  `citizen_bcn` varchar(17) NOT NULL,
  `platform_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `citizen_platforms`
--

INSERT INTO `citizen_platforms` (`id`, `citizen_bcn`, `platform_name`) VALUES
(3, '20012692531105421', 'bKash'),
(4, '20012692531105421', 'Nagad'),
(5, '15845054678399230', 'bKash'),
(6, '78546735469866543', 'bKash');

-- --------------------------------------------------------

--
-- Table structure for table `community_feed`
--

DROP TABLE IF EXISTS `community_feed`;
CREATE TABLE `community_feed` (
  `feed_id` int(11) NOT NULL,
  `event_type` enum('breeding','management','hospital','risk') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_officers`
--

DROP TABLE IF EXISTS `health_officers`;
CREATE TABLE `health_officers` (
  `badge_number` varchar(20) NOT NULL,
  `division` varchar(50) NOT NULL,
  `pin` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_tips`
--

DROP TABLE IF EXISTS `health_tips`;
CREATE TABLE `health_tips` (
  `tip_id` int(11) NOT NULL,
  `alert_logic_id` int(11) NOT NULL,
  `tip_text` text NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `priority_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_tips_cache`
--

DROP TABLE IF EXISTS `health_tips_cache`;
CREATE TABLE `health_tips_cache` (
  `cache_id` int(11) NOT NULL,
  `live_alert_id` int(11) NOT NULL,
  `tip_text` text NOT NULL,
  `action_status` enum('Pending','Done') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hospitals`
--

DROP TABLE IF EXISTS `hospitals`;
CREATE TABLE `hospitals` (
  `h_system_id` varchar(20) NOT NULL,
  `h_name` varchar(255) NOT NULL,
  `license_no` varchar(100) NOT NULL,
  `total_beds` int(11) DEFAULT 0,
  `facility_type` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hospital_reports`
--

DROP TABLE IF EXISTS `hospital_reports`;
CREATE TABLE `hospital_reports` (
  `report_id` int(11) NOT NULL,
  `patient_bcn` varchar(17) NOT NULL,
  `patient_status` enum('Stable','Critical','Recovered','Death') NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_alerts_cache`
--

DROP TABLE IF EXISTS `live_alerts_cache`;
CREATE TABLE `live_alerts_cache` (
  `live_id` int(11) NOT NULL,
  `sub_id` int(11) NOT NULL,
  `logic_id` int(11) NOT NULL,
  `final_title` varchar(255) NOT NULL,
  `final_body` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `regional_sectors`
--

DROP TABLE IF EXISTS `regional_sectors`;
CREATE TABLE `regional_sectors` (
  `region_id` int(11) NOT NULL,
  `region_name` varchar(100) NOT NULL,
  `assigned_ho_id` varchar(50) DEFAULT NULL,
  `center_lat` decimal(10,8) NOT NULL,
  `center_long` decimal(11,8) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_sectors`
--

DROP TABLE IF EXISTS `sub_sectors`;
CREATE TABLE `sub_sectors` (
  `sub_id` int(11) NOT NULL,
  `parent_region_id` int(11) NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lon` decimal(11,8) NOT NULL,
  `risk_level` enum('Low','Medium','High') DEFAULT 'Low',
  `weather_state` enum('Dry','Rainy','Hot') DEFAULT 'Dry',
  `last_audit` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `symptom_checks`
--

DROP TABLE IF EXISTS `symptom_checks`;
CREATE TABLE `symptom_checks` (
  `check_id` int(11) NOT NULL,
  `reporter_bcn` varchar(17) NOT NULL,
  `suspect_bcn` varchar(17) NOT NULL,
  `is_suspect` tinyint(1) DEFAULT 1,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `test_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alert_condition_registry`
--
ALTER TABLE `alert_condition_registry`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `breeding_sites`
--
ALTER TABLE `breeding_sites`
  ADD PRIMARY KEY (`site_id`),
  ADD KEY `fk_reporter` (`reporter_bcn`),
  ADD KEY `fk_officer` (`officer_id`);

--
-- Indexes for table `citizens`
--
ALTER TABLE `citizens`
  ADD PRIMARY KEY (`bcn`);

--
-- Indexes for table `citizen_platforms`
--
ALTER TABLE `citizen_platforms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `citizen_bcn` (`citizen_bcn`);

--
-- Indexes for table `community_feed`
--
ALTER TABLE `community_feed`
  ADD PRIMARY KEY (`feed_id`),
  ADD KEY `idx_location` (`latitude`,`longitude`),
  ADD KEY `idx_event` (`event_type`);

--
-- Indexes for table `health_officers`
--
ALTER TABLE `health_officers`
  ADD PRIMARY KEY (`badge_number`);

--
-- Indexes for table `health_tips`
--
ALTER TABLE `health_tips`
  ADD PRIMARY KEY (`tip_id`),
  ADD KEY `fk_alert_logic` (`alert_logic_id`);

--
-- Indexes for table `health_tips_cache`
--
ALTER TABLE `health_tips_cache`
  ADD PRIMARY KEY (`cache_id`),
  ADD KEY `fk_live_alert` (`live_alert_id`);

--
-- Indexes for table `hospitals`
--
ALTER TABLE `hospitals`
  ADD PRIMARY KEY (`h_system_id`),
  ADD UNIQUE KEY `license_no` (`license_no`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `hospital_reports`
--
ALTER TABLE `hospital_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_patient` (`patient_bcn`);

--
-- Indexes for table `live_alerts_cache`
--
ALTER TABLE `live_alerts_cache`
  ADD PRIMARY KEY (`live_id`),
  ADD KEY `fk_cache_sub` (`sub_id`),
  ADD KEY `fk_cache_logic` (`logic_id`);

--
-- Indexes for table `regional_sectors`
--
ALTER TABLE `regional_sectors`
  ADD PRIMARY KEY (`region_id`),
  ADD KEY `fk_assigned_ho` (`assigned_ho_id`);

--
-- Indexes for table `sub_sectors`
--
ALTER TABLE `sub_sectors`
  ADD PRIMARY KEY (`sub_id`),
  ADD KEY `fk_parent_region` (`parent_region_id`);

--
-- Indexes for table `symptom_checks`
--
ALTER TABLE `symptom_checks`
  ADD PRIMARY KEY (`check_id`),
  ADD KEY `fk_symptom_reporter` (`reporter_bcn`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alert_condition_registry`
--
ALTER TABLE `alert_condition_registry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `breeding_sites`
--
ALTER TABLE `breeding_sites`
  MODIFY `site_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `citizen_platforms`
--
ALTER TABLE `citizen_platforms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `community_feed`
--
ALTER TABLE `community_feed`
  MODIFY `feed_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `health_tips`
--
ALTER TABLE `health_tips`
  MODIFY `tip_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `health_tips_cache`
--
ALTER TABLE `health_tips_cache`
  MODIFY `cache_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hospital_reports`
--
ALTER TABLE `hospital_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_alerts_cache`
--
ALTER TABLE `live_alerts_cache`
  MODIFY `live_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regional_sectors`
--
ALTER TABLE `regional_sectors`
  MODIFY `region_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_sectors`
--
ALTER TABLE `sub_sectors`
  MODIFY `sub_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `symptom_checks`
--
ALTER TABLE `symptom_checks`
  MODIFY `check_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `breeding_sites`
--
ALTER TABLE `breeding_sites`
  ADD CONSTRAINT `fk_officer` FOREIGN KEY (`officer_id`) REFERENCES `health_officers` (`badge_number`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reporter` FOREIGN KEY (`reporter_bcn`) REFERENCES `citizens` (`bcn`) ON DELETE CASCADE;

--
-- Constraints for table `citizen_platforms`
--
ALTER TABLE `citizen_platforms`
  ADD CONSTRAINT `citizen_platforms_ibfk_1` FOREIGN KEY (`citizen_bcn`) REFERENCES `citizens` (`bcn`) ON DELETE CASCADE;

--
-- Constraints for table `health_tips`
--
ALTER TABLE `health_tips`
  ADD CONSTRAINT `fk_alert_logic` FOREIGN KEY (`alert_logic_id`) REFERENCES `alert_condition_registry` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `health_tips_cache`
--
ALTER TABLE `health_tips_cache`
  ADD CONSTRAINT `fk_live_alert` FOREIGN KEY (`live_alert_id`) REFERENCES `live_alerts_cache` (`live_id`) ON DELETE CASCADE;

--
-- Constraints for table `live_alerts_cache`
--
ALTER TABLE `live_alerts_cache`
  ADD CONSTRAINT `fk_cache_logic` FOREIGN KEY (`logic_id`) REFERENCES `alert_condition_registry` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cache_sub` FOREIGN KEY (`sub_id`) REFERENCES `sub_sectors` (`sub_id`) ON DELETE CASCADE;

--
-- Constraints for table `regional_sectors`
--
ALTER TABLE `regional_sectors`
  ADD CONSTRAINT `fk_assigned_ho` FOREIGN KEY (`assigned_ho_id`) REFERENCES `health_officers` (`badge_number`) ON DELETE SET NULL;

--
-- Constraints for table `sub_sectors`
--
ALTER TABLE `sub_sectors`
  ADD CONSTRAINT `fk_parent_region` FOREIGN KEY (`parent_region_id`) REFERENCES `regional_sectors` (`region_id`) ON DELETE CASCADE;

--
-- Constraints for table `symptom_checks`
--
ALTER TABLE `symptom_checks`
  ADD CONSTRAINT `fk_symptom_reporter` FOREIGN KEY (`reporter_bcn`) REFERENCES `citizens` (`bcn`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
