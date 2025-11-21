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

/**
 * sp_gen_audit_trigger
 *
 * @description Genera triggers de auditoría dinámicamente para registrar cambios (INSERT, UPDATE, DELETE) en AuditLogs en formato JSON.
 *
 * @param {ENUM} p_action Tipo de acción: 'insert', 'update' o 'delete'
 * @param {VARCHAR(64)} p_schema Nombre del esquema/base de datos
 * @param {VARCHAR(64)} p_table Nombre de la tabla
 * @param {VARCHAR(64)} p_record_id Columna de identificación del registro (ej: 'ID', 'user_id')
 * @param {TEXT} p_excluded_columns [OPCIONAL] Columnas a excluir separadas por comas (solo UPDATE)
 *
**/
DELIMITER //
CREATE DEFINER=`root`@`%` PROCEDURE `sp_gen_audit_trigger`(
	IN `p_action` ENUM('insert','update','delete'),
	IN `p_schema` VARCHAR(64),
	IN `p_table` VARCHAR(64),
	IN `p_record_id` VARCHAR(64),
	IN `p_excluded_columns` TEXT
)
LANGUAGE SQL
NOT DETERMINISTIC
CONTAINS SQL
SQL SECURITY DEFINER
COMMENT ''
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
		      CONCAT('''', COLUMN_NAME, '''', ', NEW.`', COLUMN_NAME, '`')
		      ORDER BY ORDINAL_POSITION SEPARATOR ', '
		    )
		    INTO v_new_json
		    FROM INFORMATION_SCHEMA.COLUMNS
		    WHERE TABLE_SCHEMA = p_schema
		    AND TABLE_NAME   = p_table;

		    -- 2) SQL completo del trigger de crear y dropear
		    SET v_drop = CONCAT('DROP TRIGGER IF EXISTS `trg_', LOWER(p_table), '_audit_insert`');
		    SET v_sql = CONCAT(
		     'CREATE TRIGGER `trg_', LOWER(p_table), '_audit_insert` ',
		     'AFTER INSERT ON `', p_schema, '`.`', p_table, '` ',
		     'FOR EACH ROW ',
		     'BEGIN ',
		     '  INSERT INTO `', p_schema, '`.`AuditLogs` ',
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
		   	CONCAT('OLD.`', COLUMN_NAME, '` <=> NEW.`', COLUMN_NAME, '`')
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
		      CONCAT('''', COLUMN_NAME, '''', ', OLD.`', COLUMN_NAME, '`')
		      ORDER BY ORDINAL_POSITION SEPARATOR ', '
		    )
		    INTO v_old_json
		    FROM INFORMATION_SCHEMA.COLUMNS
		    WHERE TABLE_SCHEMA = p_schema
		    AND TABLE_NAME   = p_table;

		    -- 3) JSON_OBJECT para NEW (con TODAS las columnas de como queda el registro ahora)
		    SELECT GROUP_CONCAT(
		      CONCAT('''', COLUMN_NAME, '''', ', NEW.`', COLUMN_NAME, '`')
		      ORDER BY ORDINAL_POSITION SEPARATOR ', '
		    )
		    INTO v_new_json
		    FROM INFORMATION_SCHEMA.COLUMNS
		    WHERE TABLE_SCHEMA = p_schema
		    AND TABLE_NAME   = p_table;

		    -- 4) SQL completo del trigger de crear y dropear
		    SET v_drop = CONCAT('DROP TRIGGER IF EXISTS `trg_', LOWER(p_table), '_audit_update`');
		    SET v_sql = CONCAT(
		     'CREATE TRIGGER `trg_', LOWER(p_table), '_audit_update` ',
		     'AFTER UPDATE ON `', p_schema, '`.`', p_table, '` ',
		     'FOR EACH ROW ',
		     'BEGIN ',
		     '  IF NOT (', v_if_cond, ') THEN ',
		     '    INSERT INTO `', p_schema, '`.`AuditLogs` ',
		     '      (TableName, RecordID, Action, ChangedBy, ChangeDate, OldValue, NewValue) ',
		     '    VALUES (',
		     '      ''', p_table, ''', ',
		     '      OLD.',p_record_id,', ',
		     '      ''UPDATE'', ',
		     '      CURRENT_USER(), ',
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
		      CONCAT('''', COLUMN_NAME, '''', ', OLD.`', COLUMN_NAME, '`')
		      ORDER BY ORDINAL_POSITION SEPARATOR ', '
		    )
		    INTO v_old_json
		    FROM INFORMATION_SCHEMA.COLUMNS
		    WHERE TABLE_SCHEMA = p_schema
		    AND TABLE_NAME   = p_table;

		    -- 2) SQL completo del trigger de crear y dropear
		    SET v_drop = CONCAT('DROP TRIGGER IF EXISTS `trg_', LOWER(p_table), '_audit_delete`');
		    SET v_sql = CONCAT(
		     'CREATE TRIGGER `trg_', LOWER(p_table), '_audit_delete` ',
		     'AFTER DELETE ON `', p_schema, '`.`', p_table, '` ',
		     'FOR EACH ROW ',
		     'BEGIN ',
		     '  INSERT INTO `', p_schema, '`.`AuditLogs` ',
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

-- Genero el trigger de insert para users
CALL sp_gen_audit_trigger(
    'insert',
    'soul',
    'Users',
    'UserID',
    ''
);

-- Genero el trigger de update para users
CALL sp_gen_audit_trigger(
    'update',
    'soul',
    'Users',
    'UserID',
    'LastLogin,OTPDate,OTPCode,OTPAttemps,FailedLoginAttempts,LockedUntil'
);

-- Genero el trigger de delete para users
CALL sp_gen_audit_trigger(
    'delete',
    'soul',
    'Users',
    'UserID',
    ''
);