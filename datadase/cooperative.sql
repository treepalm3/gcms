-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: mysql
-- Generation Time: Oct 26, 2025 at 04:16 AM
-- Server version: 8.4.7
-- PHP Version: 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cooperative`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `station_id` int UNSIGNED NOT NULL DEFAULT '1',
  `house_number` varchar(100) DEFAULT NULL,
  `address` text,
  `comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `user_id`, `station_id`, `house_number`, `address`, `comment`, `created_at`) VALUES
(1, 1, 1, '148', '148 หมู่ 9 จังหวัดร้อยเอ็ด', '', '2025-09-24 07:46:36');

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `key` varchar(64) NOT NULL,
  `json_value` json NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`key`, `json_value`, `updated_at`) VALUES
('fuel_price_settings', '{\"price_source\": \"manual\", \"round_to_satang\": 25, \"auto_price_update\": false, \"markup_percentage\": 2.5, \"price_update_time\": \"06:00\"}', '2025-10-10 10:22:34'),
('notification_settings', '{\"payment_alerts\": true, \"low_stock_alert\": true, \"sms_notifications\": false, \"daily_report_email\": true, \"line_notifications\": true, \"email_notifications\": true, \"low_stock_threshold\": 1000, \"maintenance_reminder\": true}', '2025-10-10 10:22:34'),
('security_settings', '{\"session_timeout\": 60, \"two_factor_auth\": false, \"backup_frequency\": \"daily\", \"audit_log_enabled\": true, \"max_login_attempts\": 5, \"password_min_length\": 8, \"ip_whitelist_enabled\": false, \"require_special_chars\": true}', '2025-10-10 10:22:34'),
('system_settings', '{\"tax_id\": \"1234567890123\", \"address\": \"บ้านภูเขาทอง หมู่ที่ 5 ตำบลคำพอุง อำเภอโพธิ์ชัย จังหวัดร้อยเอ็ด\", \"currency\": \"THB\", \"language\": \"th\", \"timezone\": \"Asia/Bangkok\", \"site_name\": \"สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง\", \"date_format\": \"d/m/Y\", \"contact_email\": \"info@coop-fuel.com\", \"contact_phone\": \"02-123-4567\", \"site_subtitle\": \"ระบบบริหารจัดการปั๊มน้ำมัน\", \"registration_number\": \"สหกรณ์ที่ 12345\"}', '2025-10-24 15:02:18');

-- --------------------------------------------------------

--
-- Table structure for table `committees`
--

CREATE TABLE `committees` (
  `id` bigint UNSIGNED NOT NULL,
  `committee_code` varchar(20) NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL,
  `department` varchar(50) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `shares` int DEFAULT '0',
  `house_number` varchar(255) DEFAULT NULL,
  `address` text,
  `joined_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `committees`
--

INSERT INTO `committees` (`id`, `committee_code`, `user_id`, `station_id`, `department`, `position`, `shares`, `house_number`, `address`, `joined_date`) VALUES
(1, 'C-001', 5, 1, 'finance', 'เหรัญญิก', 1, '123', '123 หมู่ 5 บ้านภูเขาทอง จ.ร้อยเอ็ด', '2025-10-01');

-- --------------------------------------------------------

--
-- Table structure for table `dividends`
--

CREATE TABLE `dividends` (
  `id` bigint UNSIGNED NOT NULL,
  `member_id` bigint UNSIGNED NOT NULL,
  `dividend_amount` decimal(15,2) NOT NULL,
  `dividend_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `dividend_payments`
--

CREATE TABLE `dividend_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `period_id` int UNSIGNED NOT NULL,
  `member_id` bigint UNSIGNED NOT NULL,
  `member_type` varchar(20) NOT NULL DEFAULT 'member',
  `shares_at_time` int UNSIGNED NOT NULL,
  `dividend_amount` decimal(15,2) NOT NULL,
  `payment_status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL
) ;

--
-- Dumping data for table `dividend_payments`
--

INSERT INTO `dividend_payments` (`id`, `period_id`, `member_id`, `member_type`, `shares_at_time`, `dividend_amount`, `payment_status`, `paid_at`) VALUES
(47, 13, 1, 'member', 1, 460.21, 'pending', NULL),
(48, 13, 7, 'member', 1, 460.21, 'pending', NULL),
(49, 13, 10, 'manager', 1, 460.21, 'pending', NULL),
(50, 13, 11, 'committee', 1, 460.21, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `dividend_periods`
--

CREATE TABLE `dividend_periods` (
  `id` int UNSIGNED NOT NULL,
  `year` smallint UNSIGNED NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `period_name` varchar(100) NOT NULL,
  `total_profit` decimal(15,2) NOT NULL,
  `dividend_rate` decimal(5,2) NOT NULL COMMENT 'Percentage of profit allocated for dividends',
  `total_shares_at_time` int UNSIGNED NOT NULL,
  `total_dividend_amount` decimal(15,2) NOT NULL,
  `dividend_per_share` decimal(15,4) NOT NULL,
  `status` enum('pending','approved','paid') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dividend_periods`
--

INSERT INTO `dividend_periods` (`id`, `year`, `start_date`, `end_date`, `period_name`, `total_profit`, `dividend_rate`, `total_shares_at_time`, `total_dividend_amount`, `dividend_per_share`, `status`, `payment_date`, `created_at`, `approved_by`) VALUES
(13, 2025, '2025-01-01', '2025-12-31', 'ปันผลประจำปี 2025', 18408.33, 10.00, 4, 1840.83, 460.2075, 'pending', NULL, '2025-10-25 14:55:21', 'ผู้ดูแลระบบ');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `emp_code` varchar(20) DEFAULT NULL,
  `position` varchar(80) NOT NULL DEFAULT 'พนักงานปั๊ม',
  `address` text,
  `joined_date` date DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `station_id`, `emp_code`, `position`, `address`, `joined_date`, `salary`, `created_at`) VALUES
(1, 3, 1, 'E-001', 'พนักงานปั๊ม', '7/5', '2023-04-12', 9000.00, '2025-08-25 16:55:21');

-- --------------------------------------------------------

--
-- Table structure for table `financial_transactions`
--

CREATE TABLE `financial_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `transaction_code` varchar(50) DEFAULT NULL,
  `transaction_date` datetime NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `financial_transactions`
--

INSERT INTO `financial_transactions` (`id`, `station_id`, `transaction_code`, `transaction_date`, `type`, `category`, `description`, `amount`, `reference_id`, `user_id`, `created_at`) VALUES
(91, 1, 'FT-20251025-105719', '2025-10-25 10:57:00', 'income', 'เงินลงทุน', 'ต้นทุน', 200000.00, NULL, 1, '2025-10-25 03:58:31'),
(95, 1, 'FT-20251025-183029', '2025-10-25 18:30:29', 'expense', 'ซื้อน้ำมัน', 'ซื้อ ดีเซล 1500 ลิตร', 46500.00, 'LOT-20251025183029-01-7f196c', 1, '2025-10-25 11:30:29'),
(96, 1, 'FT-20251025-183454', '2025-10-25 18:34:54', 'expense', 'ซื้อน้ำมัน', 'ซื้อ แก๊สโซฮอล์ 95 1500 ลิตร', 52500.00, 'LOT-20251025183454-02-3b465e', 1, '2025-10-25 11:34:54'),
(97, 1, 'FT-20251025-231930', '2025-10-25 23:19:30', 'expense', 'ซื้อน้ำมัน', 'ซื้อ ดีเซล 100 ลิตร', 3100.00, 'LOT-20251025231930-01-c8ab0d', 2, '2025-10-25 16:19:30');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_adjustments`
--

CREATE TABLE `fuel_adjustments` (
  `id` bigint UNSIGNED NOT NULL,
  `fuel_id` int NOT NULL,
  `adj_type` enum('plus','minus') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `notes` text,
  `adjusted_at` datetime DEFAULT NULL,
  `adjusted_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fuel_lots`
--

CREATE TABLE `fuel_lots` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `fuel_id` int NOT NULL,
  `tank_id` bigint UNSIGNED NOT NULL,
  `receive_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `lot_code` varchar(50) NOT NULL,
  `received_at` datetime NOT NULL,
  `observed_liters` decimal(10,2) NOT NULL,
  `corrected_liters` decimal(10,2) DEFAULT NULL,
  `unit_cost` decimal(10,4) NOT NULL,
  `tax_per_liter` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `other_costs` decimal(12,2) NOT NULL DEFAULT '0.00',
  `initial_liters` decimal(12,2) GENERATED ALWAYS AS (coalesce(`corrected_liters`,`observed_liters`)) STORED,
  `initial_unit_cost` decimal(10,4) GENERATED ALWAYS AS ((`unit_cost` + `tax_per_liter`)) STORED,
  `initial_total_cost` decimal(14,2) GENERATED ALWAYS AS (((`initial_liters` * `initial_unit_cost`) + `other_costs`)) STORED,
  `density_kg_per_l` decimal(6,3) DEFAULT NULL,
  `temp_c` decimal(5,2) DEFAULT NULL,
  `notes` text,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `unit_cost_full` decimal(12,6) GENERATED ALWAYS AS (((`unit_cost` + `tax_per_liter`) + (`other_costs` / nullif(`initial_liters`,0)))) STORED,
  `liters_received` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_cost` decimal(14,2) NOT NULL DEFAULT '0.00',
  `remaining_liters` decimal(12,2) NOT NULL DEFAULT '0.00',
  `freight` decimal(12,2) DEFAULT NULL,
  `discount` decimal(12,2) DEFAULT NULL,
  `vat_in_cost` decimal(12,2) DEFAULT NULL,
  `invoice_no` varchar(64) DEFAULT NULL
) ;

--
-- Dumping data for table `fuel_lots`
--

INSERT INTO `fuel_lots` (`id`, `station_id`, `fuel_id`, `tank_id`, `receive_id`, `supplier_id`, `lot_code`, `received_at`, `observed_liters`, `corrected_liters`, `unit_cost`, `tax_per_liter`, `other_costs`, `density_kg_per_l`, `temp_c`, `notes`, `created_by`, `created_at`, `updated_at`, `liters_received`, `total_cost`, `remaining_liters`, `freight`, `discount`, `vat_in_cost`, `invoice_no`) VALUES
(1, 1, 1, 1, NULL, 1, 'LOT-20251025183029-01-7f196c', '2025-10-25 18:30:29', 1500.00, NULL, 31.0000, 0.0000, 0.00, NULL, NULL, '', 1, '2025-10-25 11:30:29', '2025-10-25 11:30:29', 0.00, 0.00, 0.00, NULL, NULL, NULL, ''),
(2, 1, 2, 2, NULL, 1, 'LOT-20251025183454-02-3b465e', '2025-10-25 18:34:54', 1500.00, NULL, 35.0000, 0.0000, 0.00, NULL, NULL, '', 1, '2025-10-25 11:34:54', '2025-10-25 11:34:54', 0.00, 0.00, 0.00, NULL, NULL, NULL, ''),
(3, 1, 1, 1, NULL, 1, 'LOT-20251025231930-01-c8ab0d', '2025-10-25 23:19:30', 100.00, NULL, 31.0000, 0.0000, 0.00, NULL, NULL, '', 2, '2025-10-25 16:19:30', '2025-10-25 16:19:30', 0.00, 0.00, 0.00, NULL, NULL, NULL, '');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_lot_allocations`
--

CREATE TABLE `fuel_lot_allocations` (
  `id` bigint UNSIGNED NOT NULL,
  `lot_id` bigint UNSIGNED NOT NULL,
  `move_id` bigint UNSIGNED NOT NULL,
  `allocated_liters` decimal(10,2) NOT NULL,
  `unit_cost_snapshot` decimal(12,6) NOT NULL,
  `line_cost` decimal(23,8) GENERATED ALWAYS AS ((`allocated_liters` * `unit_cost_snapshot`)) STORED,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

--
-- Dumping data for table `fuel_lot_allocations`
--

INSERT INTO `fuel_lot_allocations` (`id`, `lot_id`, `move_id`, `allocated_liters`, `unit_cost_snapshot`, `created_at`) VALUES
(1, 2, 3, 2.35, 35.000000, '2025-10-25 13:30:18'),
(2, 1, 4, 8.36, 31.000000, '2025-10-25 13:30:50'),
(3, 2, 5, 9.41, 35.000000, '2025-10-25 13:57:12'),
(4, 1, 6, 0.56, 31.000000, '2025-10-25 13:57:44'),
(5, 2, 7, 7.06, 35.000000, '2025-10-25 13:58:20'),
(6, 1, 8, 1.39, 31.000000, '2025-10-25 14:12:56'),
(7, 2, 10, 0.71, 35.000000, '2025-10-26 02:53:42'),
(8, 1, 11, 30.00, 31.000000, '2025-10-26 02:54:46'),
(9, 2, 12, 0.47, 35.000000, '2025-10-26 03:43:07'),
(10, 2, 13, 1.18, 35.000000, '2025-10-26 03:55:34');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_moves`
--

CREATE TABLE `fuel_moves` (
  `id` bigint UNSIGNED NOT NULL,
  `occurred_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('receive','transfer_in','transfer_out','adjust_plus','adjust_minus','sale_out') NOT NULL,
  `tank_id` bigint UNSIGNED NOT NULL,
  `lot_id` int DEFAULT NULL,
  `liters` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `ref_doc` varchar(50) DEFAULT NULL,
  `ref_note` text,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sale_id` bigint UNSIGNED DEFAULT NULL,
  `is_sale_out` tinyint(1) GENERATED ALWAYS AS (if((`type` = _utf8mb4'sale_out'),1,0)) STORED,
  `unit_cost` decimal(10,4) DEFAULT NULL
) ;

--
-- Dumping data for table `fuel_moves`
--

INSERT INTO `fuel_moves` (`id`, `occurred_at`, `type`, `tank_id`, `lot_id`, `liters`, `unit_price`, `ref_doc`, `ref_note`, `user_id`, `created_at`, `sale_id`, `unit_cost`) VALUES
(1, '2025-10-25 11:30:29', 'receive', 1, NULL, 1500.00, NULL, 'LOT-20251025183029-01-7f196c', '', 1, '2025-10-25 11:30:29', NULL, NULL),
(2, '2025-10-25 11:34:54', 'receive', 2, NULL, 1500.00, NULL, 'LOT-20251025183454-02-3b465e', '', 1, '2025-10-25 11:34:54', NULL, NULL),
(3, '2025-10-25 13:30:18', 'sale_out', 2, NULL, 2.35, 42.50, 'R20251025-597A3D', 'POS sale', 3, '2025-10-25 13:30:18', 1, NULL),
(4, '2025-10-25 13:30:50', 'sale_out', 1, NULL, 8.36, 35.90, 'R20251025-0846DD', 'POS sale', 3, '2025-10-25 13:30:50', 2, NULL),
(5, '2025-10-25 13:57:12', 'sale_out', 2, NULL, 9.41, 42.50, 'R20251025-80AE60', 'POS sale', 3, '2025-10-25 13:57:12', 3, NULL),
(6, '2025-10-25 13:57:44', 'sale_out', 1, NULL, 0.56, 35.90, 'R20251025-06C506', 'POS sale', 3, '2025-10-25 13:57:44', 4, NULL),
(7, '2025-10-25 13:58:20', 'sale_out', 2, NULL, 7.06, 42.50, 'R20251025-CAB8F6', 'POS sale', 3, '2025-10-25 13:58:20', 5, NULL),
(8, '2025-10-25 14:12:56', 'sale_out', 1, NULL, 1.39, 35.90, 'R20251025-9DB4B5', 'POS sale', 3, '2025-10-25 14:12:56', 6, NULL),
(9, '2025-10-25 16:19:30', 'receive', 1, NULL, 100.00, NULL, 'LOT-20251025231930-01-c8ab0d', '', 2, '2025-10-25 16:19:30', NULL, NULL),
(10, '2025-10-26 02:53:42', 'sale_out', 2, NULL, 0.71, 42.50, 'R20251026-7BB20E', 'POS sale', 3, '2025-10-26 02:53:42', 7, NULL),
(11, '2025-10-26 02:54:46', 'sale_out', 1, NULL, 30.00, 35.90, 'R20251026-54A586', 'POS sale', 3, '2025-10-26 02:54:46', 8, NULL),
(12, '2025-10-26 03:43:07', 'sale_out', 2, NULL, 0.47, 42.50, 'R20251026-990A77', 'POS sale', 3, '2025-10-26 03:43:07', 9, NULL),
(13, '2025-10-26 03:55:34', 'sale_out', 2, NULL, 1.18, 42.50, 'R20251026-1923CF', 'POS sale', 3, '2025-10-26 03:55:34', 10, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fuel_moves_bak`
--

CREATE TABLE `fuel_moves_bak` (
  `id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `occurred_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('receive','transfer_in','transfer_out','adjust_plus','adjust_minus','sale_out') NOT NULL,
  `tank_id` bigint UNSIGNED NOT NULL,
  `lot_id` int DEFAULT NULL,
  `liters` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `ref_doc` varchar(50) DEFAULT NULL,
  `ref_note` text,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sale_id` bigint UNSIGNED DEFAULT NULL,
  `is_sale_out` tinyint(1) DEFAULT NULL,
  `unit_cost` decimal(10,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fuel_moves_bak`
--

INSERT INTO `fuel_moves_bak` (`id`, `occurred_at`, `type`, `tank_id`, `lot_id`, `liters`, `unit_price`, `ref_doc`, `ref_note`, `user_id`, `created_at`, `sale_id`, `is_sale_out`, `unit_cost`) VALUES
(1, '2025-09-29 15:07:06', 'receive', 1, NULL, 100.00, 34.50, 'TEST', 'seed test', 1, '2025-09-29 15:07:06', NULL, 0, NULL),
(2, '2025-10-01 08:24:14', 'sale_out', 1, NULL, 2.03, 35.01, 'R20251001-1514', 'POS sale', 3, '2025-10-01 08:24:14', 1, 1, NULL),
(3, '2025-10-01 08:47:42', 'sale_out', 1, NULL, 1.43, 35.01, 'R20251001-2922', 'POS sale', 3, '2025-10-01 08:47:42', 2, 1, NULL),
(4, '2025-10-01 08:48:14', 'sale_out', 2, NULL, 1.13, 44.28, 'R20251001-8050', 'POS sale', 3, '2025-10-01 08:48:14', 3, 1, NULL),
(5, '2025-10-01 08:48:46', 'sale_out', 3, NULL, 1.42, 42.15, 'R20251001-1652', 'POS sale', 3, '2025-10-01 08:48:46', 4, 1, NULL),
(6, '2025-10-01 08:52:51', 'sale_out', 1, NULL, 1.14, 35.01, 'R20251001-1360', 'POS sale', 3, '2025-10-01 08:52:51', 5, 1, NULL),
(7, '2025-10-01 09:37:43', 'sale_out', 3, NULL, 2.37, 42.15, 'R20251001-7848', 'POS sale', 3, '2025-10-01 09:37:43', 6, 1, NULL),
(8, '2025-10-01 10:11:16', 'sale_out', 1, NULL, 0.57, 35.01, 'R20251001-0504', 'POS sale', 3, '2025-10-01 10:11:16', 7, 1, NULL),
(9, '2025-10-01 10:11:49', 'sale_out', 1, NULL, 0.57, 35.01, 'R20251001-6802', 'POS sale', 3, '2025-10-01 10:11:49', 8, 1, NULL),
(10, '2025-10-01 10:31:32', 'sale_out', 2, NULL, 0.90, 44.28, 'R20251001-6083', 'POS sale', 3, '2025-10-01 10:31:32', 9, 1, NULL),
(11, '2025-10-01 10:31:38', 'sale_out', 2, NULL, 0.90, 44.28, 'R20251001-4802', 'POS sale', 3, '2025-10-01 10:31:38', 10, 1, NULL),
(12, '2025-10-01 10:56:44', 'sale_out', 3, NULL, 0.71, 42.15, 'R20251001-6993', 'POS sale', 3, '2025-10-01 10:56:44', 11, 1, NULL),
(13, '2025-10-05 06:44:57', 'sale_out', 2, NULL, 1.81, 44.28, 'R20251005-3471', 'POS sale', 3, '2025-10-05 06:44:57', 12, 1, NULL),
(14, '2025-10-05 07:04:23', 'sale_out', 3, NULL, 0.47, 42.15, 'R20251005-1732', 'POS sale', 3, '2025-10-05 07:04:23', 13, 1, NULL),
(15, '2025-10-05 07:12:19', 'sale_out', 1, NULL, 0.57, 35.01, 'R20251005-2864', 'POS sale', 3, '2025-10-05 07:12:19', 14, 1, NULL),
(16, '2025-10-05 07:23:02', 'sale_out', 1, NULL, 0.57, 35.01, 'R20251005-7344', 'POS sale', 3, '2025-10-05 07:23:02', 15, 1, NULL),
(17, '2025-10-05 07:41:19', 'sale_out', 2, NULL, 0.68, 44.28, 'R20251005-4812', 'POS sale', 3, '2025-10-05 07:41:19', 16, 1, NULL),
(18, '2025-10-05 08:58:18', 'sale_out', 1, NULL, 0.57, 35.01, 'R20251005-0762', 'POS sale', 3, '2025-10-05 08:58:18', 17, 1, NULL),
(19, '2025-10-05 09:01:52', 'sale_out', 1, NULL, 2.29, 35.01, 'R20251005-0326', 'POS sale', 3, '2025-10-05 09:01:52', 18, 1, NULL),
(20, '2025-10-05 09:02:33', 'sale_out', 1, NULL, 1.43, 35.01, 'R20251005-6502', 'POS sale', 3, '2025-10-05 09:02:33', 19, 1, NULL),
(21, '2025-10-05 15:20:24', 'sale_out', 3, NULL, 1.19, 42.15, 'R20251005-4626', 'POS sale', 3, '2025-10-05 15:20:24', 20, 1, NULL),
(22, '2025-10-05 17:23:08', 'sale_out', 1, NULL, 1.43, 35.01, 'R20251006-2833', 'POS sale', 3, '2025-10-05 17:23:08', 21, 1, NULL),
(23, '2025-10-05 17:25:59', 'sale_out', 2, NULL, 0.45, 44.28, 'R20251006-5429', 'POS sale', 3, '2025-10-05 17:25:59', 22, 1, NULL),
(24, '2025-10-05 17:34:09', 'sale_out', 1, NULL, 1.43, 35.01, 'R20251006-5163', 'POS sale', 3, '2025-10-05 17:34:09', 23, 1, NULL),
(25, '2025-10-05 17:56:48', 'sale_out', 1, NULL, 2.29, 35.01, 'R20251006-9147', 'POS sale', 3, '2025-10-05 17:56:48', 24, 1, NULL),
(26, '2025-10-06 17:20:11', 'sale_out', 3, NULL, 0.95, 42.15, 'R20251007-9306', 'POS sale', 3, '2025-10-06 17:20:11', 25, 1, NULL),
(27, '2025-10-06 17:35:22', 'sale_out', 2, NULL, 0.45, 44.28, 'R20251007-1418', 'POS sale', 3, '2025-10-06 17:35:22', 26, 1, NULL),
(28, '2025-10-06 17:37:51', 'sale_out', 3, NULL, 1.90, 42.15, 'R20251007-4730', 'POS sale', 3, '2025-10-06 17:37:51', 27, 1, NULL),
(29, '2025-10-07 03:31:20', 'sale_out', 1, NULL, 1.43, 35.01, 'R20251007-2570', 'POS sale', 3, '2025-10-07 03:31:20', 28, 1, NULL),
(30, '2025-10-10 22:46:00', 'transfer_in', 2, NULL, 100.00, NULL, 'TRANSFER', '', 1, '2025-10-10 15:46:00', NULL, 0, NULL),
(31, '2025-10-11 01:26:10', 'receive', 3, NULL, 100.00, NULL, 'LOT-20251011-082610-3', 'รับน้ำมันเข้าถัง T03', 1, '2025-10-11 01:26:10', NULL, 0, NULL),
(32, '2025-10-11 08:29:32', 'receive', 1, NULL, 100.00, NULL, 'RCV-10', '', 1, '2025-10-11 01:29:32', NULL, 0, NULL),
(33, '2025-10-13 13:09:06', 'receive', 2, NULL, 100.00, NULL, 'LOT-20251013200906-02-43918e', '', 1, '2025-10-13 13:09:06', NULL, 0, NULL),
(34, '2025-10-13 18:47:01', 'receive', 2, NULL, 200.00, NULL, 'LOT-20251014014701-02-078797', '', 1, '2025-10-13 18:47:01', NULL, 0, NULL),
(35, '2025-10-14 13:12:45', 'receive', 1, NULL, 100.00, NULL, 'LOT-20251014201245-01-6eb542', '', 2, '2025-10-14 13:12:45', NULL, 0, NULL),
(36, '2025-10-15 18:51:29', 'receive', 1, NULL, 100.00, NULL, 'LOT-20251016015129-01-57de31', '', 3, '2025-10-15 18:51:29', NULL, 0, NULL),
(37, '2025-10-20 04:28:48', 'receive', 2, NULL, 100.00, NULL, 'LOT-20251020112848-02-307739', '', 3, '2025-10-20 04:28:48', NULL, 0, NULL),
(38, '2025-10-20 17:42:03', 'receive', 1, NULL, 100.00, NULL, 'LOT-20251021004203-01-5838ad', '', 3, '2025-10-20 17:42:03', NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fuel_prices`
--

CREATE TABLE `fuel_prices` (
  `fuel_id` int NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `fuel_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `display_order` int NOT NULL
) ;

--
-- Dumping data for table `fuel_prices`
--

INSERT INTO `fuel_prices` (`fuel_id`, `station_id`, `fuel_name`, `price`, `display_order`) VALUES
(1, 1, 'ดีเซล', 35.90, 1),
(2, 1, 'แก๊สโซฮอล์ 95', 42.50, 2);

-- --------------------------------------------------------

--
-- Table structure for table `fuel_receives`
--

CREATE TABLE `fuel_receives` (
  `id` int NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `fuel_id` int DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `notes` text,
  `received_date` datetime DEFAULT NULL,
  `created_by` int NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `fuel_stock`
--

CREATE TABLE `fuel_stock` (
  `fuel_id` int NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `current_stock` decimal(10,2) NOT NULL DEFAULT '0.00',
  `capacity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_threshold` decimal(10,2) NOT NULL DEFAULT '90.00',
  `max_threshold` decimal(10,2) NOT NULL DEFAULT '500.00',
  `last_refill_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_refill_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fuel_stock`
--

INSERT INTO `fuel_stock` (`fuel_id`, `station_id`, `current_stock`, `capacity`, `min_threshold`, `max_threshold`, `last_refill_date`, `last_refill_amount`) VALUES
(1, 1, 0.00, 0.00, 90.00, 500.00, NULL, NULL),
(2, 1, 0.00, 1000.00, 100.00, 800.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fuel_tanks`
--

CREATE TABLE `fuel_tanks` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `code` varchar(50) NOT NULL,
  `fuel_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `capacity_l` decimal(10,2) NOT NULL,
  `min_threshold_l` decimal(10,2) NOT NULL DEFAULT '90.00',
  `max_threshold_l` decimal(10,2) NOT NULL DEFAULT '500.00',
  `current_volume_l` decimal(12,2) NOT NULL DEFAULT '0.00',
  `last_maintenance` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

--
-- Dumping data for table `fuel_tanks`
--

INSERT INTO `fuel_tanks` (`id`, `station_id`, `code`, `fuel_id`, `name`, `capacity_l`, `min_threshold_l`, `max_threshold_l`, `current_volume_l`, `last_maintenance`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'T01', 1, 'ดีเซล - Tank', 3000.00, 90.00, 300.00, 1559.69, NULL, 1, '2025-09-29 15:06:11', '2025-10-26 02:54:46'),
(2, 1, 'T02', 2, 'แก๊สโซฮอล์ 95 - Tank', 3000.00, 90.00, 300.00, 1478.82, NULL, 1, '2025-09-29 15:06:11', '2025-10-26 03:55:34');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `invoice_number` varchar(50) NOT NULL,
  `sale_id` bigint UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `issued_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `managers`
--

CREATE TABLE `managers` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `performance_score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `access_level` enum('readonly','limited','full') NOT NULL DEFAULT 'readonly',
  `shares` int UNSIGNED NOT NULL DEFAULT '0',
  `house_number` varchar(100) DEFAULT NULL,
  `address` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ;

--
-- Dumping data for table `managers`
--

INSERT INTO `managers` (`id`, `user_id`, `station_id`, `salary`, `performance_score`, `access_level`, `shares`, `house_number`, `address`, `created_at`, `updated_at`) VALUES
(12, 2, 1, 0.00, 0.00, 'readonly', 1, '134', 'บ้านภูเขาทอง หมู่ที่ 5 และหมู่ที่ 9 ตำบลคำพอุง อำเภอโพธิ์ชัย จังหวัดร้อยเอ็ด', '2025-10-14 09:40:21', '2025-10-22 15:08:07');

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `member_code` varchar(20) NOT NULL,
  `address` text,
  `points` int UNSIGNED NOT NULL DEFAULT '0',
  `joined_date` date DEFAULT NULL,
  `shares` int UNSIGNED DEFAULT '0',
  `house_number` varchar(100) DEFAULT NULL,
  `tier` enum('Bronze','Silver','Gold','Platinum','Diamond') NOT NULL DEFAULT 'Bronze',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `deleted_at` datetime DEFAULT NULL
) ;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`id`, `user_id`, `station_id`, `member_code`, `address`, `points`, `joined_date`, `shares`, `house_number`, `tier`, `created_at`, `is_active`, `deleted_at`) VALUES
(1, 4, 1, 'M-001', '12 หมู่ 9 บ้านภูเขาทอง จ.ร้อยเอ็ด', 6, '2025-09-10', 1, '12', 'Silver', '2025-10-09 12:20:22', 1, NULL),
(7, 22, 1, 'M-002', '82', 10, '2025-10-21', 1, '82', 'Bronze', '2025-10-21 13:36:26', 1, NULL),
(10, 2, 1, 'M-003', 'บ้านภูเขาทอง หมู่ที่ 5 และหมู่ที่ 9 ตำบลคำพอุง อำเภอโพธิ์ชัย จังหวัดร้อยเอ็ด', 0, '2025-10-25', 1, '134', 'Bronze', '2025-10-25 13:49:16', 1, NULL),
(11, 5, 1, 'M-004', '123 หมู่ 5 บ้านภูเขาทอง จ.ร้อยเอ็ด', 58, '2025-10-25', 1, '123', 'Bronze', '2025-10-25 13:49:29', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `point_transactions`
--

CREATE TABLE `point_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `member_user_id` bigint UNSIGNED NOT NULL,
  `type` enum('earn','redeem','adjust') NOT NULL,
  `points` int NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `employee_user_id` bigint UNSIGNED DEFAULT NULL,
  `transaction_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `posts`
--

CREATE TABLE `posts` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `status` enum('published','draft') NOT NULL DEFAULT 'draft',
  `type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pumps`
--

CREATE TABLE `pumps` (
  `pump_id` int NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `pump_name` varchar(255) NOT NULL,
  `fuel_id` int DEFAULT NULL,
  `status` enum('active','maintenance','offline') DEFAULT 'active',
  `last_maintenance` date DEFAULT NULL,
  `total_sales_today` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pump_location` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rebate_payments`
--

CREATE TABLE `rebate_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `period_id` int UNSIGNED NOT NULL,
  `member_id` bigint UNSIGNED NOT NULL,
  `member_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `purchase_amount_at_time` decimal(15,2) NOT NULL COMMENT 'ยอดซื้อของสมาชิกในงวดนั้น',
  `rebate_amount` decimal(15,2) NOT NULL COMMENT 'ยอดเฉลี่ยคืนที่ได้รับ',
  `payment_status` enum('pending','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rebate_payments`
--

INSERT INTO `rebate_payments` (`id`, `period_id`, `member_id`, `member_type`, `purchase_amount_at_time`, `rebate_amount`, `payment_status`, `paid_at`) VALUES
(5, 3, 1, 'member', 170.00, 80.24, 'pending', NULL),
(6, 3, 7, 'member', 300.00, 141.60, 'pending', NULL),
(7, 3, 11, 'member', 700.00, 330.41, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rebate_periods`
--

CREATE TABLE `rebate_periods` (
  `id` int UNSIGNED NOT NULL,
  `year` smallint UNSIGNED NOT NULL,
  `period_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL COMMENT 'วันที่เริ่มนับยอดซื้อ',
  `end_date` date NOT NULL COMMENT 'วันที่สิ้นสุดการนับยอดซื้อ',
  `total_profit_snapshot` decimal(15,2) NOT NULL COMMENT 'กำไรสุทธิที่ใช้เป็นฐานอ้างอิง',
  `rebate_base` enum('profit','net') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'profit' COMMENT 'ฐานคำนวณ (กำไรสุทธิ, คงเหลือ)',
  `rebate_type` enum('rate','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rate' COMMENT 'ประเภทงบ (%, บาท)',
  `rebate_value` decimal(10,4) NOT NULL COMMENT 'ค่า % หรือ จำนวนเงินบาท',
  `total_rebate_budget` decimal(15,2) NOT NULL COMMENT 'งบเฉลี่ยคืนรวม (คำนวณแล้ว)',
  `total_purchase_amount` decimal(15,2) DEFAULT '0.00' COMMENT 'ยอดซื้อรวมของสมาชิกในปีนั้น',
  `rebate_per_baht` decimal(10,6) DEFAULT '0.000000' COMMENT 'อัตราเฉลี่ยคืนต่อบาท (ถ้าโหมด weighted)',
  `status` enum('pending','approved','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rebate_periods`
--

INSERT INTO `rebate_periods` (`id`, `year`, `period_name`, `start_date`, `end_date`, `total_profit_snapshot`, `rebate_base`, `rebate_type`, `rebate_value`, `total_rebate_budget`, `total_purchase_amount`, `rebate_per_baht`, `status`, `payment_date`, `created_at`, `approved_by`) VALUES
(3, 2025, 'เฉลี่ยคืนประจำปี 2025', '2025-01-01', '2025-12-31', 18408.33, 'profit', 'rate', 3.0000, 552.25, 1170.00, 0.472009, 'pending', NULL, '2025-10-25 14:36:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `report_code` varchar(50) NOT NULL,
  `report_type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `report_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` bigint UNSIGNED NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `sale_code` varchar(50) NOT NULL,
  `payment_method` varchar(20) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `household_no` varchar(50) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `net_amount` decimal(15,2) NOT NULL,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `is_edited` tinyint(1) DEFAULT '0',
  `employee_user_id` bigint UNSIGNED DEFAULT NULL
) ;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `station_id`, `sale_code`, `payment_method`, `customer_phone`, `household_no`, `total_amount`, `sale_date`, `created_by`, `net_amount`, `discount_pct`, `discount_amount`, `is_edited`, `employee_user_id`) VALUES
(1, 1, 'R20251025-597A3D', 'transfer', '0812345678', '12', 100.00, '2025-10-25 20:30:18', 3, 100.00, 0.00, 0.00, 0, NULL),
(2, 1, 'R20251025-0846DD', 'qr', '0230404045', '82', 300.00, '2025-10-25 20:30:50', 3, 300.00, 0.00, 0.00, 0, NULL),
(3, 1, 'R20251025-80AE60', 'transfer', '0834445677', '123', 400.00, '2025-10-25 20:57:12', 3, 400.00, 0.00, 0.00, 0, NULL),
(4, 1, 'R20251025-06C506', 'cash', '0812345678', '12', 20.00, '2025-10-25 20:57:44', 3, 20.00, 0.00, 0.00, 0, NULL),
(5, 1, 'R20251025-CAB8F6', 'cash', '0834445677', '123', 300.00, '2025-10-25 20:58:20', 3, 300.00, 0.00, 0.00, 0, NULL),
(6, 1, 'R20251025-9DB4B5', 'cash', '0812345678', '12', 50.00, '2025-10-25 21:12:56', 3, 50.00, 0.00, 0.00, 0, NULL),
(7, 1, 'R20251026-7BB20E', 'qr', '0812345678', '12', 30.00, '2025-10-26 09:53:42', 3, 30.00, 0.00, 0.00, 0, 3),
(8, 1, 'R20251026-54A586', 'cash', '0834445677', '123', 1077.00, '2025-10-26 09:54:46', 3, 1077.00, 0.00, 0.00, 0, 3),
(9, 1, 'R20251026-990A77', 'qr', '0230404045', '82', 20.00, '2025-10-26 10:43:07', 3, 20.00, 0.00, 0.00, 0, 3),
(10, 1, 'R20251026-1923CF', 'qr', '0812345678', '12', 50.00, '2025-10-26 10:55:34', 3, 50.00, 0.00, 0.00, 0, 3);

-- --------------------------------------------------------

--
-- Table structure for table `sales_bak`
--

CREATE TABLE `sales_bak` (
  `id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `station_id` int NOT NULL DEFAULT '1',
  `sale_code` varchar(50) NOT NULL,
  `payment_method` varchar(20) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `household_no` varchar(50) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `net_amount` decimal(15,2) NOT NULL,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `is_edited` tinyint(1) DEFAULT '0',
  `employee_user_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales_bak`
--

INSERT INTO `sales_bak` (`id`, `station_id`, `sale_code`, `payment_method`, `customer_phone`, `household_no`, `total_amount`, `sale_date`, `created_by`, `net_amount`, `discount_pct`, `discount_amount`, `is_edited`, `employee_user_id`) VALUES
(1, 1, 'R20251001-1514', 'cash', NULL, NULL, 71.00, '2025-10-01 08:24:14', 3, 71.00, 0.00, 0.00, 0, NULL),
(2, 1, 'R20251001-2922', 'cash', NULL, NULL, 50.00, '2025-10-01 08:47:42', 3, 50.00, 0.00, 0.00, 0, NULL),
(3, 1, 'R20251001-8050', 'qr', NULL, NULL, 50.04, '2025-10-01 08:48:00', 3, 50.04, 0.00, 0.00, 1, NULL),
(4, 1, 'R20251001-1652', 'cash', NULL, NULL, 60.00, '2025-10-01 08:48:46', 3, 60.00, 0.00, 0.00, 0, NULL),
(5, 1, 'R20251001-1360', 'cash', NULL, NULL, 40.00, '2025-10-01 08:52:51', 3, 40.00, 0.00, 0.00, 0, NULL),
(6, 1, 'R20251001-7848', 'cash', NULL, NULL, 100.00, '2025-10-01 09:37:43', 3, 100.00, 0.00, 0.00, 0, NULL),
(7, 1, 'R20251001-0504', 'cash', NULL, NULL, 20.00, '2025-10-01 17:11:16', 3, 20.00, 0.00, 0.00, 0, NULL),
(8, 1, 'R20251001-6802', 'cash', NULL, NULL, 20.00, '2025-10-01 17:11:49', 3, 20.00, 0.00, 0.00, 0, NULL),
(9, 1, 'R20251001-6083', 'qr', NULL, NULL, 39.85, '2025-10-01 17:31:00', 3, 39.85, 0.00, 0.00, 1, NULL),
(10, 1, 'R20251001-4802', 'transfer', NULL, NULL, 39.85, '2025-10-01 17:31:00', 3, 39.85, 0.00, 0.00, 1, NULL),
(11, 1, 'R20251001-6993', 'cash', NULL, NULL, 30.00, '2025-10-01 17:56:44', 3, 30.00, 0.00, 0.00, 0, NULL),
(12, 1, 'R20251005-3471', 'cash', NULL, NULL, 80.00, '2025-10-05 13:44:57', 3, 80.00, 0.00, 0.00, 0, NULL),
(13, 1, 'R20251005-1732', 'cash', NULL, NULL, 20.00, '2025-10-05 14:04:23', 3, 20.00, 0.00, 0.00, 0, NULL),
(14, 1, 'R20251005-2864', 'cash', NULL, NULL, 20.00, '2025-10-05 14:12:19', 3, 20.00, 0.00, 0.00, 0, NULL),
(15, 1, 'R20251005-7344', 'cash', NULL, NULL, 20.00, '2025-10-05 14:23:02', 3, 20.00, 0.00, 0.00, 0, NULL),
(16, 1, 'R20251005-4812', 'cash', NULL, NULL, 30.00, '2025-10-05 14:41:19', 3, 30.00, 0.00, 0.00, 0, NULL),
(17, 1, 'R20251005-0762', 'transfer', NULL, NULL, 20.00, '2025-10-05 15:58:18', 3, 20.00, 0.00, 0.00, 0, NULL),
(18, 1, 'R20251005-0326', 'qr', NULL, NULL, 80.00, '2025-10-05 16:01:52', 3, 80.00, 0.00, 0.00, 0, NULL),
(19, 1, 'R20251005-6502', 'transfer', NULL, NULL, 50.00, '2025-10-05 16:02:33', 3, 50.00, 0.00, 0.00, 0, NULL),
(20, 1, 'R20251005-4626', 'transfer', NULL, NULL, 50.16, '2025-10-05 22:20:00', 3, 50.16, 0.00, 0.00, 1, NULL),
(21, 1, 'R20251006-2833', 'transfer', NULL, NULL, 50.00, '2025-10-06 08:23:08', 3, 50.00, 0.00, 0.00, 0, NULL),
(22, 1, 'R20251006-5429', 'qr', NULL, NULL, 20.00, '2025-10-06 09:25:59', 3, 20.00, 0.00, 0.00, 0, NULL),
(23, 1, 'R20251006-5163', 'transfer', NULL, NULL, 50.00, '2025-10-06 07:34:09', 3, 50.00, 0.00, 0.00, 0, NULL),
(24, 1, 'R20251006-9147', 'transfer', NULL, NULL, 80.00, '2025-10-06 09:56:48', 3, 80.00, 0.00, 0.00, 0, NULL),
(25, 1, 'R20251007-9306', 'qr', NULL, NULL, 40.00, '2025-10-07 08:20:11', 3, 40.00, 0.00, 0.00, 0, NULL),
(26, 1, 'R20251007-1418', 'transfer', NULL, NULL, 20.00, '2025-10-07 08:35:22', 3, 20.00, 0.00, 0.00, 0, NULL),
(27, 1, 'R20251007-4730', 'cash', NULL, NULL, 80.00, '2025-10-07 07:37:51', 3, 80.00, 0.00, 0.00, 0, NULL),
(28, 1, 'R20251007-2570', 'qr', NULL, NULL, 50.00, '2025-10-07 10:31:20', 3, 50.00, 0.00, 0.00, 0, NULL),
(29, 1, 'R20251009-4127', 'cash', NULL, NULL, 50.00, '2025-10-09 14:30:41', NULL, 50.00, 0.00, 0.00, 0, NULL),
(30, 1, 'R20251016-7637', 'cash', NULL, NULL, 80.18, '2025-10-16 08:45:00', NULL, 80.18, 0.00, 0.00, 1, NULL),
(31, 1, 'R20251016-0687', 'transfer', NULL, NULL, 49.96, '2025-10-16 08:46:00', NULL, 49.96, 0.00, 0.00, 1, NULL),
(32, 1, 'R20251016-6C0DDD', 'qr', NULL, NULL, 80.00, '2025-10-16 13:41:15', NULL, 80.00, 0.00, 0.00, 0, NULL),
(33, 1, 'R20251017-E30C2D', 'cash', NULL, NULL, 80.00, '2025-10-17 08:42:47', NULL, 80.00, 0.00, 0.00, 0, NULL),
(34, 1, 'R20251017-A2B292', 'qr', NULL, NULL, 50.00, '2025-10-17 09:42:38', NULL, 50.00, 0.00, 0.00, 0, NULL),
(35, 1, 'R20251017-95B2DB', 'cash', NULL, NULL, 80.00, '2025-10-17 09:43:27', NULL, 80.00, 0.00, 0.00, 0, NULL),
(36, 1, 'R20251017-F84AF7', 'cash', NULL, NULL, 70.02, '2025-10-17 09:44:29', NULL, 70.02, 0.00, 0.00, 0, NULL),
(37, 1, 'R20251017-1A0571', 'cash', NULL, NULL, 80.58, '2025-10-17 10:04:30', NULL, 80.58, 0.00, 0.00, 0, NULL),
(38, 1, 'R20251017-63693A', 'cash', NULL, NULL, 80.00, '2025-10-17 11:08:57', NULL, 80.00, 0.00, 0.00, 0, NULL),
(39, 1, 'R20251017-1494', 'cash', NULL, NULL, 50.00, '2025-10-17 11:16:45', NULL, 50.00, 0.00, 0.00, 0, NULL),
(41, 1, 'R20251017-89E38C', 'cash', NULL, NULL, 80.00, '2025-10-17 17:24:07', NULL, 80.00, 0.00, 0.00, 0, NULL),
(42, 1, 'R20251017-33FE70', 'qr', NULL, NULL, 80.00, '2025-10-17 20:51:36', NULL, 80.00, 0.00, 0.00, 0, NULL),
(47, 1, 'R20251018-B33B7E', 'cash', NULL, NULL, 20.00, '2025-10-18 17:57:25', NULL, 20.00, 0.00, 0.00, 0, NULL),
(48, 1, 'R20251018-19C24F', 'cash', NULL, NULL, 20.00, '2025-10-18 22:55:16', NULL, 20.00, 0.00, 0.00, 0, NULL),
(49, 1, 'R20251018-B7E0E4', 'qr', NULL, NULL, 50.00, '2025-10-18 23:05:06', NULL, 50.00, 0.00, 0.00, 0, NULL),
(51, 1, 'R20251018-22EB5B', 'cash', NULL, NULL, 80.00, '2025-10-18 23:19:49', NULL, 80.00, 0.00, 0.00, 0, NULL),
(52, 1, 'R20251020-042B58', 'cash', NULL, NULL, 50.00, '2025-10-20 11:27:57', NULL, 50.00, 0.00, 0.00, 0, NULL),
(53, 1, 'R20251021-8B9868', 'cash', NULL, NULL, 40.29, '2025-10-21 00:02:57', NULL, 40.29, 0.00, 0.00, 0, NULL),
(55, 1, 'R20251021-AED7C8', 'cash', NULL, NULL, 20.00, '2025-10-21 01:49:14', NULL, 20.00, 0.00, 0.00, 0, NULL),
(56, 1, 'R20251021-22C08F', 'cash', NULL, NULL, 50.00, '2025-10-21 02:33:21', 3, 50.00, 0.00, 0.00, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` bigint UNSIGNED NOT NULL,
  `sale_id` bigint UNSIGNED NOT NULL,
  `fuel_id` int DEFAULT NULL,
  `tank_id` bigint UNSIGNED DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `liters` decimal(10,2) NOT NULL DEFAULT '0.00',
  `price_per_liter` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_amount` decimal(15,2) GENERATED ALWAYS AS (round((`liters` * `price_per_liter`),2)) STORED,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

--
-- Dumping data for table `sales_items`
--

INSERT INTO `sales_items` (`id`, `sale_id`, `fuel_id`, `tank_id`, `fuel_type`, `liters`, `price_per_liter`, `created_at`) VALUES
(1, 1, 2, 2, 'แก๊สโซฮอล์ 95', 2.35, 42.50, '2025-10-25 13:30:18'),
(2, 2, 1, 1, 'ดีเซล', 8.36, 35.90, '2025-10-25 13:30:50'),
(3, 3, 2, 2, 'แก๊สโซฮอล์ 95', 9.41, 42.50, '2025-10-25 13:57:12'),
(4, 4, 1, 1, 'ดีเซล', 0.56, 35.90, '2025-10-25 13:57:44'),
(5, 5, 2, 2, 'แก๊สโซฮอล์ 95', 7.06, 42.50, '2025-10-25 13:58:20'),
(6, 6, 1, 1, 'ดีเซล', 1.39, 35.90, '2025-10-25 14:12:56'),
(7, 7, 2, 2, 'แก๊สโซฮอล์ 95', 0.71, 42.50, '2025-10-26 02:53:42'),
(8, 8, 1, 1, 'ดีเซล', 30.00, 35.90, '2025-10-26 02:54:46'),
(9, 9, 2, 2, 'แก๊สโซฮอล์ 95', 0.47, 42.50, '2025-10-26 03:43:07'),
(10, 10, 2, 2, 'แก๊สโซฮอล์ 95', 1.18, 42.50, '2025-10-26 03:55:34');

-- --------------------------------------------------------

--
-- Table structure for table `sales_items_bak`
--

CREATE TABLE `sales_items_bak` (
  `id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `sale_id` bigint UNSIGNED NOT NULL,
  `fuel_id` int DEFAULT NULL,
  `tank_id` bigint UNSIGNED DEFAULT NULL,
  `fuel_type` varchar(100) NOT NULL,
  `liters` decimal(10,2) NOT NULL DEFAULT '0.00',
  `price_per_liter` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_amount` decimal(21,4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales_items_bak`
--

INSERT INTO `sales_items_bak` (`id`, `sale_id`, `fuel_id`, `tank_id`, `fuel_type`, `liters`, `price_per_liter`, `line_amount`, `created_at`) VALUES
(1, 1, NULL, NULL, 'ดีเซล', 2.03, 35.01, 71.0703, '2025-10-01 08:24:14'),
(2, 2, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-01 08:47:42'),
(3, 3, NULL, NULL, 'แก๊สโซฮอล์ 95', 1.13, 44.28, 50.0364, '2025-10-01 08:48:14'),
(4, 4, NULL, NULL, 'แก๊สโซฮอล์ 91', 1.42, 42.15, 59.8530, '2025-10-01 08:48:46'),
(5, 5, NULL, NULL, 'ดีเซล', 1.14, 35.01, 39.9114, '2025-10-01 08:52:51'),
(6, 6, NULL, NULL, 'แก๊สโซฮอล์ 91', 2.37, 42.15, 99.8955, '2025-10-01 09:37:43'),
(7, 7, NULL, NULL, 'ดีเซล', 0.57, 35.01, 19.9557, '2025-10-01 10:11:16'),
(8, 8, NULL, NULL, 'ดีเซล', 0.57, 35.01, 19.9557, '2025-10-01 10:11:49'),
(9, 9, NULL, NULL, 'แก๊สโซฮอล์ 95', 0.90, 44.28, 39.8520, '2025-10-01 10:31:32'),
(10, 10, NULL, NULL, 'แก๊สโซฮอล์ 95', 0.90, 44.28, 39.8520, '2025-10-01 10:31:38'),
(11, 11, NULL, NULL, 'แก๊สโซฮอล์ 91', 0.71, 42.15, 29.9265, '2025-10-01 10:56:44'),
(12, 12, NULL, NULL, 'แก๊สโซฮอล์ 95', 1.81, 44.28, 80.1468, '2025-10-05 06:44:57'),
(13, 13, NULL, NULL, 'แก๊สโซฮอล์ 91', 0.47, 42.15, 19.8105, '2025-10-05 07:04:23'),
(14, 14, NULL, NULL, 'ดีเซล', 0.57, 35.01, 19.9557, '2025-10-05 07:12:19'),
(15, 15, NULL, NULL, 'ดีเซล', 0.57, 35.01, 19.9557, '2025-10-05 07:23:02'),
(16, 16, NULL, NULL, 'แก๊สโซฮอล์ 95', 0.68, 44.28, 30.1104, '2025-10-05 07:41:19'),
(17, 17, NULL, NULL, 'ดีเซล', 0.57, 35.01, 19.9557, '2025-10-05 08:58:18'),
(18, 18, NULL, NULL, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-05 09:01:52'),
(19, 19, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-05 09:02:33'),
(20, 20, NULL, NULL, 'แก๊สโซฮอล์ 91', 1.19, 42.15, 50.1585, '2025-10-05 15:20:24'),
(21, 21, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-05 17:23:08'),
(22, 22, NULL, NULL, 'แก๊สโซฮอล์ 95', 0.45, 44.28, 19.9260, '2025-10-05 17:25:59'),
(23, 23, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-05 17:34:09'),
(24, 24, NULL, NULL, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-05 17:56:48'),
(25, 25, NULL, NULL, 'แก๊สโซฮอล์ 91', 0.95, 42.15, 40.0425, '2025-10-06 17:20:11'),
(26, 26, NULL, NULL, 'แก๊สโซฮอล์ 95', 0.45, 44.28, 19.9260, '2025-10-06 17:35:22'),
(27, 27, NULL, NULL, 'แก๊สโซฮอล์ 91', 1.90, 42.15, 80.0850, '2025-10-06 17:37:51'),
(28, 28, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-07 03:31:20'),
(29, 29, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-09 07:30:41'),
(30, 30, NULL, NULL, 'แก๊สโซฮอล์ 95', 1.99, 40.29, 80.1771, '2025-10-15 17:57:26'),
(31, 31, NULL, NULL, 'แก๊สโซฮอล์ 95', 1.24, 40.29, 49.9596, '2025-10-15 17:59:14'),
(32, 32, NULL, NULL, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-16 06:41:15'),
(33, 33, NULL, NULL, 'แก๊สโซฮอล์ 91', 1.90, 42.15, 80.0850, '2025-10-17 01:42:47'),
(34, 34, 1, 1, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-17 02:42:38'),
(35, 35, 1, 1, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-17 02:43:27'),
(36, 36, 1, 1, 'ดีเซล', 2.00, 35.01, 70.0200, '2025-10-17 02:44:29'),
(37, 37, 2, 2, 'แก๊สโซฮอล์ 95', 2.00, 40.29, 80.5800, '2025-10-17 03:04:30'),
(38, 38, 1, 1, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-17 04:08:57'),
(39, 39, NULL, NULL, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-17 04:16:45'),
(41, 41, 2, 2, 'แก๊สโซฮอล์ 95', 1.99, 40.29, 80.1771, '2025-10-17 10:24:07'),
(42, 42, 1, 1, 'ดีเซล', 2.29, 35.01, 80.1729, '2025-10-17 13:51:36'),
(47, 47, 2, 2, 'แก๊สโซฮอล์ 95', 0.50, 40.29, 20.1450, '2025-10-18 10:57:25'),
(48, 48, 3, 3, 'แก๊สโซฮอล์ 91', 0.47, 42.15, 19.8105, '2025-10-18 15:55:16'),
(49, 49, 1, 1, 'ดีเซล', 1.43, 35.01, 50.0643, '2025-10-18 16:05:06'),
(51, 51, 2, 2, 'แก๊สโซฮอล์ 95', 1.99, 40.29, 80.1771, '2025-10-18 16:19:49'),
(52, 52, 2, 2, 'แก๊สโซฮอล์ 95', 1.24, 40.29, 49.9596, '2025-10-20 04:27:57'),
(53, 53, 2, 2, 'แก๊สโซฮอล์ 95', 1.00, 40.29, 40.2900, '2025-10-20 17:02:57'),
(55, 55, 2, 2, 'แก๊สโซฮอล์ 95', 0.50, 40.29, 20.1450, '2025-10-20 18:49:14'),
(56, 56, 2, 2, 'แก๊สโซฮอล์ 95', 1.24, 40.29, 49.9596, '2025-10-20 19:33:21');

-- --------------------------------------------------------

--
-- Table structure for table `scores`
--

CREATE TABLE `scores` (
  `id` bigint UNSIGNED NOT NULL,
  `member_id` bigint UNSIGNED NOT NULL,
  `score` int UNSIGNED NOT NULL,
  `activity` varchar(150) NOT NULL,
  `score_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

--
-- Dumping data for table `scores`
--

INSERT INTO `scores` (`id`, `member_id`, `score`, `activity`, `score_date`) VALUES
(1, 1, 3, 'POS R20251025-597A3D', '2025-10-25 13:30:18'),
(2, 7, 10, 'POS R20251025-0846DD', '2025-10-25 13:30:50'),
(3, 11, 13, 'POS R20251025-80AE60', '2025-10-25 13:57:12'),
(4, 11, 10, 'POS R20251025-CAB8F6', '2025-10-25 13:58:21'),
(5, 1, 1, 'POS R20251025-9DB4B5', '2025-10-25 14:12:56'),
(6, 1, 1, 'POS R20251026-7BB20E', '2025-10-26 02:53:42'),
(7, 11, 35, 'POS R20251026-54A586', '2025-10-26 02:54:46'),
(8, 1, 1, 'POS R20251026-1923CF', '2025-10-26 03:55:34');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `setting_value` int NOT NULL,
  `comment` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_name`, `setting_value`, `comment`) VALUES
(1, 'station_id', 1, 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง'),
(2, 'site_name', 0, 'สหกรณ์ปั๊มน้ำมันบ้านภูเขาทอง');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int NOT NULL,
  `station_id` int NOT NULL DEFAULT '1',
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `email` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `fuel_types` text,
  `last_delivery_date` date DEFAULT NULL,
  `rating` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `station_id`, `supplier_name`, `contact_person`, `phone`, `email`, `fuel_types`, `last_delivery_date`, `rating`) VALUES
(1, 1, 'ไทยออยล์', 'ปาล์ม', '097685101', 'bhuhih@gmail.com', NULL, '2025-10-25', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL,
  `email` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `role` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `full_name`, `phone`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`, `role`) VALUES
(1, 'admin', 'admin@gmail.com', 'ผู้ดูแลระบบ', '0810000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, '2025-10-26 02:55:26', '2025-08-25 16:54:00', '2025-10-26 02:55:26', 'admin'),
(2, 'manager1', 'manager2@gmail.com', 'ผู้บริหารปั๊มน้ำมัน', '0811111221', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, '2025-10-26 01:52:55', '2025-08-25 16:54:00', '2025-10-26 01:52:55', 'manager'),
(3, 'emp1', 'employee@coop.th', 'พนักงานปั๊ม', '0786456762', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, '2025-10-26 03:05:25', '2025-08-25 16:54:00', '2025-10-26 03:05:25', 'employee'),
(4, 'member1', 'member@coop.th', 'สมศรี ใจดีมาก', '0812345678', '$2y$10$bnjnnotvoiusDGRnBD9SS.P.9MjgwSStYGr21Ccw3zp9Ml6ShnVti', 1, '2025-10-26 01:16:35', '2025-08-25 16:54:00', '2025-10-26 01:16:35', 'member'),
(5, 'committee', 'committee@coop.th', 'กรรมการ1', '0834445677', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, '2025-10-26 02:03:49', '2025-08-25 16:54:00', '2025-10-26 02:03:49', 'committee'),
(6, 'manager12', 'manager1@gmail.com', 'ผู้บริหารปั๊ม', '0811111221', '$2y$10$E6.ir7ZlUacIFU/odFsg7.kxkYAwTttl78AE/gcR48Co.2ONdXvzu', 1, NULL, '2025-10-12 11:17:26', '2025-10-12 13:25:23', 'manager'),
(22, 'tim', 'la@gmail.com', 'โลตัส กั้วพิศมัย', '0230404045', '$2y$10$NEl3nMOJC8ag6rcGDnql7OG.bPRLM4B4v.9XYMSoUZSrYElZ14Muu', 1, '2025-10-26 01:34:47', '2025-10-21 13:36:26', '2025-10-26 01:34:47', 'member');

-- --------------------------------------------------------

--
-- Table structure for table `user_audit_log`
--

CREATE TABLE `user_audit_log` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `detail` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_audit_log`
--

INSERT INTO `user_audit_log` (`id`, `user_id`, `action`, `detail`, `created_at`) VALUES
(1, 5, 'update_profile', '{\"ip\":\"192.168.65.1\",\"ua\":\"Mozilla\\/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit\\/605.1.15 (KHTML, like Gecko) Version\\/17.5 Safari\\/605.1.15\"}', '2025-10-16 16:56:12');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_fuel_lots_current`
-- (See below for the actual view)
--
CREATE TABLE `v_fuel_lots_current` (
`corrected_liters` decimal(10,2)
,`created_at` datetime
,`created_by` bigint unsigned
,`density_kg_per_l` decimal(6,3)
,`discount` decimal(12,2)
,`freight` decimal(12,2)
,`fuel_id` int
,`id` bigint unsigned
,`initial_liters` decimal(12,2)
,`initial_total_cost` decimal(14,2)
,`initial_unit_cost` decimal(10,4)
,`invoice_no` varchar(64)
,`liters_received` decimal(12,2)
,`lot_code` varchar(50)
,`notes` text
,`observed_liters` decimal(10,2)
,`other_costs` decimal(12,2)
,`receive_id` int
,`received_at` datetime
,`remaining_liters` decimal(12,2)
,`remaining_liters_calc` decimal(33,2)
,`remaining_value` decimal(45,8)
,`station_id` int
,`status_calc` varchar(5)
,`supplier_id` int
,`tank_id` bigint unsigned
,`tax_per_liter` decimal(10,4)
,`temp_c` decimal(5,2)
,`total_cost` decimal(14,2)
,`unit_cost` decimal(10,4)
,`unit_cost_full` decimal(12,6)
,`updated_at` datetime
,`used_cost` decimal(44,8)
,`used_liters` decimal(32,2)
,`vat_in_cost` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_fuel_stock_live`
-- (See below for the actual view)
--
CREATE TABLE `v_fuel_stock_live` (
`capacity` decimal(32,2)
,`current_stock` decimal(34,2)
,`fuel_id` int
,`max_threshold` decimal(32,2)
,`min_threshold` decimal(32,2)
,`station_id` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_move_cogs`
-- (See below for the actual view)
--
CREATE TABLE `v_move_cogs` (
`cogs` decimal(44,8)
,`liters_allocated` decimal(32,2)
,`move_id` bigint unsigned
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_open_fuel_lots`
-- (See below for the actual view)
--
CREATE TABLE `v_open_fuel_lots` (
`corrected_liters` decimal(10,2)
,`created_at` datetime
,`created_by` bigint unsigned
,`density_kg_per_l` decimal(6,3)
,`fuel_id` int
,`id` bigint unsigned
,`initial_liters` decimal(12,2)
,`initial_total_cost` decimal(14,2)
,`initial_unit_cost` decimal(10,4)
,`lot_code` varchar(50)
,`notes` text
,`observed_liters` decimal(10,2)
,`other_costs` decimal(12,2)
,`receive_id` int
,`received_at` datetime
,`remaining_liters` decimal(33,2)
,`remaining_value` decimal(45,8)
,`station_id` int
,`status_calc` varchar(5)
,`supplier_id` int
,`tank_id` bigint unsigned
,`tax_per_liter` decimal(10,4)
,`temp_c` decimal(5,2)
,`unit_cost` decimal(10,4)
,`unit_cost_full` decimal(12,6)
,`updated_at` datetime
,`used_cost` decimal(44,8)
,`used_liters` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sales_gross_profit`
-- (See below for the actual view)
--
CREATE TABLE `v_sales_gross_profit` (
`cogs` decimal(48,12)
,`sale_date` datetime
,`sale_id` bigint unsigned
,`total_amount` decimal(15,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sale_cogs`
-- (See below for the actual view)
--
CREATE TABLE `v_sale_cogs` (
`cogs` decimal(65,8)
,`sale_id` bigint unsigned
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admin_user` (`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `committees`
--
ALTER TABLE `committees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_committees_department` (`department`);

--
-- Indexes for table `dividends`
--
ALTER TABLE `dividends`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `dividend_payments`
--
ALTER TABLE `dividend_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `period_id` (`period_id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `dividend_periods`
--
ALTER TABLE `dividend_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_year` (`year`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_employee_user` (`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_code` (`transaction_code`),
  ADD KEY `idx_station_date` (`station_id`,`transaction_date`),
  ADD KEY `idx_station_type_date` (`station_id`,`type`,`transaction_date`),
  ADD KEY `fk_ft_user` (`user_id`);

--
-- Indexes for table `fuel_adjustments`
--
ALTER TABLE `fuel_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fuel_date` (`fuel_id`,`adjusted_at`);

--
-- Indexes for table `fuel_lots`
--
ALTER TABLE `fuel_lots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lot_code_station` (`station_id`,`lot_code`),
  ADD KEY `idx_station_fuel_time` (`station_id`,`fuel_id`,`received_at`),
  ADD KEY `idx_fuel_tank_status` (`fuel_id`,`tank_id`),
  ADD KEY `fk_lots_tank` (`tank_id`),
  ADD KEY `fk_lots_receive` (`receive_id`),
  ADD KEY `fk_lots_supplier` (`supplier_id`),
  ADD KEY `idx_fuel_lots_fuel` (`fuel_id`),
  ADD KEY `idx_fuel_lots_remaining` (`fuel_id`,`remaining_liters`),
  ADD KEY `fk_lots_user` (`created_by`);

--
-- Indexes for table `fuel_lot_allocations`
--
ALTER TABLE `fuel_lot_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_move` (`move_id`),
  ADD KEY `idx_lot` (`lot_id`),
  ADD KEY `idx_alloc_lot` (`lot_id`),
  ADD KEY `idx_alloc_move` (`move_id`);

--
-- Indexes for table `fuel_moves`
--
ALTER TABLE `fuel_moves`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fuel_moves_sale_once` (`sale_id`,`is_sale_out`),
  ADD UNIQUE KEY `uq_fuel_moves_one_sale_out` (`sale_id`,`is_sale_out`),
  ADD KEY `idx_moves_tank_time` (`tank_id`,`occurred_at`),
  ADD KEY `idx_moves_type_time` (`type`,`occurred_at`),
  ADD KEY `idx_move_sale` (`sale_id`),
  ADD KEY `idx_moves_refdoc` (`ref_doc`),
  ADD KEY `idx_moves_saleout_time` (`is_sale_out`,`occurred_at`),
  ADD KEY `idx_moves_sale_time` (`sale_id`,`occurred_at`),
  ADD KEY `idx_moves_type_fuel_time` (`type`,`tank_id`,`occurred_at`),
  ADD KEY `fk_fuel_moves_user` (`user_id`),
  ADD KEY `idx_moves_tank` (`tank_id`);

--
-- Indexes for table `fuel_prices`
--
ALTER TABLE `fuel_prices`
  ADD PRIMARY KEY (`fuel_id`),
  ADD UNIQUE KEY `uq_fuel_name_station` (`station_id`,`fuel_name`),
  ADD UNIQUE KEY `uq_fuel_display_order` (`station_id`,`display_order`),
  ADD KEY `idx_station_fuel` (`station_id`,`fuel_id`);

--
-- Indexes for table `fuel_receives`
--
ALTER TABLE `fuel_receives`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fuel_id` (`fuel_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_fr_station` (`station_id`),
  ADD KEY `idx_fr_station_date` (`station_id`,`received_date`);

--
-- Indexes for table `fuel_stock`
--
ALTER TABLE `fuel_stock`
  ADD PRIMARY KEY (`fuel_id`),
  ADD UNIQUE KEY `uq_station_fuel` (`station_id`,`fuel_id`);

--
-- Indexes for table `fuel_tanks`
--
ALTER TABLE `fuel_tanks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tanks_station_code` (`station_id`,`code`),
  ADD KEY `idx_tanks_fuel` (`fuel_id`),
  ADD KEY `idx_station_fuel` (`station_id`,`fuel_id`),
  ADD KEY `idx_tank_pick` (`station_id`,`fuel_id`,`is_active`,`current_volume_l`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_station_number` (`station_id`,`invoice_number`),
  ADD UNIQUE KEY `uq_invoice_sale` (`sale_id`),
  ADD KEY `idx_invoices_sale` (`sale_id`);

--
-- Indexes for table `managers`
--
ALTER TABLE `managers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_manager_user` (`user_id`),
  ADD UNIQUE KEY `uq_manager_station` (`station_id`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_member_code_station` (`station_id`,`member_code`),
  ADD UNIQUE KEY `ux_member_code` (`member_code`),
  ADD KEY `idx_members_user` (`user_id`),
  ADD KEY `idx_members_house` (`house_number`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `point_transactions`
--
ALTER TABLE `point_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_member_date` (`member_user_id`,`transaction_date`),
  ADD KEY `fk_pt_emp_user` (`employee_user_id`);

--
-- Indexes for table `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pumps`
--
ALTER TABLE `pumps`
  ADD PRIMARY KEY (`pump_id`),
  ADD KEY `fuel_id` (`fuel_id`);

--
-- Indexes for table `rebate_payments`
--
ALTER TABLE `rebate_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `period_id` (`period_id`),
  ADD KEY `member_id` (`member_id`,`member_type`);

--
-- Indexes for table `rebate_periods`
--
ALTER TABLE `rebate_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_year` (`year`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sale_code` (`sale_code`),
  ADD UNIQUE KEY `uq_sales_sale_code` (`sale_code`),
  ADD KEY `idx_sales_date` (`sale_date`),
  ADD KEY `idx_sales_station_date` (`station_id`,`sale_date`),
  ADD KEY `fk_sales_employee` (`employee_user_id`),
  ADD KEY `idx_sales_created_by` (`created_by`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `fk_sales_items_fuel` (`fuel_id`),
  ADD KEY `fk_sales_items_tank` (`tank_id`);

--
-- Indexes for table `scores`
--
ALTER TABLE `scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `idx_scores_member_date` (`member_id`,`score_date`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_fullname` (`full_name`),
  ADD KEY `idx_users_phone` (`phone`);

--
-- Indexes for table `user_audit_log`
--
ALTER TABLE `user_audit_log`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `committees`
--
ALTER TABLE `committees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `dividends`
--
ALTER TABLE `dividends`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dividend_payments`
--
ALTER TABLE `dividend_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dividend_periods`
--
ALTER TABLE `dividend_periods`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `fuel_adjustments`
--
ALTER TABLE `fuel_adjustments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_lots`
--
ALTER TABLE `fuel_lots`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_lot_allocations`
--
ALTER TABLE `fuel_lot_allocations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_moves`
--
ALTER TABLE `fuel_moves`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_prices`
--
ALTER TABLE `fuel_prices`
  MODIFY `fuel_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_receives`
--
ALTER TABLE `fuel_receives`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_tanks`
--
ALTER TABLE `fuel_tanks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `managers`
--
ALTER TABLE `managers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `point_transactions`
--
ALTER TABLE `point_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `posts`
--
ALTER TABLE `posts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pumps`
--
ALTER TABLE `pumps`
  MODIFY `pump_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rebate_payments`
--
ALTER TABLE `rebate_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `rebate_periods`
--
ALTER TABLE `rebate_periods`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scores`
--
ALTER TABLE `scores`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `user_audit_log`
--
ALTER TABLE `user_audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

-- --------------------------------------------------------

--
-- Structure for view `v_fuel_lots_current`
--
DROP TABLE IF EXISTS `v_fuel_lots_current`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY DEFINER VIEW `v_fuel_lots_current`  AS SELECT `fl`.`id` AS `id`, `fl`.`station_id` AS `station_id`, `fl`.`fuel_id` AS `fuel_id`, `fl`.`tank_id` AS `tank_id`, `fl`.`receive_id` AS `receive_id`, `fl`.`supplier_id` AS `supplier_id`, `fl`.`lot_code` AS `lot_code`, `fl`.`received_at` AS `received_at`, `fl`.`observed_liters` AS `observed_liters`, `fl`.`corrected_liters` AS `corrected_liters`, `fl`.`unit_cost` AS `unit_cost`, `fl`.`tax_per_liter` AS `tax_per_liter`, `fl`.`other_costs` AS `other_costs`, `fl`.`initial_liters` AS `initial_liters`, `fl`.`initial_unit_cost` AS `initial_unit_cost`, `fl`.`initial_total_cost` AS `initial_total_cost`, `fl`.`density_kg_per_l` AS `density_kg_per_l`, `fl`.`temp_c` AS `temp_c`, `fl`.`notes` AS `notes`, `fl`.`created_by` AS `created_by`, `fl`.`created_at` AS `created_at`, `fl`.`updated_at` AS `updated_at`, `fl`.`unit_cost_full` AS `unit_cost_full`, `fl`.`liters_received` AS `liters_received`, `fl`.`total_cost` AS `total_cost`, `fl`.`remaining_liters` AS `remaining_liters`, `fl`.`freight` AS `freight`, `fl`.`discount` AS `discount`, `fl`.`vat_in_cost` AS `vat_in_cost`, `fl`.`invoice_no` AS `invoice_no`, coalesce(`a`.`used_liters`,0) AS `used_liters`, (`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) AS `remaining_liters_calc`, (case when ((`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) > 0) then 'OPEN' else 'CLOSE' end) AS `status_calc`, (coalesce(`a`.`used_liters`,0) * `fl`.`unit_cost_full`) AS `used_cost`, ((`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) * `fl`.`unit_cost_full`) AS `remaining_value` FROM (`fuel_lots` `fl` left join (select `fuel_lot_allocations`.`lot_id` AS `lot_id`,sum(`fuel_lot_allocations`.`allocated_liters`) AS `used_liters` from `fuel_lot_allocations` group by `fuel_lot_allocations`.`lot_id`) `a` on((`a`.`lot_id` = `fl`.`id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_fuel_stock_live`
--
DROP TABLE IF EXISTS `v_fuel_stock_live`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY DEFINER VIEW `v_fuel_stock_live`  AS SELECT `t`.`station_id` AS `station_id`, `t`.`fuel_id` AS `fuel_id`, sum(`t`.`current_volume_l`) AS `current_stock`, sum(`t`.`capacity_l`) AS `capacity`, sum(`t`.`min_threshold_l`) AS `min_threshold`, sum(`t`.`max_threshold_l`) AS `max_threshold` FROM `fuel_tanks` AS `t` WHERE (`t`.`is_active` = 1) GROUP BY `t`.`station_id`, `t`.`fuel_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_move_cogs`
--
DROP TABLE IF EXISTS `v_move_cogs`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY INVOKER VIEW `v_move_cogs`  AS SELECT `a`.`move_id` AS `move_id`, sum(`a`.`allocated_liters`) AS `liters_allocated`, sum((`a`.`allocated_liters` * `a`.`unit_cost_snapshot`)) AS `cogs` FROM `fuel_lot_allocations` AS `a` GROUP BY `a`.`move_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_open_fuel_lots`
--
DROP TABLE IF EXISTS `v_open_fuel_lots`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY DEFINER VIEW `v_open_fuel_lots`  AS SELECT `fl`.`id` AS `id`, `fl`.`station_id` AS `station_id`, `fl`.`fuel_id` AS `fuel_id`, `fl`.`tank_id` AS `tank_id`, `fl`.`receive_id` AS `receive_id`, `fl`.`supplier_id` AS `supplier_id`, `fl`.`lot_code` AS `lot_code`, `fl`.`received_at` AS `received_at`, `fl`.`observed_liters` AS `observed_liters`, `fl`.`corrected_liters` AS `corrected_liters`, `fl`.`unit_cost` AS `unit_cost`, `fl`.`tax_per_liter` AS `tax_per_liter`, `fl`.`other_costs` AS `other_costs`, `fl`.`initial_liters` AS `initial_liters`, `fl`.`initial_unit_cost` AS `initial_unit_cost`, `fl`.`initial_total_cost` AS `initial_total_cost`, `fl`.`density_kg_per_l` AS `density_kg_per_l`, `fl`.`temp_c` AS `temp_c`, `fl`.`notes` AS `notes`, `fl`.`created_by` AS `created_by`, `fl`.`created_at` AS `created_at`, `fl`.`updated_at` AS `updated_at`, `fl`.`unit_cost_full` AS `unit_cost_full`, coalesce(`a`.`used_liters`,0) AS `used_liters`, (`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) AS `remaining_liters`, (case when ((`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) > 0) then 'OPEN' else 'CLOSE' end) AS `status_calc`, (coalesce(`a`.`used_liters`,0) * `fl`.`unit_cost_full`) AS `used_cost`, ((`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) * `fl`.`unit_cost_full`) AS `remaining_value` FROM (`fuel_lots` `fl` left join (select `fuel_lot_allocations`.`lot_id` AS `lot_id`,sum(`fuel_lot_allocations`.`allocated_liters`) AS `used_liters` from `fuel_lot_allocations` group by `fuel_lot_allocations`.`lot_id`) `a` on((`a`.`lot_id` = `fl`.`id`))) WHERE ((`fl`.`initial_liters` - coalesce(`a`.`used_liters`,0)) > 0) ;

-- --------------------------------------------------------

--
-- Structure for view `v_sales_gross_profit`
--
DROP TABLE IF EXISTS `v_sales_gross_profit`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY DEFINER VIEW `v_sales_gross_profit`  AS SELECT `s`.`id` AS `sale_id`, `s`.`sale_date` AS `sale_date`, `s`.`total_amount` AS `total_amount`, coalesce(sum((`si`.`liters` * coalesce((select avg(`fla`.`unit_cost_snapshot`) from (`fuel_lot_allocations` `fla` join `fuel_moves` `fm` on((`fm`.`id` = `fla`.`move_id`))) where ((`fm`.`sale_id` = `s`.`id`) and (`fm`.`type` = 'sale_out'))),0))),0) AS `cogs` FROM (`sales` `s` left join `sales_items` `si` on((`si`.`sale_id` = `s`.`id`))) GROUP BY `s`.`id`, `s`.`sale_date`, `s`.`total_amount` ;

-- --------------------------------------------------------

--
-- Structure for view `v_sale_cogs`
--
DROP TABLE IF EXISTS `v_sale_cogs`;

CREATE ALGORITHM=UNDEFINED DEFINER=`coop`@`%` SQL SECURITY INVOKER VIEW `v_sale_cogs`  AS SELECT `fm`.`sale_id` AS `sale_id`, sum(`vc`.`cogs`) AS `cogs` FROM (`fuel_moves` `fm` join `v_move_cogs` `vc` on((`vc`.`move_id` = `fm`.`id`))) WHERE (`fm`.`type` = 'sale_out') GROUP BY `fm`.`sale_id` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `committees`
--
ALTER TABLE `committees`
  ADD CONSTRAINT `committees_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dividends`
--
ALTER TABLE `dividends`
  ADD CONSTRAINT `dividends_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dividend_payments`
--
ALTER TABLE `dividend_payments`
  ADD CONSTRAINT `dividend_payments_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `dividend_periods` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD CONSTRAINT `fk_ft_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fuel_adjustments`
--
ALTER TABLE `fuel_adjustments`
  ADD CONSTRAINT `fk_fuel_adj_fuel` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `fuel_lots`
--
ALTER TABLE `fuel_lots`
  ADD CONSTRAINT `fk_lots_fuel` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lots_receive` FOREIGN KEY (`receive_id`) REFERENCES `fuel_receives` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_lots_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_lots_tank` FOREIGN KEY (`tank_id`) REFERENCES `fuel_tanks` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lots_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fuel_lot_allocations`
--
ALTER TABLE `fuel_lot_allocations`
  ADD CONSTRAINT `fk_alloc_lot` FOREIGN KEY (`lot_id`) REFERENCES `fuel_lots` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alloc_move` FOREIGN KEY (`move_id`) REFERENCES `fuel_moves` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fuel_moves`
--
ALTER TABLE `fuel_moves`
  ADD CONSTRAINT `fk_fuel_moves_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_fuel_moves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_moves_tank` FOREIGN KEY (`tank_id`) REFERENCES `fuel_tanks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fuel_receives`
--
ALTER TABLE `fuel_receives`
  ADD CONSTRAINT `fuel_receives_ibfk_1` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`),
  ADD CONSTRAINT `fuel_receives_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

--
-- Constraints for table `fuel_tanks`
--
ALTER TABLE `fuel_tanks`
  ADD CONSTRAINT `fk_tanks_fuel` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `managers`
--
ALTER TABLE `managers`
  ADD CONSTRAINT `fk_managers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `point_transactions`
--
ALTER TABLE `point_transactions`
  ADD CONSTRAINT `fk_pt_emp_user` FOREIGN KEY (`employee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pt_member_user` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pumps`
--
ALTER TABLE `pumps`
  ADD CONSTRAINT `pumps_ibfk_1` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`);

--
-- Constraints for table `rebate_payments`
--
ALTER TABLE `rebate_payments`
  ADD CONSTRAINT `rebate_payments_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `rebate_periods` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sales_employee` FOREIGN KEY (`employee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD CONSTRAINT `fk_sales_items_fuel` FOREIGN KEY (`fuel_id`) REFERENCES `fuel_prices` (`fuel_id`),
  ADD CONSTRAINT `fk_sales_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sales_items_tank` FOREIGN KEY (`tank_id`) REFERENCES `fuel_tanks` (`id`);

--
-- Constraints for table `scores`
--
ALTER TABLE `scores`
  ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
