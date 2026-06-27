-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 27, 2026 at 02:00 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ppms`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_accounts`
--

CREATE TABLE `tbl_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(256) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password` varchar(64) NOT NULL,
  `type` varchar(64) NOT NULL,
  `phonenumber` varchar(64) NOT NULL,
  `cnicnumber` varchar(64) NOT NULL,
  `address` varchar(512) NOT NULL,
  `city` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_accounts`
--

INSERT INTO `tbl_accounts` (`id`, `name`, `username`, `password`, `type`, `phonenumber`, `cnicnumber`, `address`, `city`) VALUES
(1, 'Syed Haseeb Hashmi', 'hasyeb', '$2y$10$.U60lyPzF1YLJlBZast2Qe/yOl1meu0R5MfHvtesLtNAngZzTdYem', 'Admin', '03005090170', '31202-0000000-0', 'House # 18, Sajid Awan Colony.', 'Bahawalpur');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_banks`
--

CREATE TABLE `tbl_banks` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `account_number` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_banks`
--

INSERT INTO `tbl_banks` (`id`, `name`, `account_number`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Allied Bank', '123456789', '2026-06-23 10:49:26', '2026-06-23 10:49:40', '2026-06-23 15:49:40'),
(2, 'Allied Bank', '12345678', '2026-06-23 11:25:11', '2026-06-23 11:25:11', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_card_machines`
--

CREATE TABLE `tbl_card_machines` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `charges_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `contact_person_name` varchar(128) NOT NULL,
  `contact_person_number` varchar(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_card_machines`
--

INSERT INTO `tbl_card_machines` (`id`, `name`, `charges_percentage`, `contact_person_name`, `contact_person_number`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Mezan Bank', 1.50, 'Mezan Support', '03001234567', '2026-06-09 18:20:55', '2026-06-23 10:45:41', NULL),
(2, 'HBL', 2.00, 'HBL Support', '03007654321', '2026-06-09 18:20:55', '2026-06-23 10:45:41', NULL),
(3, 'DT PLUS CARD', 0.00, 'DT Support', '03009999999', '2026-06-09 18:20:55', '2026-06-23 10:45:41', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_items`
--

CREATE TABLE `tbl_items` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `cash_rate` decimal(10,2) NOT NULL,
  `credit_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_items`
--

INSERT INTO `tbl_items` (`id`, `name`, `cash_rate`, `credit_rate`, `purchase_rate`, `unit`, `created_at`, `updated_at`) VALUES
(2, 'Petrol', 200.00, 0.00, 0.00, '', '2026-06-03 10:47:28', '2026-06-03 10:47:28'),
(3, 'Diesel', 375.00, 0.00, 0.00, '', '2026-06-14 14:39:18', '2026-06-14 14:39:18'),
(4, 'Lubricant', 200.00, 400.00, 300.00, 'ltr', '2026-06-23 14:39:55', '2026-06-23 14:39:55');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_leave_setup`
--

CREATE TABLE `tbl_leave_setup` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `allowed_leaves` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_leave_setup`
--

INSERT INTO `tbl_leave_setup` (`id`, `staff_id`, `allowed_leaves`, `created_at`) VALUES
(1, 1, 5, '2026-06-09 09:51:06');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_lubricant_products`
--

CREATE TABLE `tbl_lubricant_products` (
  `id` int(11) NOT NULL,
  `name` varchar(256) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` varchar(64) NOT NULL DEFAULT 'Stock Item',
  `shelf_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `tbl_lubricant_products`
--

INSERT INTO `tbl_lubricant_products` (`id`, `name`, `price`, `category`, `shelf_quantity`, `created_at`, `updated_at`) VALUES
(1, 'Grease (250g)', 350.00, 'Stock Item', 0.00, '2026-06-09 12:05:06', '2026-06-09 12:05:06'),
(2, 'Engine Oil (4L)', 3200.00, 'Stock Item', 0.00, '2026-06-09 12:05:06', '2026-06-09 12:05:06'),
(3, 'Break Oil (250ml)', 450.00, 'Stock Item', 0.00, '2026-06-09 12:05:06', '2026-06-09 12:05:06'),
(4, 'Tube', 150.00, 'Stock Item', 0.05, '2026-06-23 17:10:29', '2026-06-23 17:10:29');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_lubricant_purchases`
--

CREATE TABLE `tbl_lubricant_purchases` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date` date NOT NULL,
  `payment_status` enum('paid','unpaid') NOT NULL DEFAULT 'paid',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `tbl_lubricant_purchases`
--

INSERT INTO `tbl_lubricant_purchases` (`id`, `product_id`, `quantity`, `purchase_price`, `date`, `payment_status`, `created_at`, `updated_at`) VALUES
(2, 3, 12.00, 2.00, '2026-06-09', 'paid', '2026-06-09 12:13:25', '2026-06-09 12:13:25'),
(3, 4, 25.00, 130.00, '2026-06-23', 'paid', '2026-06-23 17:11:10', '2026-06-23 17:11:10');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_lubricant_sales`
--

CREATE TABLE `tbl_lubricant_sales` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_type` enum('Cash','Credit') NOT NULL DEFAULT 'Cash',
  `details` varchar(256) DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `tbl_lubricant_sales`
--

INSERT INTO `tbl_lubricant_sales` (`id`, `product_id`, `quantity`, `rate`, `amount`, `payment_type`, `details`, `date`, `created_at`, `updated_at`) VALUES
(2, 3, 2.00, 450.00, 900.00, 'Cash', '', '2026-06-09', '2026-06-09 12:13:54', '2026-06-09 12:13:54'),
(3, 3, 8.00, 450.00, 3600.00, 'Cash', '', '2026-06-15', '2026-06-14 16:52:43', '2026-06-14 16:52:43'),
(4, 4, 12.00, 150.00, 1800.00, 'Cash', '', '2026-06-23', '2026-06-23 17:11:34', '2026-06-23 17:11:34');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_meter_readings`
--

CREATE TABLE `tbl_meter_readings` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `shift_id` int(11) NOT NULL,
  `payment_type` enum('Cash','Credit','Online') NOT NULL DEFAULT 'Cash',
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `remarks` varchar(512) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_meter_readings`
--

INSERT INTO `tbl_meter_readings` (`id`, `date`, `shift_id`, `payment_type`, `grand_total`, `created_at`, `updated_at`, `deleted_at`, `remarks`) VALUES
(5, '2026-06-08', 2, 'Cash', 60000.00, '2026-06-08 12:13:56', '2026-06-08 13:19:23', '2026-06-08 13:19:23', NULL),
(6, '2026-06-08', 2, 'Cash', 798.00, '2026-06-08 12:41:32', '2026-06-08 13:19:30', '2026-06-08 13:19:30', NULL),
(7, '2026-06-08', 2, 'Cash', 800.00, '2026-06-08 12:49:56', '2026-06-08 12:49:56', NULL, NULL),
(8, '2026-06-08', 2, 'Cash', 600.00, '2026-06-08 13:00:43', '2026-06-08 13:19:46', '2026-06-08 13:19:46', NULL),
(9, '2026-06-08', 2, 'Cash', 400.00, '2026-06-08 13:14:56', '2026-06-08 13:19:49', '2026-06-08 13:19:49', NULL),
(10, '2026-06-08', 2, 'Cash', 400.00, '2026-06-08 13:22:58', '2026-06-08 13:22:58', NULL, NULL),
(11, '2026-06-09', 2, 'Cash', 800.00, '2026-06-09 02:26:56', '2026-06-09 02:26:56', NULL, NULL),
(12, '2026-06-09', 2, 'Cash', 80000.00, '2026-06-09 11:33:47', '2026-06-09 11:33:47', NULL, 'this is rest'),
(13, '2026-06-09', 2, 'Cash', 80000.00, '2026-06-09 11:40:27', '2026-06-09 11:40:27', NULL, ''),
(14, '2026-06-15', 2, 'Cash', 2400.00, '2026-06-14 16:55:57', '2026-06-14 16:55:57', NULL, 'this is test'),
(15, '2026-06-26', 5, 'Cash', 5297600.00, '2026-06-26 04:03:43', '2026-06-26 04:03:43', NULL, ''),
(16, '2026-06-26', 5, 'Cash', 644400.00, '2026-06-26 04:32:27', '2026-06-26 04:32:27', NULL, '');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_meter_reading_card_sales`
--

CREATE TABLE `tbl_meter_reading_card_sales` (
  `id` int(11) NOT NULL,
  `meter_reading_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `card_machine_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `batch_no` varchar(64) DEFAULT NULL,
  `service_charges` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `nozzle_id` int(11) DEFAULT NULL,
  `no_of_cards` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_meter_reading_card_sales`
--

INSERT INTO `tbl_meter_reading_card_sales` (`id`, `meter_reading_id`, `staff_id`, `card_machine_id`, `item_id`, `quantity`, `rate`, `amount`, `batch_no`, `service_charges`, `net_amount`, `created_at`, `nozzle_id`, `no_of_cards`) VALUES
(1, 13, 1, 2, 2, 7.00, 200.00, 1400.00, '', 28.00, 1372.00, '2026-06-09 18:40:27', NULL, 0),
(2, 14, 2, 3, 2, 6.00, 200.00, 1200.00, '', 0.00, 1200.00, '2026-06-14 23:55:57', NULL, 0),
(3, 15, 0, 3, 2, 0.00, 0.00, 1200.00, '20', 0.00, 1200.00, '2026-06-25 23:03:43', 1, 30),
(4, 16, 0, 3, 2, 0.00, 0.00, 1111.00, '1221', 0.00, 1111.00, '2026-06-25 23:32:27', 1, 122);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_meter_reading_credit_sales`
--

CREATE TABLE `tbl_meter_reading_credit_sales` (
  `id` int(11) NOT NULL,
  `meter_reading_id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `slip_date` date NOT NULL,
  `slip_no` varchar(64) NOT NULL,
  `account_number` varchar(128) NOT NULL,
  `vehicle_number` varchar(64) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `issue_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_1` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_2` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wasoli` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_meter_reading_credit_sales`
--

INSERT INTO `tbl_meter_reading_credit_sales` (`id`, `meter_reading_id`, `nozzle_id`, `slip_date`, `slip_no`, `account_number`, `vehicle_number`, `quantity`, `rate`, `amount`, `cash_rate`, `issue_quantity`, `balance_1`, `balance_2`, `wasoli`, `created_at`) VALUES
(1, 16, 1, '2026-06-25', '1212', '1212121', '12122', 20.00, 12.00, 122.00, 200.00, 1.00, 2.00, 2.00, 0.00, '2026-06-25 23:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_meter_reading_details`
--

CREATE TABLE `tbl_meter_reading_details` (
  `id` int(11) NOT NULL,
  `meter_reading_id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL DEFAULT 0,
  `item_type` varchar(128) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_reading` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_reading` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_reading` decimal(12,2) NOT NULL DEFAULT 0.00,
  `test_reading` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_sale` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_type` enum('Cash','Credit','Online') NOT NULL DEFAULT 'Cash',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_meter_reading_details`
--

INSERT INTO `tbl_meter_reading_details` (`id`, `meter_reading_id`, `nozzle_id`, `staff_id`, `item_type`, `price`, `last_reading`, `current_reading`, `sale_reading`, `test_reading`, `net_sale`, `amount`, `payment_type`, `created_at`, `updated_at`) VALUES
(1, 9, 1, 1, 'Petrol', 200.00, 12.00, 14.00, 2.00, 0.00, 2.00, 400.00, 'Cash', '2026-06-08 13:14:56', '2026-06-08 13:14:56'),
(2, 9, 2, 0, 'Petrol', 200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'Cash', '2026-06-08 13:14:56', '2026-06-08 13:14:56'),
(3, 10, 1, 1, 'Petrol', 200.00, 12.00, 14.00, 2.00, 0.00, 2.00, 400.00, 'Cash', '2026-06-08 13:22:58', '2026-06-08 13:22:58'),
(4, 10, 2, 1, 'Petrol', 200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'Cash', '2026-06-08 13:22:58', '2026-06-08 13:22:58'),
(5, 11, 1, 1, 'Petrol', 200.00, 12.00, 14.00, 2.00, 0.00, 2.00, 400.00, 'Cash', '2026-06-09 02:26:56', '2026-06-09 02:26:56'),
(6, 11, 2, 1, 'Petrol', 200.00, 12.00, 14.00, 2.00, 0.00, 2.00, 400.00, 'Cash', '2026-06-09 02:26:56', '2026-06-09 02:26:56'),
(7, 12, 1, 1, 'Petrol', 200.00, 200.00, 400.00, 200.00, 0.00, 200.00, 40000.00, 'Cash', '2026-06-09 11:33:47', '2026-06-09 11:33:47'),
(8, 12, 2, 1, 'Petrol', 200.00, 200.00, 400.00, 200.00, 0.00, 200.00, 40000.00, 'Cash', '2026-06-09 11:33:47', '2026-06-09 11:33:47'),
(9, 13, 1, 1, 'Petrol', 200.00, 1200.00, 1400.00, 200.00, 0.00, 200.00, 40000.00, 'Cash', '2026-06-09 11:40:27', '2026-06-09 11:40:27'),
(10, 13, 2, 1, 'Petrol', 200.00, 1400.00, 1600.00, 200.00, 0.00, 200.00, 40000.00, 'Cash', '2026-06-09 11:40:27', '2026-06-09 11:40:27'),
(11, 14, 1, 2, 'Petrol', 200.00, 10.00, 14.00, 4.00, 0.00, 4.00, 800.00, 'Cash', '2026-06-14 16:55:57', '2026-06-14 16:55:57'),
(12, 14, 2, 1, 'Petrol', 200.00, 20.00, 30.00, 10.00, 2.00, 8.00, 1600.00, 'Cash', '2026-06-14 16:55:57', '2026-06-14 16:55:57'),
(13, 15, 1, 2, 'Petrol', 200.00, 121212.00, 124444.00, 3232.00, 0.00, 3232.00, 646400.00, 'Cash', '2026-06-26 04:03:43', '2026-06-26 04:03:43'),
(14, 15, 2, 2, 'Petrol', 200.00, 23132.00, 23144.00, 12.00, 0.00, 12.00, 2400.00, 'Cash', '2026-06-26 04:03:43', '2026-06-26 04:03:43'),
(15, 15, 3, 3, 'Lubricant', 200.00, 1200.00, 24444.00, 23244.00, 0.00, 23244.00, 4648800.00, 'Cash', '2026-06-26 04:03:43', '2026-06-26 04:03:43'),
(16, 16, 1, 0, 'Petrol', 200.00, 124444.00, 125555.00, 1111.00, 0.00, 1111.00, 222200.00, 'Cash', '2026-06-26 04:32:27', '2026-06-26 04:32:27'),
(17, 16, 2, 0, 'Petrol', 200.00, 23144.00, 24144.00, 1000.00, 0.00, 1000.00, 200000.00, 'Cash', '2026-06-26 04:32:27', '2026-06-26 04:32:27'),
(18, 16, 3, 0, 'Lubricant', 200.00, 24444.00, 25555.00, 1111.00, 0.00, 1111.00, 222200.00, 'Cash', '2026-06-26 04:32:27', '2026-06-26 04:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_nozzles`
--

CREATE TABLE `tbl_nozzles` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `start_reading` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_nozzles`
--

INSERT INTO `tbl_nozzles` (`id`, `name`, `tank_id`, `item_id`, `start_reading`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Nozzle 2', 2, 2, 125555.00, 'Active', '2026-06-03 12:58:12', '2026-06-26 04:32:27'),
(2, 'Nozzle 1', 2, 2, 24144.00, 'Active', '2026-06-03 12:58:30', '2026-06-26 04:32:27'),
(3, 'Nozzle 3', 4, 4, 25555.00, 'Active', '2026-06-23 15:04:54', '2026-06-26 04:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_purchases`
--

CREATE TABLE `tbl_purchases` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `date` date NOT NULL,
  `route` varchar(256) NOT NULL,
  `invoice_number` varchar(128) NOT NULL,
  `carriage_invoice_number` varchar(128) NOT NULL,
  `payment_status` enum('unpaid','in process','paid') NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_purchases`
--

INSERT INTO `tbl_purchases` (`id`, `item_id`, `quantity`, `price`, `date`, `route`, `invoice_number`, `carriage_invoice_number`, `payment_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 3, 400.00, 200.00, '2026-06-23', 'apk', '121', '122', 'paid', '2026-06-23 11:24:17', '2026-06-23 11:27:47', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_purchase_payments`
--

CREATE TABLE `tbl_purchase_payments` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `bank_id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_purchase_payments`
--

INSERT INTO `tbl_purchase_payments` (`id`, `purchase_id`, `date`, `amount`, `bank_id`, `tank_id`, `created_at`, `deleted_at`) VALUES
(1, 1, '2026-06-23', 20000.00, 2, 2, '2026-06-23 11:25:33', '2026-06-23 16:26:30'),
(2, 1, '2026-06-23', 2000.00, 2, 2, '2026-06-23 11:26:44', NULL),
(3, 1, '2026-06-23', 6000.00, 2, 2, '2026-06-23 11:27:16', NULL),
(4, 1, '2026-06-23', 72000.00, 2, 2, '2026-06-23 11:27:47', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_roles`
--

CREATE TABLE `tbl_roles` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_roles`
--

INSERT INTO `tbl_roles` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Manager', '2026-06-03 12:58:45', '2026-06-03 12:58:45'),
(2, 'Admin', '2026-06-14 16:49:35', '2026-06-14 16:49:35');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_shifts`
--

CREATE TABLE `tbl_shifts` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_shifts`
--

INSERT INTO `tbl_shifts` (`id`, `name`, `start_time`, `end_time`, `status`) VALUES
(2, 'Morning Shift', '12:01:00', '00:00:00', 'Active'),
(4, 'Night Shift', '00:01:00', '12:00:00', 'Active'),
(5, 'ABC Shift', '06:00:00', '18:00:00', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_staff`
--

CREATE TABLE `tbl_staff` (
  `id` int(11) NOT NULL,
  `first_name` varchar(128) NOT NULL,
  `last_name` varchar(128) NOT NULL,
  `role_id` int(11) NOT NULL,
  `joining_date` date NOT NULL,
  `shift_id` int(11) NOT NULL,
  `salary` decimal(10,2) NOT NULL,
  `address` varchar(512) DEFAULT NULL,
  `phone` varchar(32) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_staff`
--

INSERT INTO `tbl_staff` (`id`, `first_name`, `last_name`, `role_id`, `joining_date`, `shift_id`, `salary`, `address`, `phone`, `created_at`, `updated_at`) VALUES
(1, 'Sharjeel', 'Wakeel', 1, '2026-06-05', 2, 12500.00, NULL, '03141236401', '2026-06-03 12:59:28', '2026-06-03 12:59:28'),
(2, 'ABC', 'User', 2, '2026-06-16', 2, 1200.00, NULL, '031512364444', '2026-06-14 16:50:14', '2026-06-14 16:50:14'),
(3, 'Programmatic', 'User', 1, '2026-06-23', 2, 1500.00, 'Test Address', '03211234567', '2026-06-23 15:29:44', '2026-06-23 15:29:44'),
(4, 'test', 'name', 1, '2026-06-25', 5, 1222.00, 'okkk', '+92 (315) 123-6401', '2026-06-23 15:32:11', '2026-06-23 15:32:39');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_staff_attendance`
--

CREATE TABLE `tbl_staff_attendance` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Late','Leave') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_staff_attendance`
--

INSERT INTO `tbl_staff_attendance` (`id`, `staff_id`, `date`, `status`, `created_at`) VALUES
(1, 1, '2026-06-01', 'Present', '2026-06-09 09:51:06'),
(2, 1, '2026-06-02', 'Late', '2026-06-09 09:51:06'),
(3, 1, '2026-06-03', 'Leave', '2026-06-09 09:51:06'),
(4, 1, '2026-06-04', 'Leave', '2026-06-09 09:51:06'),
(5, 1, '2026-06-05', 'Leave', '2026-06-09 09:51:06'),
(6, 1, '2026-06-06', 'Absent', '2026-06-09 09:51:06'),
(7, 1, '2026-06-09', 'Present', '2026-06-09 09:52:25'),
(9, 2, '2026-06-15', 'Present', '2026-06-14 23:53:06'),
(10, 1, '2026-06-15', 'Absent', '2026-06-14 23:53:06');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_staff_guarantors`
--

CREATE TABLE `tbl_staff_guarantors` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `address` varchar(512) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_staff_guarantors`
--

INSERT INTO `tbl_staff_guarantors` (`id`, `staff_id`, `name`, `phone`, `address`) VALUES
(1, 3, 'Prog Guarantor', '03007654321', 'Guarantor Address'),
(2, 4, 'filter ok', '221232132', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_tanks`
--

CREATE TABLE `tbl_tanks` (
  `id` int(11) NOT NULL,
  `tank_name` varchar(128) NOT NULL,
  `item_id` int(11) NOT NULL,
  `storage_capacity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_tanks`
--

INSERT INTO `tbl_tanks` (`id`, `tank_name`, `item_id`, `storage_capacity`, `created_at`, `updated_at`) VALUES
(2, 'tank A', 2, 0.00, '2026-06-03 12:57:50', '2026-06-23 15:05:54'),
(3, 'Tank B', 3, 0.00, '2026-06-14 14:40:56', '2026-06-14 14:40:56'),
(4, 'Tank C', 4, 12000.00, '2026-06-23 14:55:29', '2026-06-23 14:55:29');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_tank_dip_charts`
--

CREATE TABLE `tbl_tank_dip_charts` (
  `id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `dip_label` varchar(64) NOT NULL,
  `dip_value` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_tank_dip_charts`
--

INSERT INTO `tbl_tank_dip_charts` (`id`, `tank_id`, `dip_label`, `dip_value`) VALUES
(1, 4, 'Key', 232.00);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_accounts`
--
ALTER TABLE `tbl_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_banks`
--
ALTER TABLE `tbl_banks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_card_machines`
--
ALTER TABLE `tbl_card_machines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_items`
--
ALTER TABLE `tbl_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_leave_setup`
--
ALTER TABLE `tbl_leave_setup`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `staff_leave` (`staff_id`);

--
-- Indexes for table `tbl_lubricant_products`
--
ALTER TABLE `tbl_lubricant_products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_lubricant_purchases`
--
ALTER TABLE `tbl_lubricant_purchases`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_lubricant_sales`
--
ALTER TABLE `tbl_lubricant_sales`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_meter_readings`
--
ALTER TABLE `tbl_meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shift_id` (`shift_id`);

--
-- Indexes for table `tbl_meter_reading_card_sales`
--
ALTER TABLE `tbl_meter_reading_card_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meter_reading_id` (`meter_reading_id`),
  ADD KEY `staff_id` (`staff_id`),
  ADD KEY `card_machine_id` (`card_machine_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `tbl_meter_reading_credit_sales`
--
ALTER TABLE `tbl_meter_reading_credit_sales`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_meter_reading_details`
--
ALTER TABLE `tbl_meter_reading_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meter_reading_id` (`meter_reading_id`),
  ADD KEY `nozzle_id` (`nozzle_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `tbl_nozzles`
--
ALTER TABLE `tbl_nozzles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tank_id` (`tank_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `tbl_purchases`
--
ALTER TABLE `tbl_purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `tbl_purchase_payments`
--
ALTER TABLE `tbl_purchase_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `bank_id` (`bank_id`),
  ADD KEY `tank_id` (`tank_id`);

--
-- Indexes for table `tbl_roles`
--
ALTER TABLE `tbl_roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_shifts`
--
ALTER TABLE `tbl_shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_staff`
--
ALTER TABLE `tbl_staff`
  ADD PRIMARY KEY (`id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `shift_id` (`shift_id`);

--
-- Indexes for table `tbl_staff_attendance`
--
ALTER TABLE `tbl_staff_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `staff_date` (`staff_id`,`date`),
  ADD KEY `staff_id_idx` (`staff_id`),
  ADD KEY `date_idx` (`date`);

--
-- Indexes for table `tbl_staff_guarantors`
--
ALTER TABLE `tbl_staff_guarantors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `tbl_tanks`
--
ALTER TABLE `tbl_tanks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `tbl_tank_dip_charts`
--
ALTER TABLE `tbl_tank_dip_charts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tank_id` (`tank_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_accounts`
--
ALTER TABLE `tbl_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_banks`
--
ALTER TABLE `tbl_banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_card_machines`
--
ALTER TABLE `tbl_card_machines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_items`
--
ALTER TABLE `tbl_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_leave_setup`
--
ALTER TABLE `tbl_leave_setup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_lubricant_products`
--
ALTER TABLE `tbl_lubricant_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_lubricant_purchases`
--
ALTER TABLE `tbl_lubricant_purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_lubricant_sales`
--
ALTER TABLE `tbl_lubricant_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_meter_readings`
--
ALTER TABLE `tbl_meter_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `tbl_meter_reading_card_sales`
--
ALTER TABLE `tbl_meter_reading_card_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_meter_reading_credit_sales`
--
ALTER TABLE `tbl_meter_reading_credit_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_meter_reading_details`
--
ALTER TABLE `tbl_meter_reading_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `tbl_nozzles`
--
ALTER TABLE `tbl_nozzles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_purchases`
--
ALTER TABLE `tbl_purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_purchase_payments`
--
ALTER TABLE `tbl_purchase_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_roles`
--
ALTER TABLE `tbl_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_shifts`
--
ALTER TABLE `tbl_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_staff`
--
ALTER TABLE `tbl_staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_staff_attendance`
--
ALTER TABLE `tbl_staff_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tbl_staff_guarantors`
--
ALTER TABLE `tbl_staff_guarantors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_tanks`
--
ALTER TABLE `tbl_tanks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_tank_dip_charts`
--
ALTER TABLE `tbl_tank_dip_charts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_purchases`
--
ALTER TABLE `tbl_purchases`
  ADD CONSTRAINT `tbl_purchases_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `tbl_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_purchase_payments`
--
ALTER TABLE `tbl_purchase_payments`
  ADD CONSTRAINT `tbl_purchase_payments_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `tbl_purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_purchase_payments_ibfk_2` FOREIGN KEY (`bank_id`) REFERENCES `tbl_banks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_purchase_payments_ibfk_3` FOREIGN KEY (`tank_id`) REFERENCES `tbl_tanks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_staff_guarantors`
--
ALTER TABLE `tbl_staff_guarantors`
  ADD CONSTRAINT `tbl_staff_guarantors_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `tbl_staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_tank_dip_charts`
--
ALTER TABLE `tbl_tank_dip_charts`
  ADD CONSTRAINT `tbl_tank_dip_charts_ibfk_1` FOREIGN KEY (`tank_id`) REFERENCES `tbl_tanks` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
