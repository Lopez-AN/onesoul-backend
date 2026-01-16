ALTER TABLE `UsersLocations`
	ADD CONSTRAINT `FK_UsersLocations_Countries` FOREIGN KEY (`CountryCode`) REFERENCES `Countries` (`CountryCode`) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE `OfferingsLocations`
 	DROP FOREIGN KEY IF EXISTS `FK_OfferingsLocations_UsersLocations`;
ALTER TABLE `OfferingsLocations`
	ADD CONSTRAINT `FK_OfferingsLocations_UsersLocations` FOREIGN KEY (`UserID`, `LocationID`) REFERENCES `UsersLocations` (`UserID`, `LocationID`) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE `OfferingsLocations`
	ADD CONSTRAINT `FK_OfferingsLocations_soul.Offerings` FOREIGN KEY (`OfferingID`) REFERENCES `soul`.`Offerings` (`OfferingID`) ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE `Users`
	CHANGE COLUMN `ValidatedPhone` `ValidatedPhone` TINYINT(1) NULL DEFAULT '0' COMMENT 'Celular validado.' AFTER `ValidatedEmail`;

CREATE TABLE `LandingContacts` (
	`ID` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`Date` DATETIME NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora del contacto',
	`Name` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`Email` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`SocialNetwork` VARCHAR(100) NOT NULL COMMENT 'Red social del guía' COLLATE 'utf8mb4_unicode_ci',
	`CountryCode` CHAR(2) NOT NULL COMMENT 'Código del país del guia' COLLATE 'utf8mb4_unicode_ci',
	`City` VARCHAR(60) NOT NULL COMMENT 'Ciudad o localidad' COLLATE 'utf8mb4_unicode_ci',
	`Specialization` VARCHAR(50) NOT NULL COMMENT 'Especialización del guía' COLLATE 'utf8mb4_unicode_ci',
	`Browser` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nombre del navegador' COLLATE 'utf8mb4_unicode_ci',
	`BrowserVersion` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Versión del navegador' COLLATE 'utf8mb4_unicode_ci',
	`Os` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Sistema operativo utilizado' COLLATE 'utf8mb4_unicode_ci',
	`Device` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Tipo de dispositivo' COLLATE 'utf8mb4_unicode_ci',
	`IP` VARCHAR(45) NULL DEFAULT NULL COMMENT 'Dirección IP del dispositivo desde el cual se realizó el contacto' COLLATE 'utf8mb4_unicode_ci',
	PRIMARY KEY (`ID`) USING BTREE
)
COMMENT='Tabla para guardar los datos de quienes contactan con el formulario de la landing page'
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;

DROP TABLE `InAppNotification`;

UPDATE Offerings SET Approved = 1, ApprovalDate = NOW() WHERE STATUS = 'Active';
UPDATE Offerings SET Status = 'Active', Approved = 0, ApprovalDate = null WHERE STATUS = 'Pending';

ALTER TABLE `Offerings`
	CHANGE COLUMN `Status` `Status` ENUM('Active','Inactive','Deleted') NULL DEFAULT NULL COMMENT 'Estado del servicio' COLLATE 'utf8mb4_unicode_ci' AFTER `UserID`;

ALTER TABLE `Offerings`
	DROP COLUMN `IsActive`;
