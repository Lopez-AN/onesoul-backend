<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Notification;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class NotificationController{

  protected $notification;

  public function __construct(Notification $notification){
    $this->notification = $notification;
  }

  public function createNotification(Request $request, Response $response) {
    $data = $request->getParsedBody();

    $recipientUserID = $data['RecipientUserID'] ?? null;
    $eventCode = $data['EventCode'] ?? null;
    $payload = $data['Payload'] ?? [];
    $idempotencyKey = $data['IdempotencyKey'] ?? null;

    if (!$recipientUserID || !$eventCode) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMS",
          "desc" => "RecipientUserID y EventCode son obligatorios"
        ]
      ]);
    }

    try {
      $notificationID = $this->notification->createNotification(
        $recipientUserID, $eventCode, $payload, $idempotencyKey
      );

      return $response->withStatus(201)->withJson([
        'success' => true,
        'notification_id' => $notificationID
      ]);

    } catch (Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "NOTIFICATION_ERROR", 
          "desc" => $e->getMessage()]
      ]);
    }
  }

  public function listInApp(Request $request, Response $response) {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $notifications = $this->notification->getUnreadInAppByUser($userID);

    return $response->withJson([
      'notifications' => $notifications
    ]);
  }

  public function markInAppAsRead(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;
    $notifID = intval($args['id']);

    $updated = $this->notification->markInAppAsRead($userID, $notifID);

    if (!$updated) {
      return $response->withStatus(404)->withJson([
        'error' => [
          'code' => 'NOT_FOUND', 
          'desc' => 'Notificación no encontrada o ya leída']
      ]);
    }

    return $response->withJson(['success' => true]);
  }
}