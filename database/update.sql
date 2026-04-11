ALTER TABLE `Offerings`
	CHANGE COLUMN `Status` `Status` ENUM('Active','Inactive','Deleted','Draft') NULL DEFAULT NULL COMMENT 'Estado del servicio' COLLATE 'utf8mb4_unicode_ci' AFTER `UserID`;

DELETE FROM `UsersLanguages`;

ALTER TABLE `UsersLanguages`
	CHANGE COLUMN `FluencyLevel` `FluencyLevel` ENUM('Basic','Intermediate','Advanced','Native') NULL DEFAULT NULL COMMENT 'Nivel de fluidez del guía en cada idioma' COLLATE 'utf8mb4_unicode_ci' AFTER `LanguageID`;


DELETE FROM `DonationVouchers`;
ALTER TABLE `DonationVouchers`
	CHANGE COLUMN `Status` `Status` ENUM('Pending','Assigned','Redeemed','Canceled','Expired') NOT NULL DEFAULT 'Pending' COMMENT 'Estado de la donación' COLLATE 'utf8mb4_unicode_ci' AFTER `RedeemCodeMasked`;
