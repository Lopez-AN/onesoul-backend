<?php

use Slim\App;

return function (App $app) {
  $app->get('/version', function ($request, $response, $args) {
    // Obtener el contenido del archivo de version
    $version = @file_get_contents(ROOT.'/.version');
    if(!$version){ // Si no existe devuelvo NULL
      $version = null;
    }

    return $response->withJson(['version' => $version]);
  });
};
