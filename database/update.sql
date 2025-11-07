DROP TABLE `CalendlyConnections`;
DROP TABLE `CalendlyWebhooks`;

CREATE TABLE `CalConnections` (
	`CalUserID` INT(10) NOT NULL COMMENT 'ID del usuario de cal.com',
	`UserID` INT(10) UNSIGNED NOT NULL COMMENT 'Id del usuario de OneSoul',
	`Slug` VARCHAR(100) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`SchedulingUrl` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`TimeZone` VARCHAR(50) NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`AccessToken` TEXT NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`RefreshToken` TEXT NOT NULL COLLATE 'utf8mb4_spanish_ci',
	`Webhook` CHAR(40) NULL DEFAULT NULL COLLATE 'utf8mb4_spanish_ci',
	PRIMARY KEY (`CalUserID`) USING BTREE,
	UNIQUE INDEX `UK_CAL_CONNECTIONS` (`UserID`) USING BTREE,
	CONSTRAINT `FK_CAL_CONNECTIONS` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE
)
COLLATE='utf8mb4_spanish_ci'
ENGINE=InnoDB
;

CREATE TABLE `CalWebhooks` (
	`Uid` CHAR(40) NOT NULL COMMENT 'uid de cal.com - identificador único de la cita' COLLATE 'utf8mb4_spanish_ci',
	`GuideID` INT(10) UNSIGNED NOT NULL COMMENT 'Id del guía de OneSoul (organizador)',
	`SeekerID` INT(10) UNSIGNED NOT NULL COMMENT 'Id del buscador de OneSoul (asistente)',
	`CalUserID` CHAR(40) NOT NULL COMMENT 'Id del usuario organizador en cal.com' COLLATE 'utf8mb4_spanish_ci',
	`AssocUUID` CHAR(40) NULL DEFAULT NULL COMMENT 'UUID generado en el cliente para asociar cita cal.com con booking que se crea a posteriori' COLLATE 'utf8mb4_spanish_ci',
	`OfferingID` INT(11) NOT NULL COMMENT 'Id del servicio/publicación asociado',
	`BookingID` INT(11) NULL DEFAULT NULL COMMENT 'Id de la reserva en OneSoul (se puede grabar a posteriori o quedar null)',
	`RescheduleUrl` VARCHAR(255) NOT NULL COMMENT 'URL para reprogramar la cita (https://cal.com/reschedule/{uid})' COLLATE 'utf8mb4_spanish_ci',
	`CancelUrl` VARCHAR(255) NOT NULL COMMENT 'URL para cancelar la cita (https://cal.com/cancel/{uid})' COLLATE 'utf8mb4_spanish_ci',
	`EventTitle` VARCHAR(50) NOT NULL COMMENT 'Título del evento/servicio' COLLATE 'utf8mb4_spanish_ci',
	`EventComment` TEXT NULL DEFAULT NULL COMMENT 'Notas o comentarios adicionales del buscador' COLLATE 'utf8mb4_spanish_ci',
	`Email` VARCHAR(255) NOT NULL COMMENT 'Email del buscador ingresado en la cita' COLLATE 'utf8mb4_spanish_ci',
	`CreatedAt` DATETIME(6) NOT NULL COMMENT 'Fecha y hora de creación de la cita en cal.com',
	`StartTime` DATETIME(6) NOT NULL COMMENT 'Fecha y hora de inicio de la cita (UTC)',
	`EndTime` DATETIME(6) NOT NULL COMMENT 'Fecha y hora de fin de la cita (UTC)',
	`Length` INT(5) NOT NULL COMMENT 'Duración de la cita en minutos',
	`TimeZone` VARCHAR(100) NOT NULL COMMENT 'Zona horaria del buscador (ej: America/Buenos_Aires)' COLLATE 'utf8mb4_spanish_ci',
	PRIMARY KEY (`Uid`) USING BTREE,
	INDEX `FK_CALWEBHOOKS_GUIDEID` (`GuideID`) USING BTREE,
	INDEX `FK_CALWEBHOOKS_SEEKERID` (`SeekerID`) USING BTREE,
	CONSTRAINT `FK_CALWEBHOOKS_GUIDEID` FOREIGN KEY (`GuideID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE,
	CONSTRAINT `FK_CALWEBHOOKS_SEEKERID` FOREIGN KEY (`SeekerID`) REFERENCES `Users` (`UserID`) ON UPDATE CASCADE ON DELETE CASCADE
)
COLLATE='utf8mb4_spanish_ci'
ENGINE=InnoDB
;
