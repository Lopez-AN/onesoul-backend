CREATE TABLE `CalConnections` (
	`CalUserID` INT(10) NOT NULL COMMENT 'ID del usuario de cal.com' COLLATE 'utf8mb4_spanish_ci',
	`UserID` INT(10) UNSIGNED NOT NULL COMMENT 'Id del usuario de OneSoul',
	`Slug` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`SchedulingUrl` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`TimeZone` VARCHAR(50) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`AccessToken` TEXT NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`RefreshToken` TEXT NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`TokenExpiresAt` BIGINT(20) NOT NULL,
	`Webhook` CHAR(40) NULL DEFAULT NULL COLLATE 'utf8mb4_spanish_ci',
	PRIMARY KEY (`CalUserID`) USING BTREE,
	UNIQUE INDEX `UK_CAL_CONNECTIONS` (`UserID`) USING BTREE,
	CONSTRAINT `FK_CAL_CONNECTIONS` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE
)
COLLATE='utf8mb4_spanish_ci'
ENGINE=InnoDB
;


