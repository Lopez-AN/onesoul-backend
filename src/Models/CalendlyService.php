<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class CalendlyService 
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  /**
   *  Hace una request por cURL a calendly
   *  @param  method: metodo HTTP a utilizar GET | POST | PATCH ...
   *  @param  subdomain: subdominio de calendly, auth, api
   *  @param  headers: cabeceras de la consulta HTTP
   *  @param  postFields: body de la consulta HTTP (opcional)
   *  @return object: { http_code: 200, data: datos }
   *  @return object: { http_code: cod_http_error, error: objeto error }
  **/
  public function calendlyRequest($method, $subdomain, $path, $headers, $postData = null){
    $ch = curl_init("https://$subdomain.calendly.com$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if(in_array($method, ["POST","PATCH","PUT"])){
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    $response = curl_exec($ch);

    // capturar errores y status antes de cerrar
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno || ($httpCode >= 400 && $httpCode <= 599)) {
      return (object) [
        "http_code" => $httpCode,
        "error" => [
          "code" => "CALENDLY_API_ERROR",
          "desc" => $curlErrno
            ? "cURL error: $curlError"
            : "Calendly returned HTTP $httpCode",
          "response" => $response // opcional, útil para debug
        ]
      ];
    }

    return (object) [
      "http_code" => 200,
      "data" => json_decode($response)
    ];
  }

  /**
   *  Actualiza info variable del usuario calendly
   *  @param  userUuid: ID de usuario Calendly
   *  @param  slug: nombre del usuario calendly
   *  @param  schedulingUrl: URL de reservas
   *  @param  timezone: zona horaria del usuario calendly
  **/
  public function updateCalendlyUserData($userUuid, $slug, $schedulingUrl, $timezone){
    try {
      $stmt = $this->db->prepare("UPDATE CalendlyConnections
        SET Slug = :slug, SchedulingUrl = :schedulingUrl, Timezone = :timezone
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':slug',           $slug,           PDO::PARAM_STR);
      $stmt->bindParam(':schedulingUrl',  $schedulingUrl,  PDO::PARAM_STR);
      $stmt->bindParam(':timezone',       $timezone,       PDO::PARAM_STR);
      $stmt->bindParam(':userUuid',       $userUuid,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Elimina un usuario calendly
   *  @param  userUuid: ID de usuario Calendly
  **/
  public function deleteCalendlyUser($userUuid){
    try {
      $stmt = $this->db->prepare("DELETE FROM CalendlyConnections
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':userUuid',       $userUuid,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Actualiza tokens del usuario calendly
   *  @param  userUuid: ID de usuario Calendly
   *  @param  accessToken: token de acceso para la API
   *  @param  refreshToken: token para obtener nuevo accesstoken cuando este expira
   *  @param  tokenExpiresAt: fechahora de expiracion del accesstoken
  **/
  public function updateCalendlyUserTokens($userUuid, $accessToken, $refreshToken, $tokenExpiresAt){
    try {
      $stmt = $this->db->prepare("UPDATE CalendlyConnections
        SET AccessToken = :accessToken, RefreshToken = :refreshToken, TokenExpiresAt = :tokenExpiresAt
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':accessToken',    $accessToken,    PDO::PARAM_STR);
      $stmt->bindParam(':refreshToken',   $refreshToken,   PDO::PARAM_STR);
      $stmt->bindParam(':tokenExpiresAt', $tokenExpiresAt, PDO::PARAM_STR);
      $stmt->bindParam(':userUuid',       $userUuid,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Guarda el webhook del usuario calendly en la base de datos
   *  @param  userUuid: ID de usuario Calendly
   *  @param  webhook: UUID del webhook
  **/
  public function saveCalendlyUserWebhook($userUuid, $webhook){
    try {
      $stmt = $this->db->prepare("UPDATE CalendlyConnections SET webhook = :webhook
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':userUuid',    $userUuid,    PDO::PARAM_STR);
      $stmt->bindParam(':webhook', $webhook, PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Guarda el usuario calendly en la base de datos
   *  @param  userId: ID usuario OneSoul
   *  @param  accessToken: token de acceso para la API
   *  @param  refreshToken: token para obtener nuevo accesstoken cuando este expira
   *  @param  tokenExpiresAt: fechahora de expiracion del accesstoken
  **/
  public function saveCalendlyUser($userID, $accessToken, $refreshToken, $tokenExpiresAt, $data){
    try {
      $stmt = $this->db->prepare("REPLACE INTO CalendlyConnections
        (UserUUID, UserID, OrgUUID, Slug, SchedulingUrl, Timezone,
        AccessToken, RefreshToken, TokenExpiresAt)
        VALUES (:userUUID, :userId, :orgUUID, :slug, :schedulingUrl, :timezone,
        :accessToken, :refreshToken, :tokenExpiresAt)");

      $userUuid = basename($data->uri);
      $orgUuid = basename($data->current_organization);

      $stmt->bindParam(':userUUID',       $userUuid,             PDO::PARAM_STR);
      $stmt->bindParam(':userId',         $userID,               PDO::PARAM_INT);
      $stmt->bindParam(':orgUUID',        $orgUuid,              PDO::PARAM_STR);
      $stmt->bindParam(':slug',           $data->slug,           PDO::PARAM_STR);
      $stmt->bindParam(':schedulingUrl',  $data->scheduling_url, PDO::PARAM_STR);
      $stmt->bindParam(':timezone',       $data->timezone,       PDO::PARAM_STR);

      // Tokens y expiración desde tu token exchange
      $stmt->bindParam(':accessToken',    $accessToken,          PDO::PARAM_STR);
      $stmt->bindParam(':refreshToken',   $refreshToken,         PDO::PARAM_STR);
      $stmt->bindParam(':tokenExpiresAt', $tokenExpiresAt,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Guarda los datos recibidos por el webhook luego de verificar su firma
   *  @param  payload: datos recibidos por el webhook desde calendly
  **/
  public function inviteCreated($payload){
    try {
      $uuid = basename($payload->payload->uri);

      // Evito request repetidas
      $stmt0= $this->db->prepare("SELECT UUID FROM CalendlyWebhooks
        WHERE UUID = :uuid");
      $stmt0->bindParam(':uuid',          $uuid,           PDO::PARAM_STR);
      $stmt0->execute();
      $events = $stmt0->fetchAll(PDO::FETCH_ASSOC);

      if(!empty($events)){
        return;
      }

      $stmt = $this->db->prepare("INSERT INTO CalendlyWebhooks
        (UUID, UserUUID, EventUUID, UserID, BookingID, OfferingID, AssocUUID,
        CreatedAt, Event, CancelUrl, RescheduleUrl, Email, StartTime, EndTime, Timezone)
        VALUES (:uuid, :userUuid, :eventUuid, :userId, :bookingId, :offeringId, :assocUuid,
        :createdAt, :event, :cancelUrl, :rescheduleUrl, :email, :startTime,
        :endTime, :timezone)");

      $utmContent = json_decode(base64_decode($payload->payload->tracking->utm_content));

      $userUuid = basename($payload->created_by);
      $eventUuid = basename($payload->payload->event);

      $stmt->bindParam(':uuid',          $uuid,           PDO::PARAM_STR);
      $stmt->bindParam(':userUuid',      $userUuid,       PDO::PARAM_STR);
      $stmt->bindParam(':eventUuid',     $eventUuid,      PDO::PARAM_STR);

      $userID = $utmContent->UserId;
      $bookingId = $utmContent->BookingId;
      $offeringId = $utmContent->OfferingId;
      $assocUuid = $utmContent->Uuid;

      $stmt->bindParam(':userId',        $userID,                           PDO::PARAM_INT);
      $stmt->bindParam(':bookingId',     $bookingId,                        PDO::PARAM_INT);
      $stmt->bindParam(':offeringId',    $offeringId,                       PDO::PARAM_INT);
      $stmt->bindParam(':assocUuid',     $assocUuid,                        PDO::PARAM_STR);

      $createdAt = $this -> _calendlyToMysqlDatetime($payload->created_at);

      $stmt->bindParam(':createdAt',     $createdAt,                        PDO::PARAM_STR);
      $stmt->bindParam(':event',         $payload->event,                   PDO::PARAM_STR);
      $stmt->bindParam(':cancelUrl',     $payload->payload->cancel_url,     PDO::PARAM_STR);
      $stmt->bindParam(':rescheduleUrl', $payload->payload->reschedule_url, PDO::PARAM_STR);
      $stmt->bindParam(':email',         $payload->payload->email,          PDO::PARAM_STR);

      $startTime = $this -> _calendlyToMysqlDatetime($payload->payload->scheduled_event->start_time);
      $endTime = $this -> _calendlyToMysqlDatetime($payload->payload->scheduled_event->end_time);

      $stmt->bindParam(':startTime',     $startTime,                        PDO::PARAM_STR);
      $stmt->bindParam(':endTime',       $endTime,                          PDO::PARAM_STR);
      $stmt->bindParam(':timezone',      $payload->payload->timezone,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Devuelve un user de calendly almacenado
   *  @param  userId: ID usuario OneSoul
   *  @return: datos del usuario calendly
  **/
  public function getCalendlyUser($userID){
    try {
      $stmt = $this->db->prepare("SELECT * FROM CalendlyConnections
        WHERE UserID = :userId");
      $stmt->bindParam(':userId', $userID, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Hace un HEAD a una url y devuelve headers sin redirigir
   *  @param  url: URL destino
   *  @return (object): http_code + headers
  **/
  public function httpHead($url) {
    $ch = curl_init();

    curl_setopt_array($ch, [
      CURLOPT_URL            => $url,
      CURLOPT_NOBODY         => true,   // HEAD en lugar de GET
      CURLOPT_FOLLOWLOCATION => false,  // no seguir 301/302
      CURLOPT_HEADER         => true,   // incluir headers en la respuesta
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 5,
    ]);

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headers = [];
    if ($raw !== false) {
      $lines = explode("\r\n", $raw);
      foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
          [$k, $v] = explode(':', $line, 2);
          $headers[trim($k)] = trim($v);
        }
      }
    }

    curl_close($ch);

    return (object)[
      'http_code' => $code,
      'headers'   => $headers
    ];
  }

  /**
   *  Convierte una fecha de formato "2025-08-31T18:31:58.000000Z" -> "2025-08-31 18:31:58.000000"
   *  @param  iso8601: fecha en formato iso8601
   *  @return fecha en formato mysql
  **/
  private function _calendlyToMysqlDatetime($iso8601) {
    // Convierte "2025-08-31T18:31:58.000000Z" -> "2025-08-31 18:31:58.000000"
    $dt = new \DateTime($iso8601);
    return $dt->format("YmdHis");
  }
}