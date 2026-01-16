ALTER TABLE `Countries`
	ADD COLUMN `CurrencyCode` CHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Moneda asociada al país' AFTER `CountryCode`,
	CHANGE COLUMN `IsActive` `IsActive` TINYINT(1) NOT NULL DEFAULT '1' COMMENT 'Indica si el país está activo.' AFTER `CountryName`,
	ADD CONSTRAINT `FK_Countries_Currencies` FOREIGN KEY (`CurrencyCode`) REFERENCES `Currencies` (`CurrencyCode`) ON UPDATE NO ACTION ON DELETE NO ACTION;

CREATE TABLE `CurrencyExchangeRates` (
	`Date` DATE NOT NULL DEFAULT curdate() COMMENT 'Fecha cotización',
	`BaseCurrency` CHAR(3) NOT NULL COMMENT 'Moneda base' COLLATE 'utf8mb4_unicode_ci',
	`QuoteCurrency` CHAR(3) NOT NULL COMMENT 'Moneda a convertir' COLLATE 'utf8mb4_unicode_ci',
	`ExchangeRate` FLOAT UNSIGNED NOT NULL COMMENT 'Tasa de cambio',
	PRIMARY KEY (`Date`, `BaseCurrency`, `QuoteCurrency`) USING BTREE,
	INDEX `FK_CurrencyExchangeRates_Currencies1` (`QuoteCurrency`) USING BTREE,
	INDEX `FK_CurrencyExchangeRates_Currencies2` (`BaseCurrency`) USING BTREE,
	CONSTRAINT `FK_CurrencyExchangeRates_Currencies1` FOREIGN KEY (`QuoteCurrency`) REFERENCES `Currencies` (`CurrencyCode`) ON UPDATE CASCADE ON DELETE CASCADE,
	CONSTRAINT `FK_CurrencyExchangeRates_Currencies2` FOREIGN KEY (`BaseCurrency`) REFERENCES `Currencies` (`CurrencyCode`) ON UPDATE CASCADE ON DELETE CASCADE
)
COMMENT='Guarda las cotizaciones de las monedas entre si'
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
;

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
