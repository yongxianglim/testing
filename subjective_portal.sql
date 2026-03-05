-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 05, 2026 at 02:15 AM
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
-- Database: `subjective_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `field_definitions`
--

CREATE TABLE `field_definitions` (
  `field_def_id` int(10) UNSIGNED NOT NULL,
  `testing_id` int(10) UNSIGNED NOT NULL,
  `field_key` tinyint(3) UNSIGNED NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `value_type` enum('text','non_negative_decimal') NOT NULL,
  `display_order` tinyint(3) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `master_fields`
--

CREATE TABLE `master_fields` (
  `master_field_id` int(10) UNSIGNED NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `value_type` enum('text','non_negative_decimal') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `master_fields`
--

INSERT INTO `master_fields` (`master_field_id`, `field_name`, `value_type`, `created_at`) VALUES
(1, 'Surface', 'text', '2026-02-24 01:41:35'),
(2, 'Heights', 'text', '2026-02-24 01:41:35'),
(3, 'Runaway Cursor', 'non_negative_decimal', '2026-02-24 01:41:35'),
(4, 'LF / HF Ripple', 'non_negative_decimal', '2026-02-24 01:41:35'),
(5, 'Angle Error', 'non_negative_decimal', '2026-02-24 01:41:35'),
(6, 'Glitches / Jumps', 'non_negative_decimal', '2026-02-24 01:41:35'),
(7, 'Lift Skating', 'non_negative_decimal', '2026-02-24 01:41:35'),
(8, 'Backlash', 'non_negative_decimal', '2026-02-24 01:41:35'),
(9, 'Tapping', 'non_negative_decimal', '2026-02-24 01:41:35'),
(10, 'Resolution Fluctuation', 'non_negative_decimal', '2026-02-24 01:41:35'),
(11, 'Alternate Axis Drift', 'non_negative_decimal', '2026-02-24 01:41:35'),
(12, 'Single Pixel', 'non_negative_decimal', '2026-02-24 01:41:35');

-- --------------------------------------------------------

--
-- Table structure for table `media_files`
--

CREATE TABLE `media_files` (
  `media_id` int(10) UNSIGNED NOT NULL,
  `testing_id` int(10) UNSIGNED NOT NULL,
  `group_name` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_data` longblob NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `testing`
--

CREATE TABLE `testing` (
  `testing_id` int(10) UNSIGNED NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `project_title` varchar(255) NOT NULL,
  `testing_name` varchar(255) NOT NULL,
  `testing_method` varchar(255) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `checked_by_engineer_t1` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Table 1 checked by engineer',
  `checked_by_engineer_t2` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Table 2 checked by engineer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `testing_description`
--

CREATE TABLE `testing_description` (
  `description_id` int(10) UNSIGNED NOT NULL,
  `testing_id` int(10) UNSIGNED NOT NULL,
  `content` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `testing_record`
--

CREATE TABLE `testing_record` (
  `testing_record_id` int(10) UNSIGNED NOT NULL,
  `testing_id` int(10) UNSIGNED NOT NULL,
  `field_key` tinyint(3) UNSIGNED NOT NULL,
  `row_number` int(10) UNSIGNED NOT NULL,
  `table_number` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Table 1 (owner), 2 = Table 2 (others)',
  `record_value` varchar(255) DEFAULT NULL,
  `edited_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('VIEWER','EDITOR','DEVELOPER') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'developer', 'dev123', 'DEVELOPER', '2026-02-26 06:03:55'),
(2, 'editor', 'editor123', 'EDITOR', '2026-02-26 06:04:30'),
(3, 'viewer', 'viewer123', 'VIEWER', '2026-02-26 06:04:40');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `field_definitions`
--
ALTER TABLE `field_definitions`
  ADD PRIMARY KEY (`field_def_id`),
  ADD UNIQUE KEY `uq_testing_field_key` (`testing_id`,`field_key`),
  ADD UNIQUE KEY `uq_testing_display_order` (`testing_id`,`display_order`),
  ADD KEY `fk_field_def_testing` (`testing_id`);

--
-- Indexes for table `master_fields`
--
ALTER TABLE `master_fields`
  ADD PRIMARY KEY (`master_field_id`),
  ADD UNIQUE KEY `uq_master_field_name` (`field_name`);

--
-- Indexes for table `media_files`
--
ALTER TABLE `media_files`
  ADD PRIMARY KEY (`media_id`),
  ADD KEY `fk_media_testing` (`testing_id`);

--
-- Indexes for table `testing`
--
ALTER TABLE `testing`
  ADD PRIMARY KEY (`testing_id`);

--
-- Indexes for table `testing_description`
--
ALTER TABLE `testing_description`
  ADD PRIMARY KEY (`description_id`),
  ADD KEY `fk_description_testing` (`testing_id`);

--
-- Indexes for table `testing_record`
--
ALTER TABLE `testing_record`
  ADD PRIMARY KEY (`testing_record_id`),
  ADD UNIQUE KEY `uq_testing_field_row` (`testing_id`,`field_key`,`row_number`,`table_number`),
  ADD KEY `fk_record_testing` (`testing_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `field_definitions`
--
ALTER TABLE `field_definitions`
  MODIFY `field_def_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_fields`
--
ALTER TABLE `master_fields`
  MODIFY `master_field_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `media_files`
--
ALTER TABLE `media_files`
  MODIFY `media_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `testing`
--
ALTER TABLE `testing`
  MODIFY `testing_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `testing_description`
--
ALTER TABLE `testing_description`
  MODIFY `description_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `testing_record`
--
ALTER TABLE `testing_record`
  MODIFY `testing_record_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `field_definitions`
--
ALTER TABLE `field_definitions`
  ADD CONSTRAINT `fk_field_def_testing` FOREIGN KEY (`testing_id`) REFERENCES `testing` (`testing_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `media_files`
--
ALTER TABLE `media_files`
  ADD CONSTRAINT `fk_media_testing` FOREIGN KEY (`testing_id`) REFERENCES `testing` (`testing_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `testing_description`
--
ALTER TABLE `testing_description`
  ADD CONSTRAINT `fk_description_testing` FOREIGN KEY (`testing_id`) REFERENCES `testing` (`testing_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `testing_record`
--
ALTER TABLE `testing_record`
  ADD CONSTRAINT `fk_record_testing` FOREIGN KEY (`testing_id`) REFERENCES `testing` (`testing_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
