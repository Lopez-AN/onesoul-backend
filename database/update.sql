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
CREATE TABLE `UsersLocations` (
	`LocationID` INT(10) UNSIGNED NOT NULL,
	`UserID` INT(10) UNSIGNED NOT NULL,
	`LocationName` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Nombre descriptivo (ej: "Oficina Centro", "Domicilio")' COLLATE 'utf8mb4_unicode_ci',
	`AddressName` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`AddressNumber` SMALLINT(6) NULL DEFAULT NULL,
	`Floor` VARCHAR(4) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`Department` VARCHAR(4) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`Cp` VARCHAR(10) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`City` VARCHAR(60) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`State` VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`CountryCode` VARCHAR(2) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`IsActive` TINYINT(1) NULL DEFAULT '1',
	`CreatedAt` DATETIME NULL DEFAULT current_timestamp(),
	PRIMARY KEY (`LocationID`, `UserID`) USING BTREE,
	INDEX `FK_UsersLocations_Users` (`UserID`) USING BTREE,
	CONSTRAINT `FK_UsersLocations_Users` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE
)
COMMENT='Ubicaciones de usuarios'
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;

INSERT INTO UsersLocations (LocationID, UserID, LocationName, AddressName, AddressNumber, Floor, Department, Cp, City, State, CountryCode)
SELECT
    0,
    UserID,
    'Primaria',
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

INSERT INTO UsersLocations (LocationID, UserID, LocationName, AddressName, AddressNumber, Floor, Department, Cp, City, State, CountryCode)
SELECT
    0,
    UserID,
    'Primaria',
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


ALTER TABLE `Bookings`
	DROP FOREIGN KEY `FK_LocationID`;

DROP TABLE `OfferingLocations`;

CREATE TABLE `OfferingsLocations` (
	`Id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`UserID` INT(10) UNSIGNED NOT NULL,
	`LocationID` INT(10) UNSIGNED NOT NULL,
	`OfferingID` INT(10) UNSIGNED NOT NULL,
	PRIMARY KEY (`Id`) USING BTREE,
	UNIQUE INDEX `UK_OFFERINGS_LOCATIONS` (`UserID`, `LocationID`, `OfferingID`) USING BTREE,
	CONSTRAINT `FK_OfferingsLocations_UsersLocations` FOREIGN KEY (`UserID`, `LocationID`) REFERENCES `UsersLocations` (`LocationID`, `UserID`) ON UPDATE RESTRICT ON DELETE RESTRICT
)
COMMENT='Guarda las ubicaciones disponibles para el servicio, debe existir en las ubicaciones del guía que lo cargo'
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;

ALTER TABLE `UsersNotifications`
	CHANGE COLUMN `WebPush` `PushWeb` TINYINT(1) NULL DEFAULT '1'  COMMENT 'Notificaciones vía Web Push (navegador)' AFTER `SMS`;

ALTER TABLE `UsersNotifications`
	CHANGE COLUMN `SMS` `Sms` TINYINT(1) NULL DEFAULT '1' COMMENT 'Notificaciones por SMS' AFTER `WhatsApp`;

RENAME TABLE `UserSettings` TO `UsersSettings`;
RENAME TABLE `UserBrowser` TO `UsersBrowser`;
RENAME TABLE `UserLegalConsents` TO `UsersLegalConsents`;

ALTER TABLE `UsersSettings`
	CHANGE COLUMN `PreferredLanguage` `Locale` VARCHAR(5) NULL DEFAULT 'es' COMMENT 'Idioma preferido en código I18N' COLLATE 'utf8mb4_unicode_ci' AFTER `UserID`;
