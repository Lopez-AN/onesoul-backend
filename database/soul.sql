-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               10.11.13-MariaDB-log - managed by https://aws.amazon.com/rds/
-- Server OS:                    Linux
-- HeidiSQL Version:             12.7.0.6850
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for soul
CREATE DATABASE IF NOT EXISTS `soul` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `soul`;

-- Dumping structure for table soul.ActivePaymentMethods
CREATE TABLE IF NOT EXISTS `ActivePaymentMethods` (
  `ID` smallint(5) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Índice',
  `Country` varchar(3) DEFAULT NULL COMMENT 'El código de país en formato ISO 3166-1 alfa-2',
  `PaymentMethodID` tinyint(3) unsigned DEFAULT NULL COMMENT 'Identificador del método de pago',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Un indicador para determinar si el método de pago está activo.',
  PRIMARY KEY (`ID`),
  CONSTRAINT `ActivePaymentMethods_ibfk_2` FOREIGN KEY (`PaymentMethodID`) REFERENCES `PaymentMethods` (`PaymentMethodID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los métodos de pago disponibles en cada país.';

-- Data exporting was unselected.

-- Dumping structure for table soul.AdminUsers
CREATE TABLE IF NOT EXISTS `AdminUsers` (
  `AdminID` smallint(5) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del administrador.',
  `UserName` varchar(50) NOT NULL COMMENT 'Nombre de usuario del administrador.',
  `PasswordHash` varchar(255) NOT NULL COMMENT 'Hash de la contraseña del administrador.',
  `Role` enum('Root','SuperAdmin','Admin','Moderator') DEFAULT NULL COMMENT 'Rol del administrador (por ejemplo, "SuperAdmin", "Moderator").',
  `Permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Conjunto de permisos asociados con el rol o el administrador específico.' CHECK (json_valid(`Permissions`)),
  `Email` varchar(255) NOT NULL COMMENT 'Dirección de correo electrónico del administrador. Formato de correo electrónico válido.',
  `Phone` varchar(20) DEFAULT NULL COMMENT 'Número de teléfono del administrador. Formato de número de teléfono válido.',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Indica si el administrador esta activo',
  `TwoFactorEnabled` enum('true','false') DEFAULT NULL COMMENT 'Campo booleano para indicar si el administrador tiene habilitada la autenticación de dos factores.',
  `TwoFactorSecret` varchar(255) DEFAULT NULL COMMENT 'El secreto utilizado para generar códigos de autenticación (comúnmente para autenticadores de aplicaciones como Google Authenticator). Puede ser un valor encriptado.',
  `LastLogin` timestamp NULL DEFAULT NULL COMMENT 'Última fecha y hora de inicio de sesión.',
  PRIMARY KEY (`AdminID`),
  UNIQUE KEY `Index_Username` (`UserName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los administradores de la plataforma.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Agencies
CREATE TABLE IF NOT EXISTS `Agencies` (
  `AgencyID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) DEFAULT NULL,
  `ContactEmail` varchar(100) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL COMMENT 'Número de teléfono de la agencia (formato válido según el país)',
  PRIMARY KEY (`AgencyID`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agencias de marketing';

-- Data exporting was unselected.

-- Dumping structure for procedure soul.AssignReferralRewards
DELIMITER //
CREATE PROCEDURE `AssignReferralRewards`()
BEGIN
    -- Crear los beneficios para los usuarios que cumplan la condición
    INSERT INTO ReferralRewards (UserID, RewardType, RewardAmount)
    SELECT UserID, 'SubscriptionMonth', 1
    FROM (
        SELECT UserID
        FROM Referrals
        WHERE ReferralStatus = 'Successful'
        GROUP BY UserID
        HAVING COUNT(*) >= 5
    ) AS eligible_users;

    -- Actualizar el campo ReferralStatus para los referidos usados en los beneficios
    UPDATE Referrals
    SET ReferralStatus = 'Redeemed',
    UpdatedAt = NOW()
    WHERE ReferralStatus = 'Successful'
    AND UserID IN (
        SELECT UserID
        FROM Referrals
        WHERE ReferralStatus = 'Successful'
        GROUP BY UserID
        HAVING COUNT(*) >= 5
    )
    LIMIT 5; -- Aseguramos que solo se marquen los primeros 5 por usuario
END//
DELIMITER ;

-- Dumping structure for table soul.AuditLogs
CREATE TABLE IF NOT EXISTS `AuditLogs` (
  `AuditID` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del registro de auditoría. Este campo es la clave primaria de la tabla.',
  `TableName` varchar(50) NOT NULL COMMENT 'El nombre de la tabla donde se realizó el cambio.',
  `RecordID` int(10) unsigned DEFAULT NULL COMMENT 'El identificador del registro afectado.',
  `Action` enum('INSERT','UPDATE','DELETE') DEFAULT NULL COMMENT 'Tipo de acción realizada. "INSERT", "UPDATE", "DELETE".',
  `ChangedBy` varchar(255) DEFAULT NULL,
  `ChangeDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha y hora en que se realizó el cambio.',
  `OldValue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Una representación de los datos antes del cambio, generalmente almacenada como JSON.' CHECK (json_valid(`OldValue`)),
  `NewValue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Una representación de los datos después del cambio, también como JSON.' CHECK (json_valid(`NewValue`)),
  `Reason` text DEFAULT NULL COMMENT 'Una descripción del motivo del cambio, si está disponible.',
  `RecordCode` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`AuditID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registra cambios realizados en la plataforma para auditoría.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Bookings
CREATE TABLE IF NOT EXISTS `Bookings` (
  `BookingID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la reserva',
  `PublicID` varchar(25) NOT NULL COMMENT 'Identificador público para la reserva.',
  `OfferingID` int(10) unsigned DEFAULT NULL COMMENT 'ID del servicio/producto asociado.',
  `UserID` int(10) unsigned DEFAULT NULL COMMENT 'ID del usuario buscador asociado. Debe existir en la tabla Users',
  `ReviewID` int(10) unsigned DEFAULT NULL COMMENT 'ID del comentario asociado',
  `PaymentID` int(10) unsigned DEFAULT NULL COMMENT 'ID del pago asociado. Debe existir en la tabla Payments',
  `Mode` enum('virtual','in-person') NOT NULL DEFAULT 'virtual' COMMENT 'Indica si el servicio es virtual o presencial',
  `LocationID` int(10) unsigned DEFAULT NULL COMMENT 'Ubicación del servicio presencial, si aplica',
  `CreationDate` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora en que se genero la reserva',
  `ScheduledDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha y hora programada para la reserva',
  `ModificationDate` timestamp NULL DEFAULT NULL COMMENT 'Última modificación de la reserva (para más información ver en tabla BookingStatus).',
  `FeedbackStatus` enum('Pending','Submitted','Expired') DEFAULT NULL COMMENT 'Estado de la reseña.',
  `LastBookingEvent` enum('Pending','Rescheduled','Modified','Canceled','Confirmed','Completed','Rated') DEFAULT NULL,
  `VoucherID` bigint(20) unsigned DEFAULT NULL,
  `Currency` char(3) DEFAULT NULL COMMENT 'Moneda de la reserva',
  `Amount` float DEFAULT NULL COMMENT 'Monto de la reserva',
  PRIMARY KEY (`BookingID`),
  KEY `FK_OfferingID` (`OfferingID`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_ReviewID` (`ReviewID`),
  KEY `FK_LocationID` (`LocationID`),
  KEY `FK_Bookings_DonationVouchers` (`VoucherID`),
  CONSTRAINT `Bookings_ibfk_1` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`),
  CONSTRAINT `Bookings_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `Bookings_ibfk_3` FOREIGN KEY (`ReviewID`) REFERENCES `Reviews` (`ReviewID`),
  CONSTRAINT `FK_Bookings_DonationVouchers` FOREIGN KEY (`VoucherID`) REFERENCES `DonationVouchers` (`VoucherID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contiene información sobre las reservas que los buscadores hacen para servicios ofrecidos por guías.';

-- Data exporting was unselected.

-- Dumping structure for table soul.BookingStatus
CREATE TABLE IF NOT EXISTS `BookingStatus` (
  `StatusID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID único de cada reprogramación de la  reserva.',
  `BookingID` int(10) unsigned NOT NULL COMMENT 'Identificador único de la reserva. Debe existir en la tabla Bookings',
  `BookingEventDate` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha cuando ocurrió el evento',
  `BookingEvent` enum('Pending','Rescheduled','Modified','Canceled','Confirmed','Completed','Rated') DEFAULT NULL COMMENT 'Indica el tipo de registro (creación, reprogramación, cancelación, etc.)',
  `ScheduledDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha de la reserva en cada estado.',
  `Message` mediumtext DEFAULT NULL COMMENT 'Mensaje del usuario que solicita una reserva y/o mensaje del guía en una reprogramación.	',
  PRIMARY KEY (`StatusID`),
  KEY `Index_BookingID` (`BookingID`),
  CONSTRAINT `BookingStatus_ibfk_1` FOREIGN KEY (`BookingID`) REFERENCES `Bookings` (`BookingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rastrea el estado de las reservas, como cancelaciones, reprogramaciones, etc.';

-- Data exporting was unselected.

-- Dumping structure for table soul.CalConnections
CREATE TABLE IF NOT EXISTS `CalConnections` (
  `CalUserID` int(10) NOT NULL COMMENT 'ID del usuario de cal.com',
  `UserID` int(10) unsigned NOT NULL COMMENT 'Id del usuario de OneSoul',
  `Slug` varchar(100) NOT NULL,
  `SchedulingUrl` varchar(255) NOT NULL,
  `TimeZone` varchar(50) NOT NULL,
  `AccessToken` text NOT NULL,
  `RefreshToken` text NOT NULL,
  `Webhook` char(40) DEFAULT NULL,
  PRIMARY KEY (`CalUserID`) USING BTREE,
  UNIQUE KEY `UK_CAL_CONNECTIONS` (`UserID`) USING BTREE,
  CONSTRAINT `FK_CAL_CONNECTIONS` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.CalWebhooks
CREATE TABLE IF NOT EXISTS `CalWebhooks` (
  `Uid` char(40) NOT NULL COMMENT 'uid de cal.com - identificador único de la cita',
  `GuideID` int(10) unsigned NOT NULL COMMENT 'Id del guía de OneSoul (organizador)',
  `SeekerID` int(10) unsigned NOT NULL COMMENT 'Id del buscador de OneSoul (asistente)',
  `Event` enum('BOOKING_CREATED','BOOKING_RESCHEDULED','BOOKING_CANCELED') NOT NULL COMMENT 'Tipo de evento',
  `CalUserID` char(40) NOT NULL COMMENT 'Id del usuario organizador en cal.com',
  `AssocUUID` char(40) DEFAULT NULL COMMENT 'UUID generado en el cliente para asociar cita cal.com con booking que se crea a posteriori',
  `OfferingID` int(11) NOT NULL COMMENT 'Id del servicio/publicación asociado',
  `BookingID` int(11) DEFAULT NULL COMMENT 'Id de la reserva en OneSoul (se puede grabar a posteriori o quedar null)',
  `RescheduleUrl` varchar(255) NOT NULL COMMENT 'URL para reprogramar la cita (https://cal.com/reschedule/{uid})',
  `CancelUrl` varchar(255) NOT NULL COMMENT 'URL para cancelar la cita (https://cal.com/cancel/{uid})',
  `EventTitle` varchar(255) NOT NULL COMMENT 'Título del evento/servicio',
  `EventComment` text DEFAULT NULL COMMENT 'Notas o comentarios adicionales del buscador',
  `Email` varchar(255) NOT NULL COMMENT 'Email del buscador ingresado en la cita',
  `CreatedAt` datetime(6) NOT NULL COMMENT 'Fecha y hora de creación de la cita en cal.com',
  `StartTime` datetime(6) NOT NULL COMMENT 'Fecha y hora de inicio de la cita (UTC)',
  `EndTime` datetime(6) NOT NULL COMMENT 'Fecha y hora de fin de la cita (UTC)',
  `Length` int(5) NOT NULL COMMENT 'Duración de la cita en minutos',
  `TimeZone` varchar(100) NOT NULL COMMENT 'Zona horaria del buscador (ej: America/Buenos_Aires)',
  PRIMARY KEY (`Uid`) USING BTREE,
  KEY `FK_CALWEBHOOKS_GUIDEID` (`GuideID`) USING BTREE,
  KEY `FK_CALWEBHOOKS_SEEKERID` (`SeekerID`) USING BTREE,
  CONSTRAINT `FK_CALWEBHOOKS_GUIDEID` FOREIGN KEY (`GuideID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_CALWEBHOOKS_SEEKERID` FOREIGN KEY (`SeekerID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.Categories
CREATE TABLE IF NOT EXISTS `Categories` (
  `CategoryID` smallint(5) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la categoría',
  `ParentCategoryID` smallint(5) unsigned DEFAULT NULL COMMENT 'ID de la categoría principal.',
  `Name` varchar(50) DEFAULT NULL COMMENT 'Nombre de la categoría',
  `Description` text DEFAULT NULL COMMENT 'Descripción de la categoría',
  `CreationDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha de creación',
  `ModificationDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha de modificación',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Indicador de si la categoría está activa',
  PRIMARY KEY (`CategoryID`),
  KEY `FK_ParentCategoryID` (`ParentCategoryID`),
  CONSTRAINT `Categories_ibfk_1` FOREIGN KEY (`ParentCategoryID`) REFERENCES `Categories` (`CategoryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define las categorías asociadas con los servicios y productos.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Countries
CREATE TABLE IF NOT EXISTS `Countries` (
  `CountryCode` varchar(2) NOT NULL COMMENT 'Código único de tres letras para el país',
  `CountryName` varchar(50) NOT NULL COMMENT 'Nombre completo del país',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Indica si el país está activo.',
  PRIMARY KEY (`CountryCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena información sobre los países.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Currencies
CREATE TABLE IF NOT EXISTS `Currencies` (
  `CurrencyCode` varchar(3) NOT NULL COMMENT 'Moneda utilizada para el precio del plan. Utilizar código ISO 4217.',
  `CurrencyName` varchar(50) DEFAULT NULL COMMENT 'El nombre completo del país.',
  `Symbol` varchar(3) DEFAULT NULL COMMENT 'El símbolo asociado con la moneda.',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Un indicador para determinar si la moneda está activa.',
  PRIMARY KEY (`CurrencyCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define las monedas utilizadas en la plataforma.';

-- Data exporting was unselected.

-- Dumping structure for procedure soul.DailySuscriptionUpdate
DELIMITER //
CREATE PROCEDURE `DailySuscriptionUpdate`()
BEGIN
  -- 1. Reducir días
  UPDATE Subscriptions
  SET RemainingDays = RemainingDays - 1
  WHERE Status != 'EXPIRED' AND RemainingDays > 0;

  -- 2. Marcar como EXPIRED si estaba cancelada y llegó a 0 días
  UPDATE Subscriptions
  SET Status = 'EXPIRED'
  WHERE Status = 'CANCELED' AND RemainingDays = 0;

  -- 3. Renovar suscripciones activas al llegar a 0 días
  UPDATE Subscriptions
  SET RemainingDays = 30
  WHERE Status = 'ACTIVE' AND RemainingDays = 0;
END//
DELIMITER ;

-- Dumping structure for table soul.DonationVouchers
CREATE TABLE IF NOT EXISTS `DonationVouchers` (
  `VoucherID` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del vale',
  `GuideID` int(10) unsigned NOT NULL COMMENT 'Guía que dona la sesión',
  `OfferingID` int(10) unsigned NOT NULL COMMENT 'Servicio donado (Offerings.OfferingID)',
  `RaffleCode` varchar(20) NOT NULL COMMENT 'Código público para sorteo (único, visible a agencia)',
  `RedeemCode` varchar(15) NOT NULL COMMENT 'Código secreto para canje',
  `RedeemCodeMasked` varchar(15) NOT NULL COMMENT 'Código secreto enmascarado ****-****-XXXX',
  `Status` enum('draft','in_raffle','assigned','redeemed','canceled','expired') NOT NULL DEFAULT 'draft' COMMENT 'Estado de la donación',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Fecha de creación del vale',
  `AssignedAt` timestamp NULL DEFAULT NULL COMMENT 'Fecha en que se asignó al ganador',
  `RedeemedAt` timestamp NULL DEFAULT NULL COMMENT 'Fecha de canje de la sesión',
  `ExpiredAt` timestamp NULL DEFAULT NULL COMMENT 'Fecha de expiración automática',
  `CanceledAt` timestamp NULL DEFAULT NULL COMMENT 'Fecha de cancelación (si aplica)',
  `CanceledBy` int(11) unsigned DEFAULT NULL COMMENT 'ID de usuario que canceló (puede ser el propio Guía o un Admin)',
  `AgencyID` int(10) unsigned DEFAULT NULL COMMENT 'Agencia que realizó el sorteo (si aplica)',
  `RaffleChannel` varchar(50) DEFAULT NULL COMMENT 'Canal del sorteo: Instagram, FB, Email, etc.',
  `WinnerUserID` int(10) unsigned DEFAULT NULL COMMENT 'Usuario buscador que ganó el sorteo',
  `BookingID` int(10) unsigned DEFAULT NULL COMMENT 'Reserva generada al canjear el vale',
  PRIMARY KEY (`VoucherID`) USING BTREE,
  KEY `FK_DonationVouchers_Users` (`GuideID`) USING BTREE,
  KEY `FK_DonationVouchers.Users2` (`WinnerUserID`) USING BTREE,
  KEY `FK_DonationVouchers_Agencies` (`AgencyID`) USING BTREE,
  KEY `FK_DonationVouchers_Bookings` (`BookingID`) USING BTREE,
  KEY `FK_DonationVouchers_Offerings` (`OfferingID`) USING BTREE,
  CONSTRAINT `FK_DonationVouchers.Users2` FOREIGN KEY (`WinnerUserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_DonationVouchers_Agencies` FOREIGN KEY (`AgencyID`) REFERENCES `Agencies` (`AgencyID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_DonationVouchers_Bookings` FOREIGN KEY (`BookingID`) REFERENCES `Bookings` (`BookingID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_DonationVouchers_Offerings` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_DonationVouchers_Users` FOREIGN KEY (`GuideID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vales de donación creados por los Guías';

-- Data exporting was unselected.

-- Dumping structure for table soul.Favorites
CREATE TABLE IF NOT EXISTS `Favorites` (
  `FavoriteID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del favorito.',
  `UserID` int(10) unsigned DEFAULT NULL COMMENT 'ID del usuario que marcó el servicio como favorito.',
  `OfferingID` int(10) unsigned DEFAULT NULL COMMENT 'ID del servicio que se marcó como favorito',
  `DateAdded` timestamp NULL DEFAULT NULL COMMENT 'Fecha en que se agregó a favoritos.',
  PRIMARY KEY (`FavoriteID`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_OfferingID` (`OfferingID`),
  CONSTRAINT `Favorites_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `Favorites_ibfk_2` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los servicios que los usuarios han marcado como favoritos.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Formats
CREATE TABLE IF NOT EXISTS `Formats` (
  `FormatID` tinyint(3) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del formato.',
  `FormatName` enum('Video-call','In-person','online','Retreat') DEFAULT NULL COMMENT 'Nombre del formato.',
  PRIMARY KEY (`FormatID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contiene información sobre los formatos en que se ofrecen los servicios.';

-- Data exporting was unselected.

-- Dumping structure for table soul.InAppNotification
CREATE TABLE IF NOT EXISTS `InAppNotification` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Title` varchar(255) NOT NULL,
  `Body` text DEFAULT NULL,
  `DeepLink` varchar(255) DEFAULT NULL,
  `Priority` int(11) DEFAULT 0,
  `IsRead` tinyint(1) NOT NULL DEFAULT 0,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `ReadAt` timestamp NULL DEFAULT NULL,
  `ExpiresAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `ix_ian_user` (`UserID`,`IsRead`),
  CONSTRAINT `fk_ian_user` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.Languages
CREATE TABLE IF NOT EXISTS `Languages` (
  `LanguageID` varchar(5) DEFAULT NULL COMMENT 'Identificador único del idioma (código I18n)',
  `LanguageName` varchar(30) DEFAULT NULL COMMENT 'Nombre del idioma'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los idiomas.';

-- Data exporting was unselected.

-- Dumping structure for table soul.LegalDocuments
CREATE TABLE IF NOT EXISTS `LegalDocuments` (
  `DocumentID` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID del documento legal.',
  `DocumentType` enum('PrivacyPolicy','TermsAndConditions') NOT NULL COMMENT 'Tipo de documento legal.',
  `Version` varchar(10) NOT NULL COMMENT 'Versión del documento.',
  `ReleaseDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha de lanzamiento del documento legal.',
  `Content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Texto del documento legal.' CHECK (json_valid(`Content`)),
  PRIMARY KEY (`DocumentID`),
  UNIQUE KEY `DocumentType` (`DocumentType`,`Version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.Media
CREATE TABLE IF NOT EXISTS `Media` (
  `MediaID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Title` varchar(255) DEFAULT NULL COMMENT 'Título del archivo multimedia.',
  `Description` text DEFAULT NULL COMMENT 'Descripción del archivo multimedia.',
  `URL` varchar(255) NOT NULL COMMENT 'URL del medio. Formato válido de URL',
  `Path` varchar(255) DEFAULT NULL COMMENT 'Se guarda la ruta de la imagen en el servidor',
  `UserID` int(10) unsigned DEFAULT NULL COMMENT 'ID del usuario asociado.',
  `CategoryID` smallint(5) unsigned DEFAULT NULL COMMENT 'ID de la categoría asociada.',
  `OfferingID` int(10) unsigned DEFAULT NULL COMMENT 'ID del servicio asociado.',
  `ReviewID` int(10) unsigned DEFAULT NULL COMMENT 'ID del comentario asociado.',
  `MediaType` enum('image','video','audio') NOT NULL DEFAULT 'image' COMMENT 'Tipo de archivo multimedia.',
  `Position` int(11) NOT NULL DEFAULT 0 COMMENT 'Posicion de ordenamiento dentro de la entidad',
  PRIMARY KEY (`MediaID`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_CategoryID` (`CategoryID`),
  KEY `FK_OfferingID` (`OfferingID`),
  KEY `FK_ReviewID` (`ReviewID`),
  CONSTRAINT `Media_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `Media_ibfk_2` FOREIGN KEY (`CategoryID`) REFERENCES `Categories` (`CategoryID`),
  CONSTRAINT `Media_ibfk_3` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`),
  CONSTRAINT `Media_ibfk_4` FOREIGN KEY (`ReviewID`) REFERENCES `Reviews` (`ReviewID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena información sobre archivos multimedia asociados con servicios, comentarios, o usuarios.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Messages
CREATE TABLE IF NOT EXISTS `Messages` (
  `MessageID` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del mensaje.',
  `BookingID` int(10) unsigned DEFAULT NULL COMMENT 'ID de la reserva asociado con el mensaje',
  `SenderUserID` int(10) unsigned DEFAULT NULL COMMENT 'ID del usuario que envió el mensaje',
  `ReceiverUserID` int(10) unsigned DEFAULT NULL COMMENT 'ID del usuario que recibió el mensaje',
  `MessageText` text DEFAULT NULL COMMENT 'El contenido del mensaje',
  `SentDate` timestamp NULL DEFAULT NULL COMMENT 'Fecha y hora en que se envió el mensaje',
  PRIMARY KEY (`MessageID`),
  KEY `FK_BookingID` (`BookingID`),
  KEY `FK_SenderUserID` (`SenderUserID`),
  KEY `FK_ReceiverUserID` (`ReceiverUserID`),
  CONSTRAINT `Messages_ibfk_1` FOREIGN KEY (`BookingID`) REFERENCES `Bookings` (`BookingID`),
  CONSTRAINT `Messages_ibfk_2` FOREIGN KEY (`SenderUserID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `Messages_ibfk_3` FOREIGN KEY (`ReceiverUserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena mensajes entre guías y buscadores relacionados con reservas.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Notifications
CREATE TABLE IF NOT EXISTS `Notifications` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `EventTypeID` bigint(20) unsigned NOT NULL,
  `RecipientUserID` int(10) unsigned NOT NULL,
  `Payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`Payload`)),
  `ScheduledAt` timestamp NULL DEFAULT NULL,
  `IdempotencyKey` varchar(120) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `IdempotencyKey` (`IdempotencyKey`),
  KEY `ix_notif_user` (`RecipientUserID`),
  KEY `fk_notif_event` (`EventTypeID`),
  CONSTRAINT `fk_notif_event` FOREIGN KEY (`EventTypeID`) REFERENCES `NotificationsEventType` (`ID`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`RecipientUserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.NotificationsDelivery
CREATE TABLE IF NOT EXISTS `NotificationsDelivery` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `NotificationID` bigint(20) unsigned NOT NULL,
  `Channel` enum('IN_APP','PUSH','EMAIL','SMS','WEB_PUSH','WHATSAPP') NOT NULL,
  `TemplateID` bigint(20) unsigned DEFAULT NULL,
  `RenderedSubject` text DEFAULT NULL,
  `RenderedBody` longtext DEFAULT NULL,
  `ProviderMessageID` varchar(120) DEFAULT NULL,
  `Status` enum('Queued','Requeued','Processing','Sent','Failed') NOT NULL DEFAULT 'Queued',
  `Attempts` int(11) NOT NULL DEFAULT 0,
  `ErrorCode` varchar(64) DEFAULT NULL,
  `SentAt` timestamp NULL DEFAULT NULL,
  `LastAttemptAt` timestamp NULL DEFAULT NULL,
  `NextAttemptAt` timestamp NULL DEFAULT NULL COMMENT 'En caso de reintentos, cuando se reintentara este envio',
  PRIMARY KEY (`ID`),
  KEY `ix_nd_notif` (`NotificationID`),
  KEY `fk_nd_template` (`TemplateID`),
  CONSTRAINT `fk_nd_notif` FOREIGN KEY (`NotificationID`) REFERENCES `Notifications` (`ID`),
  CONSTRAINT `fk_nd_template` FOREIGN KEY (`TemplateID`) REFERENCES `NotificationsTemplate` (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.NotificationsEventChannel
CREATE TABLE IF NOT EXISTS `NotificationsEventChannel` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `EventTypeID` bigint(20) unsigned NOT NULL,
  `Channel` enum('IN_APP','PUSH','EMAIL','SMS','WEB_PUSH','WHATSAPP') NOT NULL,
  `Enabled` tinyint(1) NOT NULL DEFAULT 1,
  `SendOrder` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `IsCritical` tinyint(1) NOT NULL DEFAULT 0,
  `FallbackAfterSeconds` int(11) DEFAULT NULL,
  `MaxAttempts` int(11) DEFAULT NULL,
  `Notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `ux_event_channel` (`EventTypeID`,`Channel`),
  CONSTRAINT `fk_necp_event` FOREIGN KEY (`EventTypeID`) REFERENCES `NotificationsEventType` (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.NotificationsEventType
CREATE TABLE IF NOT EXISTS `NotificationsEventType` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Code` varchar(80) NOT NULL,
  `Description` text DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `code` (`Code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.NotificationsTemplate
CREATE TABLE IF NOT EXISTS `NotificationsTemplate` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `EventTypeID` bigint(20) unsigned NOT NULL,
  `Channel` enum('IN_APP','PUSH','EMAIL','SMS','WEB_PUSH','WHATSAPP') NOT NULL,
  `Locale` varchar(8) NOT NULL DEFAULT 'es',
  `Subject` text DEFAULT NULL,
  `Body` longtext DEFAULT NULL,
  `Version` int(11) NOT NULL DEFAULT 1,
  `Status` enum('Draft','Active','Archived') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`ID`),
  UNIQUE KEY `ux_template` (`EventTypeID`,`Channel`,`Locale`,`Version`),
  CONSTRAINT `fk_template_event` FOREIGN KEY (`EventTypeID`) REFERENCES `NotificationsEventType` (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.Offerings
CREATE TABLE IF NOT EXISTS `Offerings` (
  `OfferingID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del servicio',
  `Title` varchar(255) NOT NULL COMMENT 'Título del servicio',
  `Description` text DEFAULT NULL COMMENT 'Descripción del servicio/producto',
  `CategoryID` smallint(5) unsigned DEFAULT NULL COMMENT 'ID de la categoría asociada.',
  `UserID` int(10) unsigned DEFAULT NULL COMMENT 'ID de usuario del guía asociado.',
  `Status` enum('Active','Inactive','Pending','Deleted') DEFAULT NULL COMMENT 'Estado del servicio',
  `CreationDate` timestamp NULL DEFAULT current_timestamp() COMMENT 'Fecha de creación del servicio',
  `ModificationDate` datetime DEFAULT NULL COMMENT 'Fecha de modificación del servicio',
  `IsActive` tinyint(1) DEFAULT NULL COMMENT 'Indicador de si el servicio está activo',
  `Approved` tinyint(1) DEFAULT NULL COMMENT 'Indica si el servicio fue aprobado por un administrador o moderador.',
  `ApprovalDate` datetime DEFAULT NULL COMMENT 'Fecha de Aprobación',
  `Currency` char(3) DEFAULT NULL COMMENT 'Código moneda',
  `Tags` varchar(255) DEFAULT NULL COMMENT 'Palabras clave o etiquetas que pueden ayudar en la búsqueda (separadas por comas)',
  `SKU` varchar(20) DEFAULT NULL COMMENT 'Número de referencia para productos (stock-keeping unit), útil para productos físicos',
  `Stock` int(10) unsigned DEFAULT NULL COMMENT 'Cantidad disponible (específicamente para productos)',
  `ServiceType` enum('Service','Product') DEFAULT NULL COMMENT 'Indica si es un servicio o un producto',
  `ShortDescription` varchar(255) DEFAULT NULL COMMENT 'Descripción corta de la publicación',
  `Price` float DEFAULT NULL COMMENT 'Precio del servicio',
  `SessionType` enum('in-person','virtual','both') DEFAULT NULL COMMENT 'Modalidad del servicio',
  `Conditions` text DEFAULT NULL COMMENT 'Condiciones del servicio',
  `Duration` int(11) DEFAULT NULL COMMENT 'Duración del servicio en minutos',
  PRIMARY KEY (`OfferingID`),
  KEY `FK_CategoryID` (`CategoryID`),
  KEY `FK_UserID` (`UserID`),
  CONSTRAINT `Offerings_ibfk_1` FOREIGN KEY (`CategoryID`) REFERENCES `Categories` (`CategoryID`),
  CONSTRAINT `Offerings_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contiene información sobre los servicios o productos que los guías ofrecen a los buscadores.';

-- Data exporting was unselected.

-- Dumping structure for table soul.OfferingsFaqs
CREATE TABLE IF NOT EXISTS `OfferingsFaqs` (
  `OfferingID` int(10) unsigned NOT NULL COMMENT 'Id publicacion',
  `Position` int(10) unsigned NOT NULL COMMENT 'Orden de la pregunta',
  `Question` varchar(100) NOT NULL COMMENT 'Pregunta frecuente',
  `Answer` varchar(255) NOT NULL COMMENT 'Respuesta',
  PRIMARY KEY (`OfferingID`,`Position`) USING BTREE,
  CONSTRAINT `FK_offerings_faqs` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.OfferingsLocations
CREATE TABLE IF NOT EXISTS `OfferingsLocations` (
  `Id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `LocationID` int(10) unsigned NOT NULL,
  `OfferingID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`Id`) USING BTREE,
  UNIQUE KEY `UK_OFFERINGS_LOCATIONS` (`UserID`,`LocationID`,`OfferingID`) USING BTREE,
  CONSTRAINT `FK_OfferingsLocations_UsersLocations` FOREIGN KEY (`UserID`, `LocationID`) REFERENCES `UsersLocations` (`LocationID`, `UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda las ubicaciones disponibles para el servicio, debe existir en las ubicaciones del guía que lo cargo';

-- Data exporting was unselected.

-- Dumping structure for table soul.OfferingsPackages
CREATE TABLE IF NOT EXISTS `OfferingsPackages` (
  `OfferingID` int(10) unsigned NOT NULL COMMENT 'Offering asociado',
  `Package` enum('basic','standard','premium') NOT NULL DEFAULT 'standard' COMMENT 'Tipo de paquete',
  `Price` float NOT NULL DEFAULT 0,
  `Description` longtext NOT NULL,
  `Conditions` longtext NOT NULL,
  `SessionType` enum('virtual','in-person','both') NOT NULL,
  PRIMARY KEY (`OfferingID`,`Package`) USING BTREE,
  CONSTRAINT `fk_offerings_packages` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.PaymentMethods
CREATE TABLE IF NOT EXISTS `PaymentMethods` (
  `PaymentMethodID` tinyint(3) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del método de pago.',
  `MethodName` varchar(50) DEFAULT NULL COMMENT 'Nombre del método de pago.',
  PRIMARY KEY (`PaymentMethodID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los métodos de pago disponibles en la plataforma.';

-- Data exporting was unselected.

-- Dumping structure for table soul.PaymentPlatformEvents
CREATE TABLE IF NOT EXISTS `PaymentPlatformEvents` (
  `EventID` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID interno',
  `PaymentPlatform` varchar(50) NOT NULL COMMENT 'Plataforma (Stripe, PayPal, etc.)',
  `EventType` varchar(100) NOT NULL COMMENT 'Tipo de evento (checkout.session.completed, invoice.paid, etc.)',
  `PlatformEventID` varchar(128) NOT NULL COMMENT 'ID del evento en la pasarela',
  `RelatedObjectID` varchar(128) DEFAULT NULL COMMENT 'Objeto principal afectado (ej: SubscriptionID en Stripe)',
  `ReceivedAt` timestamp NULL DEFAULT current_timestamp() COMMENT 'Fecha de recepción del webhook',
  `Processed` tinyint(1) DEFAULT 0 COMMENT 'Si ya fue procesado por el sistema',
  `RawJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Payload completo del evento' CHECK (json_valid(`RawJSON`)),
  PRIMARY KEY (`EventID`),
  UNIQUE KEY `UQ_Event_PlatformID` (`PlatformEventID`,`PaymentPlatform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro bruto de todos los webhooks/eventos recibidos de las pasarelas';

-- Data exporting was unselected.

-- Dumping structure for table soul.PushDevice
CREATE TABLE IF NOT EXISTS `PushDevice` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Provider` enum('FCM','APNS','EXPO') NOT NULL,
  `DeviceToken` varchar(255) NOT NULL,
  `Platform` enum('android','ios','web') NOT NULL,
  `LastSeenAt` timestamp NULL DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `ux_push_token` (`Provider`,`DeviceToken`),
  KEY `ix_push_user` (`UserID`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.Rates
CREATE TABLE IF NOT EXISTS `Rates` (
  `RateID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del servicio',
  `OfferingID` int(10) unsigned DEFAULT NULL COMMENT 'ID del servicio/producto asociado.',
  `RateDesc` varchar(255) DEFAULT NULL COMMENT 'Descripción',
  `Price` decimal(10,2) DEFAULT NULL COMMENT 'Precio',
  `CurrencyCode` varchar(3) DEFAULT NULL COMMENT 'Moneda utilizada para el precio. Utilizar código ISO 4217.',
  `Meassure` enum('unities','liter','grams','hours','days') DEFAULT NULL COMMENT 'Unidad de medida',
  `Quantity` decimal(5,2) DEFAULT NULL COMMENT 'Cantidad',
  PRIMARY KEY (`RateID`),
  KEY `FK_OfferingID` (`OfferingID`),
  CONSTRAINT `Rates_ibfk_1` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena las tarifas asociadas a los servicios o productos ofrecidos.';

-- Data exporting was unselected.

-- Dumping structure for table soul.ReferralRewards
CREATE TABLE IF NOT EXISTS `ReferralRewards` (
  `RewardID` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del beneficio otorgado.',
  `UserID` int(10) unsigned NOT NULL COMMENT 'Identificador del guía que recibió el beneficio.',
  `RewardType` enum('SubscriptionMonth') NOT NULL DEFAULT 'SubscriptionMonth' COMMENT 'Tipo de recompensa otorgada, actualmente solo "mes de suscripción".',
  `RewardAmount` int(11) NOT NULL DEFAULT 1 COMMENT 'Cantidad del beneficio recibido (ej., número de meses gratuitos).',
  `AchievedAt` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora en que se otorgó el beneficio.',
  PRIMARY KEY (`RewardID`),
  KEY `FK_UserID` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla que gestiona los beneficios obtenidos por los referidos exitosos.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Referrals
CREATE TABLE IF NOT EXISTS `Referrals` (
  `ReferralID` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del referido.',
  `UserID` int(10) unsigned NOT NULL COMMENT 'Identificador del guía que generó el referido.',
  `ReferredUserID` int(11) DEFAULT NULL COMMENT 'Identificador del usuario referido; se llena cuando el registro se completa.',
  `ReferralStatus` enum('Pending','Successful','Redeemed') NOT NULL DEFAULT 'Pending' COMMENT 'Estado de la suscripción del referido: pendiente o activo.',
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora en que se creó el registro del referido.',
  `UpdatedAt` datetime DEFAULT NULL COMMENT 'Fecha y hora de la última actualización del registro.',
  PRIMARY KEY (`ReferralID`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_ReferredUserID` (`ReferredUserID`) USING BTREE,
  CONSTRAINT `fk_referrals` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla que registra los intentos de referido, su estado y progreso.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Reviews
CREATE TABLE IF NOT EXISTS `Reviews` (
  `ReviewID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la reseña',
  `CreationDate` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Creación del comentario/reseña.',
  `SeekerID` int(10) unsigned DEFAULT NULL COMMENT 'ID de usuario buscador asociado.',
  `GuideID` int(10) unsigned DEFAULT NULL COMMENT 'ID de usuario guía asociado.',
  `BookingID` int(10) unsigned DEFAULT NULL COMMENT 'ID de la reserva asociada.',
  `OfferingID` int(10) unsigned DEFAULT NULL COMMENT 'ID del ofrecimiento asociado.',
  `ReviewType` enum('guide','service') DEFAULT NULL COMMENT 'Tipo de reseña (Guía o Servicio)',
  `Fulfilled` tinyint(1) NOT NULL COMMENT 'Confirmación del buscador si la reserva se realizo o no. 0 = True 1 = False	',
  `ReviewText` text DEFAULT NULL COMMENT 'Texto de la reseña',
  `Rating` tinyint(3) unsigned DEFAULT NULL COMMENT 'Calificación (del 1 al 5)',
  `Reply` text DEFAULT NULL COMMENT 'Replica de la reseña',
  PRIMARY KEY (`ReviewID`),
  KEY `FK_SeekerID` (`SeekerID`),
  KEY `FK_GuideID` (`GuideID`),
  KEY `FK_BookingID` (`BookingID`),
  KEY `FK_OfferingID` (`OfferingID`),
  CONSTRAINT `Reviews_ibfk_1` FOREIGN KEY (`SeekerID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `Reviews_ibfk_2` FOREIGN KEY (`GuideID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `Reviews_ibfk_3` FOREIGN KEY (`OfferingID`) REFERENCES `Offerings` (`OfferingID`),
  CONSTRAINT `Reviews_ibfk_4` FOREIGN KEY (`BookingID`) REFERENCES `Bookings` (`BookingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda las reseñas y calificaciones dejadas por los usuarios sobre servicios o guías.';

-- Data exporting was unselected.

-- Dumping structure for table soul.SeekerReviews
CREATE TABLE IF NOT EXISTS `SeekerReviews` (
  `SeekerReviewID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la reseña del guía al buscador.',
  `CreationDate` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Creación del comentario/reseña.',
  `SeekerID` int(10) unsigned DEFAULT NULL COMMENT 'ID de usuario buscador asociado.',
  `GuideID` int(10) unsigned DEFAULT NULL COMMENT 'ID de usuario guía asociado.',
  `BookingID` int(10) unsigned DEFAULT NULL COMMENT 'ID de la reserva asociada.',
  `Fulfilled` tinyint(1) NOT NULL COMMENT 'Confirmación del guía si la reserva se realizo o no.\r\n0 = True\r\n1 = False',
  `ReviewText` text DEFAULT NULL COMMENT 'Texto de la reseña.',
  `Rating` tinyint(3) unsigned DEFAULT NULL COMMENT 'Calificación del 1 al 5',
  PRIMARY KEY (`SeekerReviewID`),
  KEY `FK_BookingID` (`BookingID`),
  KEY `FK_SeekerID` (`SeekerID`),
  KEY `FK_GuideID` (`GuideID`),
  CONSTRAINT `SeekerReviews_ibfk_1` FOREIGN KEY (`SeekerID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `SeekerReviews_ibfk_2` FOREIGN KEY (`GuideID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `SeekerReviews_ibfk_3` FOREIGN KEY (`BookingID`) REFERENCES `Bookings` (`BookingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda las reseñas y calificaciones dejadas por los guías sobre los buscadores.';

-- Data exporting was unselected.

-- Dumping structure for table soul.SocialAccounts
CREATE TABLE IF NOT EXISTS `SocialAccounts` (
  `SocialAccountID` tinyint(3) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único de la cuenta de red asociada',
  `UserID` int(10) unsigned DEFAULT NULL COMMENT 'Identificador del usuario asociado.',
  `AgencyID` int(10) unsigned DEFAULT NULL COMMENT 'Identificador de la agencia asociada',
  `SocialAccountTypeID` tinyint(3) unsigned DEFAULT NULL COMMENT 'Identificador del tipo de red asociada.',
  `AccountName` varchar(255) DEFAULT NULL COMMENT 'Nombre de usuario, URL de la cuenta o identificador en la red asociada correspondiente. Este es el campo que almacena la información específica de la cuenta',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Un indicador para determinar si la red asociada está activa.',
  PRIMARY KEY (`SocialAccountID`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_SocialMediaTypeID` (`SocialAccountTypeID`),
  KEY `SocialAccounts_ibfk_3` (`AgencyID`),
  CONSTRAINT `SocialAccounts_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `SocialAccounts_ibfk_2` FOREIGN KEY (`SocialAccountTypeID`) REFERENCES `SocialAccountsTypes` (`SocialAccountTypeID`),
  CONSTRAINT `SocialAccounts_ibfk_3` FOREIGN KEY (`AgencyID`) REFERENCES `Agencies` (`AgencyID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre las cuentas de redes sociales asociadas con cada usuario.';

-- Data exporting was unselected.

-- Dumping structure for table soul.SocialAccountsTypes
CREATE TABLE IF NOT EXISTS `SocialAccountsTypes` (
  `SocialAccountTypeID` tinyint(3) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del tipo de red social',
  `Name` varchar(30) DEFAULT NULL COMMENT 'Nombre de la red social (por ejemplo, "Facebook", "Instagram", "Twitter")',
  `FormatName` varchar(255) DEFAULT NULL COMMENT 'Formato para la creación del link',
  `Description` text DEFAULT NULL COMMENT 'Descripción o detalles adicionales sobre la red social. Puede incluir información sobre el tipo de cuenta que se usa o alguna nota que aclare su uso',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Un indicador para determinar si el tipo de red social está activo.',
  PRIMARY KEY (`SocialAccountTypeID`),
  UNIQUE KEY `FK_Name` (`Name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define los tipos de redes sociales disponibles para vinculación.';

-- Data exporting was unselected.

-- Dumping structure for procedure soul.sp_gen_audit_trigger
DELIMITER //
CREATE PROCEDURE `sp_gen_audit_trigger`(IN `p_action` ENUM('insert','update','delete'), IN `p_schema` VARCHAR(50), IN `p_table` VARCHAR(50), IN `p_record_id` VARCHAR(50), IN `p_excluded_columns` TEXT)
BEGIN
    DECLARE v_if_cond   TEXT; -- Condiciones
    DECLARE v_old_json  TEXT; -- Json anterior
    DECLARE v_new_json  TEXT; -- Json posterior
    DECLARE v_drop      TEXT; -- Query de eliminar trigger existente
    DECLARE v_sql       LONGTEXT; -- Query de crear nuevo trigger

    CASE p_action
        WHEN 'insert' THEN
        -- 1) JSON_OBJECT para NEW (con TODAS las columnas del nuevo registro en la tabla)
        SELECT GROUP_CONCAT(
          CONCAT('''', COLUMN_NAME, '''', ', NEW.', COLUMN_NAME, '')
          ORDER BY ORDINAL_POSITION SEPARATOR ', '
        )
        INTO v_new_json
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = p_schema
        AND TABLE_NAME   = p_table;

        -- 2) SQL completo del trigger de crear y dropear
        SET v_drop = CONCAT('DROP TRIGGER IF EXISTS trg_', LOWER(p_table), '_audit_insert');
        SET v_sql = CONCAT(
         'CREATE TRIGGER trg_', LOWER(p_table), '_audit_insert ',
         'AFTER INSERT ON ', p_schema, '.', p_table, ' ',
         'FOR EACH ROW ',
         'BEGIN ',
         '  INSERT INTO ', p_schema, '.AuditLogs ',
         '    (TableName, RecordID, Action, ChangedBy, ChangeDate, OldValue, NewValue) ',
         '  VALUES (',
         '    ''', p_table, ''', ',
         '    NEW.',p_record_id,', ',
         '    ''INSERT'', ',
         '    CURRENT_USER(), ',
         '    NOW(), ',
         '    NULL,  ',
         '    JSON_OBJECT(', v_new_json, ')',
         '  ); ',
         'END'
        );
        WHEN 'update' THEN
        -- 1) Condición de igualdad (excluyendo columnas que NO deben disparar)
        SELECT GROUP_CONCAT(
         CONCAT('OLD.', COLUMN_NAME, ' <=> NEW.', COLUMN_NAME, '')
          ORDER BY ORDINAL_POSITION SEPARATOR ' AND '
        )
        INTO v_if_cond
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = p_schema
        AND TABLE_NAME = p_table
        AND (
          p_excluded_columns IS NULL
          OR p_excluded_columns = ''
          OR FIND_IN_SET(COLUMN_NAME, p_excluded_columns) = 0
        );

        -- 2) JSON_OBJECT para OLD (con TODAS las columnas de como estaba el registro antes)
        SELECT GROUP_CONCAT(
          CONCAT('''', COLUMN_NAME, '''', ', OLD.', COLUMN_NAME, '')
          ORDER BY ORDINAL_POSITION SEPARATOR ', '
        )
        INTO v_old_json
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = p_schema
        AND TABLE_NAME   = p_table;

        -- 3) JSON_OBJECT para NEW (con TODAS las columnas de como queda el registro ahora)
        SELECT GROUP_CONCAT(
          CONCAT('''', COLUMN_NAME, '''', ', NEW.', COLUMN_NAME, '')
          ORDER BY ORDINAL_POSITION SEPARATOR ', '
        )
        INTO v_new_json
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = p_schema
        AND TABLE_NAME   = p_table;
        -- 4) SQL completo del trigger de crear y dropear
        SET v_drop = CONCAT('DROP TRIGGER IF EXISTS trg_', LOWER(p_table), '_audit_update');
        SET v_sql = CONCAT(
         'CREATE TRIGGER trg_', LOWER(p_table), '_audit_update ',
         'AFTER UPDATE ON ', p_schema, '.', p_table, ' ',
         'FOR EACH ROW ',
         'BEGIN ',
         '  IF NOT (', v_if_cond, ') THEN ',
         '    INSERT INTO ', p_schema, '.AuditLogs ',
         '      (TableName, RecordID, Action, ChangedBy, ChangeDate, OldValue, NewValue) ',
         '    VALUES (',
         '      ''', p_table, ''', ',
         '      OLD.',p_record_id,', ',
         '      ''UPDATE'', ',
         '      USER(), ',
         '      NOW(), ',
         '      JSON_OBJECT(', v_old_json, '), ',
         '      JSON_OBJECT(', v_new_json, ')',
         '    ); ',
         '  END IF; ',
         'END'
        );
        WHEN 'delete' THEN
        -- 1) JSON_OBJECT para OLD (con TODAS las columnas del registro a eliminar)
        SELECT GROUP_CONCAT(
          CONCAT('''', COLUMN_NAME, '''', ', OLD.', COLUMN_NAME, '')
          ORDER BY ORDINAL_POSITION SEPARATOR ', '
        )
        INTO v_old_json
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = p_schema
        AND TABLE_NAME   = p_table;

        -- 2) SQL completo del trigger de crear y dropear
        SET v_drop = CONCAT('DROP TRIGGER IF EXISTS trg_', LOWER(p_table), '_audit_delete');
        SET v_sql = CONCAT(
         'CREATE TRIGGER trg_', LOWER(p_table), '_audit_delete ',
         'AFTER DELETE ON ', p_schema, '.', p_table, ' ',
         'FOR EACH ROW ',
         'BEGIN ',
         '  INSERT INTO ', p_schema, '.AuditLogs ',
         '    (TableName, RecordID, Action, ChangedBy, ChangeDate, OldValue, NewValue) ',
         '  VALUES (',
         '    ''', p_table, ''', ',
         '    OLD.',p_record_id,', ',
         '    ''DELETE'', ',
         '    CURRENT_USER(), ',
         '    NOW(), ',
         '    JSON_OBJECT(', v_old_json, '),',
         '    NULL  ',
         '  ); ',
         'END'
        );
    END CASE;

    -- 6) Ejecuto el DROP TRIGGER por si existe
    SET @sql := v_drop;
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    -- 7) Ejecuto el CREATE TRIGGER
    SET @sql := v_sql;
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END//
DELIMITER ;

-- Dumping structure for table soul.SubscriptionChanges
CREATE TABLE IF NOT EXISTS `SubscriptionChanges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `PlatformSubscriptionID` varchar(128) NOT NULL,
  `UserID` int(10) NOT NULL,
  `OldPlanID` tinyint(5) DEFAULT NULL,
  `NewPlanID` tinyint(5) DEFAULT NULL,
  `EffectiveDate` datetime DEFAULT NULL,
  `Status` enum('WAITING','PENDING','APPLIED','CANCELLED') DEFAULT 'PENDING',
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `AppliedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.SubscriptionFeatures
CREATE TABLE IF NOT EXISTS `SubscriptionFeatures` (
  `FeatureCode` varchar(25) NOT NULL COMMENT 'Identificador único del beneficio',
  `Description` varchar(255) DEFAULT NULL COMMENT 'Descripción del beneficio',
  `IsActive` tinyint(1) DEFAULT 1 COMMENT 'Un indicador para determinar si el beneficio está activo',
  PRIMARY KEY (`FeatureCode`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define los beneficios disponibles para las suscripciones.';

-- Data exporting was unselected.

-- Dumping structure for table soul.SubscriptionItems
CREATE TABLE IF NOT EXISTS `SubscriptionItems` (
  `PlanID` smallint(5) unsigned NOT NULL,
  `FeatureCode` varchar(25) NOT NULL,
  `Value` varchar(20) DEFAULT NULL COMMENT 'Valor',
  `Type` enum('STRING','INTEGER','FLOAT','BOOLEAN') NOT NULL COMMENT 'Tipo de dato ',
  `Description` varchar(255) DEFAULT NULL COMMENT 'Descripción opcional del valor',
  PRIMARY KEY (`PlanID`,`FeatureCode`) USING BTREE,
  KEY `FK_SUBSCRIPTION_PLAN2` (`FeatureCode`) USING BTREE,
  CONSTRAINT `FK_SUBSCRIPTION_PLAN1` FOREIGN KEY (`PlanID`) REFERENCES `SubscriptionPlans` (`PlanID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_SUBSCRIPTION_PLAN2` FOREIGN KEY (`FeatureCode`) REFERENCES `SubscriptionFeatures` (`FeatureCode`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda la relacion entre planes de suscripcion y sus features';

-- Data exporting was unselected.

-- Dumping structure for table soul.SubscriptionPlans
CREATE TABLE IF NOT EXISTS `SubscriptionPlans` (
  `PlanID` smallint(5) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del plan',
  `Name` varchar(100) DEFAULT NULL COMMENT 'Nombre del plan',
  `Description` text DEFAULT NULL COMMENT 'Descripción del plan',
  `Beneficts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Beneficios del plan.',
  `Duration` smallint(2) unsigned DEFAULT NULL COMMENT 'Duración del plan en meses',
  `Price` decimal(10,2) DEFAULT NULL COMMENT 'Precio del plan',
  `CurrencyCode` varchar(3) DEFAULT NULL COMMENT 'Moneda utilizada para el precio del plan. Utilizar código ISO 4217.',
  `StripeID` varchar(50) NOT NULL COMMENT '''PriceID''/''ID del precio'' asociado en Stripe->Catálogo de Productos. (NO confundir con ID del producto).',
  PRIMARY KEY (`PlanID`),
  KEY `FK_CurrencyCode1` (`CurrencyCode`),
  CONSTRAINT `FK_CurrencyCode1` FOREIGN KEY (`CurrencyCode`) REFERENCES `Currencies` (`CurrencyCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define los planes de suscripción disponibles para los usuarios.';

-- Data exporting was unselected.

-- Dumping structure for table soul.Subscriptions
CREATE TABLE IF NOT EXISTS `Subscriptions` (
  `SubscriptionID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único interno',
  `PlanID` smallint(5) unsigned NOT NULL COMMENT 'ID del plan de suscripción interno',
  `UserID` int(10) unsigned NOT NULL COMMENT 'Usuario asociado',
  `TrialStart` datetime DEFAULT NULL COMMENT 'Fecha de inicio del período de prueba',
  `TrialEnd` datetime DEFAULT NULL COMMENT 'Fecha de fin del período de prueba',
  `TrialSource` enum('INTERNAL','PLATFORM') DEFAULT NULL COMMENT 'Origen del trial (sistema interno o pasarela de pago)',
  `StartDate` datetime NOT NULL COMMENT 'Fecha de inicio efectivo de la suscripción',
  `EndDate` datetime DEFAULT NULL COMMENT 'Fecha de finalización efectiva',
  `Status` enum('ACTIVE','TRIALING','CANCELED','EXPIRED','PAST_DUE','INCOMPLETE','PAUSED') NOT NULL,
  `NextBillingDate` datetime DEFAULT NULL COMMENT 'Próxima fecha de renovación y de factura',
  `CancelAtPeriodEnd` tinyint(1) DEFAULT 0 COMMENT 'Indica si finaliza la suscripción al finalizar el ciclo/período',
  `CancelAt` datetime DEFAULT NULL COMMENT 'Fecha/hora de solicitud de cancelación',
  `PaymentPlatform` varchar(50) NOT NULL COMMENT 'Plataforma de pago',
  `PlatformSubscriptionID` varchar(128) NOT NULL COMMENT 'ID de suscripción en la pasarela',
  `PlatformCustomerID` varchar(128) NOT NULL COMMENT 'ID de cliente en la pasarela',
  `LatestInvoiceID` varchar(128) DEFAULT NULL COMMENT 'Última factura en la pasarela',
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`SubscriptionID`) USING BTREE,
  UNIQUE KEY `UQ_Subscription_Platform` (`PlatformSubscriptionID`,`PaymentPlatform`),
  KEY `FK_UserID` (`UserID`),
  KEY `FK_PlanID` (`PlanID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Estado actual de las suscripciones con soporte para trials';

-- Data exporting was unselected.

-- Dumping structure for table soul.SubscriptionsCoupons
CREATE TABLE IF NOT EXISTS `SubscriptionsCoupons` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `PlatformSubscriptionID` varchar(128) NOT NULL COMMENT 'ID de la suscripción en la plataforma de pagos',
  `PlatformCouponID` varchar(128) NOT NULL COMMENT 'ID del cupón en la plataforma de pagos',
  `CouponCode` varchar(100) NOT NULL COMMENT 'Código de cupón en la plataforma de pagos',
  `CouponName` varchar(255) DEFAULT NULL COMMENT 'Nombre del cupón de descuento',
  `UserID` int(11) NOT NULL COMMENT 'Usuario que recibe el cupón',
  `PercentOff` decimal(5,2) DEFAULT NULL COMMENT 'Porcentaje de descuento aplicado',
  `AmountOff` decimal(10,2) DEFAULT NULL COMMENT 'Monto de descuento aplicado',
  `Status` enum('PENDING','APPLIED','EXPIRED','REMOVED') DEFAULT 'PENDING' COMMENT 'Estado del cupón',
  `AppliedAt` datetime DEFAULT NULL COMMENT 'Fecha y Hora que comienza a regir el cupón',
  `ExpiresAt` datetime DEFAULT NULL COMMENT 'Fecha de expiración',
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora de generación del cupón en el usuario',
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Fecha y hora de modificación',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.SubscriptionsPayments
CREATE TABLE IF NOT EXISTS `SubscriptionsPayments` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'Identificador interno',
  `InvoiceID` varchar(128) NOT NULL COMMENT 'ID de la factura en Stripe',
  `Motive` text DEFAULT NULL COMMENT 'Motivo de la facturación',
  `PlatformSubscriptionID` varchar(128) DEFAULT NULL COMMENT 'ID de la suscripción en Stripe',
  `PlatformCustomerID` varchar(128) NOT NULL COMMENT 'ID del cliente en Stripe',
  `Currency` varchar(10) NOT NULL COMMENT 'Moneda utilizada',
  `AmountDue` decimal(10,2) NOT NULL COMMENT 'Monto a pagar',
  `AmountPaid` decimal(10,2) NOT NULL COMMENT 'Monto efectivamente pagado',
  `AmountRemaining` decimal(10,2) NOT NULL COMMENT 'Monto restante de pago',
  `Status` varchar(20) NOT NULL COMMENT 'Estado de la factura',
  `PlatformPriceID` varchar(128) DEFAULT NULL COMMENT 'ID del precio en Stripe',
  `PlatformProductID` varchar(128) DEFAULT NULL COMMENT 'ID del producto en Stripe',
  `PlatformCouponID` varchar(128) DEFAULT NULL COMMENT 'ID del cupón aplicado en la plataforma de pagos',
  `Quantity` int(11) DEFAULT 1 COMMENT 'Cantidad facturada',
  `PeriodStart` date DEFAULT NULL COMMENT 'Fecha de inicio del período facturado',
  `PeriodEnd` date DEFAULT NULL COMMENT 'Fecha de fin del período facturado',
  `InvoicePDF` text DEFAULT NULL COMMENT 'URL al PDF de la factura',
  `HostedInvoiceURL` text DEFAULT NULL COMMENT 'URL al invoice online en Stripe',
  `CreatedAt` date NOT NULL COMMENT 'Fecha de creación de la factura',
  `PaidAt` date DEFAULT NULL COMMENT 'Fecha en que la factura fue marcada como pagada',
  `InsertedAt` timestamp NULL DEFAULT current_timestamp() COMMENT 'Fecha de inserción en la BD',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de facturas pagadas recibidas vía webhook de Stripe';

-- Data exporting was unselected.

-- Dumping structure for table soul.Users
CREATE TABLE IF NOT EXISTS `Users` (
  `UserID` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del usuario',
  `FirstName` varchar(50) DEFAULT NULL COMMENT 'Nombre del usuario',
  `LastName` varchar(50) DEFAULT NULL COMMENT 'Apellido del usuario',
  `UserName` varchar(30) DEFAULT NULL COMMENT 'Nombre de usuario utilizado para iniciar sesión (único, alfanumérico, sin espacios)',
  `DisplayName` varchar(50) DEFAULT NULL COMMENT 'Nombre a mostrar',
  `PasswordHash` varchar(255) DEFAULT NULL COMMENT 'Contraseña del usuario almacenada de forma segura',
  `Email` varchar(100) DEFAULT NULL COMMENT 'Correo electrónico del usuario (único, formato válido)',
  `Phone` varchar(20) DEFAULT NULL COMMENT 'Número de teléfono del usuario (formato válido según el país)',
  `DateOfBirth` date DEFAULT NULL COMMENT 'Fecha de nacimiento del usuario',
  `Gender` enum('M','F','O') DEFAULT NULL COMMENT 'Género del usuario.',
  `Biography` text DEFAULT NULL COMMENT 'Biografía del usuario',
  `ValidatedEmail` tinyint(1) DEFAULT 0 COMMENT 'Correo electrónico validado.',
  `ValidatedPhone` tinyint(1) DEFAULT 1 COMMENT 'Celular validado.',
  `TwoFactorAuth` tinyint(1) DEFAULT 0 COMMENT 'Autenticación de dos factores habilitada.',
  `MfaSecret` varchar(50) DEFAULT NULL COMMENT 'Código secreto validador de MFA.',
  `UserType` enum('Guide','Seeker','Moderator','Admin') DEFAULT 'Seeker' COMMENT 'Tipo de usuario.',
  `RegistrationDate` datetime DEFAULT NULL COMMENT 'Fecha de registro del usuario',
  `LastLogin` datetime DEFAULT NULL COMMENT 'Última fecha de inicio de sesión del usuario',
  `DeactivationDate` datetime DEFAULT NULL COMMENT 'Fecha de desactivación de la cuenta del usuario',
  `UserLevel` tinyint(4) DEFAULT NULL COMMENT 'Nivel del usuario',
  `SignedContract` varchar(255) DEFAULT NULL COMMENT 'URL del contrato firmado',
  `LegalDocuments` varchar(255) DEFAULT NULL COMMENT 'URL de documentos legales',
  `ShortDescription` varchar(100) DEFAULT NULL COMMENT 'Descripcion corta',
  `Oauth2ID` varchar(50) DEFAULT NULL COMMENT 'ID para SSO',
  `Oauth2Service` varchar(20) DEFAULT NULL COMMENT 'Indica a que cuenta pertenece el oauth2_id',
  `OTPDate` datetime DEFAULT NULL COMMENT 'Fecha/Hora genereación código validación mail ',
  `OTPCode` int(10) unsigned DEFAULT NULL COMMENT 'Código validación mail',
  `OTPAttemps` tinyint(4) DEFAULT NULL COMMENT 'Cantidad de intentos fallidos de ingreso del código OTP',
  `FailedLoginAttempts` int(11) DEFAULT 0 COMMENT 'Cantidad de intentos fallidos de logueos',
  `LockedUntil` datetime DEFAULT NULL COMMENT 'Tiempo y Fecha que el usuario esta bloqueado debido al intento fallido de logueos',
  `ReferralCode` varchar(10) DEFAULT NULL COMMENT 'Código de Referido para compartir',
  `IsAdmin` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT 'Es administrador?',
  `IsModerator` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT 'Puede moderar?',
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Index_UserName` (`UserName`),
  UNIQUE KEY `Index_Email` (`Email`),
  UNIQUE KEY `Index_oauth2` (`Oauth2ID`),
  UNIQUE KEY `Index_ReferralCode` (`ReferralCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena información sobre los usuarios registrados en la plataforma.';

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersBrowser
CREATE TABLE IF NOT EXISTS `UsersBrowser` (
  `BrowserID` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Identificador único para cada navegador validado',
  `UserID` int(10) unsigned NOT NULL COMMENT 'Relación con la tabla Users, identificando al usuario dueño del navegador',
  `MfaID` varchar(255) DEFAULT NULL COMMENT 'Identificador único para el navegador, utilizado para validar MFA en futuras sesiones',
  `Browser` varchar(255) DEFAULT NULL COMMENT 'Nombre del navegador',
  `Version` varchar(50) DEFAULT NULL COMMENT 'Versión del navegador',
  `Os` varchar(255) DEFAULT NULL COMMENT 'Sistema operativo utilizado',
  `Device` varchar(50) DEFAULT NULL COMMENT 'Tipo de dispositivo',
  `IP` varchar(45) DEFAULT NULL COMMENT 'Dirección IP del dispositivo desde el cual se realizó el login',
  `Expiry` datetime DEFAULT NULL COMMENT 'Fecha y hora de expiración de la validación del navegador',
  PRIMARY KEY (`BrowserID`),
  KEY `fk_user_browser` (`UserID`),
  CONSTRAINT `fk_user_browser` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersCategories
CREATE TABLE IF NOT EXISTS `UsersCategories` (
  `UserID` int(10) unsigned NOT NULL,
  `CategoryID` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`UserID`,`CategoryID`) USING BTREE,
  KEY `FK_usersCategories2` (`CategoryID`) USING BTREE,
  CONSTRAINT `FK_usersCategories1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_usersCategories2` FOREIGN KEY (`CategoryID`) REFERENCES `Categories` (`CategoryID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersLanguages
CREATE TABLE IF NOT EXISTS `UsersLanguages` (
  `UserID` int(10) unsigned NOT NULL COMMENT 'Identificador único del usuario.',
  `LanguageID` varchar(5) NOT NULL COMMENT 'Identificador único del idioma (código I18n)',
  `FluencyLevel` enum('basico','intermedio','avanzado','nativo') DEFAULT NULL COMMENT 'Nivel de fluidez del guía en cada idioma',
  PRIMARY KEY (`UserID`,`LanguageID`) USING BTREE,
  CONSTRAINT `UsersLanguages_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Guarda información sobre los idiomas que los guías hablan.';

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersLegalConsents
CREATE TABLE IF NOT EXISTS `UsersLegalConsents` (
  `ConsentID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Accepted` tinyint(1) DEFAULT 0,
  `ConsentDate` datetime NOT NULL DEFAULT current_timestamp(),
  `UserIP` varchar(45) NOT NULL,
  `UserAgent` text DEFAULT NULL,
  `DocumentType` enum('PrivacyPolicy','TermsAndConditions') NOT NULL COMMENT 'Tipo de documento legal.',
  `Version` varchar(10) NOT NULL COMMENT 'Versión del documento legal.',
  PRIMARY KEY (`ConsentID`),
  UNIQUE KEY `unique_document` (`DocumentType`,`Version`,`UserID`) USING BTREE,
  KEY `UserID` (`UserID`),
  CONSTRAINT `UsersLegalConsents_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`),
  CONSTRAINT `fk_user_legal_doc` FOREIGN KEY (`DocumentType`, `Version`) REFERENCES `LegalDocuments` (`DocumentType`, `Version`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersLocations
CREATE TABLE IF NOT EXISTS `UsersLocations` (
  `LocationID` int(10) unsigned NOT NULL,
  `UserID` int(10) unsigned NOT NULL,
  `LocationName` varchar(100) DEFAULT NULL COMMENT 'Nombre descriptivo (ej: "Oficina Centro", "Domicilio")',
  `AddressName` varchar(255) DEFAULT NULL,
  `AddressNumber` smallint(6) DEFAULT NULL,
  `Floor` varchar(4) DEFAULT NULL,
  `Department` varchar(4) DEFAULT NULL,
  `Cp` varchar(10) DEFAULT NULL,
  `City` varchar(60) DEFAULT NULL,
  `State` varchar(50) DEFAULT NULL,
  `CountryCode` varchar(2) DEFAULT NULL,
  `IsActive` tinyint(1) DEFAULT 1,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`LocationID`,`UserID`) USING BTREE,
  KEY `FK_UsersLocations_Users` (`UserID`) USING BTREE,
  CONSTRAINT `FK_UsersLocations_Users` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ubicaciones de usuarios';

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersNotifications
CREATE TABLE IF NOT EXISTS `UsersNotifications` (
  `UserID` int(10) unsigned NOT NULL COMMENT 'Identificador único del usuario (clave primaria y foránea). Debe existir en la tabla users',
  `PushApp` tinyint(1) DEFAULT 1 COMMENT 'Notificaciones por la App móvil',
  `Email` tinyint(1) DEFAULT 1 COMMENT 'Notificaciones por correo electrónico',
  `WhatsApp` tinyint(1) DEFAULT 1 COMMENT 'Notificaciones por WhatsApp',
  `Sms` tinyint(1) DEFAULT 1 COMMENT 'Notificaciones por SMS',
  `PushWeb` tinyint(1) DEFAULT 1 COMMENT 'Notificaciones vía Web Push (navegador)',
  `InApp` tinyint(1) DEFAULT 1 COMMENT 'Mensajería interna: campana / popups',
  PRIMARY KEY (`UserID`),
  CONSTRAINT `UsersNotifications_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Define las preferencias de notificaciones de los usuarios.';

-- Data exporting was unselected.

-- Dumping structure for table soul.UsersSettings
CREATE TABLE IF NOT EXISTS `UsersSettings` (
  `UserID` int(10) unsigned NOT NULL COMMENT 'Identificador único del usuario.',
  `Locale` varchar(5) DEFAULT 'es' COMMENT 'Idioma preferido en código I18N',
  `ViewMode` enum('Light','Dark') DEFAULT 'Light' COMMENT 'Modo de visualización.',
  `ReceiveNewsletters` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Preferencia del usuario para recibir novedades por email.',
  `PrivacySettings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Configuraciones de privacidad' CHECK (json_valid(`PrivacySettings`)),
  `TimeZone` varchar(50) DEFAULT NULL COMMENT 'Zona horaria del usuario (debe ser un formato IANA válido)',
  `SearchPreferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Preferencias de búsqueda (JSON)' CHECK (json_valid(`SearchPreferences`)),
  `SearchHistory` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Historial de búsqueda (JSON)' CHECK (json_valid(`SearchHistory`)),
  PRIMARY KEY (`UserID`),
  CONSTRAINT `UsersSettings_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena las configuraciones específicas de cada usuario.';

-- Data exporting was unselected.

-- Dumping structure for table soul.WebPushSubscription
CREATE TABLE IF NOT EXISTS `WebPushSubscription` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(10) unsigned NOT NULL,
  `Endpoint` text NOT NULL,
  `P256dhKey` varchar(255) NOT NULL,
  `AuthKey` varchar(255) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `IsActive` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `ux_user_endpoint` (`UserID`,`Endpoint`(255)),
  CONSTRAINT `fk_wps_user` FOREIGN KEY (`UserID`) REFERENCES `Users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
