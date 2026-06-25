-- phpMyAdmin SQL Dump
-- version 5.0.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 14, 2020 at 09:31 PM
-- Server version: 10.4.11-MariaDB
-- PHP Version: 7.4.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lynxpos`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_accounts`
--

DROP TABLE IF EXISTS `tbl_accounts`;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_accounts`
--

INSERT INTO `tbl_accounts` (`id`, `name`, `username`, `password`, `type`, `phonenumber`, `cnicnumber`, `address`, `city`) VALUES
(1, 'Syed Haseeb Hashmi', 'hasyeb', '$2y$10$.U60lyPzF1YLJlBZast2Qe/yOl1meu0R5MfHvtesLtNAngZzTdYem', 'Admin', '03005090170', '31202-0000000-0', 'House # 18, Sajid Awan Colony.', 'Bahawalpur');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_assets`
--

DROP TABLE IF EXISTS `tbl_assets`;
CREATE TABLE `tbl_assets` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unitprice` int(11) DEFAULT NULL,
  `totalprice` int(11) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_assets`
--

INSERT INTO `tbl_assets` (`id`, `name`, `quantity`, `unitprice`, `totalprice`, `date`) VALUES
(2, 'Table', 5, 100, 5000, '2020-07-02'),
(3, 'Chairs', 25, 500, 12500, '2020-07-02'),
(4, 'Computer', 3, 20000, 60000, '2020-07-05');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_empattendance`
--

DROP TABLE IF EXISTS `tbl_empattendance`;
CREATE TABLE `tbl_empattendance` (
  `id` int(11) NOT NULL,
  `empid` int(11) NOT NULL,
  `attendance` enum('P','A') NOT NULL,
  `paid` int(11) NOT NULL,
  `date` date NOT NULL,
  `outstanding` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_empattendance`
--

INSERT INTO `tbl_empattendance` (`id`, `empid`, `attendance`, `paid`, `date`, `outstanding`) VALUES
(1, 1, 'P', 1000, '2020-07-15', 0),
(2, 2, 'P', 250, '2020-07-15', 250),
(3, 3, 'A', 0, '2020-07-15', 0),
(4, 1, 'P', 2000, '2020-07-16', -1000),
(5, 2, 'P', 750, '2020-07-16', 0),
(6, 3, 'P', 250, '2020-07-16', 250),
(7, 1, 'P', 500, '2020-07-17', -500),
(8, 2, 'P', 500, '2020-07-17', 0),
(9, 3, 'P', 750, '2020-07-17', 0),
(10, 1, 'P', 1500, '2020-07-18', -1000),
(11, 2, 'P', 500, '2020-07-18', 0),
(12, 3, 'P', 500, '2020-07-18', 0),
(13, 1, 'P', 0, '2020-07-19', 0),
(14, 2, 'P', 500, '2020-07-19', 0),
(15, 3, 'P', 500, '2020-07-19', 0);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_employees`
--

DROP TABLE IF EXISTS `tbl_employees`;
CREATE TABLE `tbl_employees` (
  `id` int(11) NOT NULL,
  `name` varchar(256) NOT NULL,
  `type` varchar(64) NOT NULL,
  `phonenumber` varchar(64) NOT NULL,
  `cnicnumber` varchar(64) NOT NULL,
  `address` varchar(512) NOT NULL,
  `city` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_employees`
--

INSERT INTO `tbl_employees` (`id`, `name`, `type`, `phonenumber`, `cnicnumber`, `address`, `city`) VALUES
(1, 'اکبر', 'Waiter', '030000000', '2312312432423', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', 'Bahawalpur'),
(2, 'اقبال', 'Waiter', '030000000', '2312312432423', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', 'Ahmedpur'),
(3, 'زیب', 'Waiter', '03000000', '2312312432423', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', 'Bahawalpur');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_empsalary`
--

DROP TABLE IF EXISTS `tbl_empsalary`;
CREATE TABLE `tbl_empsalary` (
  `id` int(11) NOT NULL,
  `empid` int(11) NOT NULL,
  `salary` int(11) NOT NULL,
  `outstanding` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_empsalary`
--

INSERT INTO `tbl_empsalary` (`id`, `empid`, `salary`, `outstanding`) VALUES
(1, 1, 1000, 0),
(2, 2, 500, 0),
(3, 3, 500, 0);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_emptype`
--

DROP TABLE IF EXISTS `tbl_emptype`;
CREATE TABLE `tbl_emptype` (
  `id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_emptype`
--

INSERT INTO `tbl_emptype` (`id`, `type`) VALUES
(1, 'Waiter'),
(2, 'Manager'),
(3, 'Cook'),
(5, 'Roti Wala'),
(6, 'Burger Wala'),
(7, 'Shawarma Wala');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_expenses`
--

DROP TABLE IF EXISTS `tbl_expenses`;
CREATE TABLE `tbl_expenses` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `total` int(11) NOT NULL,
  `type` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_expenses`
--

INSERT INTO `tbl_expenses` (`id`, `date`, `total`, `type`) VALUES
(1, '2020-06-26', 1000, 'Miscellaneous');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_expitems`
--

DROP TABLE IF EXISTS `tbl_expitems`;
CREATE TABLE `tbl_expitems` (
  `id` int(11) NOT NULL,
  `expense` varchar(128) NOT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price` int(11) NOT NULL,
  `expid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_expitems`
--

INSERT INTO `tbl_expitems` (`id`, `expense`, `quantity`, `price`, `expid`) VALUES
(1, 'Chaye Pani', 1, 100, 1),
(2, 'Khana', 1, 900, 1);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_exptype`
--

DROP TABLE IF EXISTS `tbl_exptype`;
CREATE TABLE `tbl_exptype` (
  `id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_exptype`
--

INSERT INTO `tbl_exptype` (`id`, `type`) VALUES
(1, 'Miscellaneous'),
(2, 'Canteen');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_items`
--

DROP TABLE IF EXISTS `tbl_items`;
CREATE TABLE `tbl_items` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `cash_rate` decimal(10,2) NOT NULL,
  `credit_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_items`
--

INSERT INTO `tbl_items` (`id`, `name`, `cash_rate`, `credit_rate`, `purchase_rate`, `unit`) VALUES
(1, 'چکن بریانی', 70.00, 75.00, 60.00, 'Plate'),
(2, 'سموسہ', 10.00, 12.00, 8.00, 'Piece'),
(3, 'کولڈ ڈرنک', 30.00, 35.00, 25.00, 'Bottle');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_orderitems`
--

DROP TABLE IF EXISTS `tbl_orderitems`;
CREATE TABLE `tbl_orderitems` (
  `id` bigint(20) NOT NULL,
  `item` varchar(128) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `orderid` int(11) NOT NULL,
  `empid` int(11) NOT NULL,
  `date` date NOT NULL,
  `rate` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_orderitems`
--

INSERT INTO `tbl_orderitems` (`id`, `item`, `quantity`, `price`, `orderid`, `empid`, `date`, `rate`) VALUES
(1, 'چکن بریانی', 3, 210, 1, 1, '2020-08-12', 70),
(2, 'کولڈ ڈرنک', 3, 90, 1, 1, '2020-08-12', 30),
(3, 'سموسہ', 4, 40, 2, 2, '2020-08-13', 10),
(4, 'کولڈ ڈرنک', 2, 60, 2, 2, '2020-08-13', 30),
(5, 'چکن بریانی', 3, 210, 3, 3, '2020-08-14', 70),
(6, 'سموسہ', 3, 30, 3, 3, '2020-08-14', 10),
(7, 'کولڈ ڈرنک', 3, 90, 3, 3, '2020-08-14', 30);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_orders`
--

DROP TABLE IF EXISTS `tbl_orders`;
CREATE TABLE `tbl_orders` (
  `id` int(11) NOT NULL,
  `ordernum` int(11) NOT NULL,
  `employee` int(11) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `total` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_orders`
--

INSERT INTO `tbl_orders` (`id`, `ordernum`, `employee`, `date`, `time`, `total`) VALUES
(1, 0, 1, '2020-08-14', '17:50:51', 300),
(2, 0, 2, '2020-08-14', '17:54:03', 100),
(3, 0, 3, '2020-08-14', '17:57:52', 330);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_stockbill`
--

DROP TABLE IF EXISTS `tbl_stockbill`;
CREATE TABLE `tbl_stockbill` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `total` int(11) NOT NULL,
  `supplier` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_stockbill`
--

INSERT INTO `tbl_stockbill` (`id`, `date`, `total`, `supplier`) VALUES
(4, '2020-07-19', 1468, 'Karyana Store');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_stockbillitems`
--

DROP TABLE IF EXISTS `tbl_stockbillitems`;
CREATE TABLE `tbl_stockbillitems` (
  `id` int(11) NOT NULL,
  `siid` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `sbid` int(11) NOT NULL,
  `unit` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_stockbillitems`
--

INSERT INTO `tbl_stockbillitems` (`id`, `siid`, `quantity`, `price`, `sbid`, `unit`) VALUES
(9, 2, 2, 234, 4, 'Kilogram'),
(10, 1, 24, 1234, 4, 'Kilogram');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_stockitems`
--

DROP TABLE IF EXISTS `tbl_stockitems`;
CREATE TABLE `tbl_stockitems` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_stockitems`
--

INSERT INTO `tbl_stockitems` (`id`, `name`, `quantity`) VALUES
(1, 'Flour', 10),
(2, 'Sugar', 2),
(3, 'Cooking Oil', 1),
(4, 'Rice', 2),
(5, 'Milk', 1),
(6, 'Ghee', 2);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_stocksupplier`
--

DROP TABLE IF EXISTS `tbl_stocksupplier`;
CREATE TABLE `tbl_stocksupplier` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_stocksupplier`
--

INSERT INTO `tbl_stocksupplier` (`id`, `name`) VALUES
(1, 'Karyana Store'),
(2, 'Doodh Wala');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_stockused`
--

DROP TABLE IF EXISTS `tbl_stockused`;
CREATE TABLE `tbl_stockused` (
  `id` int(11) NOT NULL,
  `siid` int(11) NOT NULL,
  `qtyused` int(11) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tbl_stockused`
--

INSERT INTO `tbl_stockused` (`id`, `siid`, `qtyused`, `date`) VALUES
(1, 1, 10, '2020-07-04'),
(2, 2, 3, '2020-07-04'),
(3, 3, 3, '2020-07-04'),
(4, 4, 5, '2020-07-04'),
(5, 5, 3, '2020-07-04'),
(6, 1, 5, '2020-07-06'),
(7, 2, 5, '2020-07-06'),
(8, 3, 1, '2020-07-06'),
(9, 4, 3, '2020-07-06'),
(10, 5, 1, '2020-07-06'),
(11, 6, 3, '2020-07-06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_accounts`
--
ALTER TABLE `tbl_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_assets`
--
ALTER TABLE `tbl_assets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_empattendance`
--
ALTER TABLE `tbl_empattendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_employees`
--
ALTER TABLE `tbl_employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_empsalary`
--
ALTER TABLE `tbl_empsalary`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_emptype`
--
ALTER TABLE `tbl_emptype`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_expenses`
--
ALTER TABLE `tbl_expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_expitems`
--
ALTER TABLE `tbl_expitems`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_exptype`
--
ALTER TABLE `tbl_exptype`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_items`
--
ALTER TABLE `tbl_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_orderitems`
--
ALTER TABLE `tbl_orderitems`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_stockbill`
--
ALTER TABLE `tbl_stockbill`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_stockbillitems`
--
ALTER TABLE `tbl_stockbillitems`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_stockitems`
--
ALTER TABLE `tbl_stockitems`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_stocksupplier`
--
ALTER TABLE `tbl_stocksupplier`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_stockused`
--
ALTER TABLE `tbl_stockused`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_accounts`
--
ALTER TABLE `tbl_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_assets`
--
ALTER TABLE `tbl_assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_empattendance`
--
ALTER TABLE `tbl_empattendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `tbl_employees`
--
ALTER TABLE `tbl_employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_empsalary`
--
ALTER TABLE `tbl_empsalary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_emptype`
--
ALTER TABLE `tbl_emptype`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tbl_expenses`
--
ALTER TABLE `tbl_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_expitems`
--
ALTER TABLE `tbl_expitems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_exptype`
--
ALTER TABLE `tbl_exptype`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_items`
--
ALTER TABLE `tbl_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_orderitems`
--
ALTER TABLE `tbl_orderitems`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_stockbill`
--
ALTER TABLE `tbl_stockbill`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_stockbillitems`
--
ALTER TABLE `tbl_stockbillitems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tbl_stockitems`
--
ALTER TABLE `tbl_stockitems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_stocksupplier`
--
ALTER TABLE `tbl_stocksupplier`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_stockused`
--
ALTER TABLE `tbl_stockused`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
