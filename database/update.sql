ALTER TABLE `UsersLocations`
	DROP FOREIGN KEY `FK_UsersLocations_Countries`;

ALTER TABLE `Countries`
	CHANGE COLUMN `CountryCode` `CountryCode` CHAR(2) NOT NULL COMMENT 'Código ISO 3166-1 alpha-2' COLLATE 'utf8mb4_unicode_ci' FIRST;

ALTER TABLE `UsersLocations`
	CHANGE COLUMN `CountryCode` `CountryCode` CHAR(2) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci' AFTER `State`,
	ADD CONSTRAINT `FK_UsersLocations_Countries` FOREIGN KEY (`CountryCode`) REFERENCES `Countries` (`CountryCode`) ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE `CountriesStates`
	ADD CONSTRAINT `FK_CountriesStates_Countries` FOREIGN KEY (`CountryCode`) REFERENCES `Countries` (`CountryCode`) ON UPDATE CASCADE ON DELETE CASCADE;

-- Estados Unidos (en inglés)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('US', 'Alabama'),
('US', 'Alaska'),
('US', 'Arizona'),
('US', 'Arkansas'),
('US', 'California'),
('US', 'Colorado'),
('US', 'Connecticut'),
('US', 'Delaware'),
('US', 'Florida'),
('US', 'Georgia'),
('US', 'Hawaii'),
('US', 'Idaho'),
('US', 'Illinois'),
('US', 'Indiana'),
('US', 'Iowa'),
('US', 'Kansas'),
('US', 'Kentucky'),
('US', 'Louisiana'),
('US', 'Maine'),
('US', 'Maryland'),
('US', 'Massachusetts'),
('US', 'Michigan'),
('US', 'Minnesota'),
('US', 'Mississippi'),
('US', 'Missouri'),
('US', 'Montana'),
('US', 'Nebraska'),
('US', 'Nevada'),
('US', 'New Hampshire'),
('US', 'New Jersey'),
('US', 'New Mexico'),
('US', 'New York'),
('US', 'North Carolina'),
('US', 'North Dakota'),
('US', 'Ohio'),
('US', 'Oklahoma'),
('US', 'Oregon'),
('US', 'Pennsylvania'),
('US', 'Rhode Island'),
('US', 'South Carolina'),
('US', 'South Dakota'),
('US', 'Tennessee'),
('US', 'Texas'),
('US', 'Utah'),
('US', 'Vermont'),
('US', 'Virginia'),
('US', 'Washington'),
('US', 'West Virginia'),
('US', 'Wisconsin'),
('US', 'Wyoming');

-- Argentina (en español)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('AR', 'CABA'),
('AR', 'Buenos Aires'),
('AR', 'Catamarca'),
('AR', 'Chaco'),
('AR', 'Chubut'),
('AR', 'Córdoba'),
('AR', 'Corrientes'),
('AR', 'Entre Ríos'),
('AR', 'Formosa'),
('AR', 'Jujuy'),
('AR', 'La Pampa'),
('AR', 'La Rioja'),
('AR', 'Mendoza'),
('AR', 'Misiones'),
('AR', 'Neuquén'),
('AR', 'Río Negro'),
('AR', 'Salta'),
('AR', 'San Juan'),
('AR', 'San Luis'),
('AR', 'Santa Cruz'),
('AR', 'Santa Fe'),
('AR', 'Santiago del Estero'),
('AR', 'Tierra del Fuego'),
('AR', 'Tucumán');

-- Brasil (en español)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('BR', 'Acre'),
('BR', 'Alagoas'),
('BR', 'Amapá'),
('BR', 'Amazonas'),
('BR', 'Bahía'),
('BR', 'Ceará'),
('BR', 'Distrito Federal'),
('BR', 'Espírito Santo'),
('BR', 'Goiás'),
('BR', 'Maranhão'),
('BR', 'Mato Grosso'),
('BR', 'Mato Grosso do Sul'),
('BR', 'Minas Gerais'),
('BR', 'Pará'),
('BR', 'Paraíba'),
('BR', 'Paraná'),
('BR', 'Pernambuco'),
('BR', 'Piauí'),
('BR', 'Río de Janeiro'),
('BR', 'Río Grande do Norte'),
('BR', 'Río Grande do Sul'),
('BR', 'Rondônia'),
('BR', 'Roraima'),
('BR', 'Santa Catarina'),
('BR', 'São Paulo'),
('BR', 'Sergipe'),
('BR', 'Tocantins');

-- Chile (en español)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('CL', 'Antofagasta'),
('CL', 'Araucanía'),
('CL', 'Atacama'),
('CL', 'Aysén'),
('CL', 'Biobío'),
('CL', 'Coquimbo'),
('CL', 'Libertador General Bernardo O''Higgins'),
('CL', 'Los Lagos'),
('CL', 'Los Ríos'),
('CL', 'Magallanes'),
('CL', 'Maule'),
('CL', 'Metropolitana'),
('CL', 'Ñuble'),
('CL', 'Tarapacá');

-- Paraguay (en español)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('PY', 'Amambay'),
('PY', 'Boquerón'),
('PY', 'Caaguazú'),
('PY', 'Caazapá'),
('PY', 'Central'),
('PY', 'Concepción'),
('PY', 'Cordillera'),
('PY', 'Guairá'),
('PY', 'Itapúa'),
('PY', 'Misiones'),
('PY', 'Ñeembucú'),
('PY', 'Paraguarí'),
('PY', 'Presidente Hayes'),
('PY', 'San Pedro');

-- Uruguay (en español)
INSERT INTO CountriesStates (CountryCode, StateName) VALUES
('UY', 'Artigas'),
('UY', 'Canelones'),
('UY', 'Cerro Largo'),
('UY', 'Colonia'),
('UY', 'Durazno'),
('UY', 'Flores'),
('UY', 'Florida'),
('UY', 'Lavalleja'),
('UY', 'Maldonado'),
('UY', 'Montevideo'),
('UY', 'Paysandú'),
('UY', 'Río Negro'),
('UY', 'Rivera'),
('UY', 'Rocha'),
('UY', 'San José'),
('UY', 'San Pedro'),
('UY', 'Soriano'),
('UY', 'Tacuarembó'),
('UY', 'Treinta y Tres');