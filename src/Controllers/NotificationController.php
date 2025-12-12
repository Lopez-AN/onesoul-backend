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

  /**
   * Crea una nueva notificación (endpoint administrativo)
   *
   * Permite a los administradores crear notificaciones manualmente para cualquier usuario.
   * Valida que el usuario destinatario exista antes de crear la notificación.
   *
   * @param Request $request Objeto de request HTTP con los datos de la notificación en el body
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con las entregas creadas o error
   *
   * @statusCode 200 Notificación creada exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 403 Usuario no autorizado (requiere permisos de administrador)
   * @statusCode 404 Usuario destinatario no encontrado
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene la próxima entrega pendiente de envío
   *
   * Utilizado por el worker de notificaciones. Los usuarios normales solo pueden
   * obtener sus propias entregas, mientras que los administradores pueden obtener
   * cualquier entrega pendiente.
   *
   * @param Request $request Objeto de request HTTP
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con los datos de la entrega o null si no hay entregas pendientes
   *
   * @statusCode 200 Operación exitosa (puede devolver null si no hay entregas)
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene una entrega específica por su ID
   *
   * Los usuarios normales solo pueden consultar sus propias entregas, mientras que
   * los administradores pueden consultar cualquier entrega.
   *
   * @param Request $request Objeto de request HTTP
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta con DeliveryID
   * @return Response JSON con los datos de la entrega o error
   *
   * @statusCode 200 Entrega encontrada exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 404 Entrega no encontrada
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene todas las entregas asociadas a una notificación
   *
   * Los usuarios normales solo pueden consultar entregas de sus propias notificaciones,
   * mientras que los administradores pueden consultar cualquier notificación.
   *
   * @param Request $request Objeto de request HTTP
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta con NotificationID
   * @return Response JSON con la lista de entregas o error
   *
   * @statusCode 200 Entregas encontradas exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 404 Notificación no encontrada
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene entregas filtradas por canal de comunicación
   *
   * Permite consultar todas las entregas de un canal específico (EMAIL, WHATSAPP, SMS).
   * Los usuarios normales solo ven sus propias entregas, los administradores ven todas.
   *
   * @param Request $request Objeto de request HTTP con parámetros de paginación
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta con Channel
   * @return Response JSON con entregas paginadas o error
   *
   * @statusCode 200 Entregas encontradas exitosamente
   * @statusCode 400 Parámetros inválidos (canal no válido)
   * @statusCode 404 No se encontraron entregas para el canal especificado
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene todas las entregas de un usuario específico
   *
   * Los usuarios solo pueden consultar sus propias entregas, mientras que los
   * administradores pueden consultar las entregas de cualquier usuario.
   *
   * @param Request $request Objeto de request HTTP con parámetros de paginación
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta con RecipientID
   * @return Response JSON con entregas paginadas o error
   *
   * @statusCode 200 Entregas encontradas exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 403 Usuario no autorizado para ver entregas de otro usuario
   * @statusCode 404 No se encontraron entregas para el usuario
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Obtiene las notificaciones in-app del usuario autenticado
   *
   * Permite filtrar por rango de IDs, límite de resultados y estado de lectura
   * (leídas, no leídas, todas). Solo devuelve notificaciones del usuario actual.
   *
   * @param Request $request Objeto de request HTTP con query params opcionales (from, to, limit, list)
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con notificaciones in-app y metadatos de paginación
   *
   * @statusCode 200 Notificaciones obtenidas exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 500 Error interno del servidor
   */
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

  /**
   * Marca notificaciones in-app como leídas
   *
   * Actualiza el estado de las notificaciones in-app dentro del rango de IDs especificado
   * para el usuario autenticado. Solo puede marcar sus propias notificaciones.
   *
   * @param Request $request Objeto de request HTTP con From y To en el body
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con cantidad de notificaciones marcadas
   *
   * @statusCode 200 Notificaciones marcadas exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 500 Error interno del servidor
   */
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