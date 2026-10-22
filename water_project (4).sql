-- phpMyAdmin SQL Dump
-- version 6.0.0-dev+20260707.3e756d69dd
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 29, 2026 at 11:47 AM
-- Server version: 8.4.3
-- PHP Version: 8.4.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `water_project`
--

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('water-project-cache-spatie.permission.cache', 'a:3:{s:5:\"alias\";a:4:{s:1:\"a\";s:2:\"id\";s:1:\"b\";s:4:\"name\";s:1:\"c\";s:10:\"guard_name\";s:1:\"r\";s:5:\"roles\";}s:11:\"permissions\";a:166:{i:0;a:4:{s:1:\"a\";i:1;s:1:\"b\";s:15:\"ViewAny:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:1;a:4:{s:1:\"a\";i:2;s:1:\"b\";s:12:\"View:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:2;a:4:{s:1:\"a\";i:3;s:1:\"b\";s:14:\"Create:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:3;a:4:{s:1:\"a\";i:4;s:1:\"b\";s:14:\"Update:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:4;a:4:{s:1:\"a\";i:5;s:1:\"b\";s:14:\"Delete:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:5;a:4:{s:1:\"a\";i:6;s:1:\"b\";s:17:\"DeleteAny:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:6;a:4:{s:1:\"a\";i:7;s:1:\"b\";s:15:\"Restore:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:7;a:4:{s:1:\"a\";i:8;s:1:\"b\";s:19:\"ForceDelete:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:8;a:4:{s:1:\"a\";i:9;s:1:\"b\";s:22:\"ForceDeleteAny:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:9;a:4:{s:1:\"a\";i:10;s:1:\"b\";s:18:\"RestoreAny:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:10;a:4:{s:1:\"a\";i:11;s:1:\"b\";s:17:\"Replicate:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:11;a:4:{s:1:\"a\";i:12;s:1:\"b\";s:15:\"Reorder:Invoice\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:12;a:4:{s:1:\"a\";i:13;s:1:\"b\";s:13:\"ViewAny:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:5;}}i:13;a:4:{s:1:\"a\";i:14;s:1:\"b\";s:10:\"View:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:5;}}i:14;a:4:{s:1:\"a\";i:15;s:1:\"b\";s:12:\"Create:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:15;a:4:{s:1:\"a\";i:16;s:1:\"b\";s:12:\"Update:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:16;a:4:{s:1:\"a\";i:17;s:1:\"b\";s:12:\"Delete:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:17;a:4:{s:1:\"a\";i:18;s:1:\"b\";s:15:\"DeleteAny:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:18;a:4:{s:1:\"a\";i:19;s:1:\"b\";s:13:\"Restore:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:19;a:4:{s:1:\"a\";i:20;s:1:\"b\";s:17:\"ForceDelete:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:20;a:4:{s:1:\"a\";i:21;s:1:\"b\";s:20:\"ForceDeleteAny:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:21;a:4:{s:1:\"a\";i:22;s:1:\"b\";s:16:\"RestoreAny:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:22;a:4:{s:1:\"a\";i:23;s:1:\"b\";s:15:\"Replicate:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:23;a:4:{s:1:\"a\";i:24;s:1:\"b\";s:13:\"Reorder:Meter\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:24;a:4:{s:1:\"a\";i:25;s:1:\"b\";s:15:\"ViewAny:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:25;a:4:{s:1:\"a\";i:26;s:1:\"b\";s:12:\"View:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:26;a:4:{s:1:\"a\";i:27;s:1:\"b\";s:14:\"Create:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:27;a:4:{s:1:\"a\";i:28;s:1:\"b\";s:14:\"Update:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:3;}}i:28;a:4:{s:1:\"a\";i:29;s:1:\"b\";s:14:\"Delete:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:29;a:4:{s:1:\"a\";i:30;s:1:\"b\";s:17:\"DeleteAny:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:30;a:4:{s:1:\"a\";i:31;s:1:\"b\";s:15:\"Restore:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:31;a:4:{s:1:\"a\";i:32;s:1:\"b\";s:19:\"ForceDelete:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:32;a:4:{s:1:\"a\";i:33;s:1:\"b\";s:22:\"ForceDeleteAny:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:33;a:4:{s:1:\"a\";i:34;s:1:\"b\";s:18:\"RestoreAny:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:34;a:4:{s:1:\"a\";i:35;s:1:\"b\";s:17:\"Replicate:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:35;a:4:{s:1:\"a\";i:36;s:1:\"b\";s:15:\"Reorder:Payment\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:36;a:4:{s:1:\"a\";i:37;s:1:\"b\";s:15:\"ViewAny:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:3;i:2;i:5;}}i:37;a:4:{s:1:\"a\";i:38;s:1:\"b\";s:12:\"View:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:3;i:2;i:5;}}i:38;a:4:{s:1:\"a\";i:39;s:1:\"b\";s:14:\"Create:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:5;}}i:39;a:4:{s:1:\"a\";i:40;s:1:\"b\";s:14:\"Update:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:2:{i:0;i:1;i:1;i:5;}}i:40;a:4:{s:1:\"a\";i:41;s:1:\"b\";s:14:\"Delete:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:41;a:4:{s:1:\"a\";i:42;s:1:\"b\";s:17:\"DeleteAny:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:42;a:4:{s:1:\"a\";i:43;s:1:\"b\";s:15:\"Restore:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:43;a:4:{s:1:\"a\";i:44;s:1:\"b\";s:19:\"ForceDelete:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:44;a:4:{s:1:\"a\";i:45;s:1:\"b\";s:22:\"ForceDeleteAny:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:45;a:4:{s:1:\"a\";i:46;s:1:\"b\";s:18:\"RestoreAny:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:46;a:4:{s:1:\"a\";i:47;s:1:\"b\";s:17:\"Replicate:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:47;a:4:{s:1:\"a\";i:48;s:1:\"b\";s:15:\"Reorder:Reading\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:48;a:4:{s:1:\"a\";i:49;s:1:\"b\";s:22:\"ViewAny:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:49;a:4:{s:1:\"a\";i:50;s:1:\"b\";s:19:\"View:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:50;a:4:{s:1:\"a\";i:51;s:1:\"b\";s:21:\"Create:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:51;a:4:{s:1:\"a\";i:52;s:1:\"b\";s:21:\"Update:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:52;a:4:{s:1:\"a\";i:53;s:1:\"b\";s:21:\"Delete:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:53;a:4:{s:1:\"a\";i:54;s:1:\"b\";s:24:\"DeleteAny:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:54;a:4:{s:1:\"a\";i:55;s:1:\"b\";s:22:\"Restore:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:55;a:4:{s:1:\"a\";i:56;s:1:\"b\";s:26:\"ForceDelete:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:56;a:4:{s:1:\"a\";i:57;s:1:\"b\";s:29:\"ForceDeleteAny:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:57;a:4:{s:1:\"a\";i:58;s:1:\"b\";s:25:\"RestoreAny:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:58;a:4:{s:1:\"a\";i:59;s:1:\"b\";s:24:\"Replicate:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:59;a:4:{s:1:\"a\";i:60;s:1:\"b\";s:22:\"Reorder:ServiceRequest\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:60;a:4:{s:1:\"a\";i:61;s:1:\"b\";s:15:\"ViewAny:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:61;a:4:{s:1:\"a\";i:62;s:1:\"b\";s:12:\"View:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:62;a:4:{s:1:\"a\";i:63;s:1:\"b\";s:14:\"Create:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:63;a:4:{s:1:\"a\";i:64;s:1:\"b\";s:14:\"Update:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:64;a:4:{s:1:\"a\";i:65;s:1:\"b\";s:14:\"Delete:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:65;a:4:{s:1:\"a\";i:66;s:1:\"b\";s:17:\"DeleteAny:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:66;a:4:{s:1:\"a\";i:67;s:1:\"b\";s:15:\"Restore:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:67;a:4:{s:1:\"a\";i:68;s:1:\"b\";s:19:\"ForceDelete:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:68;a:4:{s:1:\"a\";i:69;s:1:\"b\";s:22:\"ForceDeleteAny:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:69;a:4:{s:1:\"a\";i:70;s:1:\"b\";s:18:\"RestoreAny:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:70;a:4:{s:1:\"a\";i:71;s:1:\"b\";s:17:\"Replicate:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:71;a:4:{s:1:\"a\";i:72;s:1:\"b\";s:15:\"Reorder:Setting\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:72;a:4:{s:1:\"a\";i:73;s:1:\"b\";s:14:\"ViewAny:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:73;a:4:{s:1:\"a\";i:74;s:1:\"b\";s:11:\"View:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:74;a:4:{s:1:\"a\";i:75;s:1:\"b\";s:13:\"Create:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:75;a:4:{s:1:\"a\";i:76;s:1:\"b\";s:13:\"Update:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:76;a:4:{s:1:\"a\";i:77;s:1:\"b\";s:13:\"Delete:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:77;a:4:{s:1:\"a\";i:78;s:1:\"b\";s:16:\"DeleteAny:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:78;a:4:{s:1:\"a\";i:79;s:1:\"b\";s:14:\"Restore:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:79;a:4:{s:1:\"a\";i:80;s:1:\"b\";s:18:\"ForceDelete:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:80;a:4:{s:1:\"a\";i:81;s:1:\"b\";s:21:\"ForceDeleteAny:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:81;a:4:{s:1:\"a\";i:82;s:1:\"b\";s:17:\"RestoreAny:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:82;a:4:{s:1:\"a\";i:83;s:1:\"b\";s:16:\"Replicate:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:83;a:4:{s:1:\"a\";i:84;s:1:\"b\";s:14:\"Reorder:Square\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:84;a:4:{s:1:\"a\";i:85;s:1:\"b\";s:18:\"ViewAny:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:85;a:4:{s:1:\"a\";i:86;s:1:\"b\";s:15:\"View:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:86;a:4:{s:1:\"a\";i:87;s:1:\"b\";s:17:\"Create:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:87;a:4:{s:1:\"a\";i:88;s:1:\"b\";s:17:\"Update:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:88;a:4:{s:1:\"a\";i:89;s:1:\"b\";s:17:\"Delete:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:89;a:4:{s:1:\"a\";i:90;s:1:\"b\";s:20:\"DeleteAny:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:90;a:4:{s:1:\"a\";i:91;s:1:\"b\";s:18:\"Restore:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:91;a:4:{s:1:\"a\";i:92;s:1:\"b\";s:22:\"ForceDelete:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:92;a:4:{s:1:\"a\";i:93;s:1:\"b\";s:25:\"ForceDeleteAny:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:93;a:4:{s:1:\"a\";i:94;s:1:\"b\";s:21:\"RestoreAny:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:94;a:4:{s:1:\"a\";i:95;s:1:\"b\";s:20:\"Replicate:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:95;a:4:{s:1:\"a\";i:96;s:1:\"b\";s:18:\"Reorder:Subscriber\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:96;a:4:{s:1:\"a\";i:97;s:1:\"b\";s:23:\"ViewAny:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:97;a:4:{s:1:\"a\";i:98;s:1:\"b\";s:20:\"View:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:98;a:4:{s:1:\"a\";i:99;s:1:\"b\";s:22:\"Create:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:99;a:4:{s:1:\"a\";i:100;s:1:\"b\";s:22:\"Update:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:100;a:4:{s:1:\"a\";i:101;s:1:\"b\";s:22:\"Delete:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:101;a:4:{s:1:\"a\";i:102;s:1:\"b\";s:25:\"DeleteAny:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:102;a:4:{s:1:\"a\";i:103;s:1:\"b\";s:23:\"Restore:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:103;a:4:{s:1:\"a\";i:104;s:1:\"b\";s:27:\"ForceDelete:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:104;a:4:{s:1:\"a\";i:105;s:1:\"b\";s:30:\"ForceDeleteAny:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:105;a:4:{s:1:\"a\";i:106;s:1:\"b\";s:26:\"RestoreAny:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:106;a:4:{s:1:\"a\";i:107;s:1:\"b\";s:25:\"Replicate:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:107;a:4:{s:1:\"a\";i:108;s:1:\"b\";s:23:\"Reorder:SubscriptionFee\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:108;a:4:{s:1:\"a\";i:109;s:1:\"b\";s:14:\"ViewAny:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:109;a:4:{s:1:\"a\";i:110;s:1:\"b\";s:11:\"View:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:110;a:4:{s:1:\"a\";i:111;s:1:\"b\";s:13:\"Create:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:111;a:4:{s:1:\"a\";i:112;s:1:\"b\";s:13:\"Update:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:112;a:4:{s:1:\"a\";i:113;s:1:\"b\";s:13:\"Delete:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:113;a:4:{s:1:\"a\";i:114;s:1:\"b\";s:16:\"DeleteAny:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:114;a:4:{s:1:\"a\";i:115;s:1:\"b\";s:14:\"Restore:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:115;a:4:{s:1:\"a\";i:116;s:1:\"b\";s:18:\"ForceDelete:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:116;a:4:{s:1:\"a\";i:117;s:1:\"b\";s:21:\"ForceDeleteAny:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:117;a:4:{s:1:\"a\";i:118;s:1:\"b\";s:17:\"RestoreAny:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:118;a:4:{s:1:\"a\";i:119;s:1:\"b\";s:16:\"Replicate:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:119;a:4:{s:1:\"a\";i:120;s:1:\"b\";s:14:\"Reorder:Tariff\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:120;a:4:{s:1:\"a\";i:121;s:1:\"b\";s:19:\"ViewAny:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:121;a:4:{s:1:\"a\";i:122;s:1:\"b\";s:16:\"View:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:122;a:4:{s:1:\"a\";i:123;s:1:\"b\";s:18:\"Create:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:123;a:4:{s:1:\"a\";i:124;s:1:\"b\";s:18:\"Update:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:124;a:4:{s:1:\"a\";i:125;s:1:\"b\";s:18:\"Delete:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:125;a:4:{s:1:\"a\";i:126;s:1:\"b\";s:21:\"DeleteAny:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:126;a:4:{s:1:\"a\";i:127;s:1:\"b\";s:19:\"Restore:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:127;a:4:{s:1:\"a\";i:128;s:1:\"b\";s:23:\"ForceDelete:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:128;a:4:{s:1:\"a\";i:129;s:1:\"b\";s:26:\"ForceDeleteAny:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:129;a:4:{s:1:\"a\";i:130;s:1:\"b\";s:22:\"RestoreAny:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:130;a:4:{s:1:\"a\";i:131;s:1:\"b\";s:21:\"Replicate:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:131;a:4:{s:1:\"a\";i:132;s:1:\"b\";s:19:\"Reorder:Transaction\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:132;a:4:{s:1:\"a\";i:133;s:1:\"b\";s:12:\"ViewAny:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:3;i:2;i:5;}}i:133;a:4:{s:1:\"a\";i:134;s:1:\"b\";s:9:\"View:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:3:{i:0;i:1;i:1;i:3;i:2;i:5;}}i:134;a:4:{s:1:\"a\";i:135;s:1:\"b\";s:11:\"Create:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:135;a:4:{s:1:\"a\";i:136;s:1:\"b\";s:11:\"Update:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:136;a:4:{s:1:\"a\";i:137;s:1:\"b\";s:11:\"Delete:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:137;a:4:{s:1:\"a\";i:138;s:1:\"b\";s:14:\"DeleteAny:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:138;a:4:{s:1:\"a\";i:139;s:1:\"b\";s:12:\"Restore:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:139;a:4:{s:1:\"a\";i:140;s:1:\"b\";s:16:\"ForceDelete:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:140;a:4:{s:1:\"a\";i:141;s:1:\"b\";s:19:\"ForceDeleteAny:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:141;a:4:{s:1:\"a\";i:142;s:1:\"b\";s:15:\"RestoreAny:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:142;a:4:{s:1:\"a\";i:143;s:1:\"b\";s:14:\"Replicate:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:143;a:4:{s:1:\"a\";i:144;s:1:\"b\";s:12:\"Reorder:User\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:144;a:4:{s:1:\"a\";i:145;s:1:\"b\";s:12:\"ViewAny:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:145;a:4:{s:1:\"a\";i:146;s:1:\"b\";s:9:\"View:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:146;a:4:{s:1:\"a\";i:147;s:1:\"b\";s:11:\"Create:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:147;a:4:{s:1:\"a\";i:148;s:1:\"b\";s:11:\"Update:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:148;a:4:{s:1:\"a\";i:149;s:1:\"b\";s:11:\"Delete:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:149;a:4:{s:1:\"a\";i:150;s:1:\"b\";s:14:\"DeleteAny:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:150;a:4:{s:1:\"a\";i:151;s:1:\"b\";s:12:\"Restore:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:151;a:4:{s:1:\"a\";i:152;s:1:\"b\";s:16:\"ForceDelete:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:152;a:4:{s:1:\"a\";i:153;s:1:\"b\";s:19:\"ForceDeleteAny:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:153;a:4:{s:1:\"a\";i:154;s:1:\"b\";s:15:\"RestoreAny:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:154;a:4:{s:1:\"a\";i:155;s:1:\"b\";s:14:\"Replicate:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:155;a:4:{s:1:\"a\";i:156;s:1:\"b\";s:12:\"Reorder:Role\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:156;a:4:{s:1:\"a\";i:157;s:1:\"b\";s:20:\"View:EditProfilePage\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:157;a:4:{s:1:\"a\";i:158;s:1:\"b\";s:20:\"View:InvoiceSettings\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:158;a:4:{s:1:\"a\";i:159;s:1:\"b\";s:20:\"View:ReadingSettings\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:159;a:4:{s:1:\"a\";i:160;s:1:\"b\";s:20:\"View:ReceiptSettings\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:160;a:4:{s:1:\"a\";i:161;s:1:\"b\";s:21:\"View:WhatsAppSettings\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:161;a:4:{s:1:\"a\";i:162;s:1:\"b\";s:24:\"View:CustomAccountWidget\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:162;a:4:{s:1:\"a\";i:163;s:1:\"b\";s:18:\"View:StatsOverview\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:163;a:4:{s:1:\"a\";i:164;s:1:\"b\";s:25:\"View:LatestInvoicesWidget\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:164;a:4:{s:1:\"a\";i:165;s:1:\"b\";s:24:\"View:MonthlyRevenueChart\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}i:165;a:4:{s:1:\"a\";i:166;s:1:\"b\";s:26:\"View:OverdueInvoicesWidget\";s:1:\"c\";s:3:\"web\";s:1:\"r\";a:1:{i:0;i:1;}}}s:5:\"roles\";a:3:{i:0;a:3:{s:1:\"a\";i:1;s:1:\"b\";s:11:\"super_admin\";s:1:\"c\";s:3:\"web\";}i:1;a:3:{s:1:\"a\";i:3;s:1:\"b\";s:9:\"collector\";s:1:\"c\";s:3:\"web\";}i:2;a:3:{s:1:\"a\";i:5;s:1:\"b\";s:6:\"reader\";s:1:\"c\";s:3:\"web\";}}}', 1785344451);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `failed_jobs`
--

INSERT INTO `failed_jobs` (`id`, `uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at`) VALUES
(1, '4045ffa5-629b-4ff3-b120-e418a9767efa', 'database', 'default', '{\"uuid\":\"4045ffa5-629b-4ff3-b120-e418a9767efa\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:68;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782415081,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:01'),
(2, 'ccc3cd42-e6db-4382-9230-3a62a6343a69', 'database', 'default', '{\"uuid\":\"ccc3cd42-e6db-4382-9230-3a62a6343a69\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:69;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782741489,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:01'),
(3, '9eeea0b4-67d1-4b05-befd-e374ea3da134', 'database', 'default', '{\"uuid\":\"9eeea0b4-67d1-4b05-befd-e374ea3da134\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:71;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782745425,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:01'),
(4, 'cee723b7-34d8-4caf-9c5b-5c047325064d', 'database', 'default', '{\"uuid\":\"cee723b7-34d8-4caf-9c5b-5c047325064d\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:72;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782746418,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:02'),
(5, '146d6cdf-d61b-4e53-8086-3257c34a1e31', 'database', 'default', '{\"uuid\":\"146d6cdf-d61b-4e53-8086-3257c34a1e31\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:73;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782748578,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:02'),
(6, '26f095de-4ee4-4b87-b0ba-ae0ca7fa72e9', 'database', 'default', '{\"uuid\":\"26f095de-4ee4-4b87-b0ba-ae0ca7fa72e9\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:74;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782749848,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:12'),
(7, 'd6820b8c-199e-4890-8f1e-b01ef1fa0e4f', 'database', 'default', '{\"uuid\":\"d6820b8c-199e-4890-8f1e-b01ef1fa0e4f\",\"displayName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\",\"command\":\"O:31:\\\"App\\\\Jobs\\\\SendWhatsAppPaymentJob\\\":1:{s:7:\\\"payment\\\";O:45:\\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\\":5:{s:5:\\\"class\\\";s:18:\\\"App\\\\Models\\\\Payment\\\";s:2:\\\"id\\\";i:75;s:9:\\\"relations\\\";a:1:{i:0;s:10:\\\"subscriber\\\";}s:10:\\\"connection\\\";s:5:\\\"mysql\\\";s:15:\\\"collectionClass\\\";N;}}\",\"batchId\":null},\"createdAt\":1782754030,\"delay\":null}', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Payment]. in C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php:786\nStack trace:\n#0 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(112): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()\n#1 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesAndRestoresModelIdentifiers.php(63): App\\Jobs\\SendWhatsAppPaymentJob->restoreModel(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#2 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\SerializesModels.php(98): App\\Jobs\\SendWhatsAppPaymentJob->getRestoredPropertyValue(Object(Illuminate\\Contracts\\Database\\ModelIdentifier))\n#3 [internal function]: App\\Jobs\\SendWhatsAppPaymentJob->__unserialize(Array)\n#4 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(114): unserialize(\'O:31:\"App\\\\Jobs\\\\...\')\n#5 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(71): Illuminate\\Queue\\CallQueuedHandler->getCommand(Array)\n#6 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#7 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(553): Illuminate\\Queue\\Jobs\\Job->fire()\n#8 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(499): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#9 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(245): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#10 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#11 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#12 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#13 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#14 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#15 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#16 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(799): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#17 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(280): Illuminate\\Container\\Container->call(Array)\n#18 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Command\\Command.php(284): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#19 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(249): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#20 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(1144): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#21 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(379): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#22 C:\\laragon\\www\\water_project\\vendor\\symfony\\console\\Application.php(218): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#23 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(198): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#24 C:\\laragon\\www\\water_project\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#25 C:\\laragon\\www\\water_project\\artisan(16): Illuminate\\Foundation\\Application->handleCommand(Object(Symfony\\Component\\Console\\Input\\ArgvInput))\n#26 {main}', '2026-07-20 20:56:12');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
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
  `meter_id` bigint UNSIGNED DEFAULT NULL,
  `invoice_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'meter' COMMENT 'نوع الفاتورة: meter, subscription, tanker'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `subscriber_id`, `bill_number`, `bill_date`, `billing_period_start`, `billing_period_end`, `consumption_fee`, `subscription_installment`, `tanker_charges`, `previous_balance`, `credit_applied`, `total_amount`, `amount_paid`, `status`, `notes`, `created_at`, `updated_at`, `deleted_at`, `meter_id`, `invoice_type`) VALUES
(1, 44, 'INV-202607-0001', '2026-07-02', '2026-06-01', '2026-06-30', 0.00, 0.00, 0.00, 0.00, 0.00, 35000.00, 35000.00, 'paid', NULL, '2026-07-02 16:35:26', '2026-07-03 18:27:36', NULL, NULL, 'meter'),
(2, 18, 'INV-202607-0002', '2026-07-02', '2026-06-01', '2026-06-30', 48000.00, 0.00, 0.00, 0.00, 0.00, 48000.00, 0.00, 'issued', NULL, '2026-07-02 17:09:38', '2026-07-02 17:11:32', '2026-07-02 17:11:32', 18, 'meter'),
(3, 1, 'INV-202607-0003', '2026-07-02', '2026-05-01', '2026-05-31', 13200.00, 0.00, 0.00, 0.00, 0.00, 13200.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:01', '2026-07-02 19:04:30', NULL, 1, 'meter'),
(4, 2, 'INV-202607-0004', '2026-07-02', '2026-05-01', '2026-05-31', 19200.00, 0.00, 0.00, 0.00, 0.00, 19200.00, 19200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:24:08', NULL, 2, 'meter'),
(5, 3, 'INV-202607-0005', '2026-07-02', '2026-05-01', '2026-05-31', 4200.00, 0.00, 0.00, 0.00, 0.00, 4200.00, 4200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:26:38', NULL, 3, 'meter'),
(6, 4, 'INV-202607-0006', '2026-07-02', '2026-05-01', '2026-05-31', 9000.00, 0.00, 0.00, 0.00, 0.00, 9000.00, 9000.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:27:32', NULL, 4, 'meter'),
(7, 5, 'INV-202607-0007', '2026-07-02', '2026-05-01', '2026-05-31', 8400.00, 0.00, 0.00, 0.00, 0.00, 8400.00, 8400.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:28:25', NULL, 5, 'meter'),
(8, 6, 'INV-202607-0008', '2026-07-02', '2026-05-01', '2026-05-31', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 6000.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:36:54', '2026-07-02 17:36:54', 6, 'meter'),
(9, 7, 'INV-202607-0009', '2026-07-02', '2026-05-01', '2026-05-31', 11400.00, 0.00, 0.00, 0.00, 0.00, 11400.00, 11000.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:49:18', NULL, 7, 'meter'),
(10, 8, 'INV-202607-0010', '2026-07-02', '2026-05-01', '2026-05-31', 10200.00, 0.00, 0.00, 0.00, 0.00, 10200.00, 10200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:53:24', NULL, 8, 'meter'),
(11, 9, 'INV-202607-0011', '2026-07-02', '2026-05-01', '2026-05-31', 13200.00, 0.00, 0.00, 7500.00, 0.00, 20700.00, 0.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:59:01', NULL, 9, 'meter'),
(12, 10, 'INV-202607-0012', '2026-07-02', '2026-05-01', '2026-05-31', 31200.00, 0.00, 0.00, 0.00, 0.00, 31200.00, 31200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:09:21', NULL, 10, 'meter'),
(13, 11, 'INV-202607-0013', '2026-07-02', '2026-05-01', '2026-05-31', 13800.00, 0.00, 0.00, 0.00, 0.00, 13800.00, 13800.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:10:49', NULL, 11, 'meter'),
(14, 12, 'INV-202607-0014', '2026-07-02', '2026-05-01', '2026-05-31', 25800.00, 0.00, 0.00, 0.00, 0.00, 25800.00, 25800.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:19:03', NULL, 12, 'meter'),
(15, 13, 'INV-202607-0015', '2026-07-02', '2026-05-01', '2026-05-31', 12600.00, 0.00, 0.00, 0.00, 0.00, 12600.00, 12600.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:19:16', NULL, 13, 'meter'),
(16, 14, 'INV-202607-0016', '2026-07-02', '2026-05-01', '2026-05-31', 7200.00, 0.00, 0.00, 0.00, 0.00, 7200.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:01', '2026-07-02 18:22:58', NULL, 14, 'meter'),
(17, 15, 'INV-202607-0017', '2026-07-02', '2026-05-01', '2026-05-31', 5400.00, 0.00, 0.00, 0.00, 0.00, 5400.00, 5400.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:28:46', NULL, 15, 'meter'),
(18, 16, 'INV-202607-0018', '2026-07-02', '2026-05-01', '2026-05-31', 12000.00, 0.00, 0.00, 0.00, 0.00, 12000.00, 12000.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:30:13', NULL, 16, 'meter'),
(19, 17, 'INV-202607-0019', '2026-07-02', '2026-05-01', '2026-05-31', 10800.00, 0.00, 0.00, 0.00, 0.00, 10800.00, 10800.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:33:26', NULL, 17, 'meter'),
(20, 18, 'INV-202607-0020', '2026-07-02', '2026-05-01', '2026-05-31', 31200.00, 0.00, 0.00, 0.00, 0.00, 31200.00, 26200.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:37:29', NULL, 18, 'meter'),
(21, 19, 'INV-202607-0021', '2026-07-02', '2026-05-01', '2026-05-31', 31800.00, 0.00, 0.00, 0.00, 0.00, 31800.00, 26800.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:34:56', NULL, 19, 'meter'),
(22, 20, 'INV-202607-0022', '2026-07-02', '2026-05-01', '2026-05-31', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 7800.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:32:20', NULL, 20, 'meter'),
(23, 21, 'INV-202607-0023', '2026-07-02', '2026-05-01', '2026-05-31', 57000.00, 0.00, 0.00, 0.00, 0.00, 57000.00, 57000.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:41:59', NULL, 21, 'meter'),
(24, 22, 'INV-202607-0024', '2026-07-02', '2026-05-01', '2026-05-31', 57000.00, 0.00, 0.00, 0.00, 0.00, 57000.00, 57000.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:22:16', NULL, 22, 'meter'),
(25, 23, 'INV-202607-0025', '2026-07-02', '2026-05-01', '2026-05-31', 5400.00, 0.00, 0.00, 0.00, 0.00, 5400.00, 3500.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:45:09', NULL, 23, 'meter'),
(26, 24, 'INV-202607-0026', '2026-07-02', '2026-05-01', '2026-05-31', 4800.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 4800.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:49:10', NULL, 24, 'meter'),
(27, 25, 'INV-202607-0027', '2026-07-02', '2026-05-01', '2026-05-31', 1200.00, 0.00, 0.00, 0.00, 0.00, 1200.00, 1200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:03:25', NULL, 25, 'meter'),
(28, 26, 'INV-202607-0028', '2026-07-02', '2026-05-01', '2026-05-31', 1200.00, 0.00, 0.00, 0.00, 0.00, 1200.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:01', '2026-07-29 02:12:55', NULL, 26, 'meter'),
(29, 27, 'INV-202607-0029', '2026-07-02', '2026-05-01', '2026-05-31', 4200.00, 0.00, 0.00, 0.00, 0.00, 4200.00, 4200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 18:54:46', NULL, 27, 'meter'),
(30, 28, 'INV-202607-0030', '2026-07-02', '2026-05-01', '2026-05-31', 15600.00, 0.00, 0.00, 0.00, 0.00, 15600.00, 10600.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 21:48:32', NULL, 28, 'meter'),
(31, 29, 'INV-202607-0031', '2026-07-02', '2026-05-01', '2026-05-31', 59400.00, 0.00, 0.00, 0.00, 0.00, 59400.00, 0.00, 'issued', NULL, '2026-07-02 17:17:01', '2026-07-02 19:08:59', '2026-07-02 19:08:59', 29, 'meter'),
(32, 30, 'INV-202607-0032', '2026-07-02', '2026-05-01', '2026-05-31', 30600.00, 0.00, 0.00, 0.00, 0.00, 30600.00, 30600.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:13:47', NULL, 30, 'meter'),
(33, 31, 'INV-202607-0033', '2026-07-02', '2026-05-01', '2026-05-31', 10200.00, 0.00, 0.00, 0.00, 0.00, 10200.00, 10200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:16:52', NULL, 31, 'meter'),
(34, 32, 'INV-202607-0034', '2026-07-02', '2026-05-01', '2026-05-31', 13200.00, 0.00, 0.00, 0.00, 0.00, 13200.00, 13200.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:17:41', NULL, 32, 'meter'),
(35, 33, 'INV-202607-0035', '2026-07-02', '2026-05-01', '2026-05-31', 5400.00, 0.00, 0.00, 0.00, 0.00, 5400.00, 5400.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:27:28', NULL, 33, 'meter'),
(36, 34, 'INV-202607-0036', '2026-07-02', '2026-05-01', '2026-05-31', 5400.00, 0.00, 0.00, 0.00, 0.00, 5400.00, 0.00, 'issued', NULL, '2026-07-02 17:17:01', '2026-07-02 19:20:44', '2026-07-02 19:20:44', 34, 'meter'),
(37, 35, 'INV-202607-0037', '2026-07-02', '2026-05-01', '2026-05-31', 3000.00, 0.00, 0.00, 0.00, 0.00, 3000.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:01', '2026-07-02 19:32:54', NULL, 35, 'meter'),
(38, 36, 'INV-202607-0038', '2026-07-02', '2026-05-01', '2026-05-31', 21000.00, 0.00, 0.00, 0.00, 0.00, 21000.00, 16000.00, 'partially_paid', NULL, '2026-07-02 17:17:01', '2026-07-02 19:33:59', NULL, 36, 'meter'),
(39, 37, 'INV-202607-0039', '2026-07-02', '2026-05-01', '2026-05-31', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'paid', NULL, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 37, 'meter'),
(40, 38, 'INV-202607-0040', '2026-07-02', '2026-05-01', '2026-05-31', 3000.00, 0.00, 0.00, 0.00, 0.00, 3000.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:02', '2026-07-02 19:39:26', NULL, 38, 'meter'),
(41, 39, 'INV-202607-0041', '2026-07-02', '2026-05-01', '2026-05-31', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:02', '2026-07-02 19:41:38', NULL, 39, 'meter'),
(42, 40, 'INV-202607-0042', '2026-07-02', '2026-05-01', '2026-05-31', 15000.00, 0.00, 0.00, 0.00, 0.00, 15000.00, 0.00, 'overdue', NULL, '2026-07-02 17:17:02', '2026-07-02 19:46:00', NULL, 40, 'meter'),
(43, 41, 'INV-202607-0043', '2026-07-02', '2026-05-01', '2026-05-31', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 7800.00, 'paid', NULL, '2026-07-02 17:17:02', '2026-07-02 19:48:21', NULL, 41, 'meter'),
(44, 42, 'INV-202607-0044', '2026-07-02', '2026-05-01', '2026-05-31', 4800.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 4800.00, 'paid', NULL, '2026-07-02 17:17:02', '2026-07-02 19:49:49', NULL, 42, 'meter'),
(45, 43, 'INV-202607-0045', '2026-07-02', '2026-05-01', '2026-05-31', 4200.00, 0.00, 0.00, 0.00, 0.00, 4200.00, 4200.00, 'paid', NULL, '2026-07-02 17:17:02', '2026-07-02 18:46:10', NULL, 43, 'meter'),
(46, 22, 'INV-202607-0046', '2026-07-02', '2026-06-01', '2026-06-30', 48000.00, 0.00, 0.00, 0.00, 0.00, 48000.00, 0.00, 'overdue', NULL, '2026-07-02 17:23:11', '2026-07-29 02:12:54', NULL, 22, 'meter'),
(47, 2, 'INV-202607-0047', '2026-07-02', '2026-06-01', '2026-06-30', 24000.00, 0.00, 0.00, 0.00, 0.00, 24000.00, 0.00, 'overdue', NULL, '2026-07-02 17:24:56', '2026-07-29 02:12:54', NULL, 2, 'meter'),
(48, 3, 'INV-202607-0048', '2026-07-02', '2026-06-01', '2026-06-30', 4800.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 0.00, 'overdue', NULL, '2026-07-02 17:27:05', '2026-07-29 02:12:54', NULL, 3, 'meter'),
(49, 4, 'INV-202607-0049', '2026-07-02', '2026-06-01', '2026-06-30', 6600.00, 0.00, 0.00, 0.00, 0.00, 6600.00, 0.00, 'overdue', NULL, '2026-07-02 17:27:58', '2026-07-29 02:12:54', NULL, 4, 'meter'),
(50, 5, 'INV-202607-0050', '2026-07-02', '2026-06-01', '2026-06-30', 7200.00, 0.00, 0.00, 0.00, 0.00, 7200.00, 0.00, 'overdue', NULL, '2026-07-02 17:28:48', '2026-07-29 02:12:54', NULL, 5, 'meter'),
(51, 6, 'INV-202607-0051', '2026-07-02', '2026-05-31', '2026-07-02', 5400.00, 0.00, 12000.00, 0.00, 9000.00, 17400.00, 9000.00, 'partially_paid', NULL, '2026-07-02 17:32:30', '2026-07-02 17:33:31', '2026-07-02 17:33:31', 6, 'meter'),
(52, 6, 'INV-202607-0052', '2026-07-02', '2026-05-31', '2026-07-02', 5400.00, 17500.00, 12000.00, 0.00, 9000.00, 22900.00, 9000.00, 'partially_paid', NULL, '2026-07-02 17:34:05', '2026-07-02 17:36:35', '2026-07-02 17:36:35', 6, 'meter'),
(53, 7, 'INV-202607-0053', '2026-07-02', '2026-06-01', '2026-06-30', 13800.00, 0.00, 0.00, 400.00, 0.00, 13800.00, 0.00, 'overdue', NULL, '2026-07-02 17:49:49', '2026-07-29 02:12:54', NULL, 7, 'meter'),
(54, 8, 'INV-202607-0054', '2026-07-02', '2026-06-01', '2026-06-30', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'paid', NULL, '2026-07-02 17:53:47', '2026-07-03 18:27:36', NULL, 8, 'meter'),
(55, 10, 'INV-202607-0055', '2026-07-02', '2026-06-01', '2026-06-30', 27000.00, 0.00, 0.00, 0.00, 0.00, 27000.00, 0.00, 'overdue', NULL, '2026-07-02 18:09:58', '2026-07-29 02:12:54', NULL, 10, 'meter'),
(56, 11, 'INV-202607-0056', '2026-07-02', '2026-06-01', '2026-06-30', 11400.00, 0.00, 0.00, 0.00, 0.00, 11400.00, 0.00, 'overdue', NULL, '2026-07-02 18:11:17', '2026-07-29 02:12:54', NULL, 11, 'meter'),
(57, 12, 'INV-202607-0057', '2026-07-02', '2026-06-01', '2026-06-30', 22800.00, 0.00, 0.00, 25800.00, 0.00, 22800.00, 22800.00, 'paid', NULL, '2026-07-02 18:17:54', '2026-07-03 18:27:36', NULL, 12, 'meter'),
(58, 14, 'INV-202607-0058', '2026-07-02', '2026-06-01', '2026-06-30', 7800.00, 12500.00, 0.00, 7200.00, 0.00, 7800.00, 0.00, 'overdue', NULL, '2026-07-02 18:22:58', '2026-07-29 02:12:54', NULL, 14, 'meter'),
(59, 15, 'INV-202607-0059', '2026-07-02', '2026-06-01', '2026-06-30', 9600.00, 0.00, 0.00, 0.00, 0.00, 9600.00, 0.00, 'overdue', NULL, '2026-07-02 18:29:36', '2026-07-29 02:12:54', NULL, 15, 'meter'),
(60, 16, 'INV-202607-0060', '2026-07-02', '2026-06-01', '2026-06-30', 18000.00, 0.00, 0.00, 0.00, 0.00, 18000.00, 0.00, 'overdue', NULL, '2026-07-02 18:30:31', '2026-07-29 02:12:54', NULL, 16, 'meter'),
(61, 20, 'INV-202607-0061', '2026-07-02', '2026-06-01', '2026-06-30', 12600.00, 0.00, 0.00, 0.00, 0.00, 12600.00, 0.00, 'overdue', NULL, '2026-07-02 18:32:35', '2026-07-29 02:12:54', NULL, 20, 'meter'),
(62, 17, 'INV-202607-0062', '2026-07-02', '2026-06-01', '2026-06-30', 4200.00, 0.00, 0.00, 0.00, 0.00, 4200.00, 0.00, 'overdue', NULL, '2026-07-02 18:33:49', '2026-07-29 02:12:54', NULL, 17, 'meter'),
(63, 19, 'INV-202607-0063', '2026-07-02', '2026-06-01', '2026-06-30', 73800.00, 0.00, 0.00, 5000.00, 0.00, 73800.00, 0.00, 'overdue', NULL, '2026-07-02 18:35:57', '2026-07-29 02:12:54', NULL, 19, 'meter'),
(64, 18, 'INV-202607-0064', '2026-07-02', '2026-06-01', '2026-06-30', 16800.00, 0.00, 0.00, 5000.00, 0.00, 16800.00, 0.00, 'overdue', '5000 ريال متأخرات من رسوم الاشتراك', '2026-07-02 18:38:25', '2026-07-29 02:12:54', NULL, 18, 'meter'),
(65, 21, 'INV-202607-0065', '2026-07-02', '2026-06-01', '2026-06-30', 55800.00, 0.00, 0.00, 0.00, 0.00, 55800.00, 0.00, 'overdue', NULL, '2026-07-02 18:42:29', '2026-07-29 02:12:54', NULL, 21, 'meter'),
(66, 23, 'INV-202607-0066', '2026-07-02', '2026-06-01', '2026-06-30', 1200.00, 0.00, 0.00, 1900.00, 0.00, 1200.00, 0.00, 'overdue', NULL, '2026-07-02 18:45:22', '2026-07-29 02:12:54', NULL, 23, 'meter'),
(67, 43, 'INV-202607-0067', '2026-07-02', '2026-05-31', '2026-07-02', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'issued', NULL, '2026-07-02 18:46:23', '2026-07-02 21:44:20', '2026-07-02 21:44:20', 43, 'meter'),
(68, 24, 'INV-202607-0068', '2026-07-02', '2026-05-31', '2026-07-02', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'issued', NULL, '2026-07-02 18:50:12', '2026-07-02 18:50:31', '2026-07-02 18:50:31', 24, 'meter'),
(69, 24, 'INV-202607-0069', '2026-07-02', '2026-06-01', '2026-06-30', 6000.00, 5000.00, 0.00, 0.00, 0.00, 11000.00, 0.00, 'overdue', NULL, '2026-07-02 18:50:56', '2026-07-29 02:12:54', NULL, 24, 'meter'),
(70, 27, 'INV-202607-0070', '2026-07-02', '2026-05-31', '2026-07-02', 4800.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 0.00, 'issued', NULL, '2026-07-02 18:55:44', '2026-07-02 18:56:08', '2026-07-02 18:56:08', 27, 'meter'),
(71, 27, 'INV-202607-0071', '2026-07-02', '2026-06-01', '2026-06-30', 4800.00, 5000.00, 0.00, 0.00, 0.00, 9800.00, 0.00, 'overdue', NULL, '2026-07-02 18:56:30', '2026-07-29 02:12:55', NULL, 27, 'meter'),
(72, 25, 'INV-202607-0072', '2026-07-02', '2026-06-01', '2026-06-30', 1800.00, 0.00, 0.00, 0.00, 0.00, 1800.00, 0.00, 'overdue', NULL, '2026-07-02 19:03:44', '2026-07-29 02:12:54', NULL, 25, 'meter'),
(73, 1, 'INV-202607-0073', '2026-07-02', '2026-06-01', '2026-06-30', 23400.00, 0.00, 0.00, 13200.00, 0.00, 23400.00, 0.00, 'overdue', NULL, '2026-07-02 19:04:30', '2026-07-29 02:12:54', NULL, 1, 'meter'),
(74, 29, 'INV-202607-0074', '2026-07-02', '2026-05-01', '2026-05-31', 39600.00, 0.00, 0.00, 0.00, 0.00, 39600.00, 39600.00, 'paid', NULL, '2026-07-02 19:09:31', '2026-07-02 19:12:14', NULL, 29, 'meter'),
(75, 29, 'INV-202607-0075', '2026-07-02', '2026-06-01', '2026-06-30', 23400.00, 0.00, 0.00, 0.00, 0.00, 23400.00, 0.00, 'overdue', NULL, '2026-07-02 19:12:48', '2026-07-29 02:12:55', NULL, 29, 'meter'),
(76, 30, 'INV-202607-0076', '2026-07-02', '2026-05-31', '2026-07-02', 37800.00, 0.00, 0.00, 0.00, 0.00, 37800.00, 0.00, 'issued', NULL, '2026-07-02 19:15:12', '2026-07-02 19:15:28', '2026-07-02 19:15:28', 30, 'meter'),
(77, 30, 'INV-202607-0077', '2026-07-02', '2026-06-01', '2026-06-30', 37800.00, 35000.00, 0.00, 0.00, 0.00, 72800.00, 72800.00, 'paid', NULL, '2026-07-02 19:15:56', '2026-07-05 16:21:28', NULL, 30, 'meter'),
(78, 31, 'INV-202607-0078', '2026-07-02', '2026-06-01', '2026-06-30', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'paid', NULL, '2026-07-02 19:17:12', '2026-07-03 18:27:36', NULL, 31, 'meter'),
(79, 32, 'INV-202607-0079', '2026-07-02', '2026-06-01', '2026-06-30', 13200.00, 0.00, 0.00, 0.00, 0.00, 13200.00, 0.00, 'overdue', NULL, '2026-07-02 19:18:01', '2026-07-29 02:12:55', NULL, 32, 'meter'),
(80, 34, 'INV-202607-0080', '2026-07-02', '2026-05-01', '2026-05-31', 3600.00, 0.00, 0.00, 0.00, 0.00, 3600.00, 3600.00, 'paid', NULL, '2026-07-02 19:21:30', '2026-07-02 19:22:10', NULL, 34, 'meter'),
(81, 34, 'INV-202607-0081', '2026-07-02', '2026-06-01', '2026-06-30', 3000.00, 0.00, 0.00, 0.00, 0.00, 3000.00, 0.00, 'issued', NULL, '2026-07-02 19:22:50', '2026-07-02 19:23:10', '2026-07-02 19:23:10', 34, 'meter'),
(82, 6, 'INV-202607-0082', '2026-07-02', '2026-05-01', '2026-05-31', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'overdue', NULL, '2026-07-02 19:23:31', '2026-07-29 02:12:54', NULL, 6, 'meter'),
(83, 34, 'INV-202607-0083', '2026-07-02', '2026-06-01', '2026-06-30', 3000.00, 12500.00, 0.00, 0.00, 0.00, 15500.00, 0.00, 'overdue', NULL, '2026-07-02 19:24:32', '2026-07-29 02:12:55', NULL, 34, 'meter'),
(84, 33, 'INV-202607-0084', '2026-07-02', '2026-05-31', '2026-07-02', 4800.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 0.00, 'issued', NULL, '2026-07-02 19:28:23', '2026-07-02 19:28:40', '2026-07-02 19:28:40', 33, 'meter'),
(85, 33, 'INV-202607-0085', '2026-07-02', '2026-06-01', '2026-06-30', 4800.00, 17500.00, 0.00, 0.00, 0.00, 22300.00, 0.00, 'overdue', NULL, '2026-07-02 19:29:01', '2026-07-29 02:12:55', NULL, 33, 'meter'),
(86, 35, 'INV-202607-0086', '2026-07-02', '2026-05-31', '2026-07-02', 2400.00, 0.00, 0.00, 3000.00, 0.00, 2400.00, 0.00, 'issued', NULL, '2026-07-02 19:32:22', '2026-07-02 19:32:37', '2026-07-02 19:32:37', 35, 'meter'),
(87, 35, 'INV-202607-0087', '2026-07-02', '2026-06-01', '2026-06-30', 2400.00, 10000.00, 0.00, 3000.00, 0.00, 12400.00, 0.00, 'overdue', NULL, '2026-07-02 19:32:54', '2026-07-29 02:12:55', NULL, 35, 'meter'),
(88, 36, 'INV-202607-0088', '2026-07-02', '2026-06-01', '2026-06-30', 47400.00, 0.00, 0.00, 5000.00, 0.00, 47400.00, 0.00, 'overdue', '5000 ريال متبقي من رسوم الاشتراك', '2026-07-02 19:34:30', '2026-07-29 02:12:55', NULL, 36, 'meter'),
(89, 37, 'INV-202607-0089', '2026-07-02', '2026-06-01', '2026-06-30', 22800.00, 0.00, 0.00, 0.00, 0.00, 22800.00, 0.00, 'overdue', NULL, '2026-07-02 19:36:49', '2026-07-29 02:12:55', NULL, 37, 'meter'),
(90, 38, 'INV-202607-0090', '2026-07-02', '2026-05-31', '2026-07-02', 16200.00, 0.00, 0.00, 3000.00, 0.00, 16200.00, 0.00, 'issued', NULL, '2026-07-02 19:38:37', '2026-07-02 19:39:01', '2026-07-02 19:39:01', 38, 'meter'),
(91, 38, 'INV-202607-0091', '2026-07-02', '2026-06-01', '2026-06-30', 16200.00, 37500.00, 0.00, 3000.00, 0.00, 53700.00, 0.00, 'overdue', NULL, '2026-07-02 19:39:26', '2026-07-29 02:12:55', NULL, 38, 'meter'),
(92, 39, 'INV-202607-0092', '2026-07-02', '2026-05-31', '2026-07-02', 3000.00, 0.00, 0.00, 6000.00, 0.00, 3000.00, 0.00, 'issued', NULL, '2026-07-02 19:41:03', '2026-07-02 19:41:15', '2026-07-02 19:41:15', 39, 'meter'),
(93, 39, 'INV-202607-0093', '2026-07-02', '2026-06-01', '2026-06-30', 3000.00, 37500.00, 0.00, 6000.00, 0.00, 40500.00, 0.00, 'overdue', NULL, '2026-07-02 19:41:38', '2026-07-29 02:12:55', NULL, 39, 'meter'),
(94, 40, 'INV-202607-0094', '2026-07-02', '2026-05-31', '2026-07-02', 9600.00, 0.00, 0.00, 15000.00, 0.00, 9600.00, 0.00, 'issued', NULL, '2026-07-02 19:45:17', '2026-07-02 19:45:34', '2026-07-02 19:45:34', 40, 'meter'),
(95, 40, 'INV-202607-0095', '2026-07-02', '2026-06-01', '2026-06-30', 9600.00, 37500.00, 0.00, 15000.00, 0.00, 47100.00, 0.00, 'overdue', NULL, '2026-07-02 19:46:00', '2026-07-29 02:12:55', NULL, 40, 'meter'),
(96, 41, 'INV-202607-0096', '2026-07-02', '2026-06-01', '2026-06-30', 43800.00, 0.00, 0.00, 0.00, 0.00, 43800.00, 0.00, 'overdue', NULL, '2026-07-02 19:48:45', '2026-07-29 02:12:55', NULL, 41, 'meter'),
(97, 42, 'INV-202607-0097', '2026-07-02', '2026-06-01', '2026-06-30', 66600.00, 0.00, 0.00, 0.00, 0.00, 66600.00, 0.00, 'overdue', NULL, '2026-07-02 19:50:11', '2026-07-29 02:12:55', NULL, 42, 'meter'),
(98, 45, 'INV-202607-0098', '2026-07-02', '2026-06-01', '2026-06-30', 10200.00, 0.00, 0.00, 0.00, 0.00, 10200.00, 10200.00, 'paid', NULL, '2026-07-02 20:00:33', '2026-07-05 16:22:34', NULL, 45, 'meter'),
(99, 46, 'INV-202607-0099', '2026-07-02', '2026-06-01', '2026-06-30', 16200.00, 0.00, 0.00, 0.00, 0.00, 16200.00, 0.00, 'overdue', NULL, '2026-07-02 20:05:53', '2026-07-29 02:12:55', NULL, 47, 'meter'),
(100, 47, 'INV-202607-0100', '2026-07-02', '2026-06-01', '2026-06-30', 3600.00, 0.00, 0.00, 0.00, 0.00, 3600.00, 0.00, 'overdue', NULL, '2026-07-02 20:09:13', '2026-07-29 02:12:55', NULL, 48, 'meter'),
(101, 48, 'INV-202607-0101', '2026-07-02', '2026-06-01', '2026-06-30', 9600.00, 0.00, 0.00, 0.00, 0.00, 9600.00, 0.00, 'overdue', NULL, '2026-07-02 20:12:24', '2026-07-29 02:12:55', NULL, 50, 'meter'),
(102, 50, 'INV-202607-0102', '2026-07-02', '2026-06-01', '2026-06-30', 17400.00, 0.00, 0.00, 0.00, 0.00, 17400.00, 0.00, 'overdue', NULL, '2026-07-02 20:20:10', '2026-07-29 02:12:55', NULL, 53, 'meter'),
(103, 51, 'INV-202607-0103', '2026-07-02', '2026-06-01', '2026-06-30', 12600.00, 0.00, 0.00, 0.00, 0.00, 12600.00, 0.00, 'overdue', NULL, '2026-07-02 20:22:49', '2026-07-29 02:12:55', NULL, 54, 'meter'),
(104, 52, 'INV-202607-0104', '2026-07-02', '2026-06-01', '2026-06-30', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 0.00, 'overdue', NULL, '2026-07-02 20:24:29', '2026-07-29 02:12:55', NULL, 55, 'meter'),
(105, 55, 'INV-202607-0105', '2026-07-02', '2026-06-01', '2026-06-30', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 0.00, 'overdue', NULL, '2026-07-02 20:29:18', '2026-07-29 02:12:55', NULL, 58, 'meter'),
(106, 59, 'INV-202607-0106', '2026-07-02', '2026-06-01', '2026-06-30', 9600.00, 0.00, 0.00, 0.00, 0.00, 9600.00, 0.00, 'overdue', NULL, '2026-07-02 20:36:22', '2026-07-29 02:12:55', NULL, 62, 'meter'),
(107, 60, 'INV-202607-0107', '2026-07-02', '2026-06-01', '2026-06-30', 2400.00, 0.00, 0.00, 0.00, 0.00, 2400.00, 0.00, 'overdue', NULL, '2026-07-02 20:39:16', '2026-07-29 02:12:55', NULL, 63, 'meter'),
(108, 61, 'INV-202607-0108', '2026-07-02', '2026-06-01', '2026-07-02', 1200.00, 0.00, 0.00, 0.00, 0.00, 1200.00, 0.00, 'issued', NULL, '2026-07-02 20:41:53', '2026-07-02 20:42:46', '2026-07-02 20:42:46', 64, 'meter'),
(109, 61, 'INV-202607-0109', '2026-07-02', '2026-06-01', '2026-06-30', 1200.00, 7000.00, 0.00, 0.00, 0.00, 8200.00, 0.00, 'overdue', NULL, '2026-07-02 20:43:10', '2026-07-29 02:12:55', NULL, 64, 'meter'),
(110, 62, 'INV-202607-0110', '2026-07-02', '2026-06-01', '2026-06-30', 3000.00, 0.00, 0.00, 0.00, 0.00, 3000.00, 0.00, 'overdue', NULL, '2026-07-02 20:44:52', '2026-07-29 02:12:55', NULL, 65, 'meter'),
(111, 63, 'INV-202607-0111', '2026-07-02', '2026-06-01', '2026-06-30', 3600.00, 0.00, 0.00, 0.00, 0.00, 3600.00, 0.00, 'overdue', NULL, '2026-07-02 20:46:41', '2026-07-29 02:12:55', NULL, 66, 'meter'),
(112, 66, 'INV-202607-0112', '2026-07-02', '2026-06-01', '2026-07-02', 7800.00, 0.00, 0.00, 0.00, 0.00, 7800.00, 0.00, 'issued', NULL, '2026-07-02 20:55:24', '2026-07-02 21:11:42', '2026-07-02 21:11:42', 69, 'meter'),
(113, 67, 'INV-202607-0113', '2026-07-02', '2026-06-01', '2026-06-30', 2400.00, 0.00, 0.00, 0.00, 0.00, 2400.00, 0.00, 'overdue', NULL, '2026-07-02 20:57:09', '2026-07-29 02:12:55', NULL, 70, 'meter'),
(114, 68, 'INV-202607-0114', '2026-07-02', '2026-06-01', '2026-06-30', 6000.00, 0.00, 0.00, 0.00, 0.00, 6000.00, 0.00, 'overdue', NULL, '2026-07-02 20:58:56', '2026-07-29 02:12:55', NULL, 71, 'meter'),
(115, 69, 'INV-202607-0115', '2026-07-03', '2026-06-01', '2026-06-30', 0.00, 5000.00, 0.00, 0.00, 0.00, 5000.00, 0.00, 'overdue', NULL, '2026-07-02 21:08:21', '2026-07-29 02:12:55', NULL, NULL, 'subscription'),
(116, 66, 'INV-202607-0116', '2026-07-03', '2026-06-01', '2026-06-30', 7800.00, 35000.00, 0.00, 0.00, 0.00, 42800.00, 0.00, 'overdue', 'متأخرات رسوم اشتراك 35000 ريال\n', '2026-07-02 21:12:47', '2026-07-29 02:12:55', NULL, 69, 'meter'),
(117, 43, 'INV-202607-0117', '2026-07-03', '2026-06-01', '2026-06-30', 6000.00, 10000.00, 0.00, 0.00, 0.00, 16000.00, 0.00, 'overdue', NULL, '2026-07-02 21:44:43', '2026-07-29 02:12:55', NULL, 43, 'meter');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` bigint UNSIGNED NOT NULL,
  `invoice_id` bigint UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consumption/subscription/tanker/previous_balance/credit',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `type`, `description`, `amount`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 2, 'consumption', 'استهلاك مياه: 80 وحدة × 600 ريال', 48000.00, '2026-07-02 17:09:38', '2026-07-02 17:09:38', NULL),
(2, 3, 'consumption', 'استهلاك مياه: 22 وحدة × 600 ريال', 13200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(3, 4, 'consumption', 'استهلاك مياه: 32 وحدة × 600 ريال', 19200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(4, 5, 'consumption', 'استهلاك مياه: 7 وحدة × 600 ريال', 4200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(5, 6, 'consumption', 'استهلاك مياه: 15 وحدة × 600 ريال', 9000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(6, 7, 'consumption', 'استهلاك مياه: 14 وحدة × 600 ريال', 8400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(7, 8, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(8, 9, 'consumption', 'استهلاك مياه: 19 وحدة × 600 ريال', 11400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(9, 10, 'consumption', 'استهلاك مياه: 17 وحدة × 600 ريال', 10200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(10, 11, 'consumption', 'استهلاك مياه: 22 وحدة × 600 ريال', 13200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(11, 12, 'consumption', 'استهلاك مياه: 52 وحدة × 600 ريال', 31200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(12, 13, 'consumption', 'استهلاك مياه: 23 وحدة × 600 ريال', 13800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(13, 14, 'consumption', 'استهلاك مياه: 43 وحدة × 600 ريال', 25800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(14, 15, 'consumption', 'استهلاك مياه: 21 وحدة × 600 ريال', 12600.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(15, 16, 'consumption', 'استهلاك مياه: 12 وحدة × 600 ريال', 7200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(16, 17, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(17, 18, 'consumption', 'استهلاك مياه: 20 وحدة × 600 ريال', 12000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(18, 19, 'consumption', 'استهلاك مياه: 18 وحدة × 600 ريال', 10800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(19, 20, 'consumption', 'استهلاك مياه: 52 وحدة × 600 ريال', 31200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(20, 21, 'consumption', 'استهلاك مياه: 53 وحدة × 600 ريال', 31800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(21, 22, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(22, 23, 'consumption', 'استهلاك مياه: 95 وحدة × 600 ريال', 57000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(23, 24, 'consumption', 'استهلاك مياه: 95 وحدة × 600 ريال', 57000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(24, 25, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(25, 26, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(26, 27, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(27, 28, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(28, 29, 'consumption', 'استهلاك مياه: 7 وحدة × 600 ريال', 4200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(29, 30, 'consumption', 'استهلاك مياه: 26 وحدة × 600 ريال', 15600.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(30, 31, 'consumption', 'استهلاك مياه: 99 وحدة × 600 ريال', 59400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(31, 32, 'consumption', 'استهلاك مياه: 51 وحدة × 600 ريال', 30600.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(32, 33, 'consumption', 'استهلاك مياه: 17 وحدة × 600 ريال', 10200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(33, 34, 'consumption', 'استهلاك مياه: 22 وحدة × 600 ريال', 13200.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(34, 35, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(35, 36, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(36, 37, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(37, 38, 'consumption', 'استهلاك مياه: 35 وحدة × 600 ريال', 21000.00, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL),
(38, 40, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(39, 41, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(40, 42, 'consumption', 'استهلاك مياه: 25 وحدة × 600 ريال', 15000.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(41, 43, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(42, 44, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(43, 45, 'consumption', 'استهلاك مياه: 7 وحدة × 600 ريال', 4200.00, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL),
(44, 46, 'consumption', 'استهلاك مياه: 80 وحدة × 600 ريال', 48000.00, '2026-07-02 17:23:11', '2026-07-02 17:23:11', NULL),
(45, 47, 'consumption', 'استهلاك مياه: 40 وحدة × 600 ريال', 24000.00, '2026-07-02 17:24:56', '2026-07-02 17:24:56', NULL),
(46, 48, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 17:27:05', '2026-07-02 17:27:05', NULL),
(47, 49, 'consumption', 'استهلاك مياه: 11 وحدة × 600 ريال', 6600.00, '2026-07-02 17:27:58', '2026-07-02 17:27:58', NULL),
(48, 50, 'consumption', 'استهلاك مياه: 12 وحدة × 600 ريال', 7200.00, '2026-07-02 17:28:48', '2026-07-02 17:28:48', NULL),
(49, 51, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:32:30', '2026-07-02 17:32:30', NULL),
(50, 51, 'tanker', 'خدمة وايت ماء - 2026-07-02', 12000.00, '2026-07-02 17:32:30', '2026-07-02 17:32:30', NULL),
(51, 52, 'consumption', 'استهلاك مياه: 9 وحدة × 600 ريال', 5400.00, '2026-07-02 17:34:05', '2026-07-02 17:34:05', NULL),
(52, 52, 'subscription', 'قسط اشتراك (المسدد: 0 من 17500)', 17500.00, '2026-07-02 17:34:05', '2026-07-02 17:34:05', NULL),
(53, 53, 'consumption', 'استهلاك مياه: 23 وحدة × 600 ريال', 13800.00, '2026-07-02 17:49:49', '2026-07-02 17:49:49', NULL),
(54, 53, 'previous_balance', 'متأخرات سابقة', 400.00, '2026-07-02 17:49:49', '2026-07-02 17:49:49', NULL),
(55, 55, 'consumption', 'استهلاك مياه: 45 وحدة × 600 ريال', 27000.00, '2026-07-02 18:09:58', '2026-07-02 18:09:58', NULL),
(56, 56, 'consumption', 'استهلاك مياه: 19 وحدة × 600 ريال', 11400.00, '2026-07-02 18:11:17', '2026-07-02 18:11:17', NULL),
(57, 57, 'consumption', 'استهلاك مياه: 38 وحدة × 600 ريال', 22800.00, '2026-07-02 18:17:54', '2026-07-02 18:17:54', NULL),
(58, 57, 'previous_balance', 'متأخرات سابقة', 25800.00, '2026-07-02 18:17:54', '2026-07-02 18:17:54', NULL),
(59, 58, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 18:22:58', '2026-07-02 18:22:58', NULL),
(60, 58, 'previous_balance', 'متأخرات سابقة', 7200.00, '2026-07-02 18:22:58', '2026-07-02 18:22:58', NULL),
(61, 59, 'consumption', 'استهلاك مياه: 16 وحدة × 600 ريال', 9600.00, '2026-07-02 18:29:36', '2026-07-02 18:29:36', NULL),
(62, 60, 'consumption', 'استهلاك مياه: 30 وحدة × 600 ريال', 18000.00, '2026-07-02 18:30:31', '2026-07-02 18:30:31', NULL),
(63, 61, 'consumption', 'استهلاك مياه: 21 وحدة × 600 ريال', 12600.00, '2026-07-02 18:32:35', '2026-07-02 18:32:35', NULL),
(64, 62, 'consumption', 'استهلاك مياه: 7 وحدة × 600 ريال', 4200.00, '2026-07-02 18:33:49', '2026-07-02 18:33:49', NULL),
(65, 63, 'consumption', 'استهلاك مياه: 123 وحدة × 600 ريال', 73800.00, '2026-07-02 18:35:58', '2026-07-02 18:35:58', NULL),
(66, 63, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-07-02 18:35:58', '2026-07-02 18:35:58', NULL),
(67, 64, 'consumption', 'استهلاك مياه: 28 وحدة × 600 ريال', 16800.00, '2026-07-02 18:38:25', '2026-07-02 18:38:25', NULL),
(68, 64, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-07-02 18:38:25', '2026-07-02 18:38:25', NULL),
(69, 65, 'consumption', 'استهلاك مياه: 93 وحدة × 600 ريال', 55800.00, '2026-07-02 18:42:29', '2026-07-02 18:42:29', NULL),
(70, 66, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-07-02 18:45:23', '2026-07-02 18:45:23', NULL),
(71, 66, 'previous_balance', 'متأخرات سابقة', 1900.00, '2026-07-02 18:45:23', '2026-07-02 18:45:23', NULL),
(72, 67, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 18:46:23', '2026-07-02 18:46:23', NULL),
(73, 68, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 18:50:12', '2026-07-02 18:50:12', NULL),
(74, 69, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 18:50:56', '2026-07-02 18:50:56', NULL),
(75, 69, 'subscription', 'قسط اشتراك (المسدد: 0 من 5000)', 5000.00, '2026-07-02 18:50:56', '2026-07-02 18:50:56', NULL),
(76, 70, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 18:55:44', '2026-07-02 18:55:44', NULL),
(77, 71, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 18:56:30', '2026-07-02 18:56:30', NULL),
(78, 71, 'subscription', 'قسط اشتراك (المسدد: 0 من 5000)', 5000.00, '2026-07-02 18:56:30', '2026-07-02 18:56:30', NULL),
(79, 72, 'consumption', 'استهلاك مياه: 3 وحدة × 600 ريال', 1800.00, '2026-07-02 19:03:44', '2026-07-02 19:03:44', NULL),
(80, 73, 'consumption', 'استهلاك مياه: 39 وحدة × 600 ريال', 23400.00, '2026-07-02 19:04:30', '2026-07-02 19:04:30', NULL),
(81, 73, 'previous_balance', 'متأخرات سابقة', 13200.00, '2026-07-02 19:04:30', '2026-07-02 19:04:30', NULL),
(82, 74, 'consumption', 'استهلاك مياه: 66 وحدة × 600 ريال', 39600.00, '2026-07-02 19:09:31', '2026-07-02 19:09:31', NULL),
(83, 75, 'consumption', 'استهلاك مياه: 39 وحدة × 600 ريال', 23400.00, '2026-07-02 19:12:48', '2026-07-02 19:12:48', NULL),
(84, 76, 'consumption', 'استهلاك مياه: 63 وحدة × 600 ريال', 37800.00, '2026-07-02 19:15:12', '2026-07-02 19:15:12', NULL),
(85, 77, 'consumption', 'استهلاك مياه: 63 وحدة × 600 ريال', 37800.00, '2026-07-02 19:15:56', '2026-07-02 19:15:56', NULL),
(86, 77, 'subscription', 'قسط اشتراك (المسدد: 0 من 35000)', 35000.00, '2026-07-02 19:15:56', '2026-07-02 19:15:56', NULL),
(87, 79, 'consumption', 'استهلاك مياه: 22 وحدة × 600 ريال', 13200.00, '2026-07-02 19:18:01', '2026-07-02 19:18:01', NULL),
(88, 80, 'consumption', 'استهلاك مياه: 6 وحدة × 600 ريال', 3600.00, '2026-07-02 19:21:30', '2026-07-02 19:21:30', NULL),
(89, 81, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 19:22:50', '2026-07-02 19:22:50', NULL),
(90, 82, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 19:23:31', '2026-07-02 19:23:31', NULL),
(91, 83, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 19:24:32', '2026-07-02 19:24:32', NULL),
(92, 83, 'subscription', 'قسط اشتراك (المسدد: 0 من 12500)', 12500.00, '2026-07-02 19:24:32', '2026-07-02 19:24:32', NULL),
(93, 84, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 19:28:23', '2026-07-02 19:28:23', NULL),
(94, 85, 'consumption', 'استهلاك مياه: 8 وحدة × 600 ريال', 4800.00, '2026-07-02 19:29:01', '2026-07-02 19:29:01', NULL),
(95, 85, 'subscription', 'قسط اشتراك (المسدد: 0 من 17500)', 17500.00, '2026-07-02 19:29:01', '2026-07-02 19:29:01', NULL),
(96, 86, 'consumption', 'استهلاك مياه: 4 وحدة × 600 ريال', 2400.00, '2026-07-02 19:32:22', '2026-07-02 19:32:22', NULL),
(97, 86, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-07-02 19:32:22', '2026-07-02 19:32:22', NULL),
(98, 87, 'consumption', 'استهلاك مياه: 4 وحدة × 600 ريال', 2400.00, '2026-07-02 19:32:54', '2026-07-02 19:32:54', NULL),
(99, 87, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-07-02 19:32:54', '2026-07-02 19:32:54', NULL),
(100, 87, 'subscription', 'قسط اشتراك (المسدد: 0 من 10000)', 10000.00, '2026-07-02 19:32:54', '2026-07-02 19:32:54', NULL),
(101, 88, 'consumption', 'استهلاك مياه: 79 وحدة × 600 ريال', 47400.00, '2026-07-02 19:34:30', '2026-07-02 19:34:30', NULL),
(102, 88, 'previous_balance', 'متأخرات سابقة', 5000.00, '2026-07-02 19:34:30', '2026-07-02 19:34:30', NULL),
(103, 89, 'consumption', 'استهلاك مياه: 38 وحدة × 600 ريال', 22800.00, '2026-07-02 19:36:49', '2026-07-02 19:36:49', NULL),
(104, 90, 'consumption', 'استهلاك مياه: 27 وحدة × 600 ريال', 16200.00, '2026-07-02 19:38:37', '2026-07-02 19:38:37', NULL),
(105, 90, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-07-02 19:38:37', '2026-07-02 19:38:37', NULL),
(106, 91, 'consumption', 'استهلاك مياه: 27 وحدة × 600 ريال', 16200.00, '2026-07-02 19:39:26', '2026-07-02 19:39:26', NULL),
(107, 91, 'previous_balance', 'متأخرات سابقة', 3000.00, '2026-07-02 19:39:26', '2026-07-02 19:39:26', NULL),
(108, 91, 'subscription', 'قسط اشتراك (المسدد: 0 من 37500)', 37500.00, '2026-07-02 19:39:26', '2026-07-02 19:39:26', NULL),
(109, 92, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 19:41:03', '2026-07-02 19:41:03', NULL),
(110, 92, 'previous_balance', 'متأخرات سابقة', 6000.00, '2026-07-02 19:41:03', '2026-07-02 19:41:03', NULL),
(111, 93, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 19:41:38', '2026-07-02 19:41:38', NULL),
(112, 93, 'previous_balance', 'متأخرات سابقة', 6000.00, '2026-07-02 19:41:38', '2026-07-02 19:41:38', NULL),
(113, 93, 'subscription', 'قسط اشتراك (المسدد: 0 من 37500)', 37500.00, '2026-07-02 19:41:38', '2026-07-02 19:41:38', NULL),
(114, 94, 'consumption', 'استهلاك مياه: 16 وحدة × 600 ريال', 9600.00, '2026-07-02 19:45:17', '2026-07-02 19:45:17', NULL),
(115, 94, 'previous_balance', 'متأخرات سابقة', 15000.00, '2026-07-02 19:45:17', '2026-07-02 19:45:17', NULL),
(116, 95, 'consumption', 'استهلاك مياه: 16 وحدة × 600 ريال', 9600.00, '2026-07-02 19:46:00', '2026-07-02 19:46:00', NULL),
(117, 95, 'previous_balance', 'متأخرات سابقة', 15000.00, '2026-07-02 19:46:00', '2026-07-02 19:46:00', NULL),
(118, 95, 'subscription', 'قسط اشتراك (المسدد: 0 من 37500)', 37500.00, '2026-07-02 19:46:00', '2026-07-02 19:46:00', NULL),
(119, 96, 'consumption', 'استهلاك مياه: 73 وحدة × 600 ريال', 43800.00, '2026-07-02 19:48:45', '2026-07-02 19:48:45', NULL),
(120, 97, 'consumption', 'استهلاك مياه: 111 وحدة × 600 ريال', 66600.00, '2026-07-02 19:50:11', '2026-07-02 19:50:11', NULL),
(121, 98, 'consumption', 'استهلاك مياه: 17 وحدة × 600 ريال', 10200.00, '2026-07-02 20:00:33', '2026-07-02 20:00:33', NULL),
(122, 99, 'consumption', 'استهلاك مياه: 27 وحدة × 600 ريال', 16200.00, '2026-07-02 20:05:53', '2026-07-02 20:05:53', NULL),
(123, 100, 'consumption', 'استهلاك مياه: 6 وحدة × 600 ريال', 3600.00, '2026-07-02 20:09:13', '2026-07-02 20:09:13', NULL),
(124, 101, 'consumption', 'استهلاك مياه: 16 وحدة × 600 ريال', 9600.00, '2026-07-02 20:12:24', '2026-07-02 20:12:24', NULL),
(125, 102, 'consumption', 'استهلاك مياه: 29 وحدة × 600 ريال', 17400.00, '2026-07-02 20:20:10', '2026-07-02 20:20:10', NULL),
(126, 103, 'consumption', 'استهلاك مياه: 21 وحدة × 600 ريال', 12600.00, '2026-07-02 20:22:49', '2026-07-02 20:22:49', NULL),
(127, 104, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 20:24:29', '2026-07-02 20:24:29', NULL),
(128, 105, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 20:29:18', '2026-07-02 20:29:18', NULL),
(129, 106, 'consumption', 'استهلاك مياه: 16 وحدة × 600 ريال', 9600.00, '2026-07-02 20:36:22', '2026-07-02 20:36:22', NULL),
(130, 107, 'consumption', 'استهلاك مياه: 4 وحدة × 600 ريال', 2400.00, '2026-07-02 20:39:16', '2026-07-02 20:39:16', NULL),
(131, 108, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-07-02 20:41:53', '2026-07-02 20:41:53', NULL),
(132, 109, 'consumption', 'استهلاك مياه: 2 وحدة × 600 ريال', 1200.00, '2026-07-02 20:43:10', '2026-07-02 20:43:10', NULL),
(133, 109, 'subscription', 'قسط اشتراك (المسدد: 0 من 7000)', 7000.00, '2026-07-02 20:43:10', '2026-07-02 20:43:10', NULL),
(134, 110, 'consumption', 'استهلاك مياه: 5 وحدة × 600 ريال', 3000.00, '2026-07-02 20:44:52', '2026-07-02 20:44:52', NULL),
(135, 111, 'consumption', 'استهلاك مياه: 6 وحدة × 600 ريال', 3600.00, '2026-07-02 20:46:41', '2026-07-02 20:46:41', NULL),
(136, 112, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 20:55:24', '2026-07-02 20:55:24', NULL),
(137, 113, 'consumption', 'استهلاك مياه: 4 وحدة × 600 ريال', 2400.00, '2026-07-02 20:57:09', '2026-07-02 20:57:09', NULL),
(138, 114, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 20:58:56', '2026-07-02 20:58:56', NULL),
(139, 116, 'consumption', 'استهلاك مياه: 13 وحدة × 600 ريال', 7800.00, '2026-07-02 21:12:47', '2026-07-02 21:12:47', NULL),
(140, 116, 'subscription', 'قسط اشتراك (المسدد: 0 من 35000)', 35000.00, '2026-07-02 21:12:47', '2026-07-02 21:12:47', NULL),
(141, 117, 'consumption', 'استهلاك مياه: 10 وحدة × 600 ريال', 6000.00, '2026-07-02 21:44:43', '2026-07-02 21:44:43', NULL),
(142, 117, 'subscription', 'قسط اشتراك (المسدد: 0 من 10000)', 10000.00, '2026-07-02 21:44:43', '2026-07-02 21:44:43', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meters`
--

CREATE TABLE `meters` (
  `id` bigint UNSIGNED NOT NULL,
  `unique_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الرقم المكتوب على العداد',
  `path_number` int DEFAULT NULL,
  `tenant_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenant_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `installed_at` date DEFAULT NULL,
  `removed_at` date DEFAULT NULL,
  `initial_reading` int NOT NULL DEFAULT '0',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `meters`
--

INSERT INTO `meters` (`id`, `unique_code`, `path_number`, `tenant_name`, `tenant_phone`, `subscriber_id`, `status`, `installed_at`, `removed_at`, `initial_reading`, `balance`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, '2025063598', NULL, NULL, NULL, 1, 'active', NULL, NULL, 0, -36600.00, '2026-06-09 00:22:03', '2026-07-02 19:04:30', NULL),
(2, '2025060939', NULL, NULL, NULL, 2, 'active', NULL, NULL, 0, -24000.00, '2026-06-09 00:22:03', '2026-07-02 17:24:56', NULL),
(3, '2025061601', NULL, NULL, NULL, 3, 'active', NULL, NULL, 0, -4800.00, '2026-06-09 00:22:03', '2026-07-02 17:27:05', NULL),
(4, '2025062306', NULL, NULL, NULL, 4, 'active', NULL, NULL, 0, -6600.00, '2026-06-09 00:22:03', '2026-07-02 17:27:58', NULL),
(5, '2025060419', NULL, NULL, NULL, 5, 'active', NULL, NULL, 0, -7200.00, '2026-06-09 00:22:03', '2026-07-02 17:28:48', NULL),
(6, '2025061649', NULL, NULL, NULL, 6, 'active', NULL, NULL, 0, -6000.00, '2026-06-09 00:22:03', '2026-07-02 19:23:31', NULL),
(7, '2025561666', NULL, NULL, NULL, 7, 'active', NULL, NULL, 0, -14200.00, '2026-06-09 00:22:03', '2026-07-02 17:49:49', NULL),
(8, '2025063781', NULL, NULL, NULL, 8, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-07-02 17:53:24', NULL),
(9, '2025060646', NULL, NULL, NULL, 9, 'active', NULL, NULL, 0, -20700.00, '2026-06-09 00:22:03', '2026-07-02 17:59:01', NULL),
(10, '2025060407', NULL, NULL, NULL, 10, 'active', NULL, NULL, 0, -27000.00, '2026-06-09 00:22:03', '2026-07-02 18:09:58', NULL),
(11, '2025061603', NULL, NULL, NULL, 11, 'active', NULL, NULL, 0, -11400.00, '2026-06-09 00:22:03', '2026-07-02 18:11:17', NULL),
(12, '2025063641', NULL, NULL, NULL, 12, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-07-02 18:19:03', NULL),
(13, '2025062369', NULL, NULL, NULL, 13, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-07-02 18:19:17', NULL),
(14, '2025060956', NULL, NULL, NULL, 14, 'active', NULL, NULL, 0, -15000.00, '2026-06-09 00:22:03', '2026-07-02 18:22:58', NULL),
(15, '2025062470', NULL, NULL, NULL, 15, 'active', NULL, NULL, 0, -9600.00, '2026-06-09 00:22:03', '2026-07-02 18:29:36', NULL),
(16, '2025062485', NULL, NULL, NULL, 16, 'active', NULL, NULL, 0, -18000.00, '2026-06-09 00:22:03', '2026-07-02 18:30:32', NULL),
(17, '2025063533', NULL, NULL, NULL, 17, 'active', NULL, NULL, 0, -4200.00, '2026-06-09 00:22:03', '2026-07-02 18:33:49', NULL),
(18, '2025062309', NULL, NULL, NULL, 18, 'active', NULL, NULL, 0, -21800.00, '2026-06-09 00:22:03', '2026-07-02 18:38:25', NULL),
(19, '202506249567', NULL, NULL, NULL, 19, 'active', NULL, NULL, 0, -78800.00, '2026-06-09 00:22:03', '2026-07-02 18:35:58', NULL),
(20, '2025062495', NULL, NULL, NULL, 20, 'active', NULL, NULL, 0, -12600.00, '2026-06-09 00:22:03', '2026-07-02 18:32:35', NULL),
(21, '2025061658', 1, NULL, NULL, 21, 'active', NULL, NULL, 0, -55800.00, '2026-06-09 00:22:03', '2026-07-28 20:52:10', NULL),
(22, '2025060461', NULL, NULL, NULL, 22, 'active', NULL, NULL, 0, -48000.00, '2026-06-09 00:22:03', '2026-07-02 17:23:11', NULL),
(23, '2025062377', NULL, NULL, NULL, 23, 'active', NULL, NULL, 0, -3100.00, '2026-06-09 00:22:03', '2026-07-02 18:45:23', NULL),
(24, '2025063515', NULL, NULL, NULL, 24, 'active', NULL, NULL, 0, -11000.00, '2026-06-09 00:22:03', '2026-07-02 18:50:56', NULL),
(25, '2025061655', NULL, NULL, NULL, 25, 'active', NULL, NULL, 0, -1800.00, '2026-06-09 00:22:03', '2026-07-02 19:03:44', NULL),
(26, '2025061698', NULL, NULL, NULL, 26, 'active', NULL, NULL, 0, -1200.00, '2026-06-09 00:22:03', '2026-07-29 02:12:55', NULL),
(27, '202506', NULL, NULL, NULL, 27, 'active', NULL, NULL, 0, -9800.00, '2026-06-09 00:22:03', '2026-07-02 18:56:30', NULL),
(28, '2025062445', NULL, NULL, NULL, 28, 'active', NULL, NULL, 0, -5000.00, '2026-06-09 00:22:03', '2026-07-29 02:12:55', NULL),
(29, '2025060134', NULL, NULL, NULL, 29, 'active', NULL, NULL, 0, -23400.00, '2026-06-09 00:22:03', '2026-07-02 19:12:48', NULL),
(30, '2025063552', NULL, NULL, NULL, 30, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-07-05 16:21:29', NULL),
(31, '20255060092', NULL, NULL, NULL, 31, 'active', NULL, NULL, 0, 0.00, '2026-06-09 00:22:03', '2026-07-02 19:16:52', NULL),
(32, '2025061981', NULL, NULL, NULL, 32, 'active', NULL, NULL, 0, -13200.00, '2026-06-09 00:22:03', '2026-07-02 19:18:01', NULL),
(33, '2025060143', NULL, NULL, NULL, 33, 'active', NULL, NULL, 0, -22300.00, '2026-06-09 00:22:03', '2026-07-02 19:29:01', NULL),
(34, '2025061902', NULL, NULL, NULL, 34, 'active', NULL, NULL, 0, -15500.00, '2026-06-09 00:22:03', '2026-07-02 19:24:32', NULL),
(35, '2025061661', NULL, NULL, NULL, 35, 'active', NULL, NULL, 0, -15400.00, '2026-06-09 00:22:03', '2026-07-02 19:32:54', NULL),
(36, '2025060983', NULL, NULL, NULL, 36, 'active', NULL, NULL, 0, -52400.00, '2026-06-09 00:22:03', '2026-07-02 19:34:30', NULL),
(37, '2025063796', NULL, NULL, NULL, 37, 'active', NULL, NULL, 0, -22800.00, '2026-06-09 00:22:03', '2026-07-02 19:36:49', NULL),
(38, '2025063760', NULL, NULL, NULL, 38, 'active', NULL, NULL, 0, -56700.00, '2026-06-09 00:22:03', '2026-07-02 19:39:26', NULL),
(39, '2025061646', NULL, NULL, NULL, 39, 'active', NULL, NULL, 0, -46500.00, '2026-06-09 00:22:03', '2026-07-02 19:41:38', NULL),
(40, '2025060761', NULL, NULL, NULL, 40, 'active', NULL, NULL, 0, -62100.00, '2026-06-09 00:22:03', '2026-07-02 19:46:00', NULL),
(41, '2025067099', NULL, NULL, NULL, 41, 'active', NULL, NULL, 0, -43800.00, '2026-06-09 00:22:03', '2026-07-02 19:48:45', NULL),
(42, '2025067437', NULL, NULL, NULL, 42, 'active', NULL, NULL, 0, -66600.00, '2026-06-09 00:22:03', '2026-07-02 19:50:11', NULL),
(43, '2025060644', 2, NULL, NULL, 43, 'active', NULL, NULL, 0, -16000.00, '2026-06-09 00:22:03', '2026-07-28 23:31:23', NULL),
(44, '2403541425', NULL, NULL, NULL, 44, 'active', NULL, NULL, 0, 0.00, '2026-07-02 16:36:55', '2026-07-02 16:36:55', NULL),
(45, '2403532278', NULL, NULL, NULL, 45, 'active', NULL, NULL, 0, 0.00, '2026-07-02 19:57:48', '2026-07-05 16:22:34', NULL),
(47, '2403539646', NULL, NULL, NULL, 46, 'active', NULL, NULL, 0, -16200.00, '2026-07-02 20:05:39', '2026-07-02 20:05:54', NULL),
(48, '033626', NULL, NULL, NULL, 47, 'active', NULL, NULL, 0, -3600.00, '2026-07-02 20:09:01', '2026-07-02 20:09:13', NULL),
(50, '2403532272', NULL, NULL, NULL, 48, 'active', NULL, NULL, 0, -9600.00, '2026-07-02 20:12:14', '2026-07-02 20:12:24', NULL),
(51, '2403522946', NULL, NULL, NULL, 49, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:16:31', '2026-07-02 20:16:31', NULL),
(53, '2025060094', NULL, NULL, NULL, 50, 'active', NULL, NULL, 0, -17400.00, '2026-07-02 20:19:49', '2026-07-02 20:20:10', NULL),
(54, '2403530816', NULL, NULL, NULL, 51, 'active', NULL, NULL, 0, -12600.00, '2026-07-02 20:22:28', '2026-07-02 20:22:49', NULL),
(55, '033637', NULL, NULL, NULL, 52, 'active', NULL, NULL, 0, -7800.00, '2026-07-02 20:24:02', '2026-07-02 20:24:29', NULL),
(56, '03624', NULL, NULL, NULL, 53, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:26:23', '2026-07-02 20:26:23', NULL),
(57, '2403522886', NULL, NULL, NULL, 54, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:27:29', '2026-07-02 20:27:29', NULL),
(58, '033623', NULL, NULL, NULL, 55, 'active', NULL, NULL, 0, -7800.00, '2026-07-02 20:29:01', '2026-07-02 20:29:18', NULL),
(59, '24035241435', NULL, NULL, NULL, 56, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:30:13', '2026-07-02 20:30:13', NULL),
(60, '2403541423', NULL, NULL, NULL, 57, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:31:11', '2026-07-02 20:31:11', NULL),
(61, '2403524048', NULL, NULL, NULL, 58, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:32:17', '2026-07-02 20:32:17', NULL),
(62, '24035396433', NULL, NULL, NULL, 59, 'active', NULL, NULL, 0, -9600.00, '2026-07-02 20:36:02', '2026-07-02 20:36:22', NULL),
(63, '20403531310', NULL, NULL, NULL, 60, 'active', NULL, NULL, 0, -2400.00, '2026-07-02 20:38:59', '2026-07-02 20:39:16', NULL),
(64, '2403539642', NULL, NULL, NULL, 61, 'active', NULL, NULL, 0, -8200.00, '2026-07-02 20:41:16', '2026-07-02 20:43:10', NULL),
(65, '2403539649', NULL, NULL, NULL, 62, 'active', NULL, NULL, 0, -3000.00, '2026-07-02 20:44:35', '2026-07-02 20:44:52', NULL),
(66, '033620', NULL, NULL, NULL, 63, 'active', NULL, NULL, 0, -3600.00, '2026-07-02 20:46:26', '2026-07-02 20:46:41', NULL),
(67, '2403531634', NULL, NULL, NULL, 64, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:48:32', '2026-07-02 20:48:32', NULL),
(68, '2403539641', NULL, NULL, NULL, 65, 'active', NULL, NULL, 0, 0.00, '2026-07-02 20:51:32', '2026-07-02 20:51:32', NULL),
(69, '2025067173', NULL, NULL, NULL, 66, 'active', NULL, NULL, 0, -42800.00, '2026-07-02 20:54:52', '2026-07-02 21:12:47', NULL),
(70, '2025067117', NULL, NULL, NULL, 67, 'active', NULL, NULL, 0, -2400.00, '2026-07-02 20:56:54', '2026-07-02 20:57:09', NULL),
(71, '2025067554', NULL, NULL, NULL, 68, 'active', NULL, NULL, 0, -6000.00, '2026-07-02 20:58:40', '2026-07-02 20:58:56', NULL),
(72, '2403522949', NULL, NULL, NULL, 69, 'active', NULL, NULL, 0, 0.00, '2026-07-02 21:03:34', '2026-07-02 21:03:34', NULL),
(73, '2403525926', NULL, NULL, NULL, 70, 'active', NULL, NULL, 0, 0.00, '2026-07-02 21:06:29', '2026-07-02 21:06:29', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

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
(28, '2026_06_16_213345_isolate_meter_accounts_add_meter_id_to_payments_and_transactions', 5),
(29, '2026_06_29_171826_add_reason_to_payments_table', 6),
(30, '2026_07_21_231307_add_invoice_id_to_service_requests_table', 7),
(31, '2026_07_22_034823_create_subscription_fees_table', 8),
(32, '2026_07_22_134245_add_invoice_type_to_invoices_table', 9),
(33, '2026_07_23_034454_add_period_columns_to_readings_table', 10),
(34, '2026_07_23_172850_make_reading_periods_not_null', 11),
(35, '2026_07_24_001641_create_permission_tables', 12),
(36, '2026_07_24_010008_create_personal_access_tokens_table', 13),
(37, '2026_07_24_200438_add_user_id_to_payments_table', 14),
(38, '2026_07_28_231623_add_path_number_to_meters_table', 15);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint UNSIGNED NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint UNSIGNED NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
(1, 'App\\Models\\User', 1),
(5, 'App\\Models\\User', 2),
(3, 'App\\Models\\User', 3),
(3, 'App\\Models\\User', 4),
(5, 'App\\Models\\User', 4);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
  `invoice_id` bigint UNSIGNED DEFAULT NULL,
  `receipt_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم سند القبض',
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','check') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `meter_id` bigint UNSIGNED DEFAULT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `subscriber_id`, `invoice_id`, `receipt_number`, `amount`, `payment_date`, `payment_method`, `reference_number`, `notes`, `reason`, `created_at`, `updated_at`, `deleted_at`, `meter_id`, `user_id`) VALUES
(1, 44, 1, 'REC-202607-0001', 35000.00, '2026-07-02', 'bank_transfer', 'ايداع جيب', NULL, 'اشتراك وتوصيل عداد مياه', '2026-07-02 16:57:44', '2026-07-02 17:01:40', NULL, NULL, NULL),
(2, 22, 24, 'REC-202607-0002', 57000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:22:16', '2026-07-02 17:22:16', NULL, 22, NULL),
(3, 2, 4, 'REC-202607-0003', 19200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:24:08', '2026-07-02 17:24:08', NULL, 2, NULL),
(4, 3, 5, 'REC-202607-0004', 4200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:26:38', '2026-07-02 17:26:38', NULL, 3, NULL),
(5, 4, 6, 'REC-202607-0005', 9000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:27:32', '2026-07-02 17:27:32', NULL, 4, NULL),
(6, 5, 7, 'REC-202607-0006', 8400.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:28:25', '2026-07-02 17:28:25', NULL, 5, NULL),
(7, 6, NULL, 'REC-202607-0007', 15000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:30:10', '2026-07-02 17:38:04', '2026-07-02 17:38:04', 6, NULL),
(8, 7, 9, 'REC-202607-0008', 11000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:49:18', '2026-07-02 17:49:18', NULL, 7, NULL),
(9, 8, 10, 'REC-202607-0009', 10200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 17:53:24', '2026-07-02 17:53:24', NULL, 8, NULL),
(10, 10, 12, 'REC-202607-0010', 31200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:09:21', '2026-07-02 18:09:21', NULL, 10, NULL),
(11, 11, 13, 'REC-202607-0011', 13800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:10:49', '2026-07-02 18:10:49', NULL, 11, NULL),
(12, 12, 57, 'REC-202607-0012', 48600.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:19:03', '2026-07-02 18:19:03', NULL, 12, NULL),
(13, 13, 15, 'REC-202607-0013', 12600.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:19:16', '2026-07-02 18:19:16', NULL, 13, NULL),
(14, 15, 17, 'REC-202607-0014', 5400.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:28:46', '2026-07-02 18:28:46', NULL, 15, NULL),
(15, 16, 18, 'REC-202607-0015', 12000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:30:13', '2026-07-02 18:30:13', NULL, 16, NULL),
(16, 20, 22, 'REC-202607-0016', 7800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:32:20', '2026-07-02 18:32:20', NULL, 20, NULL),
(17, 17, 19, 'REC-202607-0017', 10000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:33:12', '2026-07-02 18:33:12', NULL, 17, NULL),
(18, 17, 19, 'REC-202607-0018', 800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:33:26', '2026-07-02 18:33:26', NULL, 17, NULL),
(19, 19, 21, 'REC-202607-0019', 26800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:34:56', '2026-07-02 18:34:56', NULL, 19, NULL),
(20, 18, 20, 'REC-202607-0020', 26200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:37:29', '2026-07-02 18:37:29', NULL, 18, NULL),
(21, 21, 23, 'REC-202607-0021', 57000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:41:59', '2026-07-02 18:41:59', NULL, 21, NULL),
(22, 23, 25, 'REC-202607-0022', 3500.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:45:09', '2026-07-02 18:45:09', NULL, 23, NULL),
(23, 43, 45, 'REC-202607-0023', 4200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:46:10', '2026-07-02 18:46:10', NULL, 43, NULL),
(24, 24, 26, 'REC-202607-0024', 4800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:49:10', '2026-07-02 18:49:10', NULL, 24, NULL),
(25, 27, 29, 'REC-202607-0025', 4200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 18:54:46', '2026-07-02 18:54:46', NULL, 27, NULL),
(26, 25, 27, 'REC-202607-0026', 1200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:03:25', '2026-07-02 19:03:25', NULL, 25, NULL),
(27, 29, 74, 'REC-202607-0027', 39600.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:12:14', '2026-07-02 19:12:14', NULL, 29, NULL),
(28, 30, 32, 'REC-202607-0028', 30600.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:13:47', '2026-07-02 19:13:47', NULL, 30, NULL),
(29, 31, 33, 'REC-202607-0029', 10200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:16:52', '2026-07-02 19:16:52', NULL, 31, NULL),
(30, 32, 34, 'REC-202607-0030', 13200.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:17:41', '2026-07-02 19:17:41', NULL, 32, NULL),
(31, 34, 36, 'REC-202607-0031', 5400.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:18:32', '2026-07-02 19:20:22', '2026-07-02 19:20:22', 34, NULL),
(32, 34, 80, 'REC-202607-0032', 3600.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:22:10', '2026-07-02 19:22:10', NULL, 34, NULL),
(33, 33, 35, 'REC-202607-0033', 5400.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:27:28', '2026-07-02 19:27:28', NULL, 33, NULL),
(34, 36, 38, 'REC-202607-0034', 16000.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:33:59', '2026-07-02 19:33:59', NULL, 36, NULL),
(35, 41, 43, 'REC-202607-0035', 7800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:48:21', '2026-07-02 19:48:21', NULL, 41, NULL),
(36, 42, 44, 'REC-202607-0036', 4800.00, '2026-07-02', 'cash', NULL, NULL, NULL, '2026-07-02 19:49:49', '2026-07-02 19:49:49', NULL, 42, NULL),
(37, 28, 30, 'REC-202607-0037', 10600.00, '2026-07-03', 'cash', NULL, NULL, NULL, '2026-07-02 21:46:54', '2026-07-02 21:48:32', NULL, 28, NULL),
(38, 30, 77, 'REC-202607-0038', 72800.00, '2026-07-05', 'cash', NULL, NULL, NULL, '2026-07-05 16:21:28', '2026-07-05 16:21:28', NULL, 30, NULL),
(39, 45, 98, 'REC-202607-0039', 10200.00, '2026-07-05', 'cash', NULL, NULL, NULL, '2026-07-05 16:22:34', '2026-07-05 16:22:34', NULL, 45, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'ViewAny:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(2, 'View:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(3, 'Create:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(4, 'Update:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(5, 'Delete:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(6, 'DeleteAny:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(7, 'Restore:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(8, 'ForceDelete:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(9, 'ForceDeleteAny:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(10, 'RestoreAny:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(11, 'Replicate:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(12, 'Reorder:Invoice', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(13, 'ViewAny:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(14, 'View:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(15, 'Create:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(16, 'Update:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(17, 'Delete:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(18, 'DeleteAny:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(19, 'Restore:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(20, 'ForceDelete:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(21, 'ForceDeleteAny:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(22, 'RestoreAny:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(23, 'Replicate:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(24, 'Reorder:Meter', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(25, 'ViewAny:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(26, 'View:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(27, 'Create:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(28, 'Update:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(29, 'Delete:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(30, 'DeleteAny:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(31, 'Restore:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(32, 'ForceDelete:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(33, 'ForceDeleteAny:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(34, 'RestoreAny:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(35, 'Replicate:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(36, 'Reorder:Payment', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(37, 'ViewAny:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(38, 'View:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(39, 'Create:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(40, 'Update:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(41, 'Delete:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(42, 'DeleteAny:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(43, 'Restore:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(44, 'ForceDelete:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(45, 'ForceDeleteAny:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(46, 'RestoreAny:Reading', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(47, 'Replicate:Reading', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(48, 'Reorder:Reading', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(49, 'ViewAny:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(50, 'View:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(51, 'Create:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(52, 'Update:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(53, 'Delete:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(54, 'DeleteAny:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(55, 'Restore:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(56, 'ForceDelete:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(57, 'ForceDeleteAny:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(58, 'RestoreAny:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(59, 'Replicate:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(60, 'Reorder:ServiceRequest', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(61, 'ViewAny:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(62, 'View:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(63, 'Create:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(64, 'Update:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(65, 'Delete:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(66, 'DeleteAny:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(67, 'Restore:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(68, 'ForceDelete:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(69, 'ForceDeleteAny:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(70, 'RestoreAny:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(71, 'Replicate:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(72, 'Reorder:Setting', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(73, 'ViewAny:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(74, 'View:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(75, 'Create:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(76, 'Update:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(77, 'Delete:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(78, 'DeleteAny:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(79, 'Restore:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(80, 'ForceDelete:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(81, 'ForceDeleteAny:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(82, 'RestoreAny:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(83, 'Replicate:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(84, 'Reorder:Square', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(85, 'ViewAny:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(86, 'View:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(87, 'Create:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(88, 'Update:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(89, 'Delete:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(90, 'DeleteAny:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(91, 'Restore:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(92, 'ForceDelete:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(93, 'ForceDeleteAny:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(94, 'RestoreAny:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(95, 'Replicate:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(96, 'Reorder:Subscriber', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(97, 'ViewAny:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(98, 'View:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(99, 'Create:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(100, 'Update:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(101, 'Delete:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(102, 'DeleteAny:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(103, 'Restore:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(104, 'ForceDelete:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(105, 'ForceDeleteAny:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(106, 'RestoreAny:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(107, 'Replicate:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(108, 'Reorder:SubscriptionFee', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(109, 'ViewAny:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(110, 'View:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(111, 'Create:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(112, 'Update:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(113, 'Delete:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(114, 'DeleteAny:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(115, 'Restore:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(116, 'ForceDelete:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(117, 'ForceDeleteAny:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(118, 'RestoreAny:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(119, 'Replicate:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(120, 'Reorder:Tariff', 'web', '2026-07-23 21:18:56', '2026-07-23 21:18:56'),
(121, 'ViewAny:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(122, 'View:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(123, 'Create:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(124, 'Update:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(125, 'Delete:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(126, 'DeleteAny:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(127, 'Restore:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(128, 'ForceDelete:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(129, 'ForceDeleteAny:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(130, 'RestoreAny:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(131, 'Replicate:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(132, 'Reorder:Transaction', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(133, 'ViewAny:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(134, 'View:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(135, 'Create:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(136, 'Update:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(137, 'Delete:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(138, 'DeleteAny:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(139, 'Restore:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(140, 'ForceDelete:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(141, 'ForceDeleteAny:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(142, 'RestoreAny:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(143, 'Replicate:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(144, 'Reorder:User', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(145, 'ViewAny:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(146, 'View:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(147, 'Create:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(148, 'Update:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(149, 'Delete:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(150, 'DeleteAny:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(151, 'Restore:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(152, 'ForceDelete:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(153, 'ForceDeleteAny:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(154, 'RestoreAny:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(155, 'Replicate:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(156, 'Reorder:Role', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(157, 'View:EditProfilePage', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(158, 'View:InvoiceSettings', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(159, 'View:ReadingSettings', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(160, 'View:ReceiptSettings', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(161, 'View:WhatsAppSettings', 'web', '2026-07-23 21:18:57', '2026-07-23 21:18:57'),
(162, 'View:CustomAccountWidget', 'web', '2026-07-23 21:18:58', '2026-07-23 21:18:58'),
(163, 'View:StatsOverview', 'web', '2026-07-23 21:18:58', '2026-07-23 21:18:58'),
(164, 'View:LatestInvoicesWidget', 'web', '2026-07-23 21:18:58', '2026-07-23 21:18:58'),
(165, 'View:MonthlyRevenueChart', 'web', '2026-07-23 21:18:58', '2026-07-23 21:18:58'),
(166, 'View:OverdueInvoicesWidget', 'web', '2026-07-23 21:18:58', '2026-07-23 21:18:58');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(9, 'App\\Models\\User', 1, 'auth_token', 'f9d6d2962e376ad585ac6a0c282a12838cc2e7237944e7a823681378cdeb0f81', '[\"*\"]', '2026-07-27 11:40:59', NULL, '2026-07-25 11:49:51', '2026-07-27 11:40:59'),
(11, 'App\\Models\\User', 1, 'auth_token', 'a3f6642261442f91cddd93cf45a97a24578a3900360de324cedbefd4ba1f6885', '[\"*\"]', NULL, NULL, '2026-07-28 17:58:03', '2026-07-28 17:58:03'),
(13, 'App\\Models\\User', 1, 'auth_token', '16cc22ad912af1d371de9c8110819e834a204681d5063a06125e2fedc08df02a', '[\"*\"]', NULL, NULL, '2026-07-28 18:07:42', '2026-07-28 18:07:42'),
(15, 'App\\Models\\User', 1, 'auth_token', '69c78d97f6132741a537808d4147f7d7fd87fc87f027f6e4e14fdfc03240838a', '[\"*\"]', '2026-07-29 03:25:33', NULL, '2026-07-28 18:11:10', '2026-07-29 03:25:33');

-- --------------------------------------------------------

--
-- Table structure for table `readings`
--

CREATE TABLE `readings` (
  `id` bigint UNSIGNED NOT NULL,
  `meter_id` bigint UNSIGNED NOT NULL,
  `reading_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `actual_reading_date` date DEFAULT NULL,
  `previous_reading` decimal(12,2) NOT NULL DEFAULT '0.00',
  `current_reading` decimal(12,2) NOT NULL,
  `consumption_units` int NOT NULL DEFAULT '0' COMMENT 'الاستهلاك بالوحدات',
  `is_invoiced` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'هل تم فوترة هذه القراءة',
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `invoice_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `readings`
--

INSERT INTO `readings` (`id`, `meter_id`, `reading_date`, `period_start`, `period_end`, `actual_reading_date`, `previous_reading`, `current_reading`, `consumption_units`, `is_invoiced`, `created_by`, `created_at`, `updated_at`, `deleted_at`, `invoice_id`) VALUES
(1, 1, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 22.00, 22, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 3),
(2, 2, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 32.00, 32, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 4),
(3, 3, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 7.00, 7, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 5),
(4, 4, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 15.00, 15, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 6),
(5, 5, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 14.00, 14, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 7),
(6, 6, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 10.00, 10, 1, 1, '2026-07-02 16:09:48', '2026-07-02 19:23:31', NULL, 82),
(7, 7, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 19.00, 19, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 9),
(8, 8, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 17.00, 17, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 10),
(9, 9, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 22.00, 22, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 11),
(10, 10, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 52.00, 52, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 12),
(11, 11, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 23.00, 23, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 13),
(12, 12, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 43.00, 43, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 14),
(13, 13, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 21.00, 21, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 15),
(14, 14, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 12.00, 12, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 16),
(15, 15, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 9.00, 9, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 17),
(16, 16, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 20.00, 20, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 18),
(17, 17, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 18.00, 18, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 19),
(18, 18, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 52.00, 52, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 20),
(19, 19, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 53.00, 53, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 21),
(20, 20, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 13.00, 13, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 22),
(21, 21, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 95.00, 95, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 23),
(22, 22, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 95.00, 95, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 24),
(23, 23, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 9.00, 9, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 25),
(24, 24, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 8.00, 8, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 26),
(25, 25, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 2.00, 2, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 27),
(26, 26, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 2.00, 2, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 28),
(27, 27, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 7.00, 7, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 29),
(28, 28, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 26.00, 26, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 30),
(29, 29, '2026-05-31', '2026-05-31', '2026-05-31', '2026-05-31', 0.00, 66.00, 66, 1, 1, '2026-07-02 16:09:48', '2026-07-02 19:09:31', NULL, 74),
(30, 30, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 51.00, 51, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 32),
(31, 31, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 17.00, 17, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 33),
(32, 32, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 22.00, 22, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 34),
(33, 33, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 9.00, 9, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 35),
(34, 34, '2026-05-31', '2026-05-31', '2026-05-31', '2026-05-31', 0.00, 6.00, 6, 1, 1, '2026-07-02 16:09:48', '2026-07-02 19:21:30', NULL, 80),
(35, 35, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 5.00, 5, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 37),
(36, 36, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 35.00, 35, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 38),
(37, 37, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 0.00, 0, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:01', NULL, 39),
(38, 38, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 5.00, 5, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 40),
(39, 39, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 10.00, 10, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 41),
(40, 40, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 25.00, 25, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 42),
(41, 41, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 13.00, 13, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 43),
(42, 42, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 8.00, 8, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 44),
(43, 43, '2026-05-30', '2026-05-30', '2026-05-30', '2026-05-31', 0.00, 7.00, 7, 1, 1, '2026-07-02 16:09:48', '2026-07-02 17:17:02', NULL, 45),
(44, 18, '2026-06-30', '2026-06-30', '2026-06-30', '2026-07-02', 52.00, 80.00, 28, 0, 1, '2026-07-02 17:09:38', '2026-07-02 17:11:48', '2026-07-02 17:11:48', NULL),
(45, 22, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 95.00, 175.00, 80, 1, 1, '2026-07-02 17:23:11', '2026-07-02 17:23:11', NULL, 46),
(46, 2, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 32.00, 72.00, 40, 1, 1, '2026-07-02 17:24:56', '2026-07-02 17:24:56', NULL, 47),
(47, 3, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 7.00, 15.00, 8, 1, 1, '2026-07-02 17:27:05', '2026-07-02 17:27:05', NULL, 48),
(48, 4, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 15.00, 26.00, 11, 1, 1, '2026-07-02 17:27:58', '2026-07-02 17:27:58', NULL, 49),
(49, 5, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 14.00, 26.00, 12, 1, 1, '2026-07-02 17:28:48', '2026-07-02 17:28:48', NULL, 50),
(50, 6, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 10.00, 19.00, 9, 0, 1, '2026-07-02 17:32:30', '2026-07-02 17:37:10', '2026-07-02 17:37:10', NULL),
(51, 7, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 19.00, 42.00, 23, 1, 1, '2026-07-02 17:49:49', '2026-07-02 17:49:49', NULL, 53),
(52, 8, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 17.00, 17.00, 0, 1, 1, '2026-07-02 17:53:47', '2026-07-02 17:53:47', NULL, 54),
(53, 10, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 52.00, 97.00, 45, 1, 1, '2026-07-02 18:09:58', '2026-07-02 18:09:58', NULL, 55),
(54, 11, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 23.00, 42.00, 19, 1, 1, '2026-07-02 18:11:17', '2026-07-02 18:11:17', NULL, 56),
(55, 12, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 43.00, 81.00, 38, 1, 1, '2026-07-02 18:17:54', '2026-07-02 18:17:54', NULL, 57),
(56, 14, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 12.00, 25.00, 13, 1, 1, '2026-07-02 18:22:58', '2026-07-02 18:22:58', NULL, 58),
(57, 15, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 9.00, 25.00, 16, 1, 1, '2026-07-02 18:29:36', '2026-07-02 18:29:36', NULL, 59),
(58, 16, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 20.00, 50.00, 30, 1, 1, '2026-07-02 18:30:31', '2026-07-02 18:30:31', NULL, 60),
(59, 20, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 13.00, 34.00, 21, 1, 1, '2026-07-02 18:32:35', '2026-07-02 18:32:35', NULL, 61),
(60, 17, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 18.00, 25.00, 7, 1, 1, '2026-07-02 18:33:49', '2026-07-02 18:33:49', NULL, 62),
(61, 19, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 53.00, 176.00, 123, 1, 1, '2026-07-02 18:35:57', '2026-07-02 18:35:58', NULL, 63),
(62, 18, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 52.00, 80.00, 28, 1, 1, '2026-07-02 18:38:25', '2026-07-02 18:38:25', NULL, 64),
(63, 21, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 95.00, 188.00, 93, 1, 1, '2026-07-02 18:42:29', '2026-07-02 18:42:29', NULL, 65),
(64, 23, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 9.00, 11.00, 2, 1, 1, '2026-07-02 18:45:22', '2026-07-02 18:45:23', NULL, 66),
(65, 43, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 7.00, 17.00, 10, 1, 1, '2026-07-02 18:46:23', '2026-07-02 21:44:43', NULL, 117),
(66, 24, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 8.00, 18.00, 10, 1, 1, '2026-07-02 18:50:12', '2026-07-02 18:50:56', NULL, 69),
(67, 27, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 7.00, 15.00, 8, 1, 1, '2026-07-02 18:55:44', '2026-07-02 18:56:30', NULL, 71),
(68, 25, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 2.00, 5.00, 3, 1, 1, '2026-07-02 19:03:44', '2026-07-02 19:03:44', NULL, 72),
(69, 1, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 22.00, 61.00, 39, 1, 1, '2026-07-02 19:04:30', '2026-07-02 19:04:30', NULL, 73),
(70, 29, '2026-06-30', '2026-06-30', '2026-06-30', '2026-07-02', 66.00, 105.00, 39, 1, 1, '2026-07-02 19:12:48', '2026-07-02 19:12:48', NULL, 75),
(71, 30, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 51.00, 114.00, 63, 1, 1, '2026-07-02 19:15:12', '2026-07-02 19:15:56', NULL, 77),
(72, 31, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 17.00, 17.00, 0, 1, 1, '2026-07-02 19:17:12', '2026-07-02 19:17:12', NULL, 78),
(73, 32, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 22.00, 44.00, 22, 1, 1, '2026-07-02 19:18:01', '2026-07-02 19:18:01', NULL, 79),
(74, 34, '2026-06-30', '2026-06-30', '2026-06-30', '2026-07-02', 6.00, 11.00, 5, 1, 1, '2026-07-02 19:22:50', '2026-07-02 19:24:32', NULL, 83),
(75, 33, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 9.00, 17.00, 8, 1, 1, '2026-07-02 19:28:23', '2026-07-02 19:29:01', NULL, 85),
(76, 35, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 5.00, 9.00, 4, 1, 1, '2026-07-02 19:32:22', '2026-07-02 19:32:54', NULL, 87),
(77, 36, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 35.00, 114.00, 79, 1, 1, '2026-07-02 19:34:30', '2026-07-02 19:34:30', NULL, 88),
(78, 37, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 0.00, 38.00, 38, 1, 1, '2026-07-02 19:36:49', '2026-07-02 19:36:49', NULL, 89),
(79, 38, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 5.00, 32.00, 27, 1, 1, '2026-07-02 19:38:37', '2026-07-02 19:39:26', NULL, 91),
(80, 39, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 10.00, 15.00, 5, 1, 1, '2026-07-02 19:41:03', '2026-07-02 19:41:38', NULL, 93),
(81, 40, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 25.00, 41.00, 16, 1, 1, '2026-07-02 19:45:17', '2026-07-02 19:46:00', NULL, 95),
(82, 41, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 13.00, 86.00, 73, 1, 1, '2026-07-02 19:48:45', '2026-07-02 19:48:45', NULL, 96),
(83, 42, '2026-07-02', '2026-07-02', '2026-06-30', '2026-07-02', 8.00, 119.00, 111, 1, 1, '2026-07-02 19:50:11', '2026-07-02 19:50:11', NULL, 97),
(84, 45, '2026-07-31', '2026-07-31', '2026-07-31', '2026-07-02', 0.00, 17.00, 17, 1, 1, '2026-07-02 20:00:33', '2026-07-02 20:00:33', NULL, 98),
(85, 47, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 27.00, 27, 1, 1, '2026-07-02 20:05:53', '2026-07-02 20:05:53', NULL, 99),
(86, 48, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 6.00, 6, 1, 1, '2026-07-02 20:09:13', '2026-07-02 20:09:13', NULL, 100),
(87, 50, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 16.00, 16, 1, 1, '2026-07-02 20:12:24', '2026-07-02 20:12:24', NULL, 101),
(88, 53, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 29.00, 29, 1, 1, '2026-07-02 20:20:09', '2026-07-02 20:20:10', NULL, 102),
(89, 54, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 21.00, 21, 1, 1, '2026-07-02 20:22:49', '2026-07-02 20:22:49', NULL, 103),
(90, 55, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 13.00, 13, 1, 1, '2026-07-02 20:24:29', '2026-07-02 20:24:29', NULL, 104),
(91, 58, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 13.00, 13, 1, 1, '2026-07-02 20:29:18', '2026-07-02 20:29:18', NULL, 105),
(92, 62, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 16.00, 16, 1, 1, '2026-07-02 20:36:22', '2026-07-02 20:36:22', NULL, 106),
(93, 63, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 4.00, 4, 1, 1, '2026-07-02 20:39:16', '2026-07-02 20:39:16', NULL, 107),
(94, 64, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 2.00, 2, 1, 1, '2026-07-02 20:41:53', '2026-07-02 20:43:10', NULL, 109),
(95, 65, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 5.00, 5, 1, 1, '2026-07-02 20:44:52', '2026-07-02 20:44:52', NULL, 110),
(96, 66, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 6.00, 6, 1, 1, '2026-07-02 20:46:41', '2026-07-02 20:46:41', NULL, 111),
(97, 69, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 13.00, 13, 1, 1, '2026-07-02 20:55:24', '2026-07-02 21:12:47', NULL, 116),
(98, 70, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 4.00, 4, 1, 1, '2026-07-02 20:57:09', '2026-07-02 20:57:09', NULL, 113),
(99, 71, '2026-07-02', '2026-07-02', '2026-06-30', NULL, 0.00, 10.00, 10, 1, 1, '2026-07-02 20:58:56', '2026-07-02 20:58:56', NULL, 114);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'web', '2026-07-23 21:18:55', '2026-07-23 21:18:55'),
(2, 'admin', 'web', '2026-07-23 21:20:13', '2026-07-23 21:20:13'),
(3, 'collector', 'web', '2026-07-23 21:20:13', '2026-07-23 21:20:13'),
(4, 'meter_reader', 'web', '2026-07-23 21:20:13', '2026-07-23 21:20:13'),
(5, 'reader', 'web', '2026-07-24 00:08:53', '2026-07-24 00:08:53');

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_has_permissions`
--

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 1),
(6, 1),
(7, 1),
(8, 1),
(9, 1),
(10, 1),
(11, 1),
(12, 1),
(13, 1),
(14, 1),
(15, 1),
(16, 1),
(17, 1),
(18, 1),
(19, 1),
(20, 1),
(21, 1),
(22, 1),
(23, 1),
(24, 1),
(25, 1),
(26, 1),
(27, 1),
(28, 1),
(29, 1),
(30, 1),
(31, 1),
(32, 1),
(33, 1),
(34, 1),
(35, 1),
(36, 1),
(37, 1),
(38, 1),
(39, 1),
(40, 1),
(41, 1),
(42, 1),
(43, 1),
(44, 1),
(45, 1),
(46, 1),
(47, 1),
(48, 1),
(49, 1),
(50, 1),
(51, 1),
(52, 1),
(53, 1),
(54, 1),
(55, 1),
(56, 1),
(57, 1),
(58, 1),
(59, 1),
(60, 1),
(61, 1),
(62, 1),
(63, 1),
(64, 1),
(65, 1),
(66, 1),
(67, 1),
(68, 1),
(69, 1),
(70, 1),
(71, 1),
(72, 1),
(73, 1),
(74, 1),
(75, 1),
(76, 1),
(77, 1),
(78, 1),
(79, 1),
(80, 1),
(81, 1),
(82, 1),
(83, 1),
(84, 1),
(85, 1),
(86, 1),
(87, 1),
(88, 1),
(89, 1),
(90, 1),
(91, 1),
(92, 1),
(93, 1),
(94, 1),
(95, 1),
(96, 1),
(97, 1),
(98, 1),
(99, 1),
(100, 1),
(101, 1),
(102, 1),
(103, 1),
(104, 1),
(105, 1),
(106, 1),
(107, 1),
(108, 1),
(109, 1),
(110, 1),
(111, 1),
(112, 1),
(113, 1),
(114, 1),
(115, 1),
(116, 1),
(117, 1),
(118, 1),
(119, 1),
(120, 1),
(121, 1),
(122, 1),
(123, 1),
(124, 1),
(125, 1),
(126, 1),
(127, 1),
(128, 1),
(129, 1),
(130, 1),
(131, 1),
(132, 1),
(133, 1),
(134, 1),
(135, 1),
(136, 1),
(137, 1),
(138, 1),
(139, 1),
(140, 1),
(141, 1),
(142, 1),
(143, 1),
(144, 1),
(145, 1),
(146, 1),
(147, 1),
(148, 1),
(149, 1),
(150, 1),
(151, 1),
(152, 1),
(153, 1),
(154, 1),
(155, 1),
(156, 1),
(157, 1),
(158, 1),
(159, 1),
(160, 1),
(161, 1),
(162, 1),
(163, 1),
(164, 1),
(165, 1),
(166, 1),
(1, 3),
(2, 3),
(25, 3),
(26, 3),
(27, 3),
(28, 3),
(37, 3),
(38, 3),
(133, 3),
(134, 3),
(13, 5),
(14, 5),
(37, 5),
(38, 5),
(39, 5),
(40, 5),
(133, 5),
(134, 5);

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

CREATE TABLE `service_requests` (
  `id` bigint UNSIGNED NOT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
  `service_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'water_tanker',
  `amount` decimal(12,2) NOT NULL,
  `service_date` date NOT NULL,
  `status` enum('pending','completed','invoiced','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `invoice_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_requests`
--

INSERT INTO `service_requests` (`id`, `subscriber_id`, `service_type`, `amount`, `service_date`, `status`, `notes`, `created_at`, `updated_at`, `deleted_at`, `invoice_id`) VALUES
(2, 6, 'water_tanker', 12000.00, '2026-07-02', 'invoiced', NULL, '2026-07-02 17:31:54', '2026-07-02 17:39:06', '2026-07-02 17:39:06', NULL),
(20, 1, 'water_tanker', 6000.00, '2026-07-26', 'completed', NULL, '2026-07-26 15:54:27', '2026-07-26 15:54:27', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('MqlhBhdiFNJrqDk7szr38TKt6426Uh2HtKLm1nUj', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiIwbG16S2ZyVVJEWjhvREIyUVdSZTZ1TG1wV0hnVUNZMHBOUUdhU2hwIiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsInBhc3N3b3JkX2hhc2hfd2ViIjoiMzlmMzFkY2QzM2UyMDUyNjNjOTAzNjRiYTVjZTY0MjM4MGZlOWNlZThiZTcwY2M4Njc3YzIzMjc0YWU2NDBmNyIsIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC93YXRlcl9wcm9qZWN0LnRlc3RcL2FkbWluIiwicm91dGUiOiJmaWxhbWVudC5hZG1pbi5wYWdlcy5kYXNoYm9hcmQifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==', 1785323872),
('tkKG1gfdaWOZEbPYFWl7HIBNm9iVuxWhFkdiwdLs', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJ3bWp3ejVmbEN6Wnk3cjZCYTF5TlBIbG82UXZzYmFLWTN2RDBVZmprIiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsInBhc3N3b3JkX2hhc2hfd2ViIjoiMzlmMzFkY2QzM2UyMDUyNjNjOTAzNjRiYTVjZTY0MjM4MGZlOWNlZThiZTcwY2M4Njc3YzIzMjc0YWU2NDBmNyIsIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC93YXRlcl9wcm9qZWN0LnRlc3RcL2FkbWluXC9yZWFkaW5ncyIsInJvdXRlIjoiZmlsYW1lbnQuYWRtaW4ucmVzb3VyY2VzLnJlYWRpbmdzLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwidGFibGVzIjp7IjlhYTIxOWMyYjBiMzg4YzBiNGFlNzhkMTYyYWEwMjY2X2NvbHVtbnMiOlt7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYmlsbF9udW1iZXIiLCJsYWJlbCI6Ilx1MDYzMVx1MDY0Mlx1MDY0NSBcdTA2MjdcdTA2NDRcdTA2NDFcdTA2MjdcdTA2MmFcdTA2NDhcdTA2MzFcdTA2MjkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoic3Vic2NyaWJlci5uYW1lIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MzRcdTA2MmFcdTA2MzFcdTA2NDMiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYmlsbF9kYXRlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MmFcdTA2MjdcdTA2MzFcdTA2NGFcdTA2MmUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoidG90YWxfYW1vdW50IiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MjVcdTA2MmNcdTA2NDVcdTA2MjdcdTA2NDRcdTA2NGEiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiYW1vdW50X3BhaWQiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzM1x1MDYyZlx1MDYyZiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiZTgxNTFmOWFjNGJmNjM2OWE2YWE1NGJiNTE4YTYzMmNfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJpZCIsImxhYmVsIjoiIyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJuYW1lIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MjdcdTA2MzNcdTA2NDUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoic3Vic2NyaXB0aW9uX3N0YXJ0X2RhdGUiLCJsYWJlbCI6Ilx1MDYyOFx1MDYyZlx1MDYyN1x1MDY0YVx1MDYyOSBcdTA2MjdcdTA2NDRcdTA2MjdcdTA2MzRcdTA2MmFcdTA2MzFcdTA2MjdcdTA2NDMiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoicGhvbmUiLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDY0N1x1MDYyN1x1MDYyYVx1MDY0MSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzcXVhcmUubmFtZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjMxXHUwNjI4XHUwNjM5IFx1MDYyN1x1MDY0NFx1MDYzM1x1MDY0M1x1MDY0Nlx1MDY0YSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJiYWxhbmNlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MzFcdTA2MzVcdTA2NGFcdTA2MmYgXHUwNjI3XHUwNjQ0XHUwNjJmXHUwNjI3XHUwNjI2XHUwNjQ2IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN0YXR1cyIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJkXHUwNjI3XHUwNjQ0XHUwNjI5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6Im1ldGVyc19jb3VudCIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjM5XHUwNjJmXHUwNjI3XHUwNjJmXHUwNjI3XHUwNjJhIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH1dLCJhNmI4YTc4OTcyMmNmYjI4Y2RhZTdkZjRlNjUwZDZlYV9jb2x1bW5zIjpbeyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImlkIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MzFcdTA2NDJcdTA2NDUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoibWV0ZXIudW5pcXVlX2NvZGUiLCJsYWJlbCI6Ilx1MDYzMVx1MDY0Mlx1MDY0NSBcdTA2MjdcdTA2NDRcdTA2MzlcdTA2MmZcdTA2MjdcdTA2MmYiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoibWV0ZXIuc3Vic2NyaWJlci5uYW1lIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2NDVcdTA2MzRcdTA2MmFcdTA2MzFcdTA2NDMgXC8gXHUwNjI3XHUwNjQ0XHUwNjQ1XHUwNjMzXHUwNjJhXHUwNjIzXHUwNjJjXHUwNjMxIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InJlYWRpbmdfZGF0ZSIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjJhXHUwNjI3XHUwNjMxXHUwNjRhXHUwNjJlIFx1MDYyN1x1MDY0NFx1MDY0NVx1MDYzOVx1MDYyYVx1MDY0NVx1MDYyZiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJhY3R1YWxfcmVhZGluZ19kYXRlIiwibGFiZWwiOiJcdTA2MjdcdTA2NDRcdTA2MmFcdTA2MjdcdTA2MzFcdTA2NGFcdTA2MmUgXHUwNjI3XHUwNjQ0XHUwNjQxXHUwNjM5XHUwNjQ0XHUwNjRhIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOmZhbHNlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6dHJ1ZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InByZXZpb3VzX3JlYWRpbmciLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYzM1x1MDYyN1x1MDYyOFx1MDY0Mlx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjdXJyZW50X3JlYWRpbmciLCJsYWJlbCI6Ilx1MDYyN1x1MDY0NFx1MDYyZFx1MDYyN1x1MDY0NFx1MDY0YVx1MDYyOSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJjb25zdW1wdGlvbl91bml0cyIsImxhYmVsIjoiXHUwNjI3XHUwNjQ0XHUwNjI3XHUwNjMzXHUwNjJhXHUwNjQ3XHUwNjQ0XHUwNjI3XHUwNjQzIChcdTA2NDhcdTA2MmRcdTA2MmZcdTA2MjkpIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImlzX2ludm9pY2VkIiwibGFiZWwiOiJcdTA2NDVcdTA2NDFcdTA2NDhcdTA2MmFcdTA2MzFcdTA2MjkiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfV19fQ==', 1785325506);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint UNSIGNED NOT NULL,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `value` text COLLATE utf8mb4_unicode_ci,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وصف الإعداد',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key`, `type`, `value`, `image_path`, `label`, `created_at`, `updated_at`) VALUES
(1, 'reading_period', 'text', 'monthly', NULL, 'دورة القراءات', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(2, 'allowed_days_before_period_end', 'text', '4', NULL, 'أيام مسموحة قبل', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(3, 'allowed_days_after_period_end', 'text', '4', NULL, 'أيام مسموحة بعد', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(4, 'unit_price', 'text', '600', NULL, 'سعر الوحدة (ريال)', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(5, 'currency', 'text', 'YER', NULL, 'العملة', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(6, 'subscription_fee', 'text', '0', NULL, 'رسوم الاشتراك', '2026-06-09 00:22:03', '2026-06-09 00:22:03'),
(7, 'invoice_project_name_ar', 'text', 'مشروع مياه الوشاح', NULL, 'اسم المشروع', '2026-06-09 00:22:03', '2026-07-27 19:16:43'),
(8, 'invoice_address_ar', 'text', 'حارة الفوارس – سعوان', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-07-21 20:59:01'),
(9, 'invoice_phone_1', 'text', '712271220', NULL, 'رقم الهاتف', '2026-06-09 00:22:03', '2026-07-27 19:16:43'),
(10, 'invoice_phone_2', 'text', '784888788', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-07-21 20:59:01'),
(11, 'invoice_project_name_en', 'text', 'Washah Water Project', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-07-21 20:59:01'),
(12, 'invoice_address_en', 'text', 'Harat Al-Fawaris - Sa\'wan', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-07-21 20:59:01'),
(13, 'invoice_collector_name', 'text', 'علي الوشاح', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-06-21 12:18:59'),
(14, 'invoice_logo', 'image', 'settings/invoices/01KTQ831TNG3BR0ZM4ANQPEGZ5.png', 'settings/01KTEMRP46MC0QFYDF991GCDP5.png', 'إعدادات الفاتورة المتقدمة', '2026-06-09 00:22:03', '2026-07-21 20:59:01'),
(15, 'invoice_watermark', 'text', 'settings/invoices/01KVP6ANGYHS1RA9FWY91EW7K9.png', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-09 19:13:42', '2026-07-21 20:59:01'),
(16, 'auto_issue_invoice_on_reading', 'text', '1', NULL, 'إصدار الفاتورة تلقائياً عند إدخال القراءة', '2026-06-16 19:51:51', '2026-07-27 22:46:55'),
(17, 'grace_period', 'text', '14', NULL, 'مدة السماح (أيام)', '2026-06-18 15:05:08', '2026-06-18 15:05:08'),
(18, 'strict_overdue_on_new_invoice', 'text', '1', NULL, 'اعتبار الفاتورة متأخرة عند الإصدار الجديد', '2026-06-18 15:05:08', '2026-07-23 01:43:17'),
(19, 'whatsapp_enabled', 'text', '0', NULL, NULL, '2026-06-18 21:20:03', '2026-07-20 21:00:47'),
(20, 'whatsapp_phone_number_id', 'text', '', NULL, NULL, '2026-06-18 21:20:03', '2026-06-20 12:02:53'),
(21, 'whatsapp_access_token', 'text', '', NULL, NULL, '2026-06-18 21:20:03', '2026-06-20 12:02:53'),
(22, 'whatsapp_invoice_template', 'text', '', NULL, NULL, '2026-06-18 21:20:03', '2026-06-20 12:02:53'),
(23, 'whatsapp_payment_template', 'text', '', NULL, NULL, '2026-06-18 21:20:03', '2026-06-20 12:02:53'),
(24, 'whatsapp_method', 'text', 'click_to_chat', NULL, NULL, '2026-06-20 12:02:53', '2026-07-20 21:00:47'),
(25, 'whatsapp_invoice_message', 'text', '', NULL, NULL, '2026-06-20 12:02:53', '2026-07-20 21:00:47'),
(26, 'whatsapp_payment_message', 'text', '', NULL, NULL, '2026-06-20 12:02:53', '2026-07-20 21:00:47'),
(27, 'auto_send_whatsapp_invoice', 'text', '0', NULL, NULL, '2026-06-21 11:20:29', '2026-07-20 21:00:47'),
(28, 'auto_send_whatsapp_payment', 'text', '0', NULL, NULL, '2026-06-21 11:20:29', '2026-07-20 21:00:47'),
(29, 'inv_title_main', 'text', 'فاتورة استهلاك مياه', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(30, 'inv_title_subscriber', 'text', 'بيانات المشترك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(31, 'inv_title_consumption', 'text', 'تفاصيل الاستهلاك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(32, 'inv_title_total', 'text', 'المبلغ المستحق', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(33, 'inv_show_bill_number', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:19:41'),
(34, 'inv_lbl_bill_number', 'text', 'رقم الفاتورة', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(35, 'inv_show_date_from', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(36, 'inv_lbl_date_from', 'text', 'من تاريخ', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(37, 'inv_show_date_to', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(38, 'inv_lbl_date_to', 'text', 'إلى تاريخ', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(39, 'inv_show_sub_name', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 15:48:33'),
(40, 'inv_lbl_sub_name', 'text', 'اسم المشترك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 15:48:33'),
(41, 'inv_show_meter_no', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(42, 'inv_lbl_meter_no', 'text', 'رقم العداد', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(43, 'inv_show_sub_phone', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-21 21:10:30'),
(44, 'inv_lbl_sub_phone', 'text', 'الهاتف', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(45, 'inv_show_square', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(46, 'inv_lbl_square', 'text', 'المربع', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(47, 'inv_show_address', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:22:05'),
(48, 'inv_lbl_address', 'text', 'العنوان', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(49, 'inv_show_account_no', 'text', '0', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-21 21:13:45'),
(50, 'inv_lbl_account_no', 'text', 'رقم الصندوق', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 12:55:00'),
(51, 'inv_show_tenant', 'text', '0', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-21 21:13:45'),
(52, 'inv_lbl_tenant', 'text', 'اسم المستخدم', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 12:54:09'),
(53, 'inv_show_tenant_phone', 'text', '0', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 17:17:07'),
(54, 'inv_lbl_tenant_phone', 'text', 'هاتف المستخدم', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 12:52:52'),
(55, 'inv_show_prev_read', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(56, 'inv_lbl_prev_read', 'text', 'القراءة السابقة', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(57, 'inv_show_curr_read', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(58, 'inv_lbl_curr_read', 'text', 'القراءة الحالية', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(59, 'inv_show_units', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(60, 'inv_lbl_units', 'text', 'وحدات الاستهلاك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(61, 'inv_show_unit_price', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(62, 'inv_lbl_unit_price', 'text', 'سعر الوحدة', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(63, 'inv_show_cons_value', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(64, 'inv_lbl_cons_value', 'text', 'قيمة الاستهلاك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(65, 'inv_show_sub_fee', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-02 17:48:34'),
(66, 'inv_lbl_sub_fee', 'text', 'رسوم الاشتراك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-03 18:42:21'),
(67, 'inv_show_tanker', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-07-21 20:59:01'),
(68, 'inv_lbl_tanker', 'text', 'رسوم الوايت', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(69, 'inv_show_arrears', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(70, 'inv_lbl_arrears', 'text', 'المتأخرات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(71, 'inv_show_collector', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(72, 'inv_lbl_collector', 'text', 'المحصل', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(73, 'inv_show_signature', 'text', '0', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:30:29'),
(74, 'inv_lbl_signature', 'text', 'توقيع المشترك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(75, 'inv_show_thank_you', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(76, 'inv_lbl_thank_you', 'text', 'شكراً لدفعكم في الموعد / Thank you for your timely payment', NULL, 'إعدادات الفاتورة المتقدمة', '2026-06-21 12:18:59', '2026-06-21 12:18:59'),
(77, 'rect_title_main', 'text', 'سند قبض', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:28:32'),
(78, 'rect_title_subscriber', 'text', 'بيانات المشترك', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(79, 'rect_title_payment', 'text', 'بيانات الدفع', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(80, 'rect_show_receipt_number', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(81, 'rect_lbl_receipt_number', 'text', 'رقم السند', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(82, 'rect_show_receipt_date', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(83, 'rect_lbl_receipt_date', 'text', 'تاريخ السند', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(84, 'rect_show_sub_name', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(85, 'rect_lbl_sub_name', 'text', 'استلمت من الاخ', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:54:01'),
(86, 'rect_show_sub_phone', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:51:06'),
(87, 'rect_lbl_sub_phone', 'text', 'الهاتف', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(88, 'rect_show_square', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 19:35:35'),
(89, 'rect_lbl_square', 'text', 'المربع السكني', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(90, 'rect_show_account_no', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 19:35:35'),
(91, 'rect_lbl_account_no', 'text', 'رقم الحساب', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(92, 'rect_show_tenant', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(93, 'rect_lbl_tenant', 'text', 'المستأجر', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(94, 'rect_show_tenant_phone', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(95, 'rect_lbl_tenant_phone', 'text', 'هاتف المستأجر', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(96, 'rect_show_payment_method', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(97, 'rect_lbl_payment_method', 'text', 'طريقة الدفع', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(98, 'rect_show_linked_invoice', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-07-05 16:25:03'),
(99, 'rect_lbl_linked_invoice', 'text', 'الفاتورة المرتبطة', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(100, 'rect_show_reference', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(101, 'rect_lbl_reference', 'text', 'رقم الحوالة', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 19:23:09'),
(102, 'rect_show_invoice_total', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:40:06'),
(103, 'rect_lbl_invoice_total', 'text', 'إجمالي الفاتورة', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 14:50:24'),
(104, 'rect_show_amount_received', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(105, 'rect_lbl_amount_received', 'text', 'المبلغ المستلم', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(106, 'rect_show_remaining_invoice', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:40:06'),
(107, 'rect_lbl_remaining_invoice', 'text', 'المبلغ المتبقي', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 14:03:15'),
(108, 'rect_show_outstanding_total', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:40:06'),
(109, 'rect_lbl_outstanding_total', 'text', 'إجمالي المتبقي للحساب', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(110, 'rect_show_credit_balance', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-26 13:41:48'),
(111, 'rect_lbl_credit_balance', 'text', 'رصيد دائن للمشترك', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(112, 'rect_show_notes', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(113, 'rect_lbl_notes', 'text', 'البيان', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 10:55:26'),
(114, 'rect_show_collector_sig', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(115, 'rect_lbl_collector_sig', 'text', 'اسم المستلم', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 12:59:36'),
(116, 'rect_show_receiver_sig', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-29 14:38:38'),
(117, 'rect_lbl_receiver_sig', 'text', 'توقيع المستلم', NULL, 'إعدادات سند القبض', '2026-06-21 13:19:19', '2026-06-21 13:19:19'),
(118, 'rect_show_prev_payments', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-21 19:23:09', '2026-06-29 12:40:06'),
(119, 'rect_lbl_prev_payments', 'text', 'مدفوعات سابقة', NULL, 'إعدادات سند القبض', '2026-06-21 19:23:09', '2026-06-21 19:23:09'),
(120, 'rect_show_amount_in_words', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-29 12:40:06', '2026-06-29 12:45:13'),
(121, 'rect_lbl_amount_in_words', 'text', 'مبلغ وقدره', NULL, 'إعدادات سند القبض', '2026-06-29 12:40:06', '2026-06-29 12:40:06'),
(122, 'rect_amount_words_suffix', 'text', 'ريال فقط لا غير', NULL, 'إعدادات سند القبض', '2026-06-29 12:40:06', '2026-06-29 21:09:26'),
(123, 'rect_show_reason', 'text', '1', NULL, 'إعدادات سند القبض', '2026-06-29 14:33:39', '2026-06-29 14:33:39'),
(124, 'rect_lbl_reason', 'text', 'وذلك مقابل', NULL, 'إعدادات سند القبض', '2026-06-29 14:33:39', '2026-06-29 14:33:39'),
(125, 'rect_show_title_subscriber', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-29 21:49:06', '2026-06-29 21:49:06'),
(126, 'rect_show_title_payment', 'text', '0', NULL, 'إعدادات سند القبض', '2026-06-29 21:49:06', '2026-06-29 21:49:06'),
(127, 'inv_show_credit_applied', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-02 12:52:21', '2026-07-02 12:52:21'),
(128, 'inv_lbl_credit_applied', 'text', 'رصيد مخصوم', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-02 12:52:21', '2026-07-02 12:52:21'),
(129, 'inv_show_notes', 'text', '0', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-02 12:52:21', '2026-07-23 20:58:27'),
(130, 'inv_lbl_notes', 'text', 'الملاحظات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-02 12:52:21', '2026-07-02 12:52:21'),
(131, 'reading_day', 'text', 'last_day', NULL, 'يوم القراءة', '2026-07-23 01:43:17', '2026-07-27 19:17:01'),
(132, 'site_favicon', 'text', 'settings/invoices/01KY8PN3GJXA3S9PQ2S4P5HZMQ.png', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(133, 'sub_inv_title_main', 'text', 'فاتورة اشتراك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(134, 'sub_inv_title_details', 'text', 'تفاصيل رسوم الاشتراك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(135, 'sub_show_amount', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(136, 'sub_lbl_amount', 'text', 'قيمة الاشتراك', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(137, 'sub_show_arrears', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(138, 'sub_lbl_arrears', 'text', 'المتأخرات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(139, 'sub_show_notes', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(140, 'sub_lbl_notes', 'text', 'ملاحظات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(141, 'tanker_inv_title_main', 'text', 'فاتورة وايت ماء', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(142, 'tanker_inv_title_details', 'text', 'تفاصيل رسوم الوايت', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(143, 'tanker_show_amount', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(144, 'tanker_lbl_amount', 'text', 'قيمة الوايت', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(145, 'tanker_show_arrears', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(146, 'tanker_lbl_arrears', 'text', 'المتأخرات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(147, 'tanker_show_notes', 'text', '1', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(148, 'tanker_lbl_notes', 'text', 'ملاحظات', NULL, 'إعدادات الفاتورة المتقدمة', '2026-07-23 20:58:27', '2026-07-23 20:58:27'),
(149, 'reading_invoice_message_template', 'text', 'فاتورة استهلاك مياه\nالأخ:  {subscriber_name}،\nتم إصدار فاتورتك رقم (#{bill_number})\nللفترة :\nمن {period_start} إلى {period_end}.\n\nتفاصيل القراءة والاستهلاك:\n• رقم العداد: {meter_code}\n• القراءة السابقة: {previous_reading}\n• القراءة الحالية: {current_reading}\n• كمية الاستهلاك: {consumption_units} وحدة\n(سعر الوحدة: {unit_price}) ريال\n\nتفاصيل الرسوم والحساب:\n• قيمة الاستهلاك: {consumption_fee} ريال\n{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}\n{if:tanker_fee}• رسوم الوايت: {tanker_fee} ريال{/if:tanker_fee}\n{if:previous_balance}• متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n{if:credit_applied}• رصيد مخصوم: {credit_applied} ريال{/if:credit_applied}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\n📄 لمعاينة وتحميل الفاتورة الرسمية (PDF):\n{pdf_url}\n\nشكراً لدفعكم في الموعد المحدد', NULL, 'قالب رسالة الفاتورة عند القراءة', '2026-07-27 19:45:36', '2026-07-28 14:48:22'),
(150, 'payment_receipt_message_template', 'text', '🧾 سند قبض 🧾\nمرحباً بك {subscriber_name}،\nتم استلام مبلغ {amount} ريال بنجاح.\nرقم السند: {receipt_number}\nتاريخ الدفع: {date}\nطريقة الدفع: {payment_method}\n\nالمتبقي من الفاتورة: {invoice_remaining} ريال\nإجمالي المتبقي عليك: {outstanding_balance} ريال\n{if:credit_balance}الرصيد الدائن: {credit_balance} ريال{/if:credit_balance}\n\nالمحصل: {collector}\n\nللاطلاع على السند وتحميله (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا 🌹', NULL, 'قالب رسالة سند القبض', '2026-07-29 00:31:29', '2026-07-29 00:31:29');

-- --------------------------------------------------------

--
-- Table structure for table `squares`
--

CREATE TABLE `squares` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `squares`
--

INSERT INTO `squares` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'المربع الأول ', NULL, '2026-06-11 17:16:22', '2026-07-02 16:17:54'),
(2, 'المربع الثاني', NULL, '2026-06-11 17:16:22', '2026-07-02 16:18:16'),
(3, 'المربع الثالث', NULL, '2026-07-02 16:16:59', '2026-07-02 16:16:59'),
(4, 'المربع الرابع', NULL, '2026-07-02 20:54:06', '2026-07-02 20:54:06');

-- --------------------------------------------------------

--
-- Table structure for table `subscribers`
--

CREATE TABLE `subscribers` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `square_id` bigint UNSIGNED NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رصيد دائن',
  `initial_subscription_fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'رسوم الاشتراك الكلية',
  `subscription_fee_paid` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'المسدد من رسوم الاشتراك',
  `is_subscription_fee_fully_paid` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `subscription_start_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscribers`
--

INSERT INTO `subscribers` (`id`, `name`, `phone`, `address`, `square_id`, `balance`, `initial_subscription_fee`, `subscription_fee_paid`, `is_subscription_fee_fully_paid`, `status`, `subscription_start_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'عزام عبدالله سعد صلاح', '+967777742777', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(2, 'علي حسن غازي فتح الله', '+967770005153', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(3, 'علي غالب نزار الحرازي', '+967772160888', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:26:18', NULL),
(4, 'عبدالحميد سنان الحميري', '+967773709917', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(5, 'عبدالعزيز عبدالله عون الذبحاني', '+967772336666', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(6, 'محمد عوض المصباحي', '+967777762528', NULL, 1, 0.00, 17500.00, 17500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:34:05', NULL),
(7, 'احمد عبدالله ناجي الصلوي', '+967771014373', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:43:34', NULL),
(8, 'سلطان ناصر مبخوت الرباعي', '+967773004004', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:52:04', NULL),
(9, 'امين احمد كليب الذبحاني', '+967777947949', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(10, 'قابوس سعيد محمد المخلافي', '+967777719047', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(11, 'محمد علي احمد فارع الحبيشي', '+967777000740', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(12, 'العزي مسعود محمد المنوبي المستاجرين', '+967777298213', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:13:01', NULL),
(13, 'العزي مسعود محمد المنوبي البيت', '+967777298213', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:13:27', NULL),
(14, 'فيصل يوسف علي الجنش', '+967777274005', NULL, 1, 0.00, 12500.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:22:05', NULL),
(15, 'حميد سالم الوصابي', '+967771943862', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:26:43', NULL),
(16, 'يحيى صالح محمد القليسي', '+967777511300', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(17, 'علي محمد النهاري', '+967777789603', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(18, 'عبدالرحمن الحطامي الصغير', '+967773144480', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(19, 'عبدالرحمن الحطامي الكبير', '+967773144480', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(20, 'خالد علي يحيى الحرازي', '+967773384488', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:31:59', NULL),
(21, 'حسن الحقبي', '+967777105536', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:41:41', NULL),
(22, 'محمد مقبل سعيد العمري', '+967777122232', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(23, 'عادل قايد الزبيري', '+967771773733', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:44:54', NULL),
(24, 'اسماء فخرالدين', '+967770836413', NULL, 2, 0.00, 5000.00, 5000.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:50:56', NULL),
(25, 'مهيوب عبدالله سعيد القباطي', NULL, NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(26, 'قاسم علي عبده قاسم', '+967773291417', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(27, 'صادق عبدالله محمد العماد', '+967772900280', NULL, 2, 0.00, 5000.00, 5000.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 18:56:30', NULL),
(28, 'علي ابن علي غلاب', '+967772218733', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(29, 'عمر احسن القاضي', '+967777894389', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:07:28', NULL),
(30, 'بلال محمد علي الفقية', '+967777788247', NULL, 2, 0.00, 35000.00, 35000.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:15:56', NULL),
(31, 'محمد محمد سعد الريمي', '+967777482171', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(32, 'طه محمد يحيى الخاوي', '+967771640646', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(33, 'يحيى حسن علي صباح', '+967770595100', NULL, 2, 0.00, 17500.00, 17500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:29:01', NULL),
(34, 'عبدالكريم حسن علي صباح', '+967777198284', NULL, 2, 0.00, 12500.00, 12500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:24:32', NULL),
(35, 'سامي انور عقلان المشرقي', '+967777133007', NULL, 2, 0.00, 10000.00, 10000.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:32:54', NULL),
(36, 'عبدالسلام الفقية', '+967773418906', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(37, 'محمد علي حسين زايد', '+967774502345', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(38, 'طلال محمد احمد يوسف', '+967779024309', NULL, 2, 0.00, 37500.00, 37500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:39:26', NULL),
(39, 'منصور محمد احمد يوسف', '+967774889125', NULL, 2, 0.00, 37500.00, 37500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:41:38', NULL),
(40, 'صالح محمد احمد يوسف', '+967772759238', NULL, 2, 0.00, 37500.00, 37500.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:46:00', NULL),
(41, 'عبدالرحيم احمد علي زنيم', '+967777236596', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 19:48:09', NULL),
(42, 'احمد احمد الشتيوي', '+967777291179', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 17:17:01', NULL),
(43, 'احسن احسن احسن حسان', '+967777488791', NULL, 2, 0.00, 10000.00, 10000.00, 1, 'active', '2026-05-01', '2026-06-09 00:22:03', '2026-07-02 21:44:43', NULL),
(44, 'احمد يحيى حسن السواري', '+967777217061', NULL, 3, 0.00, 0.00, 0.00, 0, 'active', '2026-05-01', '2026-07-02 16:18:39', '2026-07-02 17:17:01', NULL),
(45, 'وائل حميد الشرعبي العماره الصغيرة', '+967736319954', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 19:56:37', '2026-07-02 19:59:42', NULL),
(46, 'عبدالله يحيى عايض الهمداني', '+967777663225', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:01:41', '2026-07-02 20:03:45', NULL),
(47, 'يحيى الحماطي', '+967774044045', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:08:41', '2026-07-02 20:08:41', NULL),
(48, 'احمد قايد مرشد عقيل', '+967775078984', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:10:41', '2026-07-02 20:11:30', NULL),
(49, 'خالد مجاهد غالب السريحي', '7', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:16:00', '2026-07-02 20:16:10', NULL),
(50, 'وائل حميد الشرعبي العمارة الكبيره', '+967736319954', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:18:07', '2026-07-02 20:18:45', NULL),
(51, 'كمال جمال محمد الهمداني', '+967777566743', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:22:03', '2026-07-02 20:22:03', NULL),
(52, 'بلال علي يحيى شمس الدين', '+967770342400', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:23:40', '2026-07-02 20:23:40', NULL),
(53, 'عبدالاله العريفي', '+967770905239', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:26:08', '2026-07-02 20:26:08', NULL),
(54, 'مروان احمد محمد مغلس', '+967777776931', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:27:12', '2026-07-02 20:27:12', NULL),
(55, 'عز الدين عبدالرحمن العمري', '+967771008293', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:28:47', '2026-07-02 20:28:47', NULL),
(56, 'عبدالله علي احمد العمري', '+967773351569', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:29:53', '2026-07-02 20:29:53', NULL),
(57, 'عبدالله ناجي علي الشيباني', '+967771020833', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:30:52', '2026-07-02 20:30:52', NULL),
(58, 'محمد عمر عبدالله غبان', '+967778890991', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:31:54', '2026-07-02 20:31:54', NULL),
(59, 'محمد مطهر اللساني', '+967774899112', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:35:02', '2026-07-02 20:36:56', NULL),
(60, 'عرفات محمد محسن الصلول', '+967773684719', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:38:25', '2026-07-02 20:38:25', NULL),
(61, 'تركي عمار محمد الميموني', '+967782151342', NULL, 2, 0.00, 7000.00, 7000.00, 1, 'active', '2026-06-20', '2026-07-02 20:40:39', '2026-07-02 20:43:10', NULL),
(62, 'محمد منصور سلمان الحطامي', '+967777692699', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:44:16', '2026-07-02 20:44:16', NULL),
(63, 'رفيق محمد الشجاع', '+967770266962', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:45:48', '2026-07-02 20:45:48', NULL),
(64, 'يحيى محمد صالح الزبير', '+967770996618', NULL, 2, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:48:17', '2026-07-02 20:48:17', NULL),
(65, 'محمد علي محمد التويتي', '+967770941411', NULL, 1, 0.00, 0.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 20:50:54', '2026-07-02 20:50:54', NULL),
(66, 'عبدالقوي داوود مسعود العريفي', '+967777394922', NULL, 4, 0.00, 35000.00, 35000.00, 1, 'active', '2026-06-20', '2026-07-02 20:54:22', '2026-07-02 21:12:47', NULL),
(67, 'عبدالله محمد صالح محسن', '+967770167059', NULL, 4, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:56:33', '2026-07-02 20:56:33', NULL),
(68, 'خليل محمد محسن الخالدي', '+967771970104', NULL, 4, 0.00, 0.00, 0.00, 0, 'active', '2026-06-20', '2026-07-02 20:58:23', '2026-07-02 20:58:23', NULL),
(69, 'حسين عبدالله العواضي', '+967773319088', NULL, 4, -5000.00, 5000.00, 0.00, 0, 'active', '2026-07-02', '2026-07-02 21:02:58', '2026-07-02 21:09:17', NULL),
(70, 'حمود حسين احمد جندار', '+967782531413', NULL, 4, 0.00, 0.00, 0.00, 0, 'active', '2026-07-03', '2026-07-02 21:06:09', '2026-07-02 21:06:09', NULL),
(71, 'احمد جعوان', '+967770377432', NULL, 4, 0.00, 0.00, 0.00, 0, 'active', '2026-07-03', '2026-07-02 21:15:31', '2026-07-02 21:15:31', NULL),
(72, 'صالح حجر', '+967777972573', NULL, 4, 0.00, 0.00, 0.00, 0, 'active', '2026-07-03', '2026-07-02 21:29:10', '2026-07-02 21:29:10', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_fees`
--

CREATE TABLE `subscription_fees` (
  `id` bigint UNSIGNED NOT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
  `meter_id` bigint UNSIGNED DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('uninvoiced','invoiced','partially_paid','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uninvoiced',
  `invoice_id` bigint UNSIGNED DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_fees`
--

INSERT INTO `subscription_fees` (`id`, `subscriber_id`, `meter_id`, `amount`, `status`, `invoice_id`, `notes`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 6, 6, 17500.00, 'paid', NULL, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(2, 14, 14, 12500.00, 'uninvoiced', NULL, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(3, 24, 24, 5000.00, 'paid', 69, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(4, 27, 27, 5000.00, 'paid', 71, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(5, 30, 30, 35000.00, 'paid', 77, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(6, 33, 33, 17500.00, 'paid', 85, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(7, 34, 34, 12500.00, 'paid', 83, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(8, 35, 35, 10000.00, 'paid', 87, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(9, 38, 38, 37500.00, 'paid', 91, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(10, 39, 39, 37500.00, 'paid', 93, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(11, 40, 40, 37500.00, 'paid', 95, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(12, 43, 43, 10000.00, 'paid', 117, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(13, 61, 64, 7000.00, 'paid', 109, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(14, 66, 69, 35000.00, 'paid', 116, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(15, 69, 72, 5000.00, 'uninvoiced', NULL, NULL, '2026-07-22 00:48:54', '2026-07-22 00:48:54', NULL),
(32, 1, NULL, 35000.00, 'uninvoiced', NULL, NULL, '2026-07-26 13:39:45', '2026-07-29 02:20:03', '2026-07-29 02:20:03');

-- --------------------------------------------------------

--
-- Table structure for table `tariffs`
--

CREATE TABLE `tariffs` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tariffs`
--

INSERT INTO `tariffs` (`id`, `name`, `unit_price`, `is_active`, `start_date`, `end_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'التسعيرة الافتراضية', 600.00, 1, '2026-05-01', NULL, '2026-06-11 17:16:22', '2026-06-15 21:34:24', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `subscriber_id` bigint UNSIGNED NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `meter_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `subscriber_id`, `type`, `amount`, `description`, `reference_type`, `reference_id`, `created_at`, `updated_at`, `deleted_at`, `meter_id`) VALUES
(1, 44, 'invoice', -35000.00, 'إصدار فاتورة رقم INV-202607-0001', 'App\\Models\\Invoice', 1, '2026-07-02 16:35:28', '2026-07-02 16:35:28', NULL, NULL),
(2, 44, 'payment', 35000.00, 'سند قبض رقم REC-202607-0001', 'App\\Models\\Payment', 1, '2026-07-02 16:57:45', '2026-07-02 16:57:45', NULL, NULL),
(3, 18, 'invoice', -48000.00, 'إصدار فاتورة رقم INV-202607-0002', 'App\\Models\\Invoice', 2, '2026-07-02 17:09:38', '2026-07-02 17:11:32', '2026-07-02 17:11:32', 18),
(4, 1, 'invoice', -13200.00, 'إصدار فاتورة رقم INV-202607-0003', 'App\\Models\\Invoice', 3, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 1),
(5, 2, 'invoice', -19200.00, 'إصدار فاتورة رقم INV-202607-0004', 'App\\Models\\Invoice', 4, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 2),
(6, 3, 'invoice', -4200.00, 'إصدار فاتورة رقم INV-202607-0005', 'App\\Models\\Invoice', 5, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 3),
(7, 4, 'invoice', -9000.00, 'إصدار فاتورة رقم INV-202607-0006', 'App\\Models\\Invoice', 6, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 4),
(8, 5, 'invoice', -8400.00, 'إصدار فاتورة رقم INV-202607-0007', 'App\\Models\\Invoice', 7, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 5),
(9, 6, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0008', 'App\\Models\\Invoice', 8, '2026-07-02 17:17:01', '2026-07-02 17:36:54', '2026-07-02 17:36:54', 6),
(10, 7, 'invoice', -11400.00, 'إصدار فاتورة رقم INV-202607-0009', 'App\\Models\\Invoice', 9, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 7),
(11, 8, 'invoice', -10200.00, 'إصدار فاتورة رقم INV-202607-0010', 'App\\Models\\Invoice', 10, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 8),
(12, 9, 'invoice', -20700.00, 'إصدار فاتورة رقم INV-202607-0011', 'App\\Models\\Invoice', 11, '2026-07-02 17:17:01', '2026-07-02 17:59:01', NULL, 9),
(13, 10, 'invoice', -31200.00, 'إصدار فاتورة رقم INV-202607-0012', 'App\\Models\\Invoice', 12, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 10),
(14, 11, 'invoice', -13800.00, 'إصدار فاتورة رقم INV-202607-0013', 'App\\Models\\Invoice', 13, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 11),
(15, 12, 'invoice', -25800.00, 'إصدار فاتورة رقم INV-202607-0014', 'App\\Models\\Invoice', 14, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 12),
(16, 13, 'invoice', -12600.00, 'إصدار فاتورة رقم INV-202607-0015', 'App\\Models\\Invoice', 15, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 13),
(17, 14, 'invoice', -7200.00, 'إصدار فاتورة رقم INV-202607-0016', 'App\\Models\\Invoice', 16, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 14),
(18, 15, 'invoice', -5400.00, 'إصدار فاتورة رقم INV-202607-0017', 'App\\Models\\Invoice', 17, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 15),
(19, 16, 'invoice', -12000.00, 'إصدار فاتورة رقم INV-202607-0018', 'App\\Models\\Invoice', 18, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 16),
(20, 17, 'invoice', -10800.00, 'إصدار فاتورة رقم INV-202607-0019', 'App\\Models\\Invoice', 19, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 17),
(21, 18, 'invoice', -31200.00, 'إصدار فاتورة رقم INV-202607-0020', 'App\\Models\\Invoice', 20, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 18),
(22, 19, 'invoice', -31800.00, 'إصدار فاتورة رقم INV-202607-0021', 'App\\Models\\Invoice', 21, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 19),
(23, 20, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0022', 'App\\Models\\Invoice', 22, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 20),
(24, 21, 'invoice', -57000.00, 'إصدار فاتورة رقم INV-202607-0023', 'App\\Models\\Invoice', 23, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 21),
(25, 22, 'invoice', -57000.00, 'إصدار فاتورة رقم INV-202607-0024', 'App\\Models\\Invoice', 24, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 22),
(26, 23, 'invoice', -5400.00, 'إصدار فاتورة رقم INV-202607-0025', 'App\\Models\\Invoice', 25, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 23),
(27, 24, 'invoice', -4800.00, 'إصدار فاتورة رقم INV-202607-0026', 'App\\Models\\Invoice', 26, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 24),
(28, 25, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202607-0027', 'App\\Models\\Invoice', 27, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 25),
(29, 26, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202607-0028', 'App\\Models\\Invoice', 28, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 26),
(30, 27, 'invoice', -4200.00, 'إصدار فاتورة رقم INV-202607-0029', 'App\\Models\\Invoice', 29, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 27),
(31, 28, 'invoice', -15600.00, 'إصدار فاتورة رقم INV-202607-0030', 'App\\Models\\Invoice', 30, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 28),
(32, 29, 'invoice', -59400.00, 'إصدار فاتورة رقم INV-202607-0031', 'App\\Models\\Invoice', 31, '2026-07-02 17:17:01', '2026-07-02 19:08:59', '2026-07-02 19:08:59', 29),
(33, 30, 'invoice', -30600.00, 'إصدار فاتورة رقم INV-202607-0032', 'App\\Models\\Invoice', 32, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 30),
(34, 31, 'invoice', -10200.00, 'إصدار فاتورة رقم INV-202607-0033', 'App\\Models\\Invoice', 33, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 31),
(35, 32, 'invoice', -13200.00, 'إصدار فاتورة رقم INV-202607-0034', 'App\\Models\\Invoice', 34, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 32),
(36, 33, 'invoice', -5400.00, 'إصدار فاتورة رقم INV-202607-0035', 'App\\Models\\Invoice', 35, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 33),
(37, 34, 'invoice', -5400.00, 'إصدار فاتورة رقم INV-202607-0036', 'App\\Models\\Invoice', 36, '2026-07-02 17:17:01', '2026-07-02 19:20:44', '2026-07-02 19:20:44', 34),
(38, 35, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202607-0037', 'App\\Models\\Invoice', 37, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 35),
(39, 36, 'invoice', -21000.00, 'إصدار فاتورة رقم INV-202607-0038', 'App\\Models\\Invoice', 38, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 36),
(40, 37, 'invoice', 0.00, 'إصدار فاتورة رقم INV-202607-0039', 'App\\Models\\Invoice', 39, '2026-07-02 17:17:01', '2026-07-02 17:17:01', NULL, 37),
(41, 38, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202607-0040', 'App\\Models\\Invoice', 40, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 38),
(42, 39, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0041', 'App\\Models\\Invoice', 41, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 39),
(43, 40, 'invoice', -15000.00, 'إصدار فاتورة رقم INV-202607-0042', 'App\\Models\\Invoice', 42, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 40),
(44, 41, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0043', 'App\\Models\\Invoice', 43, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 41),
(45, 42, 'invoice', -4800.00, 'إصدار فاتورة رقم INV-202607-0044', 'App\\Models\\Invoice', 44, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 42),
(46, 43, 'invoice', -4200.00, 'إصدار فاتورة رقم INV-202607-0045', 'App\\Models\\Invoice', 45, '2026-07-02 17:17:02', '2026-07-02 17:17:02', NULL, 43),
(47, 22, 'payment', 57000.00, 'سند قبض رقم REC-202607-0002', 'App\\Models\\Payment', 2, '2026-07-02 17:22:16', '2026-07-02 17:22:16', NULL, 22),
(48, 22, 'invoice', -48000.00, 'إصدار فاتورة رقم INV-202607-0046', 'App\\Models\\Invoice', 46, '2026-07-02 17:23:11', '2026-07-02 17:23:11', NULL, 22),
(49, 2, 'payment', 19200.00, 'سند قبض رقم REC-202607-0003', 'App\\Models\\Payment', 3, '2026-07-02 17:24:08', '2026-07-02 17:24:08', NULL, 2),
(50, 2, 'invoice', -24000.00, 'إصدار فاتورة رقم INV-202607-0047', 'App\\Models\\Invoice', 47, '2026-07-02 17:24:56', '2026-07-02 17:24:56', NULL, 2),
(51, 3, 'payment', 4200.00, 'سند قبض رقم REC-202607-0004', 'App\\Models\\Payment', 4, '2026-07-02 17:26:38', '2026-07-02 17:26:38', NULL, 3),
(52, 3, 'invoice', -4800.00, 'إصدار فاتورة رقم INV-202607-0048', 'App\\Models\\Invoice', 48, '2026-07-02 17:27:05', '2026-07-02 17:27:05', NULL, 3),
(53, 4, 'payment', 9000.00, 'سند قبض رقم REC-202607-0005', 'App\\Models\\Payment', 5, '2026-07-02 17:27:32', '2026-07-02 17:27:32', NULL, 4),
(54, 4, 'invoice', -6600.00, 'إصدار فاتورة رقم INV-202607-0049', 'App\\Models\\Invoice', 49, '2026-07-02 17:27:58', '2026-07-02 17:27:58', NULL, 4),
(55, 5, 'payment', 8400.00, 'سند قبض رقم REC-202607-0006', 'App\\Models\\Payment', 6, '2026-07-02 17:28:25', '2026-07-02 17:28:25', NULL, 5),
(56, 5, 'invoice', -7200.00, 'إصدار فاتورة رقم INV-202607-0050', 'App\\Models\\Invoice', 50, '2026-07-02 17:28:48', '2026-07-02 17:28:48', NULL, 5),
(57, 6, 'payment', 15000.00, 'سند قبض رقم REC-202607-0007', 'App\\Models\\Payment', 7, '2026-07-02 17:30:10', '2026-07-02 17:38:04', '2026-07-02 17:38:04', 6),
(58, 6, 'invoice', -17400.00, 'إصدار فاتورة رقم INV-202607-0051', 'App\\Models\\Invoice', 51, '2026-07-02 17:32:30', '2026-07-02 17:33:31', '2026-07-02 17:33:31', 6),
(59, 6, 'invoice', -22900.00, 'إصدار فاتورة رقم INV-202607-0052', 'App\\Models\\Invoice', 52, '2026-07-02 17:34:05', '2026-07-02 17:36:35', '2026-07-02 17:36:35', 6),
(60, 7, 'payment', 11000.00, 'سند قبض رقم REC-202607-0008', 'App\\Models\\Payment', 8, '2026-07-02 17:49:18', '2026-07-02 17:49:18', NULL, 7),
(61, 7, 'invoice', -13800.00, 'إصدار فاتورة رقم INV-202607-0053', 'App\\Models\\Invoice', 53, '2026-07-02 17:49:49', '2026-07-02 17:49:49', NULL, 7),
(62, 8, 'payment', 10200.00, 'سند قبض رقم REC-202607-0009', 'App\\Models\\Payment', 9, '2026-07-02 17:53:24', '2026-07-02 17:53:24', NULL, 8),
(63, 8, 'invoice', 0.00, 'إصدار فاتورة رقم INV-202607-0054', 'App\\Models\\Invoice', 54, '2026-07-02 17:53:47', '2026-07-02 17:53:47', NULL, 8),
(64, 10, 'payment', 31200.00, 'سند قبض رقم REC-202607-0010', 'App\\Models\\Payment', 10, '2026-07-02 18:09:21', '2026-07-02 18:09:21', NULL, 10),
(65, 10, 'invoice', -27000.00, 'إصدار فاتورة رقم INV-202607-0055', 'App\\Models\\Invoice', 55, '2026-07-02 18:09:58', '2026-07-02 18:09:58', NULL, 10),
(66, 11, 'payment', 13800.00, 'سند قبض رقم REC-202607-0011', 'App\\Models\\Payment', 11, '2026-07-02 18:10:49', '2026-07-02 18:10:49', NULL, 11),
(67, 11, 'invoice', -11400.00, 'إصدار فاتورة رقم INV-202607-0056', 'App\\Models\\Invoice', 56, '2026-07-02 18:11:17', '2026-07-02 18:11:17', NULL, 11),
(68, 12, 'invoice', -22800.00, 'إصدار فاتورة رقم INV-202607-0057', 'App\\Models\\Invoice', 57, '2026-07-02 18:17:54', '2026-07-02 18:17:54', NULL, 12),
(69, 12, 'payment', 48600.00, 'سند قبض رقم REC-202607-0012', 'App\\Models\\Payment', 12, '2026-07-02 18:19:03', '2026-07-02 18:19:03', NULL, 12),
(70, 13, 'payment', 12600.00, 'سند قبض رقم REC-202607-0013', 'App\\Models\\Payment', 13, '2026-07-02 18:19:17', '2026-07-02 18:19:17', NULL, 13),
(71, 14, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0058', 'App\\Models\\Invoice', 58, '2026-07-02 18:22:58', '2026-07-02 18:22:58', NULL, 14),
(72, 15, 'payment', 5400.00, 'سند قبض رقم REC-202607-0014', 'App\\Models\\Payment', 14, '2026-07-02 18:28:46', '2026-07-02 18:28:46', NULL, 15),
(73, 15, 'invoice', -9600.00, 'إصدار فاتورة رقم INV-202607-0059', 'App\\Models\\Invoice', 59, '2026-07-02 18:29:36', '2026-07-02 18:29:36', NULL, 15),
(74, 16, 'payment', 12000.00, 'سند قبض رقم REC-202607-0015', 'App\\Models\\Payment', 15, '2026-07-02 18:30:13', '2026-07-02 18:30:13', NULL, 16),
(75, 16, 'invoice', -18000.00, 'إصدار فاتورة رقم INV-202607-0060', 'App\\Models\\Invoice', 60, '2026-07-02 18:30:31', '2026-07-02 18:30:31', NULL, 16),
(76, 20, 'payment', 7800.00, 'سند قبض رقم REC-202607-0016', 'App\\Models\\Payment', 16, '2026-07-02 18:32:20', '2026-07-02 18:32:20', NULL, 20),
(77, 20, 'invoice', -12600.00, 'إصدار فاتورة رقم INV-202607-0061', 'App\\Models\\Invoice', 61, '2026-07-02 18:32:35', '2026-07-02 18:32:35', NULL, 20),
(78, 17, 'payment', 10000.00, 'سند قبض رقم REC-202607-0017', 'App\\Models\\Payment', 17, '2026-07-02 18:33:12', '2026-07-02 18:33:12', NULL, 17),
(79, 17, 'payment', 800.00, 'سند قبض رقم REC-202607-0018', 'App\\Models\\Payment', 18, '2026-07-02 18:33:26', '2026-07-02 18:33:26', NULL, 17),
(80, 17, 'invoice', -4200.00, 'إصدار فاتورة رقم INV-202607-0062', 'App\\Models\\Invoice', 62, '2026-07-02 18:33:49', '2026-07-02 18:33:49', NULL, 17),
(81, 19, 'payment', 26800.00, 'سند قبض رقم REC-202607-0019', 'App\\Models\\Payment', 19, '2026-07-02 18:34:56', '2026-07-02 18:34:56', NULL, 19),
(82, 19, 'invoice', -73800.00, 'إصدار فاتورة رقم INV-202607-0063', 'App\\Models\\Invoice', 63, '2026-07-02 18:35:58', '2026-07-02 18:35:58', NULL, 19),
(83, 18, 'payment', 26200.00, 'سند قبض رقم REC-202607-0020', 'App\\Models\\Payment', 20, '2026-07-02 18:37:29', '2026-07-02 18:37:29', NULL, 18),
(84, 18, 'invoice', -16800.00, 'إصدار فاتورة رقم INV-202607-0064', 'App\\Models\\Invoice', 64, '2026-07-02 18:38:25', '2026-07-02 18:38:25', NULL, 18),
(85, 21, 'payment', 57000.00, 'سند قبض رقم REC-202607-0021', 'App\\Models\\Payment', 21, '2026-07-02 18:41:59', '2026-07-02 18:41:59', NULL, 21),
(86, 21, 'invoice', -55800.00, 'إصدار فاتورة رقم INV-202607-0065', 'App\\Models\\Invoice', 65, '2026-07-02 18:42:29', '2026-07-02 18:42:29', NULL, 21),
(87, 23, 'payment', 3500.00, 'سند قبض رقم REC-202607-0022', 'App\\Models\\Payment', 22, '2026-07-02 18:45:09', '2026-07-02 18:45:09', NULL, 23),
(88, 23, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202607-0066', 'App\\Models\\Invoice', 66, '2026-07-02 18:45:23', '2026-07-02 18:45:23', NULL, 23),
(89, 43, 'payment', 4200.00, 'سند قبض رقم REC-202607-0023', 'App\\Models\\Payment', 23, '2026-07-02 18:46:10', '2026-07-02 18:46:10', NULL, 43),
(90, 43, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0067', 'App\\Models\\Invoice', 67, '2026-07-02 18:46:23', '2026-07-02 21:44:20', '2026-07-02 21:44:20', 43),
(91, 24, 'payment', 4800.00, 'سند قبض رقم REC-202607-0024', 'App\\Models\\Payment', 24, '2026-07-02 18:49:10', '2026-07-02 18:49:10', NULL, 24),
(92, 24, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0068', 'App\\Models\\Invoice', 68, '2026-07-02 18:50:12', '2026-07-02 18:50:31', '2026-07-02 18:50:31', 24),
(93, 24, 'invoice', -11000.00, 'إصدار فاتورة رقم INV-202607-0069', 'App\\Models\\Invoice', 69, '2026-07-02 18:50:56', '2026-07-02 18:50:56', NULL, 24),
(94, 27, 'payment', 4200.00, 'سند قبض رقم REC-202607-0025', 'App\\Models\\Payment', 25, '2026-07-02 18:54:46', '2026-07-02 18:54:46', NULL, 27),
(95, 27, 'invoice', -4800.00, 'إصدار فاتورة رقم INV-202607-0070', 'App\\Models\\Invoice', 70, '2026-07-02 18:55:44', '2026-07-02 18:56:08', '2026-07-02 18:56:08', 27),
(96, 27, 'invoice', -9800.00, 'إصدار فاتورة رقم INV-202607-0071', 'App\\Models\\Invoice', 71, '2026-07-02 18:56:30', '2026-07-02 18:56:30', NULL, 27),
(97, 25, 'payment', 1200.00, 'سند قبض رقم REC-202607-0026', 'App\\Models\\Payment', 26, '2026-07-02 19:03:25', '2026-07-02 19:03:25', NULL, 25),
(98, 25, 'invoice', -1800.00, 'إصدار فاتورة رقم INV-202607-0072', 'App\\Models\\Invoice', 72, '2026-07-02 19:03:44', '2026-07-02 19:03:44', NULL, 25),
(99, 1, 'invoice', -23400.00, 'إصدار فاتورة رقم INV-202607-0073', 'App\\Models\\Invoice', 73, '2026-07-02 19:04:30', '2026-07-02 19:04:30', NULL, 1),
(100, 29, 'invoice', -39600.00, 'إصدار فاتورة رقم INV-202607-0074', 'App\\Models\\Invoice', 74, '2026-07-02 19:09:32', '2026-07-02 19:09:32', NULL, 29),
(101, 29, 'payment', 39600.00, 'سند قبض رقم REC-202607-0027', 'App\\Models\\Payment', 27, '2026-07-02 19:12:14', '2026-07-02 19:12:14', NULL, 29),
(102, 29, 'invoice', -23400.00, 'إصدار فاتورة رقم INV-202607-0075', 'App\\Models\\Invoice', 75, '2026-07-02 19:12:48', '2026-07-02 19:12:48', NULL, 29),
(103, 30, 'payment', 30600.00, 'سند قبض رقم REC-202607-0028', 'App\\Models\\Payment', 28, '2026-07-02 19:13:47', '2026-07-02 19:13:47', NULL, 30),
(104, 30, 'invoice', -37800.00, 'إصدار فاتورة رقم INV-202607-0076', 'App\\Models\\Invoice', 76, '2026-07-02 19:15:12', '2026-07-02 19:15:28', '2026-07-02 19:15:28', 30),
(105, 30, 'invoice', -72800.00, 'إصدار فاتورة رقم INV-202607-0077', 'App\\Models\\Invoice', 77, '2026-07-02 19:15:56', '2026-07-02 19:15:56', NULL, 30),
(106, 31, 'payment', 10200.00, 'سند قبض رقم REC-202607-0029', 'App\\Models\\Payment', 29, '2026-07-02 19:16:52', '2026-07-02 19:16:52', NULL, 31),
(107, 31, 'invoice', 0.00, 'إصدار فاتورة رقم INV-202607-0078', 'App\\Models\\Invoice', 78, '2026-07-02 19:17:12', '2026-07-02 19:17:12', NULL, 31),
(108, 32, 'payment', 13200.00, 'سند قبض رقم REC-202607-0030', 'App\\Models\\Payment', 30, '2026-07-02 19:17:41', '2026-07-02 19:17:41', NULL, 32),
(109, 32, 'invoice', -13200.00, 'إصدار فاتورة رقم INV-202607-0079', 'App\\Models\\Invoice', 79, '2026-07-02 19:18:01', '2026-07-02 19:18:01', NULL, 32),
(110, 34, 'payment', 5400.00, 'سند قبض رقم REC-202607-0031', 'App\\Models\\Payment', 31, '2026-07-02 19:18:32', '2026-07-02 19:20:22', '2026-07-02 19:20:22', 34),
(111, 34, 'invoice', -3600.00, 'إصدار فاتورة رقم INV-202607-0080', 'App\\Models\\Invoice', 80, '2026-07-02 19:21:30', '2026-07-02 19:21:30', NULL, 34),
(112, 34, 'payment', 3600.00, 'سند قبض رقم REC-202607-0032', 'App\\Models\\Payment', 32, '2026-07-02 19:22:10', '2026-07-02 19:22:10', NULL, 34),
(113, 34, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202607-0081', 'App\\Models\\Invoice', 81, '2026-07-02 19:22:50', '2026-07-02 19:23:10', '2026-07-02 19:23:10', 34),
(114, 6, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0082', 'App\\Models\\Invoice', 82, '2026-07-02 19:23:31', '2026-07-02 19:23:31', NULL, 6),
(115, 34, 'invoice', -15500.00, 'إصدار فاتورة رقم INV-202607-0083', 'App\\Models\\Invoice', 83, '2026-07-02 19:24:32', '2026-07-02 19:24:32', NULL, 34),
(116, 33, 'payment', 5400.00, 'سند قبض رقم REC-202607-0033', 'App\\Models\\Payment', 33, '2026-07-02 19:27:28', '2026-07-02 19:27:28', NULL, 33),
(117, 33, 'invoice', -4800.00, 'إصدار فاتورة رقم INV-202607-0084', 'App\\Models\\Invoice', 84, '2026-07-02 19:28:23', '2026-07-02 19:28:40', '2026-07-02 19:28:40', 33),
(118, 33, 'invoice', -22300.00, 'إصدار فاتورة رقم INV-202607-0085', 'App\\Models\\Invoice', 85, '2026-07-02 19:29:01', '2026-07-02 19:29:01', NULL, 33),
(119, 35, 'invoice', -2400.00, 'إصدار فاتورة رقم INV-202607-0086', 'App\\Models\\Invoice', 86, '2026-07-02 19:32:22', '2026-07-02 19:32:37', '2026-07-02 19:32:37', 35),
(120, 35, 'invoice', -12400.00, 'إصدار فاتورة رقم INV-202607-0087', 'App\\Models\\Invoice', 87, '2026-07-02 19:32:54', '2026-07-02 19:32:54', NULL, 35),
(121, 36, 'payment', 16000.00, 'سند قبض رقم REC-202607-0034', 'App\\Models\\Payment', 34, '2026-07-02 19:33:59', '2026-07-02 19:33:59', NULL, 36),
(122, 36, 'invoice', -47400.00, 'إصدار فاتورة رقم INV-202607-0088', 'App\\Models\\Invoice', 88, '2026-07-02 19:34:30', '2026-07-02 19:34:30', NULL, 36),
(123, 37, 'invoice', -22800.00, 'إصدار فاتورة رقم INV-202607-0089', 'App\\Models\\Invoice', 89, '2026-07-02 19:36:49', '2026-07-02 19:36:49', NULL, 37),
(124, 38, 'invoice', -16200.00, 'إصدار فاتورة رقم INV-202607-0090', 'App\\Models\\Invoice', 90, '2026-07-02 19:38:37', '2026-07-02 19:39:01', '2026-07-02 19:39:01', 38),
(125, 38, 'invoice', -53700.00, 'إصدار فاتورة رقم INV-202607-0091', 'App\\Models\\Invoice', 91, '2026-07-02 19:39:26', '2026-07-02 19:39:26', NULL, 38),
(126, 39, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202607-0092', 'App\\Models\\Invoice', 92, '2026-07-02 19:41:03', '2026-07-02 19:41:15', '2026-07-02 19:41:15', 39),
(127, 39, 'invoice', -40500.00, 'إصدار فاتورة رقم INV-202607-0093', 'App\\Models\\Invoice', 93, '2026-07-02 19:41:38', '2026-07-02 19:41:38', NULL, 39),
(128, 40, 'invoice', -9600.00, 'إصدار فاتورة رقم INV-202607-0094', 'App\\Models\\Invoice', 94, '2026-07-02 19:45:17', '2026-07-02 19:45:34', '2026-07-02 19:45:34', 40),
(129, 40, 'invoice', -47100.00, 'إصدار فاتورة رقم INV-202607-0095', 'App\\Models\\Invoice', 95, '2026-07-02 19:46:00', '2026-07-02 19:46:00', NULL, 40),
(130, 41, 'payment', 7800.00, 'سند قبض رقم REC-202607-0035', 'App\\Models\\Payment', 35, '2026-07-02 19:48:21', '2026-07-02 19:48:21', NULL, 41),
(131, 41, 'invoice', -43800.00, 'إصدار فاتورة رقم INV-202607-0096', 'App\\Models\\Invoice', 96, '2026-07-02 19:48:45', '2026-07-02 19:48:45', NULL, 41),
(132, 42, 'payment', 4800.00, 'سند قبض رقم REC-202607-0036', 'App\\Models\\Payment', 36, '2026-07-02 19:49:49', '2026-07-02 19:49:49', NULL, 42),
(133, 42, 'invoice', -66600.00, 'إصدار فاتورة رقم INV-202607-0097', 'App\\Models\\Invoice', 97, '2026-07-02 19:50:11', '2026-07-02 19:50:11', NULL, 42),
(134, 45, 'invoice', -10200.00, 'إصدار فاتورة رقم INV-202607-0098', 'App\\Models\\Invoice', 98, '2026-07-02 20:00:34', '2026-07-02 20:00:34', NULL, 45),
(135, 46, 'invoice', -16200.00, 'إصدار فاتورة رقم INV-202607-0099', 'App\\Models\\Invoice', 99, '2026-07-02 20:05:54', '2026-07-02 20:05:54', NULL, 47),
(136, 47, 'invoice', -3600.00, 'إصدار فاتورة رقم INV-202607-0100', 'App\\Models\\Invoice', 100, '2026-07-02 20:09:13', '2026-07-02 20:09:13', NULL, 48),
(137, 48, 'invoice', -9600.00, 'إصدار فاتورة رقم INV-202607-0101', 'App\\Models\\Invoice', 101, '2026-07-02 20:12:24', '2026-07-02 20:12:24', NULL, 50),
(138, 50, 'invoice', -17400.00, 'إصدار فاتورة رقم INV-202607-0102', 'App\\Models\\Invoice', 102, '2026-07-02 20:20:10', '2026-07-02 20:20:10', NULL, 53),
(139, 51, 'invoice', -12600.00, 'إصدار فاتورة رقم INV-202607-0103', 'App\\Models\\Invoice', 103, '2026-07-02 20:22:49', '2026-07-02 20:22:49', NULL, 54),
(140, 52, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0104', 'App\\Models\\Invoice', 104, '2026-07-02 20:24:29', '2026-07-02 20:24:29', NULL, 55),
(141, 55, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0105', 'App\\Models\\Invoice', 105, '2026-07-02 20:29:18', '2026-07-02 20:29:18', NULL, 58),
(142, 59, 'invoice', -9600.00, 'إصدار فاتورة رقم INV-202607-0106', 'App\\Models\\Invoice', 106, '2026-07-02 20:36:22', '2026-07-02 20:36:22', NULL, 62),
(143, 60, 'invoice', -2400.00, 'إصدار فاتورة رقم INV-202607-0107', 'App\\Models\\Invoice', 107, '2026-07-02 20:39:16', '2026-07-02 20:39:16', NULL, 63),
(144, 61, 'invoice', -1200.00, 'إصدار فاتورة رقم INV-202607-0108', 'App\\Models\\Invoice', 108, '2026-07-02 20:41:53', '2026-07-02 20:42:46', '2026-07-02 20:42:46', 64),
(145, 61, 'invoice', -8200.00, 'إصدار فاتورة رقم INV-202607-0109', 'App\\Models\\Invoice', 109, '2026-07-02 20:43:10', '2026-07-02 20:43:10', NULL, 64),
(146, 62, 'invoice', -3000.00, 'إصدار فاتورة رقم INV-202607-0110', 'App\\Models\\Invoice', 110, '2026-07-02 20:44:52', '2026-07-02 20:44:52', NULL, 65),
(147, 63, 'invoice', -3600.00, 'إصدار فاتورة رقم INV-202607-0111', 'App\\Models\\Invoice', 111, '2026-07-02 20:46:41', '2026-07-02 20:46:41', NULL, 66),
(148, 66, 'invoice', -7800.00, 'إصدار فاتورة رقم INV-202607-0112', 'App\\Models\\Invoice', 112, '2026-07-02 20:55:24', '2026-07-02 21:11:42', '2026-07-02 21:11:42', 69),
(149, 67, 'invoice', -2400.00, 'إصدار فاتورة رقم INV-202607-0113', 'App\\Models\\Invoice', 113, '2026-07-02 20:57:09', '2026-07-02 20:57:09', NULL, 70),
(150, 68, 'invoice', -6000.00, 'إصدار فاتورة رقم INV-202607-0114', 'App\\Models\\Invoice', 114, '2026-07-02 20:58:56', '2026-07-02 20:58:56', NULL, 71),
(151, 69, 'invoice', -5000.00, 'إصدار فاتورة رقم INV-202607-0115', 'App\\Models\\Invoice', 115, '2026-07-02 21:08:21', '2026-07-02 21:09:17', NULL, NULL),
(152, 66, 'invoice', -42800.00, 'إصدار فاتورة رقم INV-202607-0116', 'App\\Models\\Invoice', 116, '2026-07-02 21:12:47', '2026-07-02 21:12:47', NULL, 69),
(153, 43, 'invoice', -16000.00, 'إصدار فاتورة رقم INV-202607-0117', 'App\\Models\\Invoice', 117, '2026-07-02 21:44:43', '2026-07-02 21:44:43', NULL, 43),
(154, 28, 'payment', 10600.00, 'سند قبض رقم REC-202607-0037', 'App\\Models\\Payment', 37, '2026-07-02 21:46:54', '2026-07-02 21:48:32', NULL, 28),
(155, 30, 'payment', 72800.00, 'سند قبض رقم REC-202607-0038', 'App\\Models\\Payment', 38, '2026-07-05 16:21:29', '2026-07-05 16:21:29', NULL, 30),
(156, 45, 'payment', 10200.00, 'سند قبض رقم REC-202607-0039', 'App\\Models\\Payment', 39, '2026-07-05 16:22:34', '2026-07-05 16:22:34', NULL, 45);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `remember_token`, `created_at`, `updated_at`, `avatar`) VALUES
(1, 'علي الوشاح', 'admin@water.test', NULL, '$2y$12$EYa2umeE3lnfHy5uiBbzPOHUnYJ7DNGt1/5W4tgKJ/L4bWgPCjXS.', 'admin', '7ngzzJJCg7PZElDw3fmQR653VsOyih94C4OCj9hvT2OQJF6DKN6SfCTzrUIc', '2026-06-11 17:16:22', '2026-07-28 18:29:35', 'avatars/S2lDm4WNYZQbG9ig8B30uOxhKVeT9FR7Mt3G3iSQ.jpg'),
(2, 'قارئ عدادات', 'reader@water.test', NULL, '$2y$12$wwpa/cHIPpTo1X9QksW44uoWR.8xVH4wiEEln.v7XHtWB8od7Bt8G', 'admin', NULL, '2026-07-24 00:08:03', '2026-07-24 00:08:03', NULL),
(3, 'محمد الوشاح المحصل', 'collector@water.test', NULL, '$2y$12$ZkKz/vPpn2e75wYDdf5zn.Os4zfa52bU4Rn8J5bHaFmg1hL51w30m', 'admin', NULL, '2026-07-24 00:08:54', '2026-07-24 18:02:23', NULL),
(4, 'متحصل ومدخل القراءات للعداد', 'rc@water.test', NULL, '$2y$12$7ex3cvNnweXScWigIg7lHextrhSveBHC.Cv5LQgdW8x8bJU0/woxe', 'admin', NULL, '2026-07-24 20:55:04', '2026-07-24 20:55:04', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  ADD KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoices_bill_number_unique` (`bill_number`),
  ADD KEY `invoices_subscriber_id_foreign` (`subscriber_id`),
  ADD KEY `invoices_meter_id_foreign` (`meter_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_items_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `meters`
--
ALTER TABLE `meters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `meters_unique_code_unique` (`unique_code`),
  ADD KEY `meters_subscriber_id_foreign` (`subscriber_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payments_receipt_number_unique` (`receipt_number`),
  ADD KEY `payments_subscriber_id_foreign` (`subscriber_id`),
  ADD KEY `payments_invoice_id_foreign` (`invoice_id`),
  ADD KEY `payments_meter_id_foreign` (`meter_id`),
  ADD KEY `payments_user_id_foreign` (`user_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `readings`
--
ALTER TABLE `readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `readings_meter_id_foreign` (`meter_id`),
  ADD KEY `readings_created_by_foreign` (`created_by`),
  ADD KEY `readings_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_requests_subscriber_id_foreign` (`subscriber_id`),
  ADD KEY `service_requests_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settings_key_unique` (`key`);

--
-- Indexes for table `squares`
--
ALTER TABLE `squares`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subscribers`
--
ALTER TABLE `subscribers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscribers_square_id_foreign` (`square_id`);

--
-- Indexes for table `subscription_fees`
--
ALTER TABLE `subscription_fees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscription_fees_subscriber_id_foreign` (`subscriber_id`),
  ADD KEY `subscription_fees_meter_id_foreign` (`meter_id`),
  ADD KEY `subscription_fees_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `tariffs`
--
ALTER TABLE `tariffs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_subscriber_id_foreign` (`subscriber_id`),
  ADD KEY `transactions_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  ADD KEY `transactions_meter_id_foreign` (`meter_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=206;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=305;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=209;

--
-- AUTO_INCREMENT for table `meters`
--
ALTER TABLE `meters`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `readings`
--
ALTER TABLE `readings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=151;

--
-- AUTO_INCREMENT for table `squares`
--
ALTER TABLE `squares`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `subscribers`
--
ALTER TABLE `subscribers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `subscription_fees`
--
ALTER TABLE `subscription_fees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `tariffs`
--
ALTER TABLE `tariffs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=278;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meters`
--
ALTER TABLE `meters`
  ADD CONSTRAINT `meters_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `readings`
--
ALTER TABLE `readings`
  ADD CONSTRAINT `readings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `readings_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `readings_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD CONSTRAINT `service_requests_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `service_requests_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscribers`
--
ALTER TABLE `subscribers`
  ADD CONSTRAINT `subscribers_square_id_foreign` FOREIGN KEY (`square_id`) REFERENCES `squares` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_fees`
--
ALTER TABLE `subscription_fees`
  ADD CONSTRAINT `subscription_fees_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `subscription_fees_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `subscription_fees_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
