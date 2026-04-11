ALTER TABLE `Offerings`
	CHANGE COLUMN `Status` `Status` ENUM('Active','Inactive','Deleted','Draft') NULL DEFAULT NULL COMMENT 'Estado del servicio' COLLATE 'utf8mb4_unicode_ci' AFTER `UserID`;
