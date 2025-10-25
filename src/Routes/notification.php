<?php

use Slim\App;
use App\Controllers\NotificationController;
use App\Models\Notification;

return function (App $app) {
  # Proteccion de rutas
  // $jwtMiddleware = new JwtAuthentication([
  //   "secret" => $GLOBALS['config']['jwt']['secret'],
  //   "attribute" => "jwt"
  // ]);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $notification = new Notification($pdo);
  $notificationController = new NotificationController($notification);

  $app->post('/notifications/create', [$notificationController, 'createNotification']);
  $app->get('/notifications/in-app', [$notificationController, 'listInApp']);
  $app->patch('/notifications/in-app/{id}/read', [$notificationController, 'markInAppAsRead']);
};