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

-- Migracion de locations
CREATE TABLE `UserLocations` (
    `LocationID` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `UserID` INT(10) UNSIGNED NOT NULL,
    `LocationType` ENUM('Personal', 'Service') NOT NULL DEFAULT 'Service'
        COMMENT 'Tipo: Personal (residencia) o Service (donde ofrece servicios)',
    `IsPrimary` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Ubicación principal',
    `LocationName` VARCHAR(100) NULL
        COMMENT 'Nombre descriptivo (ej: "Oficina Centro", "Domicilio")',
    `AddressName` VARCHAR(255) NULL,
    `AddressNumber` SMALLINT(6) NULL,
    `Floor` VARCHAR(4) NULL,
    `Department` VARCHAR(4) NULL,
    `Cp` VARCHAR(10) NULL,
    `City` VARCHAR(60) NULL,
    `State` VARCHAR(50) NULL,
    `CountryCode` VARCHAR(2) NULL,
    `IsActive` TINYINT(1) DEFAULT 1,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`LocationID`),
    INDEX `idx_user` (`UserID`),
    INDEX `idx_user_type` (`UserID`, `LocationType`),
    INDEX `idx_user_primary` (`UserID`, `IsPrimary`),
    FOREIGN KEY (`UserID`) REFERENCES `Users`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ubicaciones de usuarios';

INSERT INTO UserLocations (UserID, LocationType, IsPrimary, AddressName, AddressNumber, Floor, Department, Cp, City, State, CountryCode)
SELECT
    UserID,
    'Service',
    1,
    AddressName,
    AddressNumber,
    Floor,
    Department,
    Cp,
    City,
    State,
    CountryCode
FROM Users
WHERE (AddressName IS NOT NULL OR City IS NOT NULL) AND UserType = 'Guide';

INSERT INTO UserLocations (UserID, LocationType, IsPrimary, AddressName, AddressNumber, Floor, Department, Cp, City, State, CountryCode)
SELECT
    UserID,
    'Personal',
    1,
    AddressName,
    AddressNumber,
    Floor,
    Department,
    Cp,
    City,
    State,
    CountryCode
FROM Users
WHERE (AddressName IS NOT NULL OR City IS NOT NULL) AND UserType = 'Seeker';

ALTER TABLE `Users`
	DROP COLUMN `AddressName`,
	DROP COLUMN `AddressNumber`,
	DROP COLUMN `Floor`,
	DROP COLUMN `Department`,
	DROP COLUMN `Cp`,
	DROP COLUMN `City`,
	DROP COLUMN `State`,
	DROP COLUMN `CountryCode`;
