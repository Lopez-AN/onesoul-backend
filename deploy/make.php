<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);
#Se busca que exista el directorio de la aplicacion
define('ROOT', dirname(__FILE__));
define('DS', DIRECTORY_SEPARATOR);

chdir(ROOT);

$nparam = count($argv); #Cantidad de parametros
if ($nparam < 2) {
  print "Ingrese la version\n";
  return;
}

$version = $argv[1];
$release = !empty($argv[2]) && $argv[2] === 'release';

if (!is_dir(ROOT . DS . ".." . DS . "installer")) {
  if (!mkdir(ROOT . DS . ".." . DS . "installer")) {
    print "No se encuentra el directorio ../installer y no se pudo crear verifique permisos\n";
    return;
  }
}

# Se comprueba que exista los fuentes
if (!file_exists(ROOT . DS . ".." . DS . "composer.json")) {
  print "No se encuentra composer.json\n";
  return;
}
if (!is_dir(ROOT . DS . ".." . DS . "src")) {
  print "No se encuentra el directorio /src\n";
  return;
}
if (!is_dir(ROOT . DS . ".." . DS . "public")) {
  print "No se encuentra el directorio /public\n";
  return;
}

# Se comprueba que el directorio de deploy no este con archivos previos
if (is_dir(ROOT . DS . "app")) {
  delTree(ROOT . DS . "app"); # Se intenta borrar el directorio de deploy
  if (is_dir(ROOT . DS . "app")) {
    print "No se pudo limpiar el directorio de deploy, borre el directorio /app de manera manual para volver a procesar\n";
    return;
  }
}

# Copia los fuentes al directorio de deploy
mkdir(ROOT . DS . "app");
mkdir(ROOT . DS . "app" . DS . "src");
mkdir(ROOT . DS . "app" . DS . "public");
rcopy(ROOT . DS . ".." . DS . "src", ROOT . DS . "app" . DS . "src");
rcopy(ROOT . DS . ".." . DS . "public", ROOT . DS . "app" . DS . "public");
copy(ROOT . DS . ".." . DS . "composer.json", ROOT . DS . "app" . DS . "composer.json");
if (file_exists(ROOT . DS . ".." . DS . "composer.lock")) {
  copy(ROOT . DS . ".." . DS . "composer.lock", ROOT . DS . "app" . DS . "composer.lock");
}

#Se comprime el directorio en tar.gz y se codifica en base64
$a = new PharData(ROOT . DS . "deploy.tar");
$a->buildFromDirectory(ROOT . DS . "app");
$a->compress(Phar::GZ);
unset($a);
Phar::unlinkArchive(ROOT . DS . 'deploy.tar');
$payload = base64_encode(file_get_contents(ROOT . DS . "deploy.tar.gz"));
#Decodificacion instalador de emro
$instalador = file_get_contents(ROOT . DS . "template.sh");
$instalador = str_replace("{VERSION}", $version, $instalador);
$instalador = str_replace("\r\n", "\n", $instalador);
$instalador = str_replace("{PAYLOAD}", $payload, $instalador);

file_put_contents(ROOT . DS . ".." . DS . "installer" . DS . "install-$version.sh", $instalador);
unlink(ROOT . DS . "deploy.tar.gz");
delTree("app");
echo "Instalador generado\n";

#Buscador de JS recursivo
function rglob($pattern, $flags = 0){
  $files = glob($pattern, $flags);
  foreach (glob(dirname($pattern) . DS . '*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
    $files = array_merge($files, rglob($dir . DS . basename($pattern), $flags));
  }
  return $files;
}

#Copia todos los archivos menos los CSS y JS
function rcopy($source, $target){
  if (is_dir($source)) {
    // Si es el directorio config, no copiar
    if (basename($source) === 'config') {
      return;
    }

    @mkdir($target);
    $d = dir($source);
    while (FALSE !== ($file = $d->read())) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      $File = $source . '/' . $file;
      if (is_dir($File)) {
        rcopy($File, $target . '/' . $file);
        continue;
      }
      copy($File, $target . '/' . $file);
    }
    $d->close();
  } else {
    copy($source, $target);
  }
}

#Borrar directorio recursivamente
function delTree($dir) {
  $files = array_diff(scandir($dir), array('.', '..'));
  foreach ($files as $file) {
    (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
  }
  return rmdir($dir);
}
