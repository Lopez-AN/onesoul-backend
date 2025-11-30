<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;
use DateTime;

class Booking {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  public function getBookingByID($bookingID) {
    $stmt = $this->db->prepare("SELECT b.BookingID, b.PublicID,
      b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
      b.Mode as SessionType, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate,
      o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering,
      b.ScheduledDate
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON b.UserID = u.UserID
      INNER JOIN Users AS u2 ON o.UserID = u2.UserID
      WHERE b.BookingID = ?"
    );
    $stmt->execute([$bookingID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this->_getBookingsGeneric($booking);
  }

  public function getBookingByPublicID($publicID) {
    $stmt = $this->db->prepare("SELECT b.BookingID, b.PublicID,
      b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
      b.Mode as SessionType, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate,
      o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering,
      b.ScheduledDate
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON b.UserID = u.UserID
      INNER JOIN Users AS u2 ON o.UserID = u2.UserID
      WHERE b.PublicID = ?");
    $stmt->execute([$publicID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this->_getBookingsGeneric($booking);
  }

  public function getBookingsByGuide($guideID, $paginator, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    # Consulta completa paginada
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS b.BookingID, b.PublicID,
      b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
      b.Mode as SessionType, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate,
      o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering,
      b.ScheduledDate
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON b.UserID = u.UserID
      INNER JOIN Users AS u2 ON o.UserID = u2.UserID
      WHERE o.UserID = ? {$filterOpen}
      GROUP BY b.BookingID
      ORDER BY b.CreationDate DESC
      LIMIT ? OFFSET ?");

    $stmt->execute([$guideID, $paginator->limit, $paginator->offset]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getBookingsGenericMulti($bookings, $total['total'], $onlyOpen);
  }

  public function getBookingsBySeeker($seekerID, $paginator, $onlyOpen) {
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    # Consulta completa paginada
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS b.BookingID, b.PublicID,
      b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
      b.Mode as SessionType, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate,
      o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering,
      b.ScheduledDate
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      INNER JOIN Users AS u ON b.UserID = u.UserID
      INNER JOIN Users AS u2 ON o.UserID = u2.UserID
      WHERE b.UserID = ? {$filterOpen}
      GROUP BY b.BookingID
      ORDER BY b.CreationDate DESC
      LIMIT ? OFFSET ?");

    $stmt->execute([$seekerID, $paginator->limit, $paginator->offset]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getBookingsGenericMulti($bookings, $total['total'], $onlyOpen);
  }

  /**
   * Procesa y normaliza datos de un booking individual
   *
   * Trae los eventos e información para el booking.
   *
   * @param  array|null $booking: datos del booking obtenido de la BD o null
   * @return array|false: datos de la publicación normalizados o false si no existe
   **/
  private function _getBookingsGeneric($booking){
    if (empty($booking)) {
      return false;
    }

    # Obtener los eventos de la reserva (BookingStatus)
    $stmt = $this->db->prepare("SELECT BookingEventDate, BookingEvent,
      ScheduledDate, Message
      FROM BookingStatus
      WHERE BookingID = ?
      ORDER BY BookingEventDate DESC");

    $stmt->execute([$booking['BookingID']]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    # Añadir los eventos al booking
    $booking['Events'] = $events;
    return $booking;
  }

  /**
   * Procesa y normaliza múltiples registros de booking
   *
   * Trae los eventos e información para un conjunto de bookings.
   * Retorna en formato paginado.
   *
   * @param  array $offerings: array de bookings obtenidas de la BD
   * @param  int $total: cantidad total de registros disponibles
   * @param  int $onlyOpen: solo bookings abiertos
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  private function _getBookingsGenericMulti($bookings, $total, $onlyOpen){
    $filterOpen = $onlyOpen ?
      " AND LastBookingEvent NOT IN ('Canceled', 'Completed', 'Rated') " : "";

    # Agregar eventos a cada booking
    foreach ($bookings as &$b) {
      $stmt = $this->db->prepare("SELECT BookingEventDate,
        BookingEvent, ScheduledDate, Message
        FROM BookingStatus
        WHERE BookingID = ?
        ORDER BY BookingEventDate DESC");

      $stmt->execute([$b['BookingID']]);
      $b['Events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
      "data" => $bookings,
      "rows" => [
        "total" => $total,
        "fetched" => count($bookings)
      ]
    ];
  }

  public function countBookingsByGuide($guideID, $onlyOpen) {
    $stmt = $this->db->prepare("SELECT COUNT(*) AS found
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      WHERE o.UserID = ? {$filterOpen}"
    );
    $stmt->execute([$guideID]);
    $bookings = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($bookings && isset($bookings['found'])) ? $bookings['found'] : 0;
  }

  public function countBookingsBySeeker($seekerID, $onlyOpen) {
    $stmt = $this->db->prepare("SELECT COUNT(*) AS found
      FROM Bookings AS b
      INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID
      WHERE b.UserID = ? {$filterOpen}"
    );
    $stmt->execute([$seekerID]);
    $bookings = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($bookings && isset($bookings['found'])) ? $bookings['found'] : 0;
  }

  public function createBooking($data, $subDomain, $assocUUID, $voucherID) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("INSERT INTO Bookings
        (OfferingID, PublicID, UserID, Mode, LocationID, CreationDate, ScheduledDate, VoucherID)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
      $stmt->execute([
        $data['OfferingID'],
        $data['PublicID'],
        $data['SeekerID'],
        $data['SessionType'],
        $data['LocationID'],
        $data['ScheduledDate'] -> format("YmdHis"),
        $voucherID
      ]);

      $bookingID = $this->db->lastInsertId();

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, ScheduledDate, Message)
        VALUES (?, 'Pending', ?, ?)");
      $stmt->execute([$bookingID, $data['ScheduledDate'] -> format("YmdHis"), $data['Message']]);

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
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $booking;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  public function userHasCal($userID){
    $stmt = $this->db->prepare("SELECT 1 FROM CalConnections
      WHERE UserID = ? LIMIT 1");
    $stmt->execute([$userID]);
    return (bool) $stmt->fetchColumn();
  }

  public function findCalInvitee($assocUUID, $userID) {
    $stmt = $this->db->prepare("SELECT *
      FROM CalWebhooks
      WHERE BookingID IS NULL AND AssocUUID = ?
      AND Event = 'BOOKING_CREATED' AND GuideID = ?");
    $stmt->execute([$assocUUID, $userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public function linkBookingWithCal($assocUUID, $bookingID) {
    $stmt = $this->db->prepare("UPDATE CalendlyWebhooks
      SET BookingID = ?
      WHERE BookingID IS NULL AND AssocUUID = ?
      AND Event = 'BOOKING_CREATED'
      AND ");
    $stmt->execute([$bookingID, $assocUUID]);
  }

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

  /*
  REVIEWS
  */
  public function getReviews($limit, $from = null, $to = null, $rating = null) {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating,
              r.OfferingID, o.Title AS TitleOffering,
              r.GuideID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SeekerID = u.UserID
              INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  public function getReviewsByGuide($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating,
              r.OfferingID, o.Title AS TitleOffering,
              r.GuideID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SeekerID = u.UserID
              INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  public function getReviewsBySeeker($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating,
              r.OfferingID, o.Title AS TitleOffering,
              r.GuideID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SeekerID = u.UserID
              INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  # TRAE TODAS LAS REVIEWS DEL USUARIO, TANTO COMO GUIA Y COMO BUSCADOR
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
                IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
                u.CountryCode, r.ReviewText, r.Rating,
                r.OfferingID, o.Title AS TitleOffering,
                r.GuideID AS GuideID,
                IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
                m.URL as ReviewerProfilePhoto
                FROM Reviews AS r
                INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
                INNER JOIN Users AS u ON r.SeekerID = u.UserID
                INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  public function getReviewsByID($reviewID)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating,
              r.OfferingID, o.Title AS TitleOffering,
              r.GuideID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SeekerID = u.UserID
              INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  public function getReviewsByOffering($offeringID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SeekerID AS SeekerID,
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating,
              r.OfferingID, o.Title AS TitleOffering,
              r.GuideID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SeekerID = u.UserID
              INNER JOIN Users AS u2 ON r.GuideID = u2.UserID
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

  public function getLocation($offeringID, $locationID){
    $stmt = $this->db->prepare("SELECT LocationID
      FROM OfferingLocations
      WHERE OfferingID = ? AND LocationID = ?");
    $stmt->execute([$offeringID, $locationID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }
}
