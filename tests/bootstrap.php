<?php

/**
 * Bootstrap file for PHPUnit tests
 * Este archivo se ejecuta antes de cada test para cargar dependencias
 */

// Definir el root del proyecto
define('ROOT', dirname(dirname(__FILE__)));

// Autoload de Composer
require_once ROOT . '/vendor/autoload.php';

// Cargar configuración
$GLOBALS['config'] = @json_decode(file_get_contents(ROOT.'/config/config.json'),true);