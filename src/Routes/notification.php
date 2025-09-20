<?php

use Slim\App;
use App\Controllers\NotificationController;
use App\Models\Notification;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => "/notification",
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt"
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $notification = new Notification($pdo);
  $notificationController = new NotificationController($notification);

  $app->post('/notifications/send', [$notificationsController, 'sendNotification']);
  $app->get('/notifications/in-app', [$notificationsController, 'listInApp']);
  $app->patch('/notifications/in-app/{id}/read', [$notificationsController, 'markInAppAsRead']);
};