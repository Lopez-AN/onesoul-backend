<?php

namespace App\Controllers;

use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Notification;
use App\Models\User;
use App\Utils\ParameterValidator;
use App\Enums\DeliveriesMode;

class NotificationController{

  protected $notification;
  protected $user;

  public function __construct(Notification $notification, User $user){
    $this->notification = $notification;
    $this->user = $user;
  }

  public function createNotification(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Este endpoint es para administradores
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create notifications."
        ]
      ]);
    }

    $pValidation = ParameterValidator::validate($response, 'notifications','create_notification', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Traigo el usuario y sus settings
      $recipient = $this->user->getUserById($params['RecipientUserID']);
      if(!$recipient){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified recipient ID was found"
          ]
        ]);
      }

      $deliveries = $this->notification->createNotification(
        $params['RecipientUserID'],
        $params['EventCode'],
        $params['Payload'],
        $params['IdempotencyKey']
      );

      return $response->withJson($deliveries);

    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getNextDelivery(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');

    # Si no es admin solo puede recibir deliverys propios
    $recipientID = !$jwt->data->IsAdmin ? $jwt->data->UserID : null;

    try {
      # Traigo el usuario y sus settings
      $delivery = $this->notification->getNextDelivery($recipientID);
      return $response->withJson($delivery);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getDeliveryById(Request $request, Response $response, $args) {
    $params['DeliveryID'] = $args['DeliveryID'];
    $jwt = $request->getAttribute('jwt');

    # Si no es admin solo puede recibir deliverys propios
    $recipientID = !$jwt->data->IsAdmin ? $jwt->data->UserID : null;

    $pValidation = ParameterValidator::validate($response, 'notifications','get_delivery_by_id', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $deliveries = $this->notification->getDeliveryById($params['DeliveryID'], $recipientID);
      if(!$deliveries){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DELIVERY_NOT_FOUND",
            "desc" => "No delivery associated with the specified id was found"
          ]
        ]);
      }

      return $response->withJson($deliveries);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getDeliveriesByNotificationId(Request $request, Response $response, $args) {
    $params['NotificationID'] = $args['NotificationID'];
    $jwt = $request->getAttribute('jwt');

    # Si no es admin solo puede recibir deliverys propios
    $recipientID = !$jwt->data->IsAdmin ? $jwt->data->UserID : null;

    $pValidation = ParameterValidator::validate($response, 'notifications','get_deliveries_by_notification_id', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $deliveries = $this->notification->getDeliveriesByNotificationId($params['NotificationID'], DeliveriesMode::ALL, $recipientID);
      if(!$deliveries){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NOTIFICATION_NOT_FOUND",
            "desc" => "No notification associated with the specified id was found"
          ]
        ]);
      }

      return $response->withJson($deliveries);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getDeliveriesByChannel(Request $request, Response $response, $args) {
    $params['Channel'] = strtoupper($args['Channel']);
    $paginator = paginator($request);
    $jwt = $request->getAttribute('jwt');

    # Si no es admin solo puede recibir deliverys propios
    $recipientID = !$jwt->data->IsAdmin ? $jwt->data->UserID : null;

    $pValidation = ParameterValidator::validate($response, 'notifications','get_deliveries_by_channel', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $deliveries = $this->notification->getDeliveriesByChannel($paginator, $params['Channel'], $recipientID);
      if(!$deliveries){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DELIVERY_NOT_FOUND",
            "desc" => "No delivery associated with the specified id was found"
          ]
        ]);
      }

      return $response->withJson($deliveries);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getDeliveriesByRecipient(Request $request, Response $response, $args) {
    $params['RecipientID'] = $args['RecipientID'];
    $paginator = paginator($request);
    $jwt = $request->getAttribute('jwt');

    # Validar solo el guia o un admin puede consultar sus booking
    if ($jwt->data->UserID !== $params['RecipientID'] && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to view deliveries from this user."
        ]
      ]);
    }

    $pValidation = ParameterValidator::validate($response, 'notifications','get_deliveries_by_recipient', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $deliveries = $this->notification->getDeliveriesByRecipient($paginator, $params['RecipientID']);
      if(!$deliveries){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DELIVERY_NOT_FOUND",
            "desc" => "No delivery associated with the specified id was found"
          ]
        ]);
      }

      return $response->withJson($deliveries);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getInAppNotifications(Request $request, Response $response, $args) {
    $queryParams = $request->getQueryParams();
    $jwt = $request->getAttribute('jwt');

    $userID = $jwt->data->UserID;
    $params = [
      "From" => $queryParams['from'] ?? null, # Id de notificacion minimo
      "To" => $queryParams['to'] ?? null, # Id de notificacion maximo
      "Limit" => $queryParams['limit'] ?? null, # Maxima cantidad de publicaciones a traer
      "List" => $queryParams['list'] ?? 'all' # Tipo de listado (todos, no-leidos...)
    ];

    $pValidation = ParameterValidator::validate($response, 'notifications','get_in_app_notifications', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $notifications = $this->notification->getInAppNotifications(
        $userID, $params['From'], $params['To'], $params['Limit'], $params['List']
      );

      return $response->withJson($notifications);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function markInAppNotifications(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'notifications','mark_in_app_notifications', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try{
      $updated = $this->notification->markInAppNotifications($userID, $params['From'], $params['To']);
      return $response->withJson(['marked' => $updated]);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}