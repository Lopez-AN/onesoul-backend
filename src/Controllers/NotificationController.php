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

  public function sendNotification(Request $request, Response $response) {
    $data = $request->getParsedBody();

    $recipientUserID = $data['RecipientUserID'] ?? null;
    $eventCode = $data['EventCode'] ?? null;
    $payload = $data['Payload'] ?? [];
    $idempotencyKey = $data['IdempotencyKey'] ?? null;

    if (!$recipientUserID || !$eventCode) {
      return $response->withJson([
        'error' => ['code' => 'INVALID_PARAMS', 'desc' => 'RecipientUserID y EventCode son obligatorios']
      ], 400);
    }

    try {
      $notificationID = $this->notification->createNotification(
        $recipientUserID, $eventCode, $payload, $idempotencyKey
      );

      return $response->withJson([
        'success' => true,
        'notification_id' => $notificationID
      ], 201);

    } catch (Exception $e) {
      return $response->withJson([
        'error' => ['code' => 'NOTIFICATION_ERROR', 'desc' => $e->getMessage()]
      ], 500);
    }
  }

  public function listInApp(Request $request, Response $response) {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID;

    $notifications = $this->notification->getUnreadInAppByUser($userID);

    return $response->withJson([
      'notifications' => $notifications
    ]);
  }

  public function markInAppAsRead(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID;
    $notifID = (int) $args['id'];

    $updated = $this->notification->markInAppAsRead($userID, $notifID);

    if (!$updated) {
      return $response->withJson([
        'error' => ['code' => 'NOT_FOUND', 'desc' => 'Notificación no encontrada o ya leída']
      ], 404);
    }

    return $response->withJson(['success' => true]);
  }
}