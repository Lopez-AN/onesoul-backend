<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use DateTime;

class Booking {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene los datos completos de una reserva por su ID numérico
   * Incluye información del seeker, guía, offering y eventos
   * @param int $bookingID: ID numérico de la reserva
   * @return array|false: datos de la reserva o false si no existe
   */
  public function getBookingByID($bookingID) {
    $stmt = $this->db->prepare("SELECT b.BookingID, b.PublicID,
      b.ReviewID, b.PaymentID, b.Mode as SessionType, b.LocationID, b.CreationDate,
      b.ScheduledDate, b.ModificationDate, o.UserID AS Guide, u.DisplayName, b.OfferingID,
      o.Title AS TitleOffering, b.ScheduledDate, b.Currency, b.Amount, b.VoucherID,
      b.LastBookingEvent,
      -- Subconsulta para seeker
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL
        ))
        FROM Users as u
        LEFT JOIN Media AS m
          ON m.userID = u.userID
        WHERE u.UserID = b.UserID
      ) AS seeker_info,
      -- Subconsulta para eventos
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'EventDate', s.BookingEventDate,
          'Event', s.BookingEvent,
          'ScheduledDate', s.ScheduledDate,
          'Message', s.Message
        ))
        FROM BookingStatus as s
        WHERE s.BookingID = b.BookingID
      ) AS booking_events
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON o.UserID = u.UserID
      WHERE b.BookingID = ?"
    );
    $stmt->execute([$bookingID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this->_getBookingsGeneric($booking);
  }

  /**
   * Obtiene los datos completos de una reserva por su ID público
   * Incluye información del seeker, guía, offering y eventos
   * @param string $publicID: ID público de la reserva (formato: XX-YYMM-T-HASH)
   * @return array|false: datos de la reserva o false si no existe
   */
  public function getBookingByPublicID($publicID) {
    $stmt = $this->db->prepare("SELECT b.BookingID, b.PublicID,
      b.ReviewID, b.PaymentID, b.Mode as SessionType, b.LocationID, b.CreationDate,
      b.ScheduledDate, b.ModificationDate, o.UserID AS Guide, u.DisplayName, b.OfferingID,
      o.Title AS TitleOffering, b.ScheduledDate, b.Currency, b.Amount, b.VoucherID,
      b.LastBookingEvent,
      -- Subconsulta para seeker
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL
        ))
        FROM Media AS m
        INNER JOIN Users as u
          ON m.userID = u.userID
        WHERE m.UserID = b.UserID
      ) AS seeker_info,
      -- Subconsulta para eventos
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'EventDate', s.BookingEventDate,
          'Event', s.BookingEvent,
          'ScheduledDate', s.ScheduledDate,
          'Message', s.Message
        ))
        FROM BookingStatus as s
        WHERE s.BookingID = b.BookingID
      ) AS booking_events
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON o.UserID = u.UserID
      INNER JOIN Media AS m ON m.UserID = b.UserID
      WHERE b.PublicID = ?");
    $stmt->execute([$publicID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this->_getBookingsGeneric($booking);
  }

  /**
   * Obtiene todas las reservas de un guía específico con paginación
   * Permite filtrar solo reservas abiertas (no canceladas/completadas/calificadas)
   * @param int $guideID: ID del usuario guía
   * @param object $paginator: objeto con propiedades limit y offset para paginación
   * @param bool $onlyOpen: true para filtrar solo reservas abiertas, false para todas
   * @return array: { data: [], rows: { total: int, fetched: int } }
   */
  public function getBookingsByGuide($guideID, $paginator, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    # Consulta completa paginada
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS b.BookingID, b.PublicID,
      b.ReviewID, b.PaymentID, b.Mode as SessionType, b.LocationID, b.CreationDate,
      b.ScheduledDate, b.ModificationDate, o.UserID AS Guide, u.DisplayName, b.OfferingID,
      o.Title AS TitleOffering, b.ScheduledDate, b.Currency, b.Amount, b.VoucherID,
      b.LastBookingEvent,
      -- Subconsulta para seeker
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL
        ))
        FROM Media AS m
        INNER JOIN Users as u
          ON m.userID = u.userID
        WHERE m.UserID = b.UserID
      ) AS seeker_info,
      -- Subconsulta para eventos
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'EventDate', s.BookingEventDate,
          'Event', s.BookingEvent,
          'ScheduledDate', s.ScheduledDate,
          'Message', s.Message
        ))
        FROM BookingStatus as s
        WHERE s.BookingID = b.BookingID
      ) AS booking_events
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON o.UserID = u.UserID
      INNER JOIN Media AS m ON m.UserID = b.UserID
      WHERE o.UserID = ? {$filterOpen}
      GROUP BY b.BookingID
      ORDER BY b.CreationDate DESC
      LIMIT ? OFFSET ?");

    $stmt->execute([$guideID, $paginator->limit, $paginator->offset]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getBookingsGenericMulti($bookings, $total['total']);
  }

  /**
   * Obtiene todas las reservas de un buscador específico con paginación
   * Permite filtrar solo reservas abiertas (no canceladas/completadas/calificadas)
   * @param int $seekerID: ID del usuario buscador
   * @param object $paginator: objeto con propiedades limit y offset para paginación
   * @param bool $onlyOpen: true para filtrar solo reservas abiertas, false para todas
   * @return array: { data: [], rows: { total: int, fetched: int } }
   */
  public function getBookingsBySeeker($seekerID, $paginator, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    # Consulta completa paginada
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS b.BookingID, b.PublicID,
      b.ReviewID, b.PaymentID, b.Mode as SessionType, b.LocationID, b.CreationDate,
      b.ScheduledDate, b.ModificationDate, o.UserID AS Guide, u.DisplayName, b.OfferingID,
      o.Title AS TitleOffering, b.ScheduledDate, b.Currency, b.Amount, b.VoucherID,
      b.LastBookingEvent,
      -- Subconsulta para seeker
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL
        ))
        FROM Media AS m
        INNER JOIN Users as u
          ON m.userID = u.userID
        WHERE m.UserID = b.UserID
      ) AS seeker_info,
      -- Subconsulta para eventos
      (
        SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'EventDate', s.BookingEventDate,
          'Event', s.BookingEvent,
          'ScheduledDate', s.ScheduledDate,
          'Message', s.Message
        ))
        FROM BookingStatus as s
        WHERE s.BookingID = b.BookingID
      ) AS booking_events
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON o.UserID = u.UserID
      INNER JOIN Media AS m ON m.UserID = b.UserID
      WHERE b.UserID = ? {$filterOpen}
      GROUP BY b.BookingID
      ORDER BY b.CreationDate DESC
      LIMIT ? OFFSET ?");

    $stmt->execute([$seekerID, $paginator->limit, $paginator->offset]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getBookingsGenericMulti($bookings, $total['total']);
  }

  /**
   * Obtiene la cantidad total de servicios completados por un guía
   * Cuenta reservas con estado 'Rated' o 'Completed'
   * @param int $guideID: ID del usuario guía
   * @return array: { CompletedBookings: int }
   */
  public function getGuideCompletedBookings($guideID) {
    $stmt = $this->db->prepare("SELECT COUNT(*) as CompletedBookings
      FROM Bookings as b
      INNER JOIN Offerings as o
        ON b.OfferingID = o.OfferingID
      WHERE b.LastBookingEvent IN ('Rated','Completed')
      AND o.UserID = ?");
    $stmt->execute([$guideID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Procesa y normaliza datos de un booking individual
   *
   * Trae los eventos e información para el booking.
   *
   * @param  array|null $booking: datos del booking obtenido de la BD o null
   * @return array|false: datos del booking normalizados o false si no existe
   **/
  private function _getBookingsGeneric($booking){
    if (empty($booking)) {
      return false;
    }

    $seeker = @json_decode($booking['seeker_info'], true);
    $booking['Seeker'] = $seeker ? array_shift($seeker) : null;
    unset($booking['seeker_info']);

    $booking['Events'] = @json_decode($booking['booking_events'], true);
    unset($booking['booking_events']);

    if(!empty($booking['Events'])){
      usort($booking['Events'] ?? [], function($a, $b){
        return $a['EventDate'] < $b['EventDate'] ? 1 : -1;
      });
    }

    return $booking;
  }

  /**
   * Procesa y normaliza múltiples registros de booking
   *
   * Trae los eventos e información para un conjunto de bookings.
   * Retorna en formato paginado.
   *
   * @param  array $bookings: array de bookings obtenidas de la BD
   * @param  int $total: cantidad total de registros disponibles
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  private function _getBookingsGenericMulti($bookings, $total){
    foreach ($bookings as &$e) {
      $seeker = @json_decode($e['seeker_info'], true);
      $e['Seeker'] = $seeker ? array_shift($seeker) : null;

      $e['Events'] = @json_decode($e['booking_events'], true);
      unset($e['booking_events']);

      if(!empty($e['Events'])){
        usort($e['Events'], function($a, $b){
          return $a['EventDate'] < $b['EventDate'] ? 1 : -1;
        });
      }
    }

    return [
      "data" => $bookings,
      "rows" => [
        "total" => $total,
        "fetched" => count($bookings)
      ]
    ];
  }

  /**
   * Cuenta el total de reservas de un guía
   * Permite filtrar solo reservas abiertas
   * @param int $guideID: ID del usuario guía
   * @param bool $onlyOpen: true para contar solo reservas abiertas
   * @return int: cantidad de reservas encontradas
   */
  public function countBookingsByGuide($guideID, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    $stmt = $this->db->prepare("SELECT COUNT(*) AS found
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      WHERE o.UserID = ? {$filterOpen}"
    );
    $stmt->execute([$guideID]);
    $bookings = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($bookings && isset($bookings['found'])) ? $bookings['found'] : 0;
  }

  /**
   * Cuenta el total de reservas de un buscador
   * Permite filtrar solo reservas abiertas
   * @param int $seekerID: ID del usuario buscador
   * @param bool $onlyOpen: true para contar solo reservas abiertas
   * @return int: cantidad de reservas encontradas
   */
  public function countBookingsBySeeker($seekerID, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    $stmt = $this->db->prepare("SELECT COUNT(*) AS found
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      WHERE b.UserID = ? {$filterOpen}"
    );
    $stmt->execute([$seekerID]);
    $bookings = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($bookings && isset($bookings['found'])) ? $bookings['found'] : 0;
  }

  /**
   * Crea una nueva reserva en el sistema
   * Inserta booking, evento inicial 'Pending', actualiza voucher si existe y vincula Cal.com
   * Usa transacciones para garantizar consistencia de datos
   * @param array $data: datos de la reserva (OfferingID, PublicID, SeekerID, SessionType, LocationID, ScheduledDate, Currency, Amount)
   * @param string $subDomain: subdominio de la agencia (opcional)
   * @param string|null $assocUUID: UUID de Cal.com para vincular con webhook
   * @param int|null $voucherID: ID del voucher/cupón a redimir
   * @return array: datos completos de la reserva creada
   * @throws DatabaseException: si falla la creación o recuperación del booking
   */
  public function createBooking($data, $subDomain, $assocUUID, $voucherID) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("INSERT INTO Bookings
        (OfferingID, PublicID, UserID, Mode, LocationID,
        CreationDate, ScheduledDate, VoucherID, Currency, Amount)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
      $stmt->execute([
        $data['OfferingID'],
        $data['PublicID'],
        $data['SeekerID'],
        $data['SessionType'],
        $data['LocationID'],
        $data['ScheduledDate']?->format("YmdHis") ?? null,
        $voucherID,
        $data['Currency'],
        $data['Amount']
      ]);

      $bookingID = $this->db->lastInsertId();

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, ScheduledDate, Message)
        VALUES (?, 'Pending', ?, ?)");
      $stmt->execute([$bookingID, $data['ScheduledDate']?->format("YmdHis") ?? null, $data['Message']]);

      if($voucherID){
        $stmt = $this->db->prepare("UPDATE DonationVouchers
          SET WinnerUserID = ?, RedeemedAt = NOW(), Status = 'redeemed'
          WHERE VoucherID = ?");
        $stmt->execute([$data['SeekerID'], $voucherID]);
      }

      if($assocUUID){
        $stmt = $this->db->prepare("UPDATE CalWebhooks
          SET BookingID = ?
          WHERE AssocUUID = ?");
        $stmt->execute([$bookingID, $assocUUID]);
      }

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the created booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Verifica si un usuario tiene conexión activa con Cal.com
   * @param int $userID: ID del usuario a verificar
   * @return bool: true si tiene conexión, false en caso contrario
   */
  public function userHasCal($userID){
    $stmt = $this->db->prepare("SELECT 1 FROM CalConnections
      WHERE UserID = ? LIMIT 1");
    $stmt->execute([$userID]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * Busca un invitee de Cal.com sin booking asociado
   * Verifica que el evento sea 'BOOKING_CREATED' y pertenezca al guía
   * @param string $assocUUID: UUID asociado al webhook de Cal.com
   * @param int $userID: ID del guía propietario del evento
   * @return array|false: datos del webhook o false si no existe
   */
  public function findCalInvitee($assocUUID, $userID) {
    $stmt = $this->db->prepare("SELECT *
      FROM CalWebhooks
      WHERE BookingID IS NULL AND AssocUUID = ?
      AND Event = 'BOOKING_CREATED' AND GuideID = ?");
    $stmt->execute([$assocUUID, $userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Vincula una reserva con un webhook de Cal.com
   * @param string $assocUUID: UUID asociado al webhook
   * @param int $bookingID: ID de la reserva a vincular
   */
  public function linkBookingWithCal($assocUUID, $bookingID) {
    $stmt = $this->db->prepare("UPDATE CalWebhooks
      SET BookingID = ?
      WHERE BookingID IS NULL AND AssocUUID = ?
      AND Event = 'BOOKING_CREATED'");
    $stmt->execute([$bookingID, $assocUUID]);
  }

  /**
   * Actualiza los datos de una reserva existente
   * Modifica tipo de sesión, fecha programada y crea evento 'Modified'
   * Usa transacciones para garantizar consistencia
   * @param int $bookingID: ID de la reserva a actualizar
   * @param string $sessionType: tipo de sesión ('in-person' o 'virtual')
   * @param string|null $scheduledDate: fecha programada en formato ISO o Y-m-d H:i:s
   * @param string|null $message: mensaje del cliente/guía
   * @param int|null $locationID: ID de la ubicación (para sesiones presenciales)
   * @param string $subDomain: subdominio de la agencia
   * @return array: datos completos de la reserva actualizada
   * @throws DatabaseException: si falla la actualización o recuperación
   */
  public function updateBooking($bookingID, $sessionType, $scheduledDate, $message, $locationID, $subDomain) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      if (strpos($scheduledDate, 'T') !== false && strpos($scheduledDate, 'Z') !== false) {
        $datetime = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $scheduledDate);
        if ($datetime) {
          $scheduledDate = $datetime->format('Y-m-d H:i:s');
        }
      }

      # Compara y actualiza SessionType
      $stmt = $this->db->prepare("UPDATE Bookings
        SET Mode = ?, ModificationDate = NOW(),
        LastBookingEvent = 'Modified'
        WHERE BookingID = ?");
      $stmt->execute([$sessionType, $bookingID]);

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, ScheduledDate, Message)
        VALUES (?, ?, ?, ?)");
      $stmt->execute([$bookingID, 'Modified', $scheduledDate, $message]);

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the updated booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Cancela una reserva existente
   * Actualiza estado a 'Canceled' y crea evento correspondiente
   * Usa transacciones para garantizar consistencia
   * @param int $bookingID: ID de la reserva a cancelar
   * @param string|null $message: motivo de cancelación
   * @param string $subDomain: subdominio de la agencia
   * @return array: datos completos de la reserva cancelada
   * @throws DatabaseException: si falla la cancelación o recuperación
   */
  public function cancelBooking($bookingID, $message, $subDomain) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Bookings
        SET ModificationDate = NOW(),
        LastBookingEvent = 'Canceled'
        WHERE BookingID = ?");
      $stmt->execute([$bookingID]);

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, Message)
        VALUES (?, 'Canceled', ?)");
      $stmt->execute([$bookingID, $message]);

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the updated booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Confirma una reserva existente
   * Actualiza estado a 'Confirmed' y crea evento correspondiente
   * Usa transacciones para garantizar consistencia
   * @param int $bookingID: ID de la reserva a confirmar
   * @param string|null $message: mensaje de confirmación del guía
   * @param string $subDomain: subdominio de la agencia
   * @return array: datos completos de la reserva confirmada
   * @throws DatabaseException: si falla la confirmación o recuperación
   */
  public function confirmBooking($bookingID, $message, $subDomain) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Bookings
        SET ModificationDate = NOW(),
        LastBookingEvent = 'Confirmed'
        WHERE BookingID = ?");
      $stmt->execute([$bookingID]);

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, Message)
        VALUES (?, 'Confirmed', ?)");
      $stmt->execute([$bookingID, $message]);

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the updated booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Marca una reserva como completada
   * Crea una reseña del guía sobre el seeker, actualiza estado a 'Completed'
   * y establece FeedbackStatus a 'Pending' (esperando calificación del seeker)
   * Usa transacciones para garantizar consistencia
   * @param int $bookingID: ID de la reserva a completar
   * @param string|null $message: comentario del guía sobre la sesión
   * @param int $seekerID: ID del usuario buscador
   * @param int $guideID: ID del usuario guía
   * @param int $rating: calificación del seeker por el guía (1-5)
   * @param bool $fulfilled: true si la sesión se cumplió, false si no asistió
   * @return array: datos completos de la reserva completada
   * @throws DatabaseException: si falla la operación o recuperación
   */
  public function completeBooking($bookingID, $message, $seekerID, $guideID, $rating, $fulfilled) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

     # Determinar estado a insertar según Fulfilled
      $fulfilled = $fulfilled ? 0 : 1;

      $stmt = $this->db->prepare("INSERT INTO SeekerReviews (SeekerID, GuideID, BookingID, Fulfilled, ReviewText, Rating)
        VALUES (:seekerID, :guideID, :bookingID, :fulfilled, :message, :rating)");
      $stmt->execute([
        ':seekerID' => $seekerID,
        ':guideID' => $guideID,
        ':bookingID' => $bookingID,
        ':fulfilled' => $fulfilled,
        ':message' => $message,
        ':rating' => $rating
      ]);

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, Message)
        VALUES (?, 'Completed', ?)");
      $stmt->execute([$bookingID, $message]);

      $stmt = $this->db->prepare("UPDATE Bookings
        SET ModificationDate = NOW(), FeedbackStatus = 'Pending',
        LastBookingEvent = 'Completed'
        WHERE BookingID = ?");
      $stmt->execute([$bookingID]);

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the updated booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Permite al seeker calificar una reserva completada
   * Crea una reseña del servicio/offering, actualiza estado a 'Rated'
   * y establece FeedbackStatus a 'Submitted'
   * Usa transacciones para garantizar consistencia
   * @param int $offeringID: ID del servicio calificado
   * @param int $bookingID: ID de la reserva a calificar
   * @param string|null $message: comentario del seeker sobre el servicio
   * @param int $seekerID: ID del usuario buscador
   * @param int $guideID: ID del usuario guía
   * @param int $rating: calificación del servicio (1-5)
   * @param bool $fulfilled: true si el servicio cumplió expectativas
   * @return array: datos completos de la reserva calificada
   * @throws DatabaseException: si falla la operación o recuperación
   */
  public function rateBooking($offeringID, $bookingID, $message, $seekerID, $guideID, $rating, $fulfilled) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Determinar estado a insertar según Fulfilled
      $fulfilled = $fulfilled ? 0 : 1;

      $stmt = $this->db->prepare("INSERT INTO Reviews (OfferingID, SeekerID, GuideID, BookingID, Fulfilled, ReviewText, Rating, ReviewType)
        VALUES (:offeringID, :seekerID, :guideID, :bookingID, :fulfilled, :message, :rating, 'service')");
      $stmt->execute([
        ':offeringID' => $offeringID,
        ':seekerID' => $seekerID,
        ':guideID' => $guideID,
        ':bookingID' => $bookingID,
        ':fulfilled' => $fulfilled,
        ':message' => $message,
        ':rating' => $rating
      ]);

      $reviewID = $this->db->lastInsertId();
      if (!$reviewID) {
        throw new DatabaseException("Failed to retrieve the inserted review");
      }

      $stmt = $this->db->prepare("UPDATE Bookings
        SET ModificationDate = NOW(), FeedbackStatus = 'Submitted', ReviewID = ?,
        LastBookingEvent = 'Rated'
        WHERE BookingID = ?");
      $stmt->execute([$reviewID, $bookingID]);

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, Message)
        VALUES (?, 'Rated', ?)");
      $stmt->execute([$bookingID, $message]);

      $booking = $this->getBookingByID($bookingID) ?:
        throw new DatabaseException("Failed to retrieve the updated booking");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene todas las reseñas del sistema con filtros opcionales
   * Soporta paginación y filtrado por rango de fechas y calificación
   * @param int $limit: cantidad máxima de resultados a retornar
   * @param string|null $from: fecha inicio en formato YYYYMMDD
   * @param string|null $to: fecha fin en formato YYYYMMDD
   * @param int|null $rating: filtrar por calificación específica (1-5)
   * @return array|null: { data: [], rows: { total: int, fetched: int } } o null si no hay resultados
   * @throws DatabaseException: si falla la consulta
   */
  public function getReviews($limit, $from = null, $to = null, $rating = null) {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID";

      # Construimos el WHERE condicionalmente
      $whereClauses = [];
      if ($from) $whereClauses[] = "r.CreationDate >= :fromDate";
      if ($to) $whereClauses[] = "r.CreationDate <= :toDate";
      if ($rating) $whereClauses[] = "r.Rating = :rating";

      if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(" AND ", $whereClauses);
      }

      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);

      if ($from) {
        $fromFormatted = DateTime::createFromFormat('Ymd', $from)->format('Y-m-d');
        $stmt->bindParam(':fromDate', $fromFormatted);
      }
      if ($to) {
        $toFormatted = DateTime::createFromFormat('Ymd', $to)->format('Y-m-d');
        $stmt->bindParam(':toDate', $toFormatted);
      }
      if ($rating) {
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
      }

      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$reviews) {
        return null;
      }

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $reviews,
        "rows" => [
          "total" => (int)$total['total'],
          "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene todas las reseñas recibidas por un guía específico
   * Soporta paginación y filtrado por rango de fechas y calificación
   * @param int $userID: ID del usuario guía
   * @param int $limit: cantidad máxima de resultados a retornar
   * @param string|null $from: fecha inicio en formato YYYYMMDD
   * @param string|null $to: fecha fin en formato YYYYMMDD
   * @param int|null $rating: filtrar por calificación específica (1-5)
   * @return array|null: { data: [], rows: { total: int, fetched: int } } o null si no hay resultados
   * @throws DatabaseException: si falla la consulta
   */
  public function getReviewsByGuide($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID";

      # Construimos el WHERE condicionalmente
      $whereClauses = ["r.GuideID = :userID"];
      if ($from) $whereClauses[] = "r.CreationDate >= :fromDate";
      if ($to) $whereClauses[] = "r.CreationDate <= :toDate";
      if ($rating) $whereClauses[] = "r.Rating = :rating";

      if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(" AND ", $whereClauses);
      }

      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);

      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

      if ($from) {
        $fromFormatted = DateTime::createFromFormat('Ymd', $from)->format('Y-m-d');
        $stmt->bindParam(':fromDate', $fromFormatted);
      }
      if ($to) {
        $toFormatted = DateTime::createFromFormat('Ymd', $to)->format('Y-m-d');
        $stmt->bindParam(':toDate', $toFormatted);
      }
      if ($rating) {
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
      }

      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$reviews) {
        return null;
      }

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
          "data" => $reviews,
          "rows" => [
            "total" => (int)$total['total'],
            "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene todas las reseñas realizadas por un buscador específico
   * Soporta paginación y filtrado por rango de fechas y calificación
   * @param int $userID: ID del usuario buscador
   * @param int $limit: cantidad máxima de resultados a retornar
   * @param string|null $from: fecha inicio en formato YYYYMMDD
   * @param string|null $to: fecha fin en formato YYYYMMDD
   * @param int|null $rating: filtrar por calificación específica (1-5)
   * @return array|null: { data: [], rows: { total: int, fetched: int } } o null si no hay resultados
   * @throws DatabaseException: si falla la consulta
   */
  public function getReviewsBySeeker($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID";

      # Construimos el WHERE condicionalmente
      $whereClauses = ["r.SeekerID = :userID"];
      if ($from) $whereClauses[] = "r.CreationDate >= :fromDate";
      if ($to) $whereClauses[] = "r.CreationDate <= :toDate";
      if ($rating) $whereClauses[] = "r.Rating = :rating";

      if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(" AND ", $whereClauses);
      }

      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);

      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

      if ($from) {
        $fromFormatted = DateTime::createFromFormat('Ymd', $from)->format('Y-m-d');
        $stmt->bindParam(':fromDate', $fromFormatted);
      }
      if ($to) {
        $toFormatted = DateTime::createFromFormat('Ymd', $to)->format('Y-m-d');
        $stmt->bindParam(':toDate', $toFormatted);
      }
      if ($rating) {
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
      }

      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$reviews) {
        return null;
      }

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $reviews,
        "rows" => [
          "total" => (int)$total['total'],
          "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene todas las reseñas de un usuario (como guía O como buscador)
   * Determina automáticamente el rol del usuario según su UserType
   * Soporta paginación y filtrado por rango de fechas y calificación
   * @param int $userID: ID del usuario
   * @param int $limit: cantidad máxima de resultados a retornar
   * @param string|null $from: fecha inicio en formato YYYYMMDD
   * @param string|null $to: fecha fin en formato YYYYMMDD
   * @param int|null $rating: filtrar por calificación específica (1-5)
   * @return array|null: { data: [], rows: { total: int, fetched: int } } o null si no hay resultados
   * @throws DatabaseException: si falla la consulta
   * @throws \Exception: si el usuario no existe
   */
  public function getReviewsByUser($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      # Determinar el rol del usuario en las reviews
      $stmt = $this->db->prepare("SELECT UserType FROM Users WHERE UserID = :userID");
      $stmt->execute([':userID' => $userID]);
      $role = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$role) {
        throw new \Exception("User not found.");
      }

      $isGuide = $role['UserType'] === 'Guide';

      $type = $isGuide ? 'r.GuideID' : 'r.SeekerID';

      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID
        WHERE $type = :userID";

      if ($from) $query .= " AND r.CreationDate >= :fromDate";
      if ($to) $query .= " AND r.CreationDate <= :toDate";
      if ($rating) $query .= " AND r.Rating = :rating";

      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      if ($from) {
        $fromFormatted = DateTime::createFromFormat('Ymd', $from)->format('Y-m-d');
        $stmt->bindParam(':fromDate', $fromFormatted);
      }
      if ($to) {
        $toFormatted = DateTime::createFromFormat('Ymd', $to)->format('Y-m-d');
        $stmt->bindParam(':toDate', $toFormatted);
      }
      if ($rating) {
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
      }
      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$reviews) {
        return null;
      }

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $reviews,
        "rows" => [
          "total" => (int)$total['total'],
          "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene los datos de una reseña específica por su ID
   * @param int $reviewID: ID de la reseña
   * @return array|null: datos completos de la reseña o null si no existe
   * @throws DatabaseException: si falla la consulta
   */
  public function getReviewsByID($reviewID)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID
        WHERE r.ReviewID = :reviewID");

      $stmt->bindParam(':reviewID', $reviewID, PDO::PARAM_INT);
      $stmt->execute();

      $review = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$review) {
        return null; # No se encontró booking
      }

      return $review;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene todas las reseñas de un servicio/offering específico
   * Soporta paginación y filtrado por rango de fechas y calificación
   * @param int $offeringID: ID del servicio
   * @param int $limit: cantidad máxima de resultados a retornar
   * @param string|null $from: fecha inicio en formato YYYYMMDD
   * @param string|null $to: fecha fin en formato YYYYMMDD
   * @param int|null $rating: filtrar por calificación específica (1-5)
   * @return array|null: { data: [], rows: { total: int, fetched: int } } o null si no hay resultados
   * @throws DatabaseException: si falla la consulta
   */
  public function getReviewsByOffering($offeringID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
        IF(u.DisplayName IS NULL, u.Username, u.DisplayName) AS Reviewer,
        l.CountryCode, r.ReviewText, r.Rating,
        r.OfferingID, o.Title AS TitleOffering,
        r.GuideID AS GuideID,
        IF(u2.DisplayName IS NULL, u2.Username, u2.DisplayName) AS Guide,
        m.URL as ReviewerProfilePhoto, r.Reply
        FROM Reviews AS r
        INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
        INNER JOIN Users AS u ON r.SeekerID = u.UserID
        INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
        INNER JOIN UsersLocations as l ON l.UserID = u.UserID AND l.LocationID = 0
        LEFT JOIN Media AS m ON r.SeekerID = m.UserID";

      # Construimos el WHERE condicionalmente
      $whereClauses = ["r.OfferingID = :offeringID"];
      if ($from) $whereClauses[] = "r.CreationDate >= :fromDate";
      if ($to) $whereClauses[] = "r.CreationDate <= :toDate";
      if ($rating) $whereClauses[] = "r.Rating = :rating";

      if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(" AND ", $whereClauses);
      }

      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);

      $stmt->bindParam(':offeringID', $offeringID, PDO::PARAM_INT);
      if ($from) {
        $fromFormatted = DateTime::createFromFormat('Ymd', $from)->format('Y-m-d');
        $stmt->bindParam(':fromDate', $fromFormatted);
      }
      if ($to) {
        $toFormatted = DateTime::createFromFormat('Ymd', $to)->format('Y-m-d');
        $stmt->bindParam(':toDate', $toFormatted);
      }
      if ($rating) {
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
      }

      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($reviews)) {
        return null;
      }

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $reviews,
        "rows" => [
          "total" => (int)$total['total'],
          "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Verifica si una ubicación específica existe para un offering
   * @param int $offeringID: ID del servicio
   * @param int $locationID: ID de la ubicación
   * @return array|false: { LocationID: int } o false si no existe
   */
  public function getLocation($offeringID, $locationID){
    $stmt = $this->db->prepare("SELECT LocationID
      FROM OfferingLocations
      WHERE OfferingID = ? AND LocationID = ?");
    $stmt->execute([$offeringID, $locationID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }
}
