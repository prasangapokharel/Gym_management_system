-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 25, 2025 at 04:35 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.0.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gym_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `admin_id`, `action`, `created_at`) VALUES
(1, 1, 'Added new member: kapil tamang', '2025-04-03 04:08:25'),
(2, 1, 'Updated status of member #1 to active', '2025-04-03 04:09:42'),
(3, 1, 'Added new member: rkaehs saasa', '2025-04-03 04:25:47'),
(4, 1, 'Recorded payment of 250 for member #GM00002', '2025-04-03 04:25:47'),
(5, 1, 'Added new member: wdsd sdsd', '2025-04-03 04:31:07'),
(6, 1, 'Recorded payment of 135 for member #GM00003', '2025-04-03 04:31:07'),
(7, 1, 'Renewed membership for member #3', '2025-04-03 04:31:52'),
(8, 1, 'Logged in', '2025-04-24 02:19:07'),
(9, 1, 'Created cafe order #ORD20250424043922 for member #1', '2025-04-24 02:39:22'),
(10, 1, 'Created cafe order #ORD20250424043953 for member #2', '2025-04-24 02:39:53'),
(11, 1, 'Created cafe order #ORD-20250424-4961', '2025-04-24 04:37:58'),
(12, 1, 'Created cafe order #ORD-20250425-3518', '2025-04-25 00:49:31'),
(13, 1, 'Added new cafe product: coffee', '2025-04-25 00:56:39'),
(14, 1, 'Created cafe order #ORD-20250425-5566', '2025-04-25 00:57:22'),
(15, 1, 'Updated status of member #3 to active', '2025-04-25 02:28:09'),
(16, 1, 'Added new member: rakesh niraula', '2025-04-25 02:29:33'),
(17, 1, 'Recorded payment of 50 for member #GM00004', '2025-04-25 02:29:33');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `email`, `fullname`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$uTJNATVqt2y7tmtylTmCluTfdgnl377kLb6yMP/qcduHZoWctNPU.', 'admin@example.com', 'System Administrator', '2025-04-03 03:32:45', '2025-04-03 03:32:45');

-- --------------------------------------------------------

--
-- Table structure for table `cafe_orders`
--

CREATE TABLE `cafe_orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(20) NOT NULL,
  `member_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','credit_card','debit_card','other') NOT NULL,
  `status` enum('completed','cancelled') DEFAULT 'completed',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cafe_orders`
--

INSERT INTO `cafe_orders` (`id`, `order_number`, `member_id`, `total_amount`, `payment_method`, `status`, `created_by`, `created_at`) VALUES
(1, 'ORD20250424043922', 1, 6.98, 'cash', 'completed', 1, '2025-04-24 02:39:22'),
(2, 'ORD20250424043953', 2, 54.98, 'cash', 'completed', 1, '2025-04-24 02:39:53'),
(3, 'ORD-20250424-4961', 1, 49.93, 'cash', 'completed', 1, '2025-04-24 04:37:58'),
(4, 'ORD-20250425-3518', 1, 69.92, 'cash', 'completed', 1, '2025-04-25 00:49:31'),
(6, 'ORD-20250425-5566', 2, 17.99, 'cash', 'completed', 1, '2025-04-25 00:57:22');

-- --------------------------------------------------------

--
-- Table structure for table `cafe_order_items`
--

CREATE TABLE `cafe_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cafe_order_items`
--

INSERT INTO `cafe_order_items` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`, `created_at`) VALUES
(1, 1, 2, 1, 3.99, 3.99, '2025-04-24 02:39:22'),
(2, 1, 8, 1, 2.99, 2.99, '2025-04-24 02:39:22'),
(3, 2, 3, 1, 24.99, 24.99, '2025-04-24 02:39:53'),
(4, 2, 5, 1, 29.99, 29.99, '2025-04-24 02:39:53'),
(5, 3, 2, 1, 3.99, 3.00, '2025-04-24 04:37:58'),
(6, 3, 9, 1, 6.99, 6.00, '2025-04-24 04:37:58'),
(7, 3, 8, 1, 2.99, 2.00, '2025-04-24 04:37:58'),
(8, 3, 1, 1, 5.99, 5.00, '2025-04-24 04:37:58'),
(9, 3, 7, 1, 2.99, 2.00, '2025-04-24 04:37:58'),
(10, 3, 6, 1, 1.99, 1.00, '2025-04-24 04:37:58'),
(11, 3, 3, 1, 24.99, 24.00, '2025-04-24 04:37:58'),
(12, 4, 2, 1, 3.99, 3.00, '2025-04-25 00:49:31'),
(13, 4, 9, 1, 6.99, 6.00, '2025-04-25 00:49:31'),
(14, 4, 8, 1, 2.99, 2.00, '2025-04-25 00:49:31'),
(15, 4, 1, 1, 5.99, 5.00, '2025-04-25 00:49:31'),
(16, 4, 7, 1, 2.99, 2.00, '2025-04-25 00:49:31'),
(17, 4, 6, 1, 1.99, 1.00, '2025-04-25 00:49:31'),
(18, 4, 3, 1, 24.99, 24.00, '2025-04-25 00:49:31'),
(19, 4, 10, 1, 19.99, 19.00, '2025-04-25 00:49:31'),
(20, 6, 1, 1, 5.99, 5.00, '2025-04-25 00:57:22'),
(21, 6, 11, 1, 12.00, 12.00, '2025-04-25 00:57:22');

-- --------------------------------------------------------

--
-- Table structure for table `cafe_products`
--

CREATE TABLE `cafe_products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('food','beverage','supplement','other') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cafe_products`
--

INSERT INTO `cafe_products` (`id`, `name`, `description`, `category`, `price`, `cost_price`, `stock_quantity`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Protein Shake', 'Whey protein shake with 25g protein', 'beverage', 5.99, 2.50, 97, 'active', '2025-04-24 02:26:38', '2025-04-25 00:57:22'),
(2, 'Energy Bar', 'High protein energy bar', 'food', 3.99, 1.75, 47, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(3, 'BCAA Supplement', 'Branched-chain amino acids supplement', 'supplement', 24.99, 12.50, 27, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(4, 'Protein Powder (2lb)', 'Whey protein powder', 'supplement', 39.99, 20.00, 25, 'active', '2025-04-24 02:26:38', '2025-04-24 02:26:38'),
(5, 'Pre-Workout', 'Pre-workout energy supplement', 'supplement', 29.99, 15.00, 19, 'active', '2025-04-24 02:26:38', '2025-04-24 02:39:53'),
(6, 'Water Bottle', 'Purified water (500ml)', 'beverage', 1.99, 0.50, 198, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(7, 'Sports Drink', 'Electrolyte sports drink', 'beverage', 2.99, 1.25, 73, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(8, 'Protein Cookie', 'High protein cookie', 'food', 2.99, 1.00, 37, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(9, 'Fitness Sandwich', 'Healthy chicken sandwich', 'food', 6.99, 3.00, 13, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(10, 'Creatine (500g)', 'Creatine monohydrate', 'supplement', 19.99, 8.00, 29, 'active', '2025-04-24 02:26:38', '2025-04-25 00:49:31'),
(11, 'coffee', 'black coffee', 'beverage', 12.00, 12.00, 99, 'active', '2025-04-25 00:56:39', '2025-04-25 00:57:22');

-- --------------------------------------------------------

--
-- Table structure for table `gym_members`
--

CREATE TABLE `gym_members` (
  `id` int(11) NOT NULL,
  `member_id` varchar(20) NOT NULL COMMENT 'Custom member ID for display',
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `gender` enum('male','female','other') NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `membership_id` int(11) DEFAULT NULL,
  `membership_start_date` date DEFAULT NULL,
  `membership_end_date` date DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gym_members`
--

INSERT INTO `gym_members` (`id`, `member_id`, `first_name`, `last_name`, `email`, `phone`, `gender`, `date_of_birth`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `membership_id`, `membership_start_date`, `membership_end_date`, `status`, `notes`, `profile_image`, `created_at`, `updated_at`) VALUES
(1, 'GM00001', 'kapil', 'tamang', 'kapil123@gmail.com', '9816308527', 'male', '2002-04-06', 'khorase', '9823232', '232323232', 1, '2025-04-03', '2025-04-03', 'active', 'dads', NULL, '2025-04-03 04:08:25', '2025-04-03 04:33:26'),
(2, 'GM00002', 'rkaehs', 'saasa', 'asasas1@gmail.com', '1234567896', 'male', '1999-04-03', 'wsdsdsd', '12312342323', '23231212324', 3, '2025-04-03', '2025-09-30', 'active', 'sddsds', NULL, '2025-04-03 04:25:47', '2025-04-03 04:25:47'),
(3, 'GM00003', 'wdsd', 'sdsd', 'asde@gmail.com', '34234344232', 'male', NULL, '23wedw', '1234567890', '1234567890', 3, '2025-04-03', '2025-09-30', 'active', 'sods', NULL, '2025-04-03 04:31:07', '2025-04-03 04:31:52'),
(4, 'GM00004', 'rakesh', 'niraula', 'rakesh@123gmail.com', '9816308527', 'male', '2002-04-25', 'bhauna', '9816308527', '9821728932', 1, '2025-04-25', '2025-05-25', 'active', 'kapilsasa', NULL, '2025-04-25 02:29:33', '2025-04-25 02:29:33');

-- --------------------------------------------------------

--
-- Table structure for table `membership_history`
--

CREATE TABLE `membership_history` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `membership_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `status` enum('active','expired','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `membership_history`
--

INSERT INTO `membership_history` (`id`, `member_id`, `membership_id`, `start_date`, `end_date`, `payment_id`, `status`, `created_at`) VALUES
(1, 2, 3, '2025-04-03', '2025-09-30', 1, 'active', '2025-04-03 04:25:47'),
(2, 3, 2, '2025-04-03', '2025-07-02', 2, 'active', '2025-04-03 04:31:07'),
(3, 3, 3, '2025-04-03', '2025-09-30', 3, 'active', '2025-04-03 04:31:52'),
(4, 4, 1, '2025-04-25', '2025-05-25', 4, 'active', '2025-04-25 02:29:33');

-- --------------------------------------------------------

--
-- Table structure for table `membership_plans`
--

CREATE TABLE `membership_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `duration` int(11) NOT NULL COMMENT 'Duration in days',
  `price` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `membership_plans`
--

INSERT INTO `membership_plans` (`id`, `name`, `duration`, `price`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Monthly', 30, 50.00, 'Basic monthly membership with access to all facilities', 'active', '2025-04-03 03:32:29', '2025-04-03 03:32:29'),
(2, 'Quarterly', 90, 135.00, '3-month membership with access to all facilities', 'active', '2025-04-03 03:32:29', '2025-04-03 03:32:29'),
(3, 'Half-yearly', 180, 250.00, '6-month membership with access to all facilities', 'active', '2025-04-03 03:32:29', '2025-04-03 03:32:29'),
(4, 'Annual', 365, 450.00, 'Yearly membership with access to all facilities and one free personal training session', 'active', '2025-04-03 03:32:29', '2025-04-03 03:32:29');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('expiring_membership','expired_membership','payment_due','system') NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `member_id`, `message`, `is_read`, `created_at`) VALUES
(1, 'expiring_membership', 1, 'Membership for kapil tamang will expire on 2025-04-03', 0, '2025-04-03 04:33:29'),
(2, 'expired_membership', 1, 'Membership has expired on 2025-04-03', 0, '2025-04-24 02:19:07'),
(3, 'expired_membership', 1, 'Membership has expired on 2025-04-03', 0, '2025-04-25 00:50:06');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expiry` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','credit_card','debit_card','bank_transfer','other') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `member_id`, `amount`, `payment_date`, `payment_method`, `description`, `receipt_number`, `created_by`, `created_at`) VALUES
(1, 2, 250.00, '2025-04-03', 'cash', 'Payment for Half-yearly', 'R20250403062547', 1, '2025-04-03 04:25:47'),
(2, 3, 135.00, '2025-04-03', 'cash', 'Payment for Quarterly', 'R20250403063107', 1, '2025-04-03 04:31:07'),
(3, 3, 250.00, '2025-04-03', 'cash', 'Renewal payment for Half-yearly', 'R20250403063152', 1, '2025-04-03 04:31:52'),
(4, 4, 50.00, '2025-04-25', 'cash', 'Payment for Monthly', 'R20250425042933', 1, '2025-04-25 02:29:33');

-- --------------------------------------------------------

--
-- Table structure for table `sms_config`
--

CREATE TABLE `sms_config` (
  `id` int(11) NOT NULL,
  `api_provider` varchar(100) NOT NULL,
  `api_endpoint` varchar(255) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `sender_id` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_templates`
--

CREATE TABLE `sms_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` enum('activation','renewal','custom') NOT NULL,
  `template_content` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_templates`
--

INSERT INTO `sms_templates` (`id`, `template_name`, `template_type`, `template_content`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Membership Activation', 'activation', 'Dear {member_name}, welcome to our gym! Your {plan_name} membership is now active until {expiry_date}. Thank you for joining us!', 1, '2025-04-25 02:27:44', '2025-04-25 02:27:44'),
(2, 'Membership Renewal Reminder', 'renewal', 'Dear {member_name}, your {plan_name} membership will expire on {expiry_date}. Please visit our gym to renew your membership. Thank you!', 1, '2025-04-25 02:27:44', '2025-04-25 02:27:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `cafe_orders`
--
ALTER TABLE `cafe_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `cafe_order_items`
--
ALTER TABLE `cafe_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `cafe_products`
--
ALTER TABLE `cafe_products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gym_members`
--
ALTER TABLE `gym_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `member_id` (`member_id`),
  ADD KEY `membership_id` (`membership_id`);

--
-- Indexes for table `membership_history`
--
ALTER TABLE `membership_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `membership_id` (`membership_id`),
  ADD KEY `payment_id` (`payment_id`);

--
-- Indexes for table `membership_plans`
--
ALTER TABLE `membership_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `sms_config`
--
ALTER TABLE `sms_config`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cafe_orders`
--
ALTER TABLE `cafe_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `cafe_order_items`
--
ALTER TABLE `cafe_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `cafe_products`
--
ALTER TABLE `cafe_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `gym_members`
--
ALTER TABLE `gym_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `membership_history`
--
ALTER TABLE `membership_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `membership_plans`
--
ALTER TABLE `membership_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sms_config`
--
ALTER TABLE `sms_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_templates`
--
ALTER TABLE `sms_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cafe_orders`
--
ALTER TABLE `cafe_orders`
  ADD CONSTRAINT `cafe_orders_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `gym_members` (`id`),
  ADD CONSTRAINT `cafe_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`);

--
-- Constraints for table `cafe_order_items`
--
ALTER TABLE `cafe_order_items`
  ADD CONSTRAINT `cafe_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `cafe_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cafe_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `cafe_products` (`id`);

--
-- Constraints for table `gym_members`
--
ALTER TABLE `gym_members`
  ADD CONSTRAINT `gym_members_ibfk_1` FOREIGN KEY (`membership_id`) REFERENCES `membership_plans` (`id`);

--
-- Constraints for table `membership_history`
--
ALTER TABLE `membership_history`
  ADD CONSTRAINT `membership_history_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `gym_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `membership_history_ibfk_2` FOREIGN KEY (`membership_id`) REFERENCES `membership_plans` (`id`),
  ADD CONSTRAINT `membership_history_ibfk_3` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `gym_members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `gym_members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`);

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `gym_members` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sms_logs_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `sms_templates` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
