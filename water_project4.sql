-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.4.3 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for water_project
CREATE DATABASE IF NOT EXISTS `water_project` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `water_project`;

-- Dumping structure for table water_project.cache
CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.cache: ~0 rows (approximately)
DELETE FROM `cache`;

-- Dumping structure for table water_project.cache_locks
CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.cache_locks: ~0 rows (approximately)
DELETE FROM `cache_locks`;

-- Dumping structure for table water_project.failed_jobs
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.failed_jobs: ~0 rows (approximately)
DELETE FROM `failed_jobs`;

-- Dumping structure for table water_project.invoices
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_id` bigint unsigned NOT NULL,
  `bill_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bill_date` date NOT NULL,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `consumption_fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'قيمة الاستهلاك',
  `subscription_installment` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'قسط الاشتراك',
  `tanker_charges` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رسوم الوايت',
  `previous_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'المتأخرات السابقة',
  `credit_applied` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رصيد دائن مخصوم',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'الإجمالي النهائي',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'المبلغ المسدد',
  `status` enum('draft','issued','partially_paid','paid','overdue') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `meter_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_bill_number_unique` (`bill_number`),
  KEY `invoices_subscriber_id_foreign` (`subscriber_id`),
  KEY `invoices_meter_id_foreign` (`meter_id`),
  CONSTRAINT `invoices_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.invoices: ~39 rows (approximately)
DELETE FROM `invoices`;
INSERT INTO `invoices` (`id`, `subscriber_id`, `bill_number`, `bill_date`, `billing_period_start`, `billing_period_end`, `consumption_fee`, `subscription_installment`, `tanker_charges`, `previous_balance`, `credit_applied`, `total_amount`, `amount_paid`, `status`, `notes`, `created_at`, `updated_at`, `deleted_at`, `meter_id`) VALUES
	(9, 1, 'INV-202606-0001', '2026-06-12', '2026-05-01', '2026-05-31', 15400.00, 0.00, 0.00, 0.00, 0.00, 15400.00, 15400.00, 'paid', NULL, '2026-06-11 21:51:54', '2026-06-11 23:17:55', NULL, NULL),
	(10, 1, 'INV-202606-0002', '2026-06-12', '2026-05-01', '2026-06-30', 2100.00, 0.00, 0.00, 15400.00, 0.00, 2100.00, 2100.00, 'paid', NULL, '2026-06-11 22:11:01', '2026-06-11 23:17:55', NULL, NULL),
	(11, 2, 'INV-202606-0003', '2026-06-12', '2026-05-01', '2026-05-31', 22400.00, 0.00, 0.00, 0.00, 0.00, 22400.00, 22400.00, 'paid', NULL, '2026-06-11 22:14:37', '2026-06-11 23:03:04', NULL, NULL),
	(12, 2, 'INV-202606-0004', '2026-06-12', '2026-05-01', '2026-06-30', 5600.00, 0.00, 0.00, 0.00, 0.00, 5600.00, 5600.00, 'paid', NULL, '2026-06-11 22:16:16', '2026-06-11 23:03:04', NULL, NULL),
	(13, 43, 'INV-202606-0005', '2026-06-12', '2026-06-01', '2026-06-30', 2100.00, 0.00, 0.00, 4900.00, 0.00, 2100.00, 2100.00, 'paid', NULL, '2026-06-11 22:28:05', '2026-06-11 23:03:04', NULL, NULL),
	(14, 43, 'INV-202606-0006', '2026-06-12', '2026-07-01', '2026-07-31', 3500.00, 0.00, 0.00, 7000.00, 0.00, 3500.00, 3500.00, 'paid', NULL, '2026-06-11 22:30:02', '2026-06-11 23:03:04', NULL, NULL),
	(15, 43, 'INV-202606-0007', '2026-06-12', '2026-08-01', '2026-08-31', 3500.00, 0.00, 0.00, 7500.00, 3500.00, 0.00, 0.00, 'paid', NULL, '2026-06-11 22:38:00', '2026-06-18 14:40:13', NULL, NULL),
	(16, 4, 'INV-202606-0008', '2026-06-12', '2026-05-01', '2026-05-31', 10500.00, 0.00, 0.00, 0.00, 0.00, 10500.00, 10500.00, 'paid', NULL, '2026-06-11 23:19:22', '2026-06-11 23:26:27', NULL, NULL),
	(17, 4, 'INV-202606-0009', '2026-06-12', '2026-06-01', '2026-06-30', 3500.00, 0.00, 0.00, 500.00, 0.00, 3500.00, 3500.00, 'paid', NULL, '2026-06-11 23:20:40', '2026-06-11 23:26:27', NULL, NULL),
	(18, 4, 'INV-202606-0010', '2026-06-12', '2026-07-01', '2026-07-31', 3500.00, 0.00, 0.00, 4000.00, 0.00, 3500.00, 3500.00, 'paid', NULL, '2026-06-11 23:22:38', '2026-06-11 23:26:27', NULL, NULL),
	(19, 4, 'INV-202606-0011', '2026-06-12', '2026-08-01', '2026-08-31', 3500.00, 0.00, 0.00, 7000.00, 0.00, 3500.00, 3500.00, 'paid', NULL, '2026-06-11 23:24:04', '2026-06-11 23:26:27', NULL, NULL),
	(20, 4, 'INV-202606-0012', '2026-06-12', '2026-09-01', '2026-09-30', 3500.00, 0.00, 0.00, 10500.00, 0.00, 3500.00, 3500.00, 'paid', NULL, '2026-06-11 23:25:23', '2026-06-11 23:26:27', NULL, NULL),
	(21, 4, 'INV-202606-0013', '2026-06-12', '2026-10-01', '2026-10-31', 3500.00, 0.00, 0.00, 0.00, 500.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-11 23:28:02', '2026-06-11 23:28:52', NULL, NULL),
	(22, 4, 'INV-202606-0014', '2026-06-12', '2026-11-01', '2026-11-30', 7000.00, 0.00, 0.00, 0.00, 7000.00, 0.00, 0.00, 'paid', NULL, '2026-06-11 23:29:50', '2026-06-18 14:40:13', NULL, NULL),
	(23, 4, 'INV-202606-0015', '2026-06-12', '2026-12-01', '2026-12-31', 3500.00, 0.00, 0.00, 0.00, 3500.00, 0.00, 0.00, 'paid', NULL, '2026-06-11 23:30:47', '2026-06-18 14:40:13', NULL, NULL),
	(24, 4, 'INV-202606-0016', '2026-06-12', '2027-01-01', '2027-01-31', 17500.00, 0.00, 0.00, 0.00, 9500.00, 8000.00, 300.00, 'partially_paid', NULL, '2026-06-11 23:32:11', '2026-06-11 23:33:07', NULL, NULL),
	(25, 44, 'INV-202606-0017', '2026-06-16', '2026-06-01', '2026-06-30', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 0.00, 'overdue', NULL, '2026-06-15 21:36:33', '2026-06-18 15:08:44', NULL, NULL),
	(26, 44, 'INV-202606-0018', '2026-06-16', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 7800.00, 0.00, 6000.00, 0.00, 'issued', NULL, '2026-06-15 21:40:40', '2026-06-15 21:40:40', NULL, NULL),
	(27, 45, 'INV-202606-0019', '2026-06-16', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'issued', 'لسيبلي', '2026-06-16 12:26:02', '2026-06-16 12:26:02', NULL, 46),
	(28, 45, 'INV-202606-0020', '2026-06-16', '2026-06-01', '2026-06-30', 12000.00, 0.00, 0.00, 6000.00, 0.00, 12000.00, 0.00, 'partially_paid', NULL, '2026-06-16 12:26:56', '2026-06-18 14:40:13', NULL, 47),
	(29, 46, 'INV-202606-0021', '2026-06-16', '2026-06-01', '2026-06-30', 12000.00, 0.00, 0.00, 0.00, 0.00, 12000.00, 0.00, 'issued', NULL, '2026-06-16 12:56:23', '2026-06-16 12:56:23', NULL, 49),
	(30, 46, 'INV-202606-0022', '2026-06-16', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-06-16 12:56:57', '2026-06-16 13:07:21', NULL, 50),
	(31, 47, 'INV-202606-0023', '2026-06-16', '2026-06-01', '2026-06-30', 12000.00, 0.00, 0.00, 0.00, 0.00, 12000.00, 0.00, 'issued', NULL, '2026-06-16 13:43:03', '2026-06-18 13:02:15', '2026-06-18 13:02:15', 52),
	(32, 47, 'INV-202606-0024', '2026-06-16', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'issued', NULL, '2026-06-16 13:45:09', '2026-06-16 13:45:09', NULL, 51),
	(33, 47, 'INV-202606-0025', '2026-06-16', '2026-07-01', '2026-07-31', 3000.00, 0.00, 0.00, 18000.00, 0.00, 3000.00, 0.00, 'issued', NULL, '2026-06-16 14:55:31', '2026-06-18 13:01:59', '2026-06-18 13:01:59', 53),
	(34, 47, 'INV-202606-0026', '2026-06-16', '2026-08-01', '2026-08-31', 3000.00, 0.00, 0.00, 3000.00, 0.00, 3000.00, 0.00, 'issued', NULL, '2026-06-16 15:10:06', '2026-06-18 13:01:59', '2026-06-18 13:01:59', 53),
	(35, 48, 'INV-202606-0027', '2026-06-16', '2026-07-01', '2026-07-31', 15000.00, 0.00, 0.00, 0.00, 0.00, 15000.00, 15000.00, 'paid', NULL, '2026-06-16 17:41:30', '2026-06-16 18:13:41', NULL, 56),
	(36, 48, 'INV-202606-0028', '2026-06-16', '2026-10-01', '2026-10-31', 9000.00, 0.00, 0.00, 15000.00, 0.00, 9000.00, 9000.00, 'paid', NULL, '2026-06-16 17:46:09', '2026-06-16 17:52:07', NULL, 56),
	(37, 48, 'INV-202606-0029', '2026-06-16', '2026-11-01', '2026-11-30', 3000.00, 0.00, 0.00, 14000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-16 18:02:14', '2026-06-16 18:10:08', NULL, 56),
	(38, 48, 'INV-202606-0030', '2026-06-16', '2026-12-01', '2026-12-31', 3000.00, 0.00, 0.00, 3000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-16 18:12:29', '2026-06-16 18:13:41', NULL, 56),
	(39, 48, 'INV-202606-0031', '2026-06-16', '2027-01-01', '2027-01-31', 3000.00, 0.00, 0.00, 0.00, 3000.00, 0.00, 0.00, 'paid', NULL, '2026-06-16 18:15:40', '2026-06-18 14:40:13', NULL, 56),
	(40, 48, 'INV-202606-0032', '2026-06-16', '2026-06-01', '2026-06-30', 12000.00, 0.00, 0.00, 0.00, 12000.00, 0.00, 0.00, 'paid', NULL, '2026-06-16 18:17:11', '2026-06-18 14:40:13', NULL, 54),
	(41, 49, 'INV-202606-0033', '2026-06-16', '2026-06-01', '2026-06-30', 5400.00, 0.00, 0.00, 0.00, 0.00, 5400.00, 1800.00, 'partially_paid', NULL, '2026-06-16 19:39:03', '2026-06-16 20:06:05', NULL, 57),
	(42, 49, 'INV-202606-0034', '2026-06-16', '2026-07-01', '2026-07-31', 3600.00, 0.00, 0.00, 5400.00, 0.00, 3600.00, 3600.00, 'partially_paid', NULL, '2026-06-16 19:56:45', '2026-06-18 14:40:13', NULL, 57),
	(43, 51, 'INV-202606-0035', '2026-06-17', '2026-06-01', '2026-06-30', 3000.00, 0.00, 0.00, 0.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-17 13:51:02', '2026-06-18 11:03:31', NULL, 63),
	(44, 51, 'INV-202606-0036', '2026-06-17', '2026-07-01', '2026-07-31', 3000.00, 0.00, 0.00, 1000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-17 13:53:40', '2026-06-18 12:03:01', NULL, 63),
	(45, 51, 'INV-202606-0037', '2026-06-18', '2026-08-01', '2026-08-31', 6000.00, 0.00, 0.00, 4000.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-06-18 10:58:23', '2026-06-18 12:03:01', NULL, 63),
	(46, 51, 'INV-202606-0038', '2026-06-18', '2026-09-01', '2026-09-30', 3000.00, 0.00, 0.00, 10000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-18 11:02:10', '2026-06-18 13:17:41', NULL, 63),
	(47, 51, 'INV-202606-0039', '2026-06-18', '2026-10-01', '2026-10-31', 3000.00, 0.00, 0.00, 11000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-18 11:08:31', '2026-06-18 14:34:11', NULL, 63),
	(48, 51, 'INV-202606-0040', '2026-06-18', '2026-06-01', '2026-06-30', 12000.00, 0.00, 0.00, 0.00, 0.00, 12000.00, 0.00, 'overdue', NULL, '2026-06-18 11:13:46', '2026-06-18 12:43:54', '2026-06-18 12:43:54', 64),
	(49, 51, 'INV-202606-0041', '2026-06-18', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-06-18 11:14:43', '2026-06-18 11:21:53', NULL, 62),
	(50, 51, 'INV-202606-0042', '2026-06-18', '2026-07-01', '2026-07-31', 1800.00, 0.00, 0.00, 12000.00, 0.00, 1800.00, 0.00, 'issued', NULL, '2026-06-18 11:16:44', '2026-06-18 12:43:54', '2026-06-18 12:43:54', 64),
	(51, 51, 'INV-202606-0043', '2026-06-18', '2026-07-01', '2026-07-31', 1200.00, 0.00, 0.00, 6000.00, 0.00, 1200.00, 1000.00, 'partially_paid', NULL, '2026-06-18 11:17:37', '2026-06-18 11:21:53', NULL, 62),
	(52, 51, 'INV-202606-0044', '2026-06-18', '2026-11-01', '2026-11-30', 1800.00, 0.00, 0.00, 14000.00, 0.00, 1800.00, 200.00, 'partially_paid', NULL, '2026-06-18 11:58:33', '2026-06-18 15:14:10', NULL, 63),
	(53, 51, 'INV-202606-0045', '2026-06-18', '2026-12-01', '2026-12-31', 600.00, 0.00, 0.00, 5000.00, 0.00, 600.00, 600.00, 'paid', NULL, '2026-06-18 12:16:27', '2026-06-18 12:44:58', NULL, 63),
	(54, 51, 'INV-202606-0046', '2026-06-18', '2027-01-01', '2027-01-31', 600.00, 0.00, 0.00, 5000.00, 0.00, 600.00, 600.00, 'paid', NULL, '2026-06-18 13:16:26', '2026-06-18 14:40:13', NULL, 63),
	(55, 51, 'INV-202606-0047', '2026-06-18', '2027-02-01', '2027-02-28', 1800.00, 0.00, 0.00, 4800.00, 0.00, 1800.00, 1800.00, 'partially_paid', NULL, '2026-06-18 13:24:45', '2026-06-18 13:26:08', NULL, 63),
	(56, 52, 'INV-202606-0048', '2026-06-18', '2026-06-01', '2026-06-18', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-06-18 15:17:23', '2026-06-18 15:56:31', NULL, 65),
	(57, 52, 'INV-202606-0049', '2026-06-18', '2026-06-19', '2026-06-30', 3000.00, 0.00, 0.00, 6000.00, 0.00, 3000.00, 3000.00, 'paid', NULL, '2026-06-18 15:21:19', '2026-06-18 15:38:00', NULL, 65),
	(58, 52, 'INV-202606-0050', '2026-06-18', '2026-07-01', '2026-07-31', 3000.00, 0.00, 0.00, 5000.00, 0.00, 3000.00, 2700.00, 'partially_paid', NULL, '2026-06-18 15:23:39', '2026-06-18 16:11:04', NULL, 65),
	(59, 52, 'INV-202606-0051', '2026-06-18', '2026-08-01', '2026-08-31', 1800.00, 0.00, 0.00, 8000.00, 0.00, 1800.00, 1800.00, 'paid', NULL, '2026-06-18 15:54:34', '2026-06-18 16:12:11', NULL, 65),
	(60, 52, 'INV-202606-0052', '2026-06-18', '2026-09-01', '2026-09-30', 4200.00, 0.00, 0.00, 300.00, 0.00, 4200.00, 0.00, 'overdue', NULL, '2026-06-18 16:12:11', '2026-06-18 16:39:25', NULL, 65),
	(61, 52, 'INV-202606-0053', '2026-06-18', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-06-18 16:28:39', '2026-06-18 16:29:08', NULL, 66),
	(62, 52, 'INV-202606-0054', '2026-06-18', '2026-10-01', '2026-10-31', 1200.00, 0.00, 0.00, 4500.00, 0.00, 1200.00, 0.00, 'issued', NULL, '2026-06-18 16:39:25', '2026-06-18 16:41:45', '2026-06-18 16:41:45', 65);

-- Dumping structure for table water_project.invoice_items
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint unsigned NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consumption/subscription/tanker/previous_balance/credit',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_items_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.invoice_items: ~63 rows (approximately)
DELETE FROM `invoice_items`;
INSERT INTO `invoice_items` (`id`, `invoice_id`, `type`, `description`, `amount`, `created_at`, `updated_at`, `deleted_at`) VALUES
	(10, 9, 'consumption', 'استهلاك مياه: 22 وحدة × 700 ريال', 15400.00, '2026-06-11 21:51:54', '2026-06-11 21:51:54', NULL),
	(11, 10, 'consumption', 'استهلاك مياه: 3 وحدة × 700 ريال', 2100.00, '2026-06-11 22:11:01', '2026-06-11 22:11:01', NULL),
	(12, 10, 'previous_balance', 'متأخرات سابقة', 15400.00, '2026-06-11 22:11:01', '2026-06-11 22:11:01', NULL),
	(13, 11, 'consumption', 'استهلاك مياه: 32 وحدة × 700 ريال', 22400.00, '2026-06-11 22:14:37', '2026-06-11 22:14:37', NULL),
	(14, 12, 'consumption', 'استهلاك مياه: 8 وحدة × 700 ريال', 5600.00, '2026-06-11 22:16:16', '2026-06-11 22:16:16', NULL),
	(15, 13, 'consumption', 'استهلاك مياه: 3 وحدة × 700 ريال', 2100.00, '2026-06-11 22:28:05', '2026-06-11 22:28:05', NULL),
	(16, 13, 'previous_balance', 'متأخرات سابقة', 4900.00, '2026-06-11 22:28:05', '2026-06-11 22:28:05', NULL),
	(17, 14, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 22:30:02', '2026-06-11 22:30:02', NULL),
	(18, 14, 'previous_balance', 'متأخرات سابقة', 7000.00, '2026-06-11 22:30:02', '2026-06-11 22:30:02', NULL),
	(19, 15, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 22:38:00', '2026-06-11 22:38:00', NULL),
	(20, 15, 'previous_balance', 'متأخرات سابقة', 7500.00, '2026-06-11 22:38:00', '2026-06-11 22:38:00', NULL),
	(21, 15, 'credit', 'خصم رصيد دائن', -3500.00, '2026-06-11 22:38:00', '2026-06-11 22:38:00', NULL),
	(22, 16, 'consumption', 'استهلاك مياه: 15 وحدة × 700 ريال', 10500.00, '2026-06-11 23:19:22', '2026-06-11 23:19:22', NULL),
	(23, 17, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:20:40', '2026-06-11 23:20:40', NULL),
	(24, 17, 'previous_balance', 'متأخرات سابقة', 500.00, '2026-06-11 23:20:40', '2026-06-11 23:20:40', NULL),
	(25, 18, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:22:38', '2026-06-11 23:22:38', NULL),
	(26, 18, 'previous_balance', 'متأخرات سابقة', 4000.00, '2026-06-11 23:22:38', '2026-06-11 23:22:38', NULL),
	(27, 19, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:24:04', '2026-06-11 23:24:04', NULL),
	(28, 19, 'previous_balance', 'متأخرات سابقة', 7000.00, '2026-06-11 23:24:04', '2026-06-11 23:24:04', NULL),
	(29, 20, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:25:23', '2026-06-11 23:25:23', NULL),
	(30, 20, 'previous_balance', 'متأخرات سابقة', 10500.00, '2026-06-11 23:25:23', '2026-06-11 23:25:23', NULL),
	(31, 21, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:28:02', '2026-06-11 23:28:02', NULL),
	(32, 21, 'credit', 'خصم رصيد دائن', -500.00, '2026-06-11 23:28:02', '2026-06-11 23:28:02', NULL),
	(33, 22, 'consumption', 'استهلاك مياه: 10 وحدة × 700 ريال', 7000.00, '2026-06-11 23:29:50', '2026-06-11 23:29:50', NULL),
	(34, 22, 'credit', 'خصم رصيد دائن', -7000.00, '2026-06-11 23:29:50', '2026-06-11 23:29:50', NULL),
	(35, 23, 'consumption', 'استهلاك مياه: 5 وحدة × 700 ريال', 3500.00, '2026-06-11 23:30:47', '2026-06-11 23:30:47', NULL),
	(36, 23, 'credit', 'خصم رصيد دائن', -3500.00, '2026-06-11 23:30:47', '2026-06-11 23:30:47', NULL),
	(37, 24, 'consumption', 'استهلاك مياه: 25 وحدة × 700 ريال', 17500.00, '2026-06-11 23:32:11', '2026-06-11 23:32:11', NULL),
	(38, 24, 'credit', 'خصم رصيد دائن', -9500.00, '2026-06-11 23:32:11', '2026-06-11 23:32:11', NULL),
	(39, 25, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-06-15 21:36:33', '2026-06-15 21:36:33', NULL),
	(40, 26, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-15 21:40:40', '2026-06-15 21:40:40', NULL),
	(41, 26, 'previous_balance', 'متأخرات سابقة', 7800.00, '2026-06-15 21:40:40', '2026-06-15 21:40:40', NULL),
	(42, 27, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-16 12:26:02', '2026-06-16 12:26:02', NULL),
	(43, 28, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-06-16 12:26:56', '2026-06-16 12:26:56', NULL),
	(44, 28, 'previous_balance', 'متأخرات سابقة', 6000.00, '2026-06-16 12:26:56', '2026-06-16 12:26:56', NULL),
	(45, 29, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-06-16 12:56:23', '2026-06-16 12:56:23', NULL),
	(46, 30, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-16 12:56:57', '2026-06-16 12:56:57', NULL),
	(47, 31, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-06-16 13:43:03', '2026-06-16 13:43:03', NULL),
	(48, 32, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-16 13:45:09', '2026-06-16 13:45:09', NULL),
	(49, 33, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-16 14:55:31', '2026-06-16 14:55:31', NULL),
	(50, 33, 'previous_balance', 'متأخرات سابقة', 18000.00, '2026-06-16 14:55:31', '2026-06-16 14:55:31', NULL),
	(51, 34, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-16 15:10:06', '2026-06-16 15:10:06', NULL),
	(52, 34, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-06-16 15:10:06', '2026-06-16 15:10:06', NULL),
	(53, 35, 'consumption', 'استهلاك مياه: 25 وحدة × 600 ريال', 15000.00, '2026-06-16 17:41:30', '2026-06-16 17:41:30', NULL),
	(54, 36, 'consumption', 'استهلاك مياه: 15 وحدة × 600 ريال', 9000.00, '2026-06-16 17:46:09', '2026-06-16 17:46:09', NULL),
	(55, 36, 'previous_balance', 'متأخرات سابقة', 15000.00, '2026-06-16 17:46:09', '2026-06-16 17:46:09', NULL),
	(56, 37, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-16 18:02:14', '2026-06-16 18:02:14', NULL),
	(57, 37, 'previous_balance', 'متأخرات سابقة', 14000.00, '2026-06-16 18:02:14', '2026-06-16 18:02:14', NULL),
	(58, 38, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-16 18:12:29', '2026-06-16 18:12:29', NULL),
	(59, 38, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-06-16 18:12:29', '2026-06-16 18:12:29', NULL),
	(60, 39, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-16 18:15:40', '2026-06-16 18:15:40', NULL),
	(61, 39, 'credit', 'خصم رصيد دائن', -3000.00, '2026-06-16 18:15:40', '2026-06-16 18:15:40', NULL),
	(62, 40, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-06-16 18:17:11', '2026-06-16 18:17:11', NULL),
	(63, 40, 'credit', 'خصم رصيد دائن', -12000.00, '2026-06-16 18:17:11', '2026-06-16 18:17:11', NULL),
	(64, 41, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-06-16 19:39:03', '2026-06-16 19:39:03', NULL),
	(65, 42, 'consumption', 'استهلاك مياه: 6 وحدة × 600 ريال', 3600.00, '2026-06-16 19:56:45', '2026-06-16 19:56:45', NULL),
	(66, 42, 'previous_balance', 'متأخرات سابقة', 5400.00, '2026-06-16 19:56:45', '2026-06-16 19:56:45', NULL),
	(67, 43, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-17 13:51:02', '2026-06-17 13:51:02', NULL),
	(68, 44, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-17 13:53:40', '2026-06-17 13:53:40', NULL),
	(69, 44, 'previous_balance', 'متأخرات سابقة', 1000.00, '2026-06-17 13:53:40', '2026-06-17 13:53:40', NULL),
	(70, 45, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-18 10:58:23', '2026-06-18 10:58:23', NULL),
	(71, 45, 'previous_balance', 'متأخرات سابقة', 4000.00, '2026-06-18 10:58:23', '2026-06-18 10:58:23', NULL),
	(72, 46, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-18 11:02:10', '2026-06-18 11:02:10', NULL),
	(73, 46, 'previous_balance', 'متأخرات سابقة', 10000.00, '2026-06-18 11:02:10', '2026-06-18 11:02:10', NULL),
	(74, 47, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-18 11:08:31', '2026-06-18 11:08:31', NULL),
	(75, 47, 'previous_balance', 'متأخرات سابقة', 11000.00, '2026-06-18 11:08:31', '2026-06-18 11:08:31', NULL),
	(76, 48, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-06-18 11:13:46', '2026-06-18 11:13:46', NULL),
	(77, 49, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-18 11:14:43', '2026-06-18 11:14:43', NULL),
	(78, 50, 'consumption', 'استهلاك مياه: 3 وحدة × 600 ريال', 1800.00, '2026-06-18 11:16:44', '2026-06-18 11:16:44', NULL),
	(79, 50, 'previous_balance', 'متأخرات سابقة', 12000.00, '2026-06-18 11:16:44', '2026-06-18 11:16:44', NULL),
	(80, 51, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-06-18 11:17:37', '2026-06-18 11:17:37', NULL),
	(81, 51, 'previous_balance', 'متأخرات سابقة', 6000.00, '2026-06-18 11:17:37', '2026-06-18 11:17:37', NULL),
	(82, 52, 'consumption', 'استهلاك مياه: 3 وحدة × 600 ريال', 1800.00, '2026-06-18 11:58:33', '2026-06-18 11:58:33', NULL),
	(83, 52, 'previous_balance', 'متأخرات سابقة', 14000.00, '2026-06-18 11:58:33', '2026-06-18 11:58:33', NULL),
	(84, 53, 'consumption', 'استهلاك مياه: 1 وحدة × 600 ريال', 600.00, '2026-06-18 12:16:27', '2026-06-18 12:16:27', NULL),
	(85, 53, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-06-18 12:16:27', '2026-06-18 12:16:27', NULL),
	(86, 54, 'consumption', 'استهلاك مياه: 1 وحدة × 600 ريال', 600.00, '2026-06-18 13:16:26', '2026-06-18 13:16:26', NULL),
	(87, 54, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-06-18 13:16:26', '2026-06-18 13:16:26', NULL),
	(88, 55, 'consumption', 'استهلاك مياه: 3 وحدة × 600 ريال', 1800.00, '2026-06-18 13:24:45', '2026-06-18 13:24:45', NULL),
	(89, 55, 'previous_balance', 'متأخرات سابقة', 4800.00, '2026-06-18 13:24:45', '2026-06-18 13:24:45', NULL),
	(90, 56, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-18 15:17:23', '2026-06-18 15:17:23', NULL),
	(91, 57, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-18 15:21:19', '2026-06-18 15:21:19', NULL),
	(92, 57, 'previous_balance', 'متأخرات سابقة', 6000.00, '2026-06-18 15:21:19', '2026-06-18 15:21:19', NULL),
	(93, 58, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-06-18 15:23:39', '2026-06-18 15:23:39', NULL),
	(94, 58, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-06-18 15:23:39', '2026-06-18 15:23:39', NULL),
	(95, 59, 'consumption', 'استهلاك مياه: 3 وحدة × 600 ريال', 1800.00, '2026-06-18 15:54:34', '2026-06-18 15:54:34', NULL),
	(96, 59, 'previous_balance', 'متأخرات سابقة', 8000.00, '2026-06-18 15:54:34', '2026-06-18 15:54:34', NULL),
	(97, 60, 'consumption', 'استهلاك مياه: 7 وحدة × 600 ريال', 4200.00, '2026-06-18 16:12:11', '2026-06-18 16:12:11', NULL),
	(98, 60, 'previous_balance', 'متأخرات سابقة', 300.00, '2026-06-18 16:12:11', '2026-06-18 16:12:11', NULL),
	(99, 61, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-06-18 16:28:39', '2026-06-18 16:28:39', NULL),
	(100, 62, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-06-18 16:39:25', '2026-06-18 16:39:25', NULL),
	(101, 62, 'previous_balance', 'متأخرات سابقة', 4500.00, '2026-06-18 16:39:25', '2026-06-18 16:39:25', NULL);

-- Dumping structure for table water_project.jobs
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.jobs: ~0 rows (approximately)
DELETE FROM `jobs`;

-- Dumping structure for table water_project.job_batches
CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.job_batches: ~0 rows (approximately)
DELETE FROM `job_batches`;

-- Dumping structure for table water_project.meters
CREATE TABLE IF NOT EXISTS `meters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `unique_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الرقم المكتوب على العداد',
  `tenant_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenant_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscriber_id` bigint unsigned NOT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `installed_at` date DEFAULT NULL,
  `removed_at` date DEFAULT NULL,
  `initial_reading` int NOT NULL DEFAULT '0',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meters_unique_code_unique` (`unique_code`),
  KEY `meters_subscriber_id_foreign` (`subscriber_id`),
  CONSTRAINT `meters_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.meters: ~58 rows (approximately)
DELETE FROM `meters`;
INSERT INTO `meters` (`id`, `unique_code`, `tenant_name`, `tenant_phone`, `subscriber_id`, `status`, `installed_at`, `removed_at`, `initial_reading`, `balance`, `created_at`, `updated_at`, `deleted_at`) VALUES
	(1, '2025063598', NULL, NULL, 1, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(2, '2025060939', NULL, NULL, 2, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(3, '2025061601', NULL, NULL, 3, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(4, '2025062306', NULL, NULL, 4, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(5, '2025060419', NULL, NULL, 5, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(6, '2025061649', NULL, NULL, 6, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(7, '2025561666', NULL, NULL, 7, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(8, '2025063781', NULL, NULL, 8, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(9, '2025060646', NULL, NULL, 9, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(10, '2025060407', NULL, NULL, 10, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(11, '2025061603', NULL, NULL, 11, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(12, '2025063641', NULL, NULL, 12, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(13, '2025062369', NULL, NULL, 13, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(14, '2025060956', NULL, NULL, 14, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(15, '2025062470', NULL, NULL, 15, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(16, '2025062485', NULL, NULL, 16, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(17, '2025063533', NULL, NULL, 17, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(18, '2025062309', NULL, NULL, 18, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(19, '2025062495', NULL, NULL, 19, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(20, '2025062495-2', NULL, NULL, 20, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(21, '2025061658', NULL, NULL, 21, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(22, '2025060461', NULL, NULL, 22, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(23, '2025062377', NULL, NULL, 23, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(24, '2025063515', NULL, NULL, 24, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(25, '2025061655', NULL, NULL, 25, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(26, '2025061698', NULL, NULL, 26, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(27, '202506', NULL, NULL, 27, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(28, '2025062445', NULL, NULL, 28, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(29, '2025060134', NULL, NULL, 29, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(30, '2025063552', NULL, NULL, 30, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(31, '20255060092', NULL, NULL, 31, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(32, '2025061981', NULL, NULL, 32, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(33, '2025060143', NULL, NULL, 33, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(34, '2025061902', NULL, NULL, 34, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(35, '2025061661', NULL, NULL, 35, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(36, '2025060983', NULL, NULL, 36, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(37, '2025063796', NULL, NULL, 37, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(38, '2025063760', NULL, NULL, 38, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(39, '2025061646', NULL, NULL, 39, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(40, '2025060761', NULL, NULL, 40, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(41, '2025067099', NULL, NULL, 41, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(42, '2025067437', NULL, NULL, 42, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(43, '2025060644', NULL, NULL, 43, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(44, '123', NULL, NULL, 44, 'active', NULL, NULL, 0, 0.00, '2026-06-15 21:18:17', '2026-06-15 21:18:17', NULL),
	(45, '1234', NULL, NULL, 44, 'active', NULL, NULL, 0, 0.00, '2026-06-15 21:18:28', '2026-06-15 21:18:28', NULL),
	(46, '789', NULL, NULL, 45, 'active', NULL, NULL, 0, 0.00, '2026-06-16 12:21:26', '2026-06-16 12:21:26', NULL),
	(47, '7894', NULL, NULL, 45, 'active', NULL, NULL, 0, 0.00, '2026-06-16 12:21:37', '2026-06-16 12:21:37', NULL),
	(48, '78945', NULL, NULL, 45, 'active', NULL, NULL, 0, 0.00, '2026-06-16 12:22:21', '2026-06-16 12:22:21', NULL),
	(49, '456', NULL, NULL, 46, 'active', NULL, NULL, 0, 0.00, '2026-06-16 12:52:40', '2026-06-16 12:52:40', NULL),
	(50, '4561', NULL, NULL, 46, 'active', NULL, NULL, 0, 0.00, '2026-06-16 12:52:53', '2026-06-16 12:52:53', NULL),
	(51, '147', NULL, NULL, 47, 'active', NULL, NULL, 0, 0.00, '2026-06-16 13:26:04', '2026-06-16 13:26:04', NULL),
	(52, '1474', 'مبخوت المستاجر', '+967876534', 47, 'active', NULL, NULL, 0, 0.00, '2026-06-16 13:31:42', '2026-06-16 21:36:18', NULL),
	(53, '14741', 'مبخوت المساجر اثنين', '25346', 47, 'active', NULL, NULL, 0, 0.00, '2026-06-16 13:36:24', '2026-06-16 13:36:24', NULL),
	(54, '258', NULL, NULL, 48, 'active', NULL, NULL, 0, 0.00, '2026-06-16 17:32:18', '2026-06-16 17:32:18', NULL),
	(55, '2585', 'محسن', '+96746746884', 48, 'active', NULL, NULL, 0, 0.00, '2026-06-16 17:32:58', '2026-06-16 21:36:18', NULL),
	(56, '25852', 'منير', '46496', 48, 'active', NULL, NULL, 0, 0.00, '2026-06-16 17:33:26', '2026-06-16 17:33:26', NULL),
	(57, '545365432', 'مروان', '+967345654', 49, 'active', NULL, NULL, 0, -3600.00, '2026-06-16 18:47:38', '2026-06-16 21:36:18', NULL),
	(58, '56254645', NULL, NULL, 49, 'active', NULL, NULL, 0, 0.00, '2026-06-16 18:48:13', '2026-06-16 18:48:13', NULL),
	(59, '34523', '1', '+967712323456', 50, 'active', NULL, NULL, 0, 0.00, '2026-06-16 22:12:12', '2026-06-16 22:12:12', NULL),
	(60, '4643565', '2', '+967743534554', 50, 'active', NULL, NULL, 0, 0.00, '2026-06-16 22:12:35', '2026-06-16 22:12:35', NULL),
	(61, '5363234', NULL, NULL, 50, 'active', NULL, NULL, 0, 0.00, '2026-06-16 22:12:49', '2026-06-16 22:12:49', NULL),
	(62, '551', NULL, NULL, 51, 'active', NULL, NULL, 0, -200.00, '2026-06-17 13:28:45', '2026-06-18 11:21:53', NULL),
	(63, '552', 'خ', '+967752366866', 51, 'active', NULL, NULL, 0, -1600.00, '2026-06-17 13:29:40', '2026-06-18 15:14:10', NULL),
	(64, '55123', 'خخ', '+967714234556', 51, 'active', NULL, NULL, 0, -13800.00, '2026-06-17 13:30:57', '2026-06-18 11:16:44', NULL),
	(65, '094382751', NULL, NULL, 52, 'active', NULL, NULL, 0, -5700.00, '2026-06-18 15:16:40', '2026-06-18 16:39:25', NULL),
	(66, '234234', 'محسن', '23465765765', 52, 'active', NULL, NULL, 0, 2000.00, '2026-06-18 16:27:36', '2026-06-18 16:29:08', NULL);

-- Dumping structure for table water_project.migrations
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.migrations: ~15 rows (approximately)
DELETE FROM `migrations`;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
	(1, '0001_01_01_000000_create_users_table', 1),
	(2, '0001_01_01_000001_create_cache_table', 1),
	(3, '0001_01_01_000002_create_jobs_table', 1),
	(4, '2024_01_01_000001_create_squares_table', 1),
	(5, '2024_01_01_000002_create_subscribers_table', 1),
	(6, '2024_01_01_000003_create_meters_table', 1),
	(7, '2024_01_01_000004_create_readings_table', 1),
	(8, '2024_01_01_000005_create_service_requests_table', 1),
	(9, '2024_01_01_000006_create_invoices_table', 1),
	(10, '2024_01_01_000007_create_invoice_items_table', 1),
	(11, '2024_01_01_000008_create_payments_table', 1),
	(12, '2024_01_01_000009_create_settings_table', 1),
	(13, '2026_06_06_125759_add_type_to_settings_table', 1),
	(14, '2026_06_06_130215_add_image_path_to_settings_table', 1),
	(15, '2026_06_08_210618_add_period_dates_to_invoices_table', 1),
	(16, '2026_06_08_234158_add_billing_periods_to_invoices_table', 1),
	(17, '2026_06_09_031731_add_actual_reading_date_to_readings_table', 1),
	(18, '2026_06_09_031731_add_subscription_start_date_to_subscribers_table', 1),
	(19, '2026_06_10_133709_add_reference_number_to_payments_table', 1),
	(20, '2026_06_10_235038_add_soft_deletes_to_all_core_tables', 1),
	(21, '2026_06_11_000023_add_history_fields_to_meters_table', 1),
	(22, '2026_06_11_000353_create_tariffs_table', 1),
	(23, '2026_06_11_001210_create_transactions_table', 1),
	(24, '2026_06_11_161711_add_role_to_users_table', 1),
	(25, '2026_06_11_203159_add_avatar_to_users_table', 2),
	(26, '2026_06_16_150251_add_meter_and_invoice_relations', 3),
	(27, '2026_06_16_161149_add_tenant_name_to_meters_table', 4),
	(28, '2026_06_16_213345_isolate_meter_accounts_add_meter_id_to_payments_and_transactions', 5);

-- Dumping structure for table water_project.password_reset_tokens
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.password_reset_tokens: ~0 rows (approximately)
DELETE FROM `password_reset_tokens`;

-- Dumping structure for table water_project.payments
CREATE TABLE IF NOT EXISTS `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_id` bigint unsigned NOT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `receipt_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم سند القبض',
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','check') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `meter_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_receipt_number_unique` (`receipt_number`),
  KEY `payments_subscriber_id_foreign` (`subscriber_id`),
  KEY `payments_invoice_id_foreign` (`invoice_id`),
  KEY `payments_meter_id_foreign` (`meter_id`),
  CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.payments: ~15 rows (approximately)
DELETE FROM `payments`;
INSERT INTO `payments` (`id`, `subscriber_id`, `invoice_id`, `receipt_number`, `amount`, `payment_date`, `payment_method`, `reference_number`, `notes`, `created_at`, `updated_at`, `deleted_at`, `meter_id`) VALUES
	(1, 2, 11, 'REC-202606-0001', 22400.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 22:15:28', '2026-06-11 22:15:28', NULL, NULL),
	(2, 2, 12, 'REC-202606-0002', 5600.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 22:20:16', '2026-06-11 22:20:16', NULL, NULL),
	(3, 43, 14, 'REC-202606-0003', 10000.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 22:34:38', '2026-06-11 22:34:38', NULL, NULL),
	(4, 43, 15, 'REC-202606-0004', 7500.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 22:41:56', '2026-06-11 22:41:56', NULL, NULL),
	(5, 1, 10, 'REC-202606-0005', 17500.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:17:55', '2026-06-11 23:17:55', NULL, NULL),
	(6, 4, 16, 'REC-202606-0006', 10000.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:19:46', '2026-06-11 23:19:46', NULL, NULL),
	(7, 4, 18, 'REC-202606-0007', 500.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:23:08', '2026-06-11 23:23:08', NULL, NULL),
	(8, 4, 20, 'REC-202606-0008', 14500.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:26:27', '2026-06-11 23:26:27', NULL, NULL),
	(9, 4, 21, 'REC-202606-0009', 23000.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:28:52', '2026-06-11 23:28:52', NULL, NULL),
	(10, 4, 24, 'REC-202606-0010', 300.00, '2026-06-12', 'cash', NULL, NULL, '2026-06-11 23:33:07', '2026-06-11 23:33:07', NULL, NULL),
	(11, 46, 30, 'REC-202606-0011', 6000.00, '2026-06-16', 'cash', NULL, NULL, '2026-06-16 13:07:21', '2026-06-16 13:07:21', NULL, NULL),
	(12, 48, 36, 'REC-202606-0012', 10000.00, '2026-06-16', 'cash', NULL, NULL, '2026-06-16 17:52:07', '2026-06-16 17:52:07', NULL, NULL),
	(13, 48, 37, 'REC-202606-0013', 14000.00, '2026-06-16', 'cash', NULL, NULL, '2026-06-16 18:10:08', '2026-06-16 18:10:08', NULL, NULL),
	(14, 48, 38, 'REC-202606-0014', 50000.00, '2026-06-16', 'cash', NULL, NULL, '2026-06-16 18:13:41', '2026-06-16 18:13:41', NULL, NULL),
	(15, 49, 42, 'REC-202606-0015', 5400.00, '2026-06-16', 'cash', NULL, NULL, '2026-06-16 20:06:05', '2026-06-16 20:06:05', NULL, 57),
	(16, 51, 43, 'REC-202606-0016', 2000.00, '2026-06-17', 'cash', NULL, NULL, '2026-06-17 13:52:47', '2026-06-17 13:52:47', NULL, 63),
	(17, 51, 46, 'REC-202606-0017', 2000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 11:03:31', '2026-06-18 11:03:31', NULL, 63),
	(18, 51, 51, 'REC-202606-0018', 7000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 11:21:53', '2026-06-18 11:21:53', NULL, 62),
	(19, 51, 52, 'REC-202606-0019', 10000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 12:03:01', '2026-06-18 12:03:01', NULL, 63),
	(20, 51, 52, 'REC-202606-0020', 800.00, '2026-06-18', 'cash', NULL, 'ناعهمع', '2026-06-18 12:12:34', '2026-06-18 12:12:34', NULL, 63),
	(21, 51, 53, 'REC-202606-0021', 600.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 12:44:58', '2026-06-18 12:44:58', NULL, 63),
	(22, 51, 54, 'REC-202606-0022', 800.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 13:17:41', '2026-06-18 13:17:41', NULL, 63),
	(23, 51, 55, 'REC-202606-0023', 1800.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 13:26:08', '2026-06-18 13:26:08', NULL, 63),
	(24, 51, 55, 'REC-202606-0024', 2000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 14:33:41', '2026-06-18 14:33:41', NULL, 63),
	(25, 51, 55, 'REC-202606-0025', 1000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 14:34:11', '2026-06-18 14:34:11', NULL, 63),
	(26, 51, 55, 'REC-202606-0026', 200.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 15:14:10', '2026-06-18 15:14:10', NULL, 63),
	(27, 52, 57, 'REC-202606-0027', 4000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 15:22:53', '2026-06-18 15:22:53', NULL, 65),
	(28, 52, 59, 'REC-202606-0028', 9000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 15:56:31', '2026-06-18 15:56:31', NULL, 65),
	(29, 52, 59, 'REC-202606-0029', 500.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 16:11:04', '2026-06-18 16:11:04', NULL, 65),
	(30, 52, 61, 'REC-202606-0030', 8000.00, '2026-06-18', 'cash', NULL, NULL, '2026-06-18 16:29:08', '2026-06-18 16:29:08', NULL, 66);

-- Dumping structure for table water_project.readings
CREATE TABLE IF NOT EXISTS `readings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `meter_id` bigint unsigned NOT NULL,
  `reading_date` date NOT NULL,
  `actual_reading_date` date DEFAULT NULL,
  `previous_reading` decimal(12,2) NOT NULL DEFAULT '0.00',
  `current_reading` decimal(12,2) NOT NULL,
  `consumption_units` int NOT NULL DEFAULT '0' COMMENT 'الاستهلاك بالوحدات',
  `is_invoiced` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'هل تم فوترة هذه القراءة',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `readings_meter_id_foreign` (`meter_id`),
  KEY `readings_created_by_foreign` (`created_by`),
  KEY `readings_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `readings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `readings_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `readings_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.readings: ~75 rows (approximately)
DELETE FROM `readings`;
INSERT INTO `readings` (`id`, `meter_id`, `reading_date`, `actual_reading_date`, `previous_reading`, `current_reading`, `consumption_units`, `is_invoiced`, `created_by`, `created_at`, `updated_at`, `deleted_at`, `invoice_id`) VALUES
	(1, 1, '2026-05-31', '2026-05-31', 0.00, 22.00, 22, 1, 1, '2026-06-09 00:22:03', '2026-06-11 21:51:54', NULL, NULL),
	(2, 2, '2026-05-31', '2026-05-31', 0.00, 32.00, 32, 1, 1, '2026-06-09 00:22:03', '2026-06-11 22:14:37', NULL, NULL),
	(3, 3, '2026-05-31', '2026-05-31', 0.00, 7.00, 7, 0, 1, '2026-06-09 00:22:03', '2026-06-11 21:26:16', NULL, NULL),
	(4, 4, '2026-05-31', '2026-05-31', 0.00, 15.00, 15, 1, 1, '2026-06-09 00:22:03', '2026-06-11 23:19:22', NULL, NULL),
	(5, 5, '2026-05-31', '2026-05-31', 0.00, 14.00, 14, 0, 1, '2026-06-09 00:22:03', '2026-06-09 01:09:15', NULL, NULL),
	(6, 6, '2026-05-31', '2026-05-31', 0.00, 10.00, 10, 0, 1, '2026-06-09 00:22:03', '2026-06-09 23:40:13', NULL, NULL),
	(7, 7, '2026-05-31', '2026-05-31', 0.00, 19.00, 19, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(8, 8, '2026-05-31', '2026-05-31', 0.00, 17.00, 17, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(9, 9, '2026-05-31', '2026-05-31', 0.00, 22.00, 22, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(10, 10, '2026-05-31', '2026-05-31', 0.00, 52.00, 52, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(11, 11, '2026-05-31', '2026-05-31', 0.00, 23.00, 23, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(12, 12, '2026-05-31', '2026-05-31', 0.00, 43.00, 43, 0, 1, '2026-06-09 00:22:03', '2026-06-09 13:15:19', NULL, NULL),
	(13, 13, '2026-05-31', '2026-05-31', 0.00, 21.00, 21, 0, 1, '2026-06-09 00:22:03', '2026-06-09 23:40:51', NULL, NULL),
	(14, 14, '2026-05-31', '2026-05-31', 0.00, 12.00, 12, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(15, 15, '2026-05-31', '2026-05-31', 0.00, 9.00, 9, 0, 1, '2026-06-09 00:22:03', '2026-06-10 19:27:03', NULL, NULL),
	(16, 16, '2026-05-31', '2026-05-31', 0.00, 20.00, 20, 0, 1, '2026-06-09 00:22:03', '2026-06-10 19:38:40', NULL, NULL),
	(17, 17, '2026-05-31', '2026-05-31', 0.00, 18.00, 18, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(18, 18, '2026-05-31', '2026-05-31', 0.00, 56.00, 56, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(19, 19, '2026-05-31', '2026-05-31', 0.00, 53.00, 53, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(20, 20, '2026-05-31', '2026-05-31', 0.00, 13.00, 13, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(21, 21, '2026-05-31', '2026-05-31', 0.00, 95.00, 95, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(22, 22, '2026-05-31', '2026-05-31', 0.00, 95.00, 95, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(23, 23, '2026-05-31', '2026-05-31', 0.00, 9.00, 9, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(24, 24, '2026-05-31', '2026-05-31', 0.00, 8.00, 8, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(25, 25, '2026-05-31', '2026-05-31', 0.00, 2.00, 2, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(26, 26, '2026-05-31', '2026-05-31', 0.00, 2.00, 2, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(27, 27, '2026-05-31', '2026-05-31', 0.00, 7.00, 7, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(28, 28, '2026-05-31', '2026-05-31', 0.00, 26.00, 26, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(29, 29, '2026-05-31', '2026-05-31', 0.00, 66.00, 66, 0, 1, '2026-06-09 00:22:03', '2026-06-17 11:26:53', NULL, NULL),
	(30, 30, '2026-05-31', '2026-05-31', 0.00, 51.00, 51, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(31, 31, '2026-05-31', '2026-05-31', 0.00, 17.00, 17, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(32, 32, '2026-05-31', '2026-05-31', 0.00, 22.00, 22, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(33, 33, '2026-05-31', '2026-05-31', 0.00, 9.00, 9, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(34, 34, '2026-05-31', '2026-05-31', 0.00, 9.00, 9, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(35, 35, '2026-05-31', '2026-05-31', 0.00, 5.00, 5, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(36, 36, '2026-05-31', '2026-05-31', 0.00, 35.00, 35, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(37, 37, '2026-05-31', '2026-05-31', 0.00, 0.00, 0, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(38, 38, '2026-05-31', '2026-05-31', 0.00, 5.00, 5, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(39, 39, '2026-05-31', '2026-05-31', 0.00, 10.00, 10, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(40, 40, '2026-05-31', '2026-05-31', 0.00, 25.00, 25, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(41, 41, '2026-05-31', '2026-05-31', 0.00, 13.00, 13, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(42, 42, '2026-05-31', '2026-05-31', 0.00, 8.00, 8, 0, 1, '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL, NULL),
	(43, 43, '2026-05-31', '2026-05-31', 0.00, 7.00, 7, 1, 1, '2026-06-09 00:22:03', '2026-06-11 22:28:05', NULL, NULL),
	(52, 1, '2026-06-30', '2026-06-12', 22.00, 25.00, 3, 1, 1, '2026-06-11 22:10:48', '2026-06-11 22:11:01', NULL, NULL),
	(53, 2, '2026-06-30', '2026-06-12', 32.00, 40.00, 8, 1, 1, '2026-06-11 22:16:04', '2026-06-11 22:16:16', NULL, NULL),
	(54, 43, '2026-06-30', '2026-06-12', 7.00, 10.00, 3, 1, 1, '2026-06-11 22:27:17', '2026-06-11 22:28:05', NULL, NULL),
	(55, 43, '2026-07-31', '2026-06-12', 10.00, 15.00, 5, 1, 1, '2026-06-11 22:29:41', '2026-06-11 22:30:02', NULL, NULL),
	(56, 43, '2026-08-31', '2026-06-12', 15.00, 20.00, 5, 1, 1, '2026-06-11 22:37:46', '2026-06-11 22:38:00', NULL, NULL),
	(57, 4, '2026-06-30', '2026-06-12', 15.00, 20.00, 5, 1, 1, '2026-06-11 23:20:28', '2026-06-11 23:20:40', NULL, NULL),
	(58, 4, '2026-07-31', '2026-06-12', 20.00, 25.00, 5, 1, 1, '2026-06-11 23:21:30', '2026-06-11 23:22:38', NULL, NULL),
	(59, 4, '2026-08-31', '2026-06-12', 25.00, 30.00, 5, 1, 1, '2026-06-11 23:23:44', '2026-06-11 23:24:04', NULL, NULL),
	(60, 4, '2026-09-30', '2026-06-12', 30.00, 35.00, 5, 1, 1, '2026-06-11 23:25:14', '2026-06-11 23:25:23', NULL, NULL),
	(61, 4, '2026-10-31', '2026-06-12', 35.00, 40.00, 5, 1, 1, '2026-06-11 23:27:52', '2026-06-11 23:28:02', NULL, NULL),
	(62, 4, '2026-11-30', '2026-06-12', 40.00, 50.00, 10, 1, 1, '2026-06-11 23:29:39', '2026-06-11 23:29:50', NULL, NULL),
	(63, 4, '2026-12-31', '2026-06-12', 50.00, 55.00, 5, 1, 1, '2026-06-11 23:30:35', '2026-06-11 23:30:47', NULL, NULL),
	(64, 4, '2027-01-31', '2026-06-12', 55.00, 80.00, 25, 1, 1, '2026-06-11 23:32:02', '2026-06-11 23:32:11', NULL, NULL),
	(65, 44, '2026-06-30', '2026-06-16', 0.00, 13.00, 13, 1, 1, '2026-06-15 21:36:03', '2026-06-15 21:36:33', NULL, NULL),
	(66, 45, '2026-06-30', '2026-06-16', 0.00, 10.00, 10, 1, 1, '2026-06-15 21:39:57', '2026-06-15 21:40:40', NULL, NULL),
	(67, 46, '2026-06-30', '2026-06-16', 0.00, 10.00, 10, 1, 1, '2026-06-16 12:23:42', '2026-06-16 12:26:02', NULL, 27),
	(68, 47, '2026-06-30', '2026-06-16', 0.00, 20.00, 20, 1, 1, '2026-06-16 12:24:09', '2026-06-16 12:26:56', NULL, 28),
	(69, 49, '2026-06-30', NULL, 0.00, 20.00, 20, 1, 1, '2026-06-16 12:53:49', '2026-06-16 12:56:23', NULL, 29),
	(70, 50, '2026-06-30', '2026-06-16', 0.00, 10.00, 10, 1, 1, '2026-06-16 12:55:37', '2026-06-16 12:56:57', NULL, 30),
	(71, 51, '2026-06-30', '2026-06-16', 0.00, 10.00, 10, 1, 1, '2026-06-16 13:37:26', '2026-06-16 13:45:09', NULL, 32),
	(72, 52, '2026-06-30', '2026-06-16', 0.00, 20.00, 20, 0, 1, '2026-06-16 13:42:05', '2026-06-18 13:02:15', NULL, NULL),
	(73, 53, '2026-06-30', '2026-06-16', 0.00, 30.00, 30, 0, 1, '2026-06-16 14:44:10', '2026-06-18 13:01:59', NULL, NULL),
	(74, 53, '2026-07-31', '2026-06-16', 30.00, 35.00, 5, 0, 1, '2026-06-16 14:53:10', '2026-06-18 13:01:59', NULL, NULL),
	(75, 53, '2026-08-31', '2026-06-16', 35.00, 40.00, 5, 0, 1, '2026-06-16 15:09:06', '2026-06-18 13:01:59', NULL, NULL),
	(76, 56, '2026-06-30', '2026-06-16', 0.00, 20.00, 20, 1, 1, '2026-06-16 17:37:06', '2026-06-16 17:41:30', NULL, 35),
	(77, 56, '2026-07-31', '2026-06-16', 20.00, 25.00, 5, 1, 1, '2026-06-16 17:38:12', '2026-06-16 17:41:30', NULL, 35),
	(78, 56, '2026-08-31', '2026-06-16', 25.00, 30.00, 5, 1, 1, '2026-06-16 17:43:12', '2026-06-16 17:46:09', NULL, 36),
	(79, 56, '2026-09-30', '2026-06-16', 30.00, 35.00, 5, 1, 1, '2026-06-16 17:45:02', '2026-06-16 17:46:09', NULL, 36),
	(80, 56, '2026-10-31', '2026-06-16', 35.00, 40.00, 5, 1, 1, '2026-06-16 17:45:47', '2026-06-16 17:46:09', NULL, 36),
	(81, 56, '2026-11-30', '2026-06-16', 40.00, 45.00, 5, 1, 1, '2026-06-16 18:01:57', '2026-06-16 18:02:14', NULL, 37),
	(82, 56, '2026-12-31', '2026-06-16', 45.00, 50.00, 5, 1, 1, '2026-06-16 18:11:22', '2026-06-16 18:12:29', NULL, 38),
	(83, 56, '2027-01-31', '2026-06-16', 50.00, 55.00, 5, 1, 1, '2026-06-16 18:15:22', '2026-06-16 18:15:40', NULL, 39),
	(84, 54, '2026-06-30', '2026-06-16', 0.00, 20.00, 20, 1, 1, '2026-06-16 18:16:49', '2026-06-16 18:17:11', NULL, 40),
	(85, 57, '2026-06-30', '2026-06-16', 0.00, 9.00, 9, 1, 1, '2026-06-16 19:37:50', '2026-06-16 19:39:03', NULL, 41),
	(86, 57, '2026-07-31', '2026-06-16', 9.00, 15.00, 6, 1, 1, '2026-06-16 19:56:45', '2026-06-16 19:56:45', NULL, 42),
	(87, 63, '2026-06-30', '2026-06-17', 0.00, 5.00, 5, 1, 1, '2026-06-17 13:51:02', '2026-06-17 13:51:02', NULL, 43),
	(88, 63, '2026-07-31', '2026-06-17', 5.00, 10.00, 5, 1, 1, '2026-06-17 13:53:40', '2026-06-17 13:53:40', NULL, 44),
	(89, 63, '2026-08-31', '2026-06-18', 10.00, 20.00, 10, 1, 1, '2026-06-18 10:58:23', '2026-06-18 10:58:23', NULL, 45),
	(90, 63, '2026-09-30', '2026-06-18', 20.00, 25.00, 5, 1, 1, '2026-06-18 11:02:10', '2026-06-18 11:02:10', NULL, 46),
	(91, 63, '2026-10-31', '2026-06-18', 25.00, 30.00, 5, 1, 1, '2026-06-18 11:08:31', '2026-06-18 11:08:31', NULL, 47),
	(92, 64, '2026-06-30', '2026-06-18', 0.00, 20.00, 20, 0, 1, '2026-06-18 11:13:46', '2026-06-18 12:43:54', NULL, NULL),
	(93, 62, '2026-06-30', '2026-06-18', 0.00, 10.00, 10, 1, 1, '2026-06-18 11:14:43', '2026-06-18 11:14:43', NULL, 49),
	(94, 64, '2026-07-31', '2026-06-18', 20.00, 23.00, 3, 0, 1, '2026-06-18 11:16:44', '2026-06-18 12:43:54', NULL, NULL),
	(95, 62, '2026-07-31', '2026-06-18', 10.00, 12.00, 2, 1, 1, '2026-06-18 11:17:37', '2026-06-18 11:17:37', NULL, 51),
	(96, 63, '2026-11-30', '2026-06-18', 30.00, 33.00, 3, 1, 1, '2026-06-18 11:58:33', '2026-06-18 11:58:33', NULL, 52),
	(97, 63, '2026-12-31', '2026-06-18', 33.00, 34.00, 1, 1, 1, '2026-06-18 12:16:27', '2026-06-18 12:16:27', NULL, 53),
	(98, 63, '2027-01-31', '2026-06-18', 34.00, 35.00, 1, 1, 1, '2026-06-18 13:16:26', '2026-06-18 13:16:26', NULL, 54),
	(99, 63, '2027-02-28', '2026-06-18', 35.00, 38.00, 3, 1, 1, '2026-06-18 13:24:45', '2026-06-18 13:24:45', NULL, 55),
	(100, 65, '2026-06-18', NULL, 0.00, 10.00, 10, 1, 1, '2026-06-18 15:17:23', '2026-06-18 15:17:23', NULL, 56),
	(101, 65, '2026-06-30', '2026-06-18', 10.00, 15.00, 5, 1, 1, '2026-06-18 15:21:19', '2026-06-18 15:21:19', NULL, 57),
	(102, 65, '2026-07-31', '2026-06-18', 15.00, 20.00, 5, 1, 1, '2026-06-18 15:23:39', '2026-06-18 15:23:39', NULL, 58),
	(103, 65, '2026-08-31', '2026-06-18', 20.00, 23.00, 3, 1, 1, '2026-06-18 15:54:34', '2026-06-18 15:54:34', NULL, 59),
	(104, 65, '2026-09-30', '2026-06-18', 23.00, 30.00, 7, 1, 1, '2026-06-18 16:12:11', '2026-06-18 16:12:11', NULL, 60),
	(105, 66, '2026-06-30', '2026-06-18', 0.00, 10.00, 10, 1, 1, '2026-06-18 16:28:39', '2026-06-18 16:28:39', NULL, 61),
	(106, 65, '2026-10-31', '2026-06-18', 30.00, 32.00, 2, 0, 1, '2026-06-18 16:39:25', '2026-06-18 16:41:45', NULL, NULL);

-- Dumping structure for table water_project.service_requests
CREATE TABLE IF NOT EXISTS `service_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_id` bigint unsigned NOT NULL,
  `service_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'water_tanker',
  `amount` decimal(12,2) NOT NULL,
  `service_date` date NOT NULL,
  `status` enum('pending','completed','invoiced','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_requests_subscriber_id_foreign` (`subscriber_id`),
  CONSTRAINT `service_requests_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.service_requests: ~1 rows (approximately)
DELETE FROM `service_requests`;

-- Dumping structure for table water_project.sessions
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.sessions: ~4 rows (approximately)
DELETE FROM `sessions`;
INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
	('tuauaRKwXv7SDF93NUNV5xkKIoCnsUWPTADuSH9H', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', 'eyJfdG9rZW4iOiIxa055VmdzNEZ5SXIwa3ZwRGJYMTRsRzRncjF6QXdONWJxN0VNVnRPIiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsInBhc3N3b3JkX2hhc2hfd2ViIjoiMzlmMzFkY2QzM2UyMDUyNjNjOTAzNjRiYTVjZTY0MjM4MGZlOWNlZThiZTcwY2M4Njc3YzIzMjc0YWU2NDBmNyIsInRhYmxlcyI6eyJhNmI4YTc4OTcyMmNmYjI4Y2RhZTdkZjRlNjUwZDZlYV9jb2x1bW5zIjpbeyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6Im1ldGVyLnVuaXF1ZV9jb2RlIiwibGFiZWwiOiJcdTA2MzFcdTA2NDJcdTA2NDUgXHUwNjI3XHUwNjQ0XHUwNjM5XHUwNjJmXHUwNjI3XHUwNjJmIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6Im1ldGVyLnN1YnNjcmliZXIubmFtZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjM0XHUwNjJhXHUwNjMxXHUwNjQzIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InJlYWRpbmdfZGF0ZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJhXHUwNjI3XHUwNjMxXHUwNjRhXHUwNjJlIFx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzOVx1MDYyYVx1MDY0NVx1MDYyZiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJhY3R1YWxfcmVhZGluZ19kYXRlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MmFcdTA2MjdcdTA2MzFcdTA2NGFcdTA2MmUgXHUwNjI3XHUwNjQ0XHUwNjQxXHUwNjM5XHUwNjQ0XHUwNjRhIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOmZhbHNlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6dHJ1ZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InByZXZpb3VzX3JlYWRpbmciLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYzM1x1MDYyN1x1MDYyOFx1MDY0Mlx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjdXJyZW50X3JlYWRpbmciLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyZFx1MDYyN1x1MDY0NFx1MDY0YVx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjb25zdW1wdGlvbl91bml0cyIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjI3XHUwNjMzXHUwNjJhXHUwNjQ3XHUwNjQ0XHUwNjI3XHUwNjQzIChcdTA2NDhcdTA2MmRcdTA2MmZcdTA2MjkpIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImlzX2ludm9pY2VkIiwibGFiZWwiOiJcdTA2NDVcdTA2NDFcdTA2NDhcdTA2MmFcdTA2MzFcdTA2MjkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfV0sImE2YjhhNzg5NzIyY2ZiMjhjZGFlN2RmNGU2NTBkNmVhX3Blcl9wYWdlIjoiNTAiLCIzN2U2ZTNmNjBhZGM4M2RjNTYyNWEwYmI1MjYxZTYyMl9jb2x1bW5zIjpbeyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImJpbGxfbnVtYmVyIiwibGFiZWwiOiJcdTA2MzFcdTA2NDJcdTA2NDUgXHUwNjI3XHUwNjQ0XHUwNjQxXHUwNjI3XHUwNjJhXHUwNjQ4XHUwNjMxXHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImJlbmVmaWNpYXJ5X25hbWUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzNFx1MDYyYVx1MDYzMVx1MDY0MyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJiaWxsX2RhdGUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyYVx1MDYyN1x1MDYzMVx1MDY0YVx1MDYyZSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjb25zdW1wdGlvbl9mZWUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyN1x1MDYzM1x1MDYyYVx1MDY0N1x1MDY0NFx1MDYyN1x1MDY0MyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJwcmV2aW91c19iYWxhbmNlIiwibGFiZWwiOiJcdTA2NDVcdTA2MmFcdTA2MjNcdTA2MmVcdTA2MzFcdTA2MjdcdTA2MmEgXHUwNjMzXHUwNjI3XHUwNjI4XHUwNjQyXHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpmYWxzZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InRvdGFsX2Ftb3VudCIsImxhYmVsIjoiXHUwNjQyXHUwNjRhXHUwNjQ1XHUwNjI5IFx1MDYyN1x1MDY0NFx1MDY0MVx1MDYyN1x1MDYyYVx1MDY0OFx1MDYzMVx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ0b3RhbF9kdWUiLCJsYWJlbCI6Ilx1MDYyNVx1MDYyY1x1MDY0NVx1MDYyN1x1MDY0NFx1MDY0YSBcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MzdcdTA2NDRcdTA2NDhcdTA2MjgiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYW1vdW50X3BhaWQiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzM1x1MDYyZlx1MDYyZiBcdTA2NDVcdTA2NDYgXHUwNjI3XHUwNjQ0XHUwNjQxXHUwNjI3XHUwNjJhXHUwNjQ4XHUwNjMxXHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InJlbWFpbmluZ19hbW91bnQiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0NVx1MDYyYVx1MDYyOFx1MDY0Mlx1MDY0YSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzdGF0dXMiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyZFx1MDYyN1x1MDY0NFx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiMzdlNmUzZjYwYWRjODNkYzU2MjVhMGJiNTI2MWU2MjJfcGVyX3BhZ2UiOiI1IiwiZDQ4Y2Q2ZDQxZGFmYTQ0ODNiY2U3NmYxYThkZmI5ZTBfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJyZWNlaXB0X251bWJlciIsImxhYmVsIjoiXHUwNjMxXHUwNjQyXHUwNjQ1IFx1MDYzM1x1MDY0Nlx1MDYyZiBcdTA2MjdcdTA2NDRcdTA2NDJcdTA2MjhcdTA2MzYiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoic3Vic2NyaWJlci5uYW1lIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MzRcdTA2MmFcdTA2MzFcdTA2NDMiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYW1vdW50IiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MjhcdTA2NDRcdTA2M2EiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoicGF5bWVudF9kYXRlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MmFcdTA2MjdcdTA2MzFcdTA2NGFcdTA2MmUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoicGF5bWVudF9tZXRob2QiLCJsYWJlbCI6Ilx1MDYzN1x1MDYzMVx1MDY0YVx1MDY0Mlx1MDYyOSBcdTA2MjdcdTA2NDRcdTA2MmZcdTA2NDFcdTA2MzkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiaW52b2ljZS5iaWxsX251bWJlciIsImxhYmVsIjoiXHUwNjMxXHUwNjQyXHUwNjQ1IFx1MDYyN1x1MDY0NFx1MDY0MVx1MDYyN1x1MDYyYVx1MDY0OFx1MDYzMVx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiZTgxNTFmOWFjNGJmNjM2OWE2YWE1NGJiNTE4YTYzMmNfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJpZCIsImxhYmVsIjoiIyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJuYW1lIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MjdcdTA2MzNcdTA2NDUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoic3Vic2NyaXB0aW9uX3N0YXJ0X2RhdGUiLCJsYWJlbCI6Ilx1MDYyOFx1MDYyZlx1MDYyN1x1MDY0YVx1MDYyOSBcdTA2MjdcdTA2NDRcdTA2MjdcdTA2MzRcdTA2MmFcdTA2MzFcdTA2MjdcdTA2NDMiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoicGhvbmUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0N1x1MDYyN1x1MDYyYVx1MDY0MSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzcXVhcmUubmFtZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjMxXHUwNjI4XHUwNjM5IFx1MDYyN1x1MDY0NFx1MDYzM1x1MDY0M1x1MDY0Nlx1MDY0YSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJiYWxhbmNlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MzFcdTA2MzVcdTA2NGFcdTA2MmYgXHUwNjI3XHUwNjQ0XHUwNjJmXHUwNjI3XHUwNjI2XHUwNjQ2IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN0YXR1cyIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJkXHUwNjI3XHUwNjQ0XHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6Im1ldGVyc19jb3VudCIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjM5XHUwNjJmXHUwNjI3XHUwNjJmXHUwNjI3XHUwNjJhIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH1dLCI2YTQwNThiOThhZDU0OWY4ODUyNjU1ZTg5NmNiN2RlYl9jb2x1bW5zIjpbeyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImNyZWF0ZWRfYXQiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyYVx1MDYyN1x1MDYzMVx1MDY0YVx1MDYyZSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ0eXBlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDZcdTA2NDhcdTA2MzkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYW1vdW50IiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MjhcdTA2NDRcdTA2M2EiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiZGVzY3JpcHRpb24iLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyOFx1MDY0YVx1MDYyN1x1MDY0NiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiYTc2YWZhM2MxNDY3ZTcyOWI1NWI1N2JiOGI5NzIwNjBfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1bmlxdWVfY29kZSIsImxhYmVsIjoiXHUwNjMxXHUwNjQyXHUwNjQ1IFx1MDYyN1x1MDY0NFx1MDYzOVx1MDYyZlx1MDYyN1x1MDYyZiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ0ZW5hbnRfbmFtZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjMzXHUwNjJhXHUwNjIzXHUwNjJjXHUwNjMxIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpmYWxzZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN0YXR1cyIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJkXHUwNjI3XHUwNjQ0XHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InJlYWRpbmdzX2NvdW50IiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDJcdTA2MzFcdTA2MjdcdTA2MjFcdTA2MjdcdTA2MmEiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfV0sIjZhNDA1OGI5OGFkNTQ5Zjg4NTI2NTVlODk2Y2I3ZGViX3Blcl9wYWdlIjoiMjUiLCIyMjA2YjI4MTcwOTJlYTdkYWJiYzY2ZmFiMzEwOWVlOF9jb2x1bW5zIjpbeyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6Im5hbWUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyN1x1MDYzM1x1MDY0NSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1bml0X3ByaWNlIiwibGFiZWwiOiJcdTA2MzNcdTA2MzlcdTA2MzEgXHUwNjI3XHUwNjQ0XHUwNjQ4XHUwNjJkXHUwNjJmXHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImlzX2FjdGl2ZSIsImxhYmVsIjoiXHUwNjQ2XHUwNjM0XHUwNjM3IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN0YXJ0X2RhdGUiLCJsYWJlbCI6Ilx1MDYyYVx1MDYyN1x1MDYzMVx1MDY0YVx1MDYyZSBcdTA2MjdcdTA2NDRcdTA2MjhcdTA2MmZcdTA2MjEiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiZW5kX2RhdGUiLCJsYWJlbCI6Ilx1MDYyYVx1MDYyN1x1MDYzMVx1MDY0YVx1MDYyZSBcdTA2MjdcdTA2NDRcdTA2MjdcdTA2NDZcdTA2MmFcdTA2NDdcdTA2MjdcdTA2MjEiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiY3JlYXRlZF9hdCIsImxhYmVsIjoiQ3JlYXRlZCBhdCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjpmYWxzZSwiaXNUb2dnbGVhYmxlIjp0cnVlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOnRydWV9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1cGRhdGVkX2F0IiwibGFiZWwiOiJVcGRhdGVkIGF0IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOmZhbHNlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6dHJ1ZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImRlbGV0ZWRfYXQiLCJsYWJlbCI6IkRlbGV0ZWQgYXQiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6ZmFsc2UsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0Ijp0cnVlfV0sImQ2NDMwYzVlYzhjYTMzNzNlN2YwNGE3NjIxOTU3Yzg2X2NvbHVtbnMiOlt7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoibGFiZWwiLCJsYWJlbCI6Ilx1MDYyN1x1MDYzM1x1MDY0NSBcdTA2MjdcdTA2NDRcdTA2MjVcdTA2MzlcdTA2MmZcdTA2MjdcdTA2MmYiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoidHlwZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ2XHUwNjQ4XHUwNjM5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InZhbHVlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDJcdTA2NGFcdTA2NDVcdTA2MjkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiaW1hZ2VfcGF0aCIsImxhYmVsIjoiXHUwNjQ1XHUwNjM5XHUwNjI3XHUwNjRhXHUwNjQ2XHUwNjI5IFx1MDYyN1x1MDY0NFx1MDYzNVx1MDY0OFx1MDYzMVx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjcmVhdGVkX2F0IiwibGFiZWwiOiJDcmVhdGVkIGF0IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOmZhbHNlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6dHJ1ZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InVwZGF0ZWRfYXQiLCJsYWJlbCI6IlVwZGF0ZWQgYXQiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6ZmFsc2UsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0Ijp0cnVlfV0sIjE2NDZlZWY1NTQ4ZDUyMjBlMzcwODhjOTBiNzI5ZGQ0X2NvbHVtbnMiOlt7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiY3JlYXRlZF9hdCIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJhXHUwNjI3XHUwNjMxXHUwNjRhXHUwNjJlIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN1YnNjcmliZXIubmFtZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjM0XHUwNjJhXHUwNjMxXHUwNjQzIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InR5cGUiLCJsYWJlbCI6Ilx1MDY0Nlx1MDY0OFx1MDYzOSBcdTA2MjdcdTA2NDRcdTA2MmRcdTA2MzFcdTA2NDNcdTA2MjkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYW1vdW50IiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MjhcdTA2NDRcdTA2M2EiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiZGVzY3JpcHRpb24iLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyOFx1MDY0YVx1MDYyN1x1MDY0NiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJyZWZlcmVuY2VfdHlwZSIsImxhYmVsIjoiXHUwNjQ2XHUwNjQ4XHUwNjM5IFx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzMVx1MDYyY1x1MDYzOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjpmYWxzZSwiaXNUb2dnbGVhYmxlIjp0cnVlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOnRydWV9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJyZWZlcmVuY2VfaWQiLCJsYWJlbCI6Ilx1MDYzMVx1MDY0Mlx1MDY0NSBcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MzFcdTA2MmNcdTA2MzkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6ZmFsc2UsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0Ijp0cnVlfV19LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cDpcL1wvd2F0ZXJfcHJvamVjdC50ZXN0XC9hZG1pblwvcGF5bWVudHMiLCJyb3V0ZSI6ImZpbGFtZW50LmFkbWluLnJlc291cmNlcy5wYXltZW50cy5pbmRleCJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX0sImZpbGFtZW50IjpbXX0=', 1781812034);

-- Dumping structure for table water_project.settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `value` text COLLATE utf8mb4_unicode_ci,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وصف الإعداد',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.settings: ~15 rows (approximately)
DELETE FROM `settings`;
INSERT INTO `settings` (`id`, `key`, `type`, `value`, `image_path`, `label`, `created_at`, `updated_at`) VALUES
	(1, 'reading_period', 'text', 'monthly', NULL, 'دورة القراءات', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(2, 'allowed_days_before_period_end', 'text', '4', NULL, 'أيام مسموحة قبل', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(3, 'allowed_days_after_period_end', 'text', '4', NULL, 'أيام مسموحة بعد', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(4, 'unit_price', 'text', '600', NULL, 'سعر الوحدة (ريال)', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(5, 'currency', 'text', 'YER', NULL, 'العملة', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(6, 'subscription_fee', 'text', '0', NULL, 'رسوم الاشتراك', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
	(7, 'invoice_project_name_ar', 'text', 'مشروع مياه الوشاح', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(8, 'invoice_address_ar', 'text', 'حارة الفوارس – سعوان', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(9, 'invoice_phone_1', 'text', '712271220', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(10, 'invoice_phone_2', 'text', '777760193', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(11, 'invoice_project_name_en', 'text', 'Washah Water Project', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(12, 'invoice_address_en', 'text', 'Harat Al-Fawaris - Sa\'wan', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(13, 'invoice_collector_name', 'text', 'علي الوشاح', NULL, 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:13:42'),
	(14, 'invoice_logo', 'image', 'settings/invoices/01KTQ831TNG3BR0ZM4ANQPEGZ5.png', 'settings/01KTEMRP46MC0QFYDF991GCDP5.png', 'إعدادات الفاتورة', '2026-06-09 00:22:03', '2026-06-09 19:29:44'),
	(15, 'invoice_watermark', 'text', 'settings/invoices/01KTQ75NQP7F7SZRRTEQKN1YEP.png', NULL, 'إعدادات الفاتورة', '2026-06-09 19:13:42', '2026-06-09 19:13:42'),
	(16, 'auto_issue_invoice_on_reading', 'text', '1', NULL, 'إصدار الفاتورة تلقائياً عند إدخال القراءة', '2026-06-16 19:51:51', '2026-06-16 19:51:51'),
	(17, 'grace_period', 'text', '14', NULL, 'مدة السماح (أيام)', '2026-06-18 15:05:08', '2026-06-18 15:05:08'),
	(18, 'strict_overdue_on_new_invoice', 'text', '1', NULL, 'اعتبار الفاتورة متأخرة عند الإصدار الجديد', '2026-06-18 15:05:08', '2026-06-18 15:05:08');

-- Dumping structure for table water_project.squares
CREATE TABLE IF NOT EXISTS `squares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.squares: ~2 rows (approximately)
DELETE FROM `squares`;
INSERT INTO `squares` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
	(1, 'المربع الأول (A)', NULL, '2026-06-11 17:16:22', '2026-06-11 17:16:22'),
	(2, 'المربع الثاني (B)', NULL, '2026-06-11 17:16:22', '2026-06-11 17:16:22');

-- Dumping structure for table water_project.subscribers
CREATE TABLE IF NOT EXISTS `subscribers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `square_id` bigint unsigned NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رصيد دائن',
  `initial_subscription_fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رسوم الاشتراك الكلية',
  `subscription_fee_paid` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'المسدد من رسوم الاشتراك',
  `is_subscription_fee_fully_paid` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `subscription_start_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscribers_square_id_foreign` (`square_id`),
  CONSTRAINT `subscribers_square_id_foreign` FOREIGN KEY (`square_id`) REFERENCES `squares` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.subscribers: ~50 rows (approximately)
DELETE FROM `subscribers`;
INSERT INTO `subscribers` (`id`, `name`, `phone`, `address`, `square_id`, `balance`, `initial_subscription_fee`, `subscription_fee_paid`, `is_subscription_fee_fully_paid`, `status`, `subscription_start_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
	(1, 'عزام عبدالله سعد صلاح', '+967777742777', NULL, 1, 0.00, 0.00, 0.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(2, 'علي حسن غازي فتح الله', '+967770005153', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(3, 'علي غالب نار الحرازي', '+967772160888', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(4, 'عبدالحميد سنان الحميري', '+967773709917', NULL, 1, -7700.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(5, 'عبدالعزيز عبدالله عون الذبحاني', '+967772336666', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(6, 'محمد عوض المصباحي', '+967777762528', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(7, 'احمد عبدالله ناجي العلوي', '+967771014373', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(8, 'سلطان ناصر مبخوت الدياعي', '+967773004004', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(9, 'امين احمد كليب الذبحاني', '+967777947949', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(10, 'قابوس سعيد محمد المخلافي', '+967777719047', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(11, 'محمد علي احمد فارع الحبيشي', '+967777000740', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(12, 'العزي محمد المنصوبي المتاجرين', '+967777298213', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(13, 'العزي محمد المنصوبي البيت', '+967777298213', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(14, 'فيصل يوسف علي الجنش', '+967777274005', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(15, 'صالح احمد سعد المطري', '+967771943862', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(16, 'يحيى صالح محمد القليسي', '+967777511300', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(17, 'علي محمد النهاري', '+967777789603', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(18, 'عبدالرحمن الحطامي الصغير', '+967773144480', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(19, 'عبدالرحمن الحطامي الكبير', '+967773144480', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(20, 'خاليد علي يحيى الحرازي', '+967777119160', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(21, 'حسن العقبي', '+967777105536', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(22, 'محمد مقبل سعيد العمري', '+967777122232', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(23, 'عادل قايدالزبيدي', '56383240', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 22:04:49', NULL),
	(24, 'اسماء معصار', '+967770836413', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(25, 'مهيوب عبدالله سعيد القباطي', NULL, NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-09 00:22:03', NULL),
	(26, 'قاسم علي عبده قاسم', '+967773291417', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(27, 'صادق عبدالله محمد العماد', '+967772900280', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(28, 'علي ابن علي غلاب', '+967772218733', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(29, 'ابن القاضي', '+967777894389', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(30, 'بلال محمد علي الفقية', '+967777788247', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(31, 'محمد محمد سعد الريمي', '+967777482171', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(32, 'طه محمد يحيى الخاوي', '+967771640646', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(33, 'يحيى حسن علي صالح', '+967770595100', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(34, 'عبدالكريم حسن علي صباح', '+967777198284', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(35, 'سامي انور عقلان المرفدي', '+967777133007', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(36, 'عبدالسلام الفقية', '+967773418906', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(37, 'محمد علي حسين زايد', '+967774502345', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(38, 'طلال محمد احمد يوسف', '+967779024309', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(39, 'منصور محمد احمد يوسف', '+967774889125', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(40, 'صالح محمد احمد يوسف', '+967775682221', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(41, 'عبدالرحيم احمد علي زيلم', '+967777236596', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(42, 'احمد احمد الشتيوي', '+967777291179', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(43, 'احسن احسن احسن حسان', '+967777488791', NULL, 2, 8400.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-06-16 21:36:18', NULL),
	(44, 'فيصل', '85429', 'الفوارس', 2, -13800.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-15 21:17:41', '2026-06-15 21:40:40', NULL),
	(45, 'ردفان', '+96778984312', 'يبليبلب', 1, -18000.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-16 12:20:42', '2026-06-16 21:36:18', NULL),
	(46, 'فراص', '79875', NULL, 1, -12000.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-16 12:52:18', '2026-06-16 13:07:21', NULL),
	(47, 'مبخوت', '2323', NULL, 1, -24000.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-16 13:25:18', '2026-06-16 15:10:06', NULL),
	(48, 'محمود', '789', NULL, 2, 29000.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-16 17:29:47', '2026-06-16 18:17:11', NULL),
	(49, 'd', '+9674352312', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-06-16', '2026-06-16 18:46:32', '2026-06-16 21:36:18', NULL),
	(50, 'ش', '+967784040246', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-06-17', '2026-06-16 21:14:27', '2026-06-16 22:00:57', NULL),
	(51, '55', '+96771300000', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-06-17', '2026-06-17 13:16:18', '2026-06-17 13:16:18', NULL),
	(52, 'ايوب', '+967701348486', 'نيبت', 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-18', '2026-06-18 15:16:16', '2026-06-18 15:16:16', NULL);

-- Dumping structure for table water_project.tariffs
CREATE TABLE IF NOT EXISTS `tariffs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.tariffs: ~1 rows (approximately)
DELETE FROM `tariffs`;
INSERT INTO `tariffs` (`id`, `name`, `unit_price`, `is_active`, `start_date`, `end_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
	(1, 'التسعيرة الافتراضية', 600.00, 1, '2026-05-01', NULL, '2026-06-11 17:16:22', '2026-06-15 21:34:24', NULL);

-- Dumping structure for table water_project.transactions
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `meter_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transactions_subscriber_id_foreign` (`subscriber_id`),
  KEY `transactions_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  KEY `transactions_meter_id_foreign` (`meter_id`),
  CONSTRAINT `transactions_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.transactions: ~50 rows (approximately)
DELETE FROM `transactions`;
INSERT INTO `transactions` (`id`, `subscriber_id`, `type`, `amount`, `description`, `reference_type`, `reference_id`, `created_at`, `updated_at`, `deleted_at`, `meter_id`) VALUES
	(7, 1, 'invoice', -15400.00, 'إصدار فاتورة رقم INV-202606-0001', 'App\\Models\\Invoice', 9, '2026-06-11 21:51:54', '2026-06-11 21:51:54', NULL, NULL),
	(8, 1, 'invoice', -2100.00, 'إصدار فاتورة رقم INV-202606-0002', 'App\\Models\\Invoice', 10, '2026-06-11 22:11:01', '2026-06-11 22:11:01', NULL, NULL),
	(9, 2, 'invoice', -22400.00, 'إصدار فاتورة رقم INV-202606-0003', 'App\\Models\\Invoice', 11, '2026-06-11 22:14:37', '2026-06-11 22:14:37', NULL, NULL),
	(10, 2, 'payment', 22400.00, 'سند قبض رقم REC-202606-0001', 'App\\Models\\Payment', 1, '2026-06-11 22:15:28', '2026-06-11 22:15:28', NULL, NULL),
	(11, 2, 'invoice', -5600.00, 'إصدار فاتورة رقم INV-202606-0004', 'App\\Models\\Invoice', 12, '2026-06-11 22:16:16', '2026-06-11 22:16:16', NULL, NULL),
	(12, 2, 'payment', 5600.00, 'سند قبض رقم REC-202606-0002', 'App\\Models\\Payment', 2, '2026-06-11 22:20:16', '2026-06-11 22:20:16', NULL, NULL),
	(13, 43, 'invoice', -2100.00, 'إصدار فاتورة رقم INV-202606-0005', 'App\\Models\\Invoice', 13, '2026-06-11 22:28:05', '2026-06-11 22:28:05', NULL, NULL),
	(14, 43, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0006', 'App\\Models\\Invoice', 14, '2026-06-11 22:30:02', '2026-06-11 22:30:02', NULL, NULL),
	(15, 43, 'payment', 10000.00, 'سند قبض رقم REC-202606-0003', 'App\\Models\\Payment', 3, '2026-06-11 22:34:38', '2026-06-11 22:34:38', NULL, NULL),
	(16, 43, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0007', 'App\\Models\\Invoice', 15, '2026-06-11 22:38:00', '2026-06-11 22:38:00', NULL, NULL),
	(17, 43, 'payment', 7500.00, 'سند قبض رقم REC-202606-0004', 'App\\Models\\Payment', 4, '2026-06-11 22:41:56', '2026-06-11 22:41:56', NULL, NULL),
	(18, 1, 'payment', 17500.00, 'سند قبض رقم REC-202606-0005', 'App\\Models\\Payment', 5, '2026-06-11 23:17:55', '2026-06-11 23:17:55', NULL, NULL),
	(19, 4, 'invoice', -10500.00, 'إصدار فاتورة رقم INV-202606-0008', 'App\\Models\\Invoice', 16, '2026-06-11 23:19:22', '2026-06-11 23:19:22', NULL, NULL),
	(20, 4, 'payment', 10000.00, 'سند قبض رقم REC-202606-0006', 'App\\Models\\Payment', 6, '2026-06-11 23:19:46', '2026-06-11 23:19:46', NULL, NULL),
	(21, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0009', 'App\\Models\\Invoice', 17, '2026-06-11 23:20:40', '2026-06-11 23:20:40', NULL, NULL),
	(22, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0010', 'App\\Models\\Invoice', 18, '2026-06-11 23:22:38', '2026-06-11 23:22:38', NULL, NULL),
	(23, 4, 'payment', 500.00, 'سند قبض رقم REC-202606-0007', 'App\\Models\\Payment', 7, '2026-06-11 23:23:08', '2026-06-11 23:23:08', NULL, NULL),
	(24, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0011', 'App\\Models\\Invoice', 19, '2026-06-11 23:24:04', '2026-06-11 23:24:04', NULL, NULL),
	(25, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0012', 'App\\Models\\Invoice', 20, '2026-06-11 23:25:23', '2026-06-11 23:25:23', NULL, NULL),
	(26, 4, 'payment', 14500.00, 'سند قبض رقم REC-202606-0008', 'App\\Models\\Payment', 8, '2026-06-11 23:26:27', '2026-06-11 23:26:27', NULL, NULL),
	(27, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0013', 'App\\Models\\Invoice', 21, '2026-06-11 23:28:02', '2026-06-11 23:28:02', NULL, NULL),
	(28, 4, 'payment', 23000.00, 'سند قبض رقم REC-202606-0009', 'App\\Models\\Payment', 9, '2026-06-11 23:28:52', '2026-06-11 23:28:52', NULL, NULL),
	(29, 4, 'invoice', -7000.00, 'إصدار فاتورة رقم INV-202606-0014', 'App\\Models\\Invoice', 22, '2026-06-11 23:29:50', '2026-06-11 23:29:50', NULL, NULL),
	(30, 4, 'invoice', -3500.00, 'إصدار فاتورة رقم INV-202606-0015', 'App\\Models\\Invoice', 23, '2026-06-11 23:30:47', '2026-06-11 23:30:47', NULL, NULL),
	(31, 4, 'invoice', -17500.00, 'إصدار فاتورة رقم INV-202606-0016', 'App\\Models\\Invoice', 24, '2026-06-11 23:32:11', '2026-06-11 23:32:11', NULL, NULL),
	(32, 4, 'payment', 300.00, 'سند قبض رقم REC-202606-0010', 'App\\Models\\Payment', 10, '2026-06-11 23:33:07', '2026-06-11 23:33:07', NULL, NULL),
	(33, 44, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202606-0017', 'App\\Models\\Invoice', 25, '2026-06-15 21:36:33', '2026-06-15 21:36:33', NULL, NULL),
	(34, 44, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0018', 'App\\Models\\Invoice', 26, '2026-06-15 21:40:40', '2026-06-15 21:40:40', NULL, NULL),
	(35, 45, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0019', 'App\\Models\\Invoice', 27, '2026-06-16 12:26:03', '2026-06-16 12:26:03', NULL, NULL),
	(36, 45, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202606-0020', 'App\\Models\\Invoice', 28, '2026-06-16 12:26:56', '2026-06-16 12:26:56', NULL, NULL),
	(37, 46, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202606-0021', 'App\\Models\\Invoice', 29, '2026-06-16 12:56:23', '2026-06-16 12:56:23', NULL, NULL),
	(38, 46, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0022', 'App\\Models\\Invoice', 30, '2026-06-16 12:56:57', '2026-06-16 12:56:57', NULL, NULL),
	(39, 46, 'payment', 6000.00, 'سند قبض رقم REC-202606-0011', 'App\\Models\\Payment', 11, '2026-06-16 13:07:21', '2026-06-16 13:07:21', NULL, NULL),
	(40, 47, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202606-0023', 'App\\Models\\Invoice', 31, '2026-06-16 13:43:03', '2026-06-16 13:43:03', NULL, NULL),
	(41, 47, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0024', 'App\\Models\\Invoice', 32, '2026-06-16 13:45:09', '2026-06-16 13:45:09', NULL, NULL),
	(42, 47, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0025', 'App\\Models\\Invoice', 33, '2026-06-16 14:55:31', '2026-06-16 14:55:31', NULL, NULL),
	(43, 47, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0026', 'App\\Models\\Invoice', 34, '2026-06-16 15:10:06', '2026-06-16 15:10:06', NULL, NULL),
	(44, 48, 'invoice', -15000.00, 'إصدار فاتورة رقم INV-202606-0027', 'App\\Models\\Invoice', 35, '2026-06-16 17:41:31', '2026-06-16 17:41:31', NULL, NULL),
	(45, 48, 'invoice', -9000.00, 'إصدار فاتورة رقم INV-202606-0028', 'App\\Models\\Invoice', 36, '2026-06-16 17:46:09', '2026-06-16 17:46:09', NULL, NULL),
	(46, 48, 'payment', 10000.00, 'سند قبض رقم REC-202606-0012', 'App\\Models\\Payment', 12, '2026-06-16 17:52:07', '2026-06-16 17:52:07', NULL, NULL),
	(47, 48, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0029', 'App\\Models\\Invoice', 37, '2026-06-16 18:02:14', '2026-06-16 18:02:14', NULL, NULL),
	(48, 48, 'payment', 14000.00, 'سند قبض رقم REC-202606-0013', 'App\\Models\\Payment', 13, '2026-06-16 18:10:08', '2026-06-16 18:10:08', NULL, NULL),
	(49, 48, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0030', 'App\\Models\\Invoice', 38, '2026-06-16 18:12:29', '2026-06-16 18:12:29', NULL, NULL),
	(50, 48, 'payment', 50000.00, 'سند قبض رقم REC-202606-0014', 'App\\Models\\Payment', 14, '2026-06-16 18:13:41', '2026-06-16 18:13:41', NULL, NULL),
	(51, 48, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0031', 'App\\Models\\Invoice', 39, '2026-06-16 18:15:40', '2026-06-16 18:15:40', NULL, NULL),
	(52, 48, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202606-0032', 'App\\Models\\Invoice', 40, '2026-06-16 18:17:11', '2026-06-16 18:17:11', NULL, NULL),
	(53, 49, 'invoice', -5400.00, 'إصدار فاتورة رقم INV-202606-0033', 'App\\Models\\Invoice', 41, '2026-06-16 19:39:03', '2026-06-16 19:39:03', NULL, 57),
	(54, 49, 'invoice', -3600.00, 'إصدار فاتورة رقم INV-202606-0034', 'App\\Models\\Invoice', 42, '2026-06-16 19:56:45', '2026-06-16 19:56:45', NULL, 57),
	(55, 49, 'payment', 5400.00, 'سند قبض رقم REC-202606-0015', 'App\\Models\\Payment', 15, '2026-06-16 20:06:05', '2026-06-16 20:06:05', NULL, 57),
	(56, 51, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0035', 'App\\Models\\Invoice', 43, '2026-06-17 13:51:02', '2026-06-17 13:51:02', NULL, 63),
	(57, 51, 'payment', 2000.00, 'سند قبض رقم REC-202606-0016', 'App\\Models\\Payment', 16, '2026-06-17 13:52:47', '2026-06-17 13:52:47', NULL, 63),
	(58, 51, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0036', 'App\\Models\\Invoice', 44, '2026-06-17 13:53:40', '2026-06-17 13:53:40', NULL, 63),
	(59, 51, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0037', 'App\\Models\\Invoice', 45, '2026-06-18 10:58:24', '2026-06-18 10:58:24', NULL, 63),
	(60, 51, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0038', 'App\\Models\\Invoice', 46, '2026-06-18 11:02:10', '2026-06-18 11:02:10', NULL, 63),
	(61, 51, 'payment', 2000.00, 'سند قبض رقم REC-202606-0017', 'App\\Models\\Payment', 17, '2026-06-18 11:03:31', '2026-06-18 11:03:31', NULL, 63),
	(62, 51, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0039', 'App\\Models\\Invoice', 47, '2026-06-18 11:08:31', '2026-06-18 11:08:31', NULL, 63),
	(63, 51, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202606-0040', 'App\\Models\\Invoice', 48, '2026-06-18 11:13:46', '2026-06-18 11:13:46', NULL, 64),
	(64, 51, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0041', 'App\\Models\\Invoice', 49, '2026-06-18 11:14:43', '2026-06-18 11:14:43', NULL, 62),
	(65, 51, 'invoice', -1800.00, 'إصدار فاتورة رقم INV-202606-0042', 'App\\Models\\Invoice', 50, '2026-06-18 11:16:44', '2026-06-18 11:16:44', NULL, 64),
	(66, 51, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202606-0043', 'App\\Models\\Invoice', 51, '2026-06-18 11:17:37', '2026-06-18 11:17:37', NULL, 62),
	(67, 51, 'payment', 7000.00, 'سند قبض رقم REC-202606-0018', 'App\\Models\\Payment', 18, '2026-06-18 11:21:53', '2026-06-18 11:21:53', NULL, 62),
	(68, 51, 'invoice', -1800.00, 'إصدار فاتورة رقم INV-202606-0044', 'App\\Models\\Invoice', 52, '2026-06-18 11:58:34', '2026-06-18 11:58:34', NULL, 63),
	(69, 51, 'payment', 10000.00, 'سند قبض رقم REC-202606-0019', 'App\\Models\\Payment', 19, '2026-06-18 12:03:01', '2026-06-18 12:03:01', NULL, 63),
	(70, 51, 'payment', 800.00, 'سند قبض رقم REC-202606-0020', 'App\\Models\\Payment', 20, '2026-06-18 12:12:34', '2026-06-18 12:12:34', NULL, 63),
	(71, 51, 'invoice', -600.00, 'إصدار فاتورة رقم INV-202606-0045', 'App\\Models\\Invoice', 53, '2026-06-18 12:16:27', '2026-06-18 12:16:27', NULL, 63),
	(72, 51, 'payment', 600.00, 'سند قبض رقم REC-202606-0021', 'App\\Models\\Payment', 21, '2026-06-18 12:44:58', '2026-06-18 12:44:58', NULL, 63),
	(73, 51, 'invoice', -600.00, 'إصدار فاتورة رقم INV-202606-0046', 'App\\Models\\Invoice', 54, '2026-06-18 13:16:26', '2026-06-18 13:16:26', NULL, 63),
	(74, 51, 'payment', 800.00, 'سند قبض رقم REC-202606-0022', 'App\\Models\\Payment', 22, '2026-06-18 13:17:42', '2026-06-18 13:17:42', NULL, 63),
	(75, 51, 'invoice', -1800.00, 'إصدار فاتورة رقم INV-202606-0047', 'App\\Models\\Invoice', 55, '2026-06-18 13:24:45', '2026-06-18 13:24:45', NULL, 63),
	(76, 51, 'payment', 1800.00, 'سند قبض رقم REC-202606-0023', 'App\\Models\\Payment', 23, '2026-06-18 13:26:08', '2026-06-18 13:26:08', NULL, 63),
	(77, 51, 'payment', 2000.00, 'سند قبض رقم REC-202606-0024', 'App\\Models\\Payment', 24, '2026-06-18 14:33:41', '2026-06-18 14:33:41', NULL, 63),
	(78, 51, 'payment', 1000.00, 'سند قبض رقم REC-202606-0025', 'App\\Models\\Payment', 25, '2026-06-18 14:34:11', '2026-06-18 14:34:11', NULL, 63),
	(79, 51, 'payment', 200.00, 'سند قبض رقم REC-202606-0026', 'App\\Models\\Payment', 26, '2026-06-18 15:14:10', '2026-06-18 15:14:10', NULL, 63),
	(80, 52, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0048', 'App\\Models\\Invoice', 56, '2026-06-18 15:17:23', '2026-06-18 15:17:23', NULL, 65),
	(81, 52, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0049', 'App\\Models\\Invoice', 57, '2026-06-18 15:21:19', '2026-06-18 15:21:19', NULL, 65),
	(82, 52, 'payment', 4000.00, 'سند قبض رقم REC-202606-0027', 'App\\Models\\Payment', 27, '2026-06-18 15:22:53', '2026-06-18 15:22:53', NULL, 65),
	(83, 52, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202606-0050', 'App\\Models\\Invoice', 58, '2026-06-18 15:23:39', '2026-06-18 15:23:39', NULL, 65),
	(84, 52, 'invoice', -1800.00, 'إصدار فاتورة رقم INV-202606-0051', 'App\\Models\\Invoice', 59, '2026-06-18 15:54:34', '2026-06-18 15:54:34', NULL, 65),
	(85, 52, 'payment', 9000.00, 'سند قبض رقم REC-202606-0028', 'App\\Models\\Payment', 28, '2026-06-18 15:56:31', '2026-06-18 15:56:31', NULL, 65),
	(86, 52, 'payment', 500.00, 'سند قبض رقم REC-202606-0029', 'App\\Models\\Payment', 29, '2026-06-18 16:11:05', '2026-06-18 16:11:05', NULL, 65),
	(87, 52, 'invoice', -4200.00, 'إصدار فاتورة رقم INV-202606-0052', 'App\\Models\\Invoice', 60, '2026-06-18 16:12:11', '2026-06-18 16:12:11', NULL, 65),
	(88, 52, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202606-0053', 'App\\Models\\Invoice', 61, '2026-06-18 16:28:39', '2026-06-18 16:28:39', NULL, 66),
	(89, 52, 'payment', 8000.00, 'سند قبض رقم REC-202606-0030', 'App\\Models\\Payment', 30, '2026-06-18 16:29:08', '2026-06-18 16:29:08', NULL, 66),
	(90, 52, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202606-0054', 'App\\Models\\Invoice', 62, '2026-06-18 16:39:25', '2026-06-18 16:39:25', NULL, 65);

-- Dumping structure for table water_project.users
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table water_project.users: ~1 rows (approximately)
DELETE FROM `users`;
INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `remember_token`, `created_at`, `updated_at`, `avatar`) VALUES
	(1, 'علي الوشاح', 'admin@water.test', NULL, '$2y$12$EYa2umeE3lnfHy5uiBbzPOHUnYJ7DNGt1/5W4tgKJ/L4bWgPCjXS.', 'admin', 'I7orltZUaRRpkBS4adYbhdL2UQeeEkPkabNKBPT08zBq9WgYcgQmmxUlmWpu', '2026-06-11 17:16:22', '2026-06-11 21:13:22', 'avatars/01KTWJT7X71QH0VD13TVV8YJSR.png');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
