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
