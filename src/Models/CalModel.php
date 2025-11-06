<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;

class CalModel {
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
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
      $stmt = $this->db->prepare("UPDATE CalConnections
        SET Slug = :slug, SchedulingUrl = :schedulingUrl, Timezone = :timezone
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':slug',           $slug,           PDO::PARAM_STR);
      $stmt->bindParam(':schedulingUrl',  $schedulingUrl,  PDO::PARAM_STR);
      $stmt->bindParam(':timezone',       $timezone,       PDO::PARAM_STR);
      $stmt->bindParam(':userUuid',       $userUuid,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Elimina un usuario calendly
   *  @param  userUuid: ID de usuario Calendly
  **/
  public function deleteCalUser($calUserID){
    $stmt = $this->db->prepare("DELETE FROM CalConnections
      WHERE CalUserID = ?");
    $stmt->execute([$calUserID]);
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
      $stmt = $this->db->prepare("UPDATE CalConnections
        SET AccessToken = :accessToken, RefreshToken = :refreshToken, TokenExpiresAt = :tokenExpiresAt
        WHERE UserUUID = :userUuid");

      $stmt->bindParam(':accessToken',    $accessToken,    PDO::PARAM_STR);
      $stmt->bindParam(':refreshToken',   $refreshToken,   PDO::PARAM_STR);
      $stmt->bindParam(':tokenExpiresAt', $tokenExpiresAt, PDO::PARAM_STR);
      $stmt->bindParam(':userUuid',       $userUuid,       PDO::PARAM_STR);

      $stmt->execute();
    } catch (PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Guarda el webhook del usuario calendly en la base de datos
   *  @param  userUuid: ID de usuario Calendly
   *  @param  webhook: UUID del webhook
  **/
  public function saveCalUserWebhook($calUserID, $webhook){
    $stmt = $this->db->prepare("UPDATE CalConnections SET Webhook = ?
      WHERE CalUserID = ?");
    $stmt->execute([$webhook, $calUserID]);
  }

  /**
   *  Guarda el usuario calendly en la base de datos
   *  @param  calUserId: ID usuario de Cal.com
   *  @param  userId: ID usuario OneSoul
   *  @param  accessToken: token de acceso para la API
   *  @param  refreshToken: token para obtener nuevo accesstoken cuando este expira
   *  @param  tokenExpiresAt: fechahora de expiracion del accesstoken
  **/
  public function saveCalUser(
    $calUserID, $userID, $accessToken, $refreshToken, $tokenExpiresAt, $slug, $schedulingUrl, $timeZone
  ){
    file_put_contents(ROOT."/debug.log", json_encode([$calUserID, $userID, $slug, $schedulingUrl, $timeZone, $accessToken, $refreshToken, $tokenExpiresAt]));
    $stmt = $this->db->prepare("REPLACE INTO CalConnections
      (CalUserID, UserID, Slug, SchedulingUrl, TimeZone, AccessToken, RefreshToken, TokenExpiresAt)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$calUserID, $userID, $slug, $schedulingUrl, $timeZone, $accessToken, $refreshToken, $tokenExpiresAt]);
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
    } catch (PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   *  Devuelve un user de calendly almacenado
   *  @param  userId: ID usuario OneSoul
   *  @return: datos del usuario calendly
  **/
  public function getCalUser($userID){
    $stmt = $this->db->prepare("SELECT * FROM CalConnections
      WHERE UserID = ?");
    $stmt->execute([$userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
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
    $dt = new DateTime($iso8601);
    return $dt->format("YmdHis");
  }
}