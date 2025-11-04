-- --------------------------------------------------------
-- Host:                         testing.cpcvuoast9uv.us-east-1.rds.amazonaws.com
-- Server version:               10.6.22-MariaDB-log - managed by https://aws.amazon.com/rds/
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
  `AddressName` varchar(255) DEFAULT NULL COMMENT 'Direción del usuario',
  `AddressNumber` smallint(6) DEFAULT NULL COMMENT 'Dirección: Altura',
  `Floor` varchar(4) DEFAULT NULL COMMENT 'Piso',
  `Department` varchar(4) DEFAULT NULL COMMENT 'Departamento',
  `Cp` varchar(10) DEFAULT NULL COMMENT 'Código postal',
  `City` varchar(60) DEFAULT NULL COMMENT 'Ciudad o pueblo del usuario',
  `State` varchar(50) DEFAULT NULL COMMENT 'Estado (provincia) del usuario',
  `CountryCode` varchar(2) DEFAULT NULL COMMENT 'País del usuario. Usar códigos de país estandarizados (ISO 3166)',
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

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
