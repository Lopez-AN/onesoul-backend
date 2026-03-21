-- --------------------------------------------------------
-- Host:                         testing.cpcvuoast9uv.us-east-1.rds.amazonaws.com
-- Server version:               10.6.22-MariaDB-log - managed by https://aws.amazon.com/rds/
-- Server OS:                    Linux
-- HeidiSQL Version:             12.7.0.6850
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Data exporting was unselected.

-- Migration: Subscription payment rejection logs
CREATE TABLE IF NOT EXISTS `SubscriptionPaymentRejections` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Internal identifier',
  `UserID` int(10) unsigned NOT NULL COMMENT 'User related to the rejected payment',
  `PlatformSubscriptionID` varchar(128) DEFAULT NULL COMMENT 'Subscription identifier in payment platform',
  `PlatformCustomerID` varchar(128) DEFAULT NULL COMMENT 'Customer identifier in payment platform',
  `NewPlanID` smallint(5) unsigned DEFAULT NULL COMMENT 'Target plan that was requested',
  `PaymentPlatform` varchar(50) NOT NULL DEFAULT 'STRIPE' COMMENT 'Payment platform name',
  `InvoiceID` varchar(128) DEFAULT NULL COMMENT 'Invoice identifier in payment platform',
  `PaymentIntentID` varchar(128) DEFAULT NULL COMMENT 'Payment intent identifier in payment platform',
  `PaymentIntentStatus` varchar(50) DEFAULT NULL COMMENT 'Payment intent status at rejection time',
  `RejectionCode` varchar(100) NOT NULL COMMENT 'Internal rejection code',
  `RejectionReason` text DEFAULT NULL COMMENT 'Human-readable rejection reason',
  `ContextJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional structured context' CHECK (json_valid(`ContextJSON`)),
  `CreatedAt` timestamp NULL DEFAULT current_timestamp() COMMENT 'Creation timestamp',
  PRIMARY KEY (`ID`),
  KEY `IDX_SubscriptionPaymentRejections_UserID` (`UserID`),
  KEY `IDX_SubscriptionPaymentRejections_Subscription` (`PlatformSubscriptionID`),
  KEY `IDX_SubscriptionPaymentRejections_Code` (`RejectionCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores subscription payment rejections for audit and troubleshooting.';

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
