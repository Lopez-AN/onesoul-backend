<?php

use Slim\App;
use App\Controllers\NotificationController;
use App\Models\Notification;
use App\Models\User;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $notification = new Notification($pdo);
  $user = new User($pdo);
  $notificationController = new NotificationController($notification, $user);

  $app->post('/notifications', [$notificationController, 'createNotification'])->add($requiredJwt);
  $app->get('/notifications/in_app', [$notificationController, 'getInAppNotifications'])->add($requiredJwt);
  $app->patch('/notifications/in_app/mark', [$notificationController, 'markInAppNotifications'])->add($requiredJwt);
  $app->get('/delivery/next', [$notificationController, 'getNextDelivery'])->add($requiredJwt);
  $app->get('/delivery/{DeliveryID}', [$notificationController, 'getDeliveryById'])->add($requiredJwt);
  $app->get('/deliveries/notification/{NotificationID}', [$notificationController, 'getDeliveriesByNotificationId'])->add($requiredJwt);
  $app->get('/deliveries/channel/{Channel}', [$notificationController, 'getDeliveriesByChannel'])->add($requiredJwt);
  $app->get('/deliveries/recipient/{RecipientID}', [$notificationController, 'getDeliveriesByRecipient'])->add($requiredJwt);
};