-- phpMyAdmin SQL Dump
-- version 4.0.10deb1
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: May 26, 2016 at 12:23 PM
-- Server version: 5.5.47-0ubuntu0.14.04.1
-- PHP Version: 5.5.9-1ubuntu4.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `ops_live_20160501`
--

-- --------------------------------------------------------

--
-- Table structure for table `fin_accounts`
--

CREATE TABLE IF NOT EXISTS `fin_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_type` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `account_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=474 ;

-- --------------------------------------------------------

--
-- Table structure for table `fin_asset_transaction_map`
--

CREATE TABLE IF NOT EXISTS `fin_asset_transaction_map` (
  `transaction_id` int(11) NOT NULL,
  `asset_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=2589 ;

-- --------------------------------------------------------

--
-- Table structure for table `fin_transactions`
--

CREATE TABLE IF NOT EXISTS `fin_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `accnt_number` bigint(20) NOT NULL,
  `source` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `trans_type_id` int(10) NOT NULL DEFAULT '0',
  `trans_date` bigint(20) NOT NULL,
  `trans_amount` decimal(10,2) unsigned NOT NULL,
  `cr_db` enum('credit','debit') COLLATE utf8_unicode_ci DEFAULT NULL,
  `post_date` bigint(20) NOT NULL,
  `description` text COLLATE utf8_unicode_ci,
  `reference` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `secondary_reference` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `immutable` int(11) NOT NULL DEFAULT '1',
  `suspense` int(11) NOT NULL DEFAULT '0',
  `suspense_reason` varchar(500) COLLATE utf8_unicode_ci NOT NULL,
  `pl_account_type` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pl_account_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pl_asset_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pl_trans_type_code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `pl_class` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=2589 ;

-- --------------------------------------------------------

--
-- Table structure for table `fin_transactions_type`
--

CREATE TABLE IF NOT EXISTS `fin_transactions_type` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `type_code` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `class` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `system` tinyint(4) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=27 ;

-- --------------------------------------------------------

--
-- Table structure for table `fin_transaction_log`
--

CREATE TABLE IF NOT EXISTS `fin_transaction_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user` bigint(20) NOT NULL,
  `success` text COLLATE utf8_unicode_ci NOT NULL,
  `failed_csv` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `fin_transaction_special_edit`
--

CREATE TABLE IF NOT EXISTS `fin_transaction_special_edit` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `start_time` bigint(20) NOT NULL,
  `end_time` bigint(20) NOT NULL,
  `enabler_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_adjustment`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_adjustment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=61 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_bonus`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_bonus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=2 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_booking_details`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_booking_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `start_time` bigint(20) NOT NULL,
  `trip_day` varchar(512) NOT NULL,
  `trip_time` varchar(512) NOT NULL,
  `trip_type` enum('trip','share') DEFAULT NULL,
  `crn_osn` bigint(20) NOT NULL,
  `crn_osn_type` varchar(255) NOT NULL,
  `distance_kms` double NOT NULL,
  `ride_time_mins` double NOT NULL,
  `operator_bill` double NOT NULL,
  `ola_commission` double NOT NULL,
  `ride_earnings` double NOT NULL,
  `tolls` double NOT NULL,
  `tds` double NOT NULL,
  `net_earnings` double NOT NULL,
  `cash_collected` double NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `summary_id` int(11) NOT NULL,
  `import_id` int(11) NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=493 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_booking_summary`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_booking_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `day` bigint(20) NOT NULL,
  `date` varchar(512) NOT NULL,
  `bookings` int(11) NOT NULL,
  `operator_bill` double NOT NULL,
  `ola_commission` double NOT NULL,
  `ride_earnings` double NOT NULL,
  `tolls` double NOT NULL,
  `tds` double NOT NULL,
  `net_earnings` double NOT NULL,
  `cash_collected` double NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `import_id` int(11) NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=52 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_carlevel_deductions`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_carlevel_deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `period_start_ts` bigint(20) NOT NULL,
  `period_end_ts` bigint(20) NOT NULL,
  `period_start` varchar(255) NOT NULL,
  `period_end` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `cash_collected` double NOT NULL,
  `penalties` double NOT NULL,
  `tds` double NOT NULL,
  `other_deductions` double NOT NULL,
  `total` double NOT NULL,
  `import_id` int(11) NOT NULL,
  `malformed` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_carlevel_earnings`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_carlevel_earnings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `period_start_ts` bigint(20) NOT NULL,
  `period_end_ts` bigint(20) NOT NULL,
  `period_start` varchar(255) NOT NULL,
  `period_end` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `bookings` int(11) NOT NULL,
  `ride_earnings` double NOT NULL,
  `incentives` double NOT NULL,
  `toll` double NOT NULL,
  `other_earnings` double NOT NULL,
  `total` double NOT NULL,
  `import_id` int(11) NOT NULL,
  `malformed` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_data_device_charges`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_data_device_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_incentives`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_incentives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `bookings` int(11) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=70 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_mbg_calc`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_mbg_calc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_parse_log`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_parse_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_id` int(11) NOT NULL,
  `stage` int(11) NOT NULL,
  `level` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `pattern` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=2791 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_penalty`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_penalty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_share_cash_coll`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_share_cash_coll` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_acc_stmt_share_earnings`
--

CREATE TABLE IF NOT EXISTS `ola_acc_stmt_share_earnings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `for_transaction_day` bigint(20) NOT NULL,
  `payment_post_day` bigint(20) NOT NULL,
  `txn_day` varchar(512) NOT NULL,
  `post_day` varchar(512) NOT NULL,
  `type` varchar(255) NOT NULL,
  `car_number` varchar(255) NOT NULL,
  `amount` double NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `import_id` int(11) NOT NULL,
  `ref_id` text NOT NULL,
  `malformed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_exports`
--

CREATE TABLE IF NOT EXISTS `ola_exports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `period_start` bigint(20) NOT NULL,
  `period_end` bigint(20) NOT NULL,
  `type` varchar(255) NOT NULL,
  `run_results` longblob NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ola_imports`
--

CREATE TABLE IF NOT EXISTS `ola_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  `period_start` bigint(20) NOT NULL,
  `period_end` bigint(20) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `document_section` varchar(255) NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=6 ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
