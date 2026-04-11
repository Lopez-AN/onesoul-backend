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
  KEY `IDX_SubscriptionPaymentRejections_Code` (`RejectionCode`),
  CONSTRAINT `FK_SubscriptionPaymentRejections_User` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores subscription payment rejections for audit and troubleshooting.';

ALTER TABLE `Referrals`
	CHANGE COLUMN `ReferredUserID` `ReferredUserID` INT(10) UNSIGNED NOT NULL COMMENT 'Identificador del usuario referido; se llena cuando el registro se completa.' AFTER `UserID`,
	ADD CONSTRAINT `FK_Referrals_Users` FOREIGN KEY (`ReferredUserID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE `Bookings`
	CHANGE COLUMN `FeedbackStatus` `FeedbackStatus` ENUM('pending','submitted','expired') NULL DEFAULT NULL COMMENT 'Estado de la reseña.' COLLATE 'utf8mb4_unicode_ci' AFTER `ModificationDate`,
	CHANGE COLUMN `LastBookingEvent` `LastBookingEvent` ENUM('pending','rescheduled','modified','canceled','confirmed','completed','rated') NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci' AFTER `FeedbackStatus`;
