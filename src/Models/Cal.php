<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use DateTime;

class Cal {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Actualiza datos variables del usuario Cal.com
   * Sincroniza información del perfil de Cal.com como slug, URL de reservas y zona horaria
   * @param int $calUserID ID del usuario en Cal.com
   * @param string $slug Slug del usuario Cal.com (usado en la URL de reservas)
   * @param string $schedulingUrl URL completa de reservas del usuario
   * @param string $timeZone Zona horaria del usuario (ej: America/Buenos_Aires)
   * @return void
   * @throws PDOException Si hay error en la base de datos
   */
  public function updateCalUserData($calUserID, $slug, $schedulingUrl, $timeZone){
    $stmt = $this->db->prepare("UPDATE CalConnections
      SET Slug = ?, SchedulingUrl = ?, TimeZone = ?
      WHERE CalUserID = ?");
    $stmt->execute([$slug, $schedulingUrl, $timeZone, $calUserID]);
  }

  /**
   * Elimina un usuario Cal.com de la base de datos
   * Se ejecuta cuando el usuario desvincula su cuenta de Cal.com
   * @param int $calUserID ID del usuario en Cal.com
   * @return void
   * @throws PDOException Si hay error en la base de datos
   */
  public function deleteCalUser($calUserID){
    $stmt = $this->db->prepare("DELETE FROM CalConnections
      WHERE CalUserID = ?");
    $stmt->execute([$calUserID]);
  }

  /**
   * Actualiza los tokens OAuth del usuario Cal.com
   * Almacena nuevos tokens cuando se refresca la sesión OAuth
   * @param int $calUserID ID del usuario en Cal.com
   * @param string $accessToken Token de acceso para la API de Cal.com (temporal)
   * @param string $refreshToken Token para renovar el accessToken (de larga duración)
   * @return void
   * @throws PDOException Si hay error en la base de datos
   */
  public function updateCalUserTokens($calUserID, $accessToken, $refreshToken){
    $stmt = $this->db->prepare("UPDATE CalConnections
      SET AccessToken = ?, RefreshToken = ?
      WHERE CalUserID = ?");
    $stmt->execute([$accessToken, $refreshToken, $calUserID]);
  }

  /**
   * Almacena el UUID del webhook en Cal.com
   * Se guarda después de crear exitosamente el webhook en Cal.com
   * @param int $calUserID ID del usuario en Cal.com
   * @param string $webhook UUID del webhook generado en Cal.com
   * @return void
   * @throws PDOException Si hay error en la base de datos
   */
  public function updateCalUserWebhook($calUserID, $webhook){
    $stmt = $this->db->prepare("UPDATE CalConnections SET Webhook = ?
      WHERE CalUserID = ?");
    $stmt->execute([$webhook, $calUserID]);
  }

  /**
   * Guarda un nuevo usuario Cal.com vinculado a OneSoul
   * Se ejecuta cuando el usuario completa exitosamente el OAuth con Cal.com
   * @param int $calUserID ID del usuario en Cal.com
   * @param int $userID ID del usuario en OneSoul
   * @param string $accessToken Token de acceso para la API de Cal.com
   * @param string $refreshToken Token para renovar accessToken
   * @param string $slug Slug del usuario Cal.com
   * @param string $schedulingUrl URL base de reservas del usuario
   * @param string $timeZone Zona horaria del usuario
   * @return void
   * @throws PDOException Si hay error en la base de datos
   */
  public function saveCalUser(
    $calUserID, $userID, $accessToken, $refreshToken, $slug, $schedulingUrl, $timeZone
  ){
    $stmt = $this->db->prepare("REPLACE INTO CalConnections
      (CalUserID, UserID, Slug, SchedulingUrl, TimeZone, AccessToken, RefreshToken)
      VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$calUserID, $userID, $slug, $schedulingUrl, $timeZone, $accessToken, $refreshToken]);
  }

  /**
   * Almacena una nueva reserva recibida por webhook de Cal.com
   * Se ejecuta cuando Cal.com notifica de una nueva reserva (BOOKING_CREATED)
   * Los datos se validan previamente en el controlador antes de llamar este método
   * @param string $createdAt Fecha/hora de creación de la cita en Cal.com (ISO 8601)
   * @param int $seekerID ID del buscador en OneSoul
   * @param int $guideID ID del guía en OneSoul
   * @param int $offeringID ID de la publicación/servicio en OneSoul
   * @param object $payload Objeto con los datos completos del evento de Cal.com
   * @return void
   * @throws DatabaseException Si hay error en la inserción
   */
  public function bookingCreated($createdAt, $seekerID, $guideID, $offeringID, $assocUUID, $payload){
    try {
      $stmt = $this->db->prepare("INSERT INTO CalWebhooks
        (Event, Uid, AssocUUID, GuideID, SeekerID, CalUserID, OfferingID,
        CreatedAt, CancelUrl, RescheduleUrl, Email, StartTime,
        EndTime, TimeZone, EventTitle, EventComment, Length)
        VALUES ('BOOKING_CREATED', :uid, :assocUUID, :guideId, :seekerId,
        :calUserId, :offeringId, :createdAt, :cancelUrl, :rescheduleUrl,
        :email, :startTime, :endTime, :timeZone, :eventTitle, :eventComment, :length)");

      $stmt->execute([
        ':uid' => $payload->uid,
        ':assocUUID' => $assocUUID,
        ':guideId' => $guideID,
        ':seekerId' => $seekerID,
        ':calUserId' => $payload->organizer->id,
        ':offeringId' => $offeringID,
        ':createdAt' => $this->_calZoneAndFormat($createdAt, $payload->organizer->utcOffset),
        ':cancelUrl' => "{$payload->bookerUrl}/cancel/{$payload->uid}",
        ':rescheduleUrl' => "{$payload->bookerUrl}/reschedule/{$payload->uid}",
        ':email' => $payload->attendees[0]->email ?? '',
        ':startTime' => $this->_calZoneAndFormat($payload->startTime, $payload->organizer->utcOffset),
        ':endTime' => $this->_calZoneAndFormat($payload->endTime, $payload->organizer->utcOffset),
        ':timeZone' => $payload->organizer->timeZone ?? 'UTC',
        ':eventTitle' => $payload->title,
        ':eventComment' => $payload->description ?? null,
        ':length' => $payload->length ?? 60
      ]);
    } catch (PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene los datos de un usuario Cal.com vinculado
   * @param int $userID ID del usuario en OneSoul
   * @return array|null Datos del usuario Cal.com o null si no existe vinculación
   * @throws PDOException Si hay error en la consulta
   */
  public function getCalUser($userID){
    $stmt = $this->db->prepare("SELECT * FROM CalConnections
      WHERE UserID = ?");
    $stmt->execute([$userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Convierte fecha ISO 8601 de Cal.com a formato MySQL DATETIME
   * Transforma "2025-08-31T18:31:58.000000Z" a "2025-08-31 18:31:58.000000"
   * y aplica el desplazamiento de zona horaria
   * @param string $iso8601 Fecha en formato ISO 8601
   * @param int $utcOffset Desplazamiento de zona horaria en minutos
   * @return string Fecha en formato MySQL DATETIME
   */
  private function _calZoneAndFormat($iso8601, $utcOffset) {
    # Convierte "2025-08-31T18:31:58.000000Z" -> "2025-08-31 18:31:58.000000"
    $dt = new DateTime($iso8601);
    $dt -> modify("$utcOffset minutes");
    return $dt->format("YmdHis");
  }
}