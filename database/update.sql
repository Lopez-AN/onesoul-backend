ALTER TABLE `NotificationsTemplate`
	DROP COLUMN `Status`;

ALTER TABLE `NotificationsDelivery`
	ADD COLUMN `LastAttemptAt` TIMESTAMP NULL DEFAULT NULL AFTER `SentAt`;

ALTER TABLE `NotificationsEventType`
	DROP COLUMN `DefaultPriority`;

DROP TABLE `NotificationJobs`;

ALTER TABLE `NotificationsDelivery`
	CHANGE COLUMN `Status` `Status` ENUM('Queued','Requeued','Processing','Sent','Failed') NOT NULL DEFAULT 'Queued' COLLATE 'utf8mb4_unicode_ci' AFTER `ProviderMessageID`;
	ADD COLUMN `NextAttemptAt` TIMESTAMP NULL DEFAULT NULL COMMENT 'En caso de reintentos, cuando se reintentara este envio' AFTER `LastAttemptAt`;

ALTER TABLE `NotificationsTemplate`
	CHANGE COLUMN `Status` `Status` ENUM('Draft','Active','Archived') NOT NULL DEFAULT 'Active' COLLATE 'utf8mb4_unicode_ci' AFTER `Version`;

ALTER TABLE `NotificationsEventChannel`
	CHANGE COLUMN `DailyCap` `MaxAttemps` INT(11) NULL DEFAULT NULL AFTER `FallbackAfterSeconds`;

ALTER TABLE `Notifications`
	DROP COLUMN `Status`;

