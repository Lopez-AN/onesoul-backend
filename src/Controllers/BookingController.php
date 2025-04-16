<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;
use \DateTime;

class Booking
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getBookingByID($bookingID)
  {
    try {
      $stmt = $this->db->prepare("SELECT b.*, o.Title AS TitleOffering, o.UserID AS Guide
                                  FROM Bookings AS b 
                                  INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID 
                                  WHERE b.BookingID = :bookingID");
      $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
      $stmt->execute();
      $booking = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$booking) {
        return null; // No se encontró booking
      }
      
      // Obtener los eventos de la reserva (BookingStatus)
      $stmt2 = $this->db->prepare("SELECT BookingEventDate, BookingEvent, ScheduledDate 
                                  FROM BookingStatus 
                                  WHERE BookingID = :bookingID
                                  ORDER BY BookingEventDate ASC");
      $stmt2->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
      $stmt2->execute();

      $events = $stmt2->fetchAll(PDO::FETCH_ASSOC);

      // Añadir los eventos al booking
      $booking['Events'] = $events;

      return $booking;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getBookingsByGuide($userID)
  {
    try {
      // Obtener todos los bookings del guía
      $stmt = $this->db->prepare("SELECT b.BookingID, b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
                                        b.Mode, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate, 
                                        o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering
                                  FROM Bookings AS b 
                                  INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID 
                                  INNER JOIN Users AS u ON b.UserID = u.UserID
                                  INNER JOIN Users AS u2 ON o.UserID = u2.UserID
                                  WHERE o.UserID = :userID");
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt->execute();
      $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$bookings) {
        return null; // No se encontraron bookings
      }

      // Para cada booking obtenemos sus eventos
      foreach ($bookings as &$booking) {
        $bookingID = $booking['BookingID'];

        $stmt2 = $this->db->prepare("SELECT BookingEventDate, BookingEvent, ScheduledDate 
                                    FROM BookingStatus 
                                    WHERE BookingID = :bookingID
                                    ORDER BY BookingEventDate ASC");
        $stmt2->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt2->execute();
        $events = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $booking['Events'] = $events; // Le agregamos la lista de eventos a cada booking
      }

      return $bookings;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getBookingsBySeeker($userID)
  {
    try {
      // Obtener todos los bookings del buscador
      $stmt = $this->db->prepare("SELECT b.BookingID, b.UserID, u.DisplayName AS Seeker, b.ReviewID, b.PaymentID,
                                        b.Mode, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate, 
                                        o.UserID AS Guide, u2.DisplayName, b.OfferingID, o.Title AS TitleOffering
                                  FROM Bookings AS b 
                                  INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID 
                                  INNER JOIN Users AS u ON b.UserID = u.UserID
                                  INNER JOIN Users AS u2 ON o.UserID = u2.UserID
                                  WHERE b.UserID = :userID");
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt->execute();
      $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$bookings) {
        return null; // No se encontraron bookings
      }

      // Para cada booking obtenemos sus eventos
      foreach ($bookings as &$booking) {
        $bookingID = $booking['BookingID'];

        $stmt2 = $this->db->prepare("SELECT BookingEventDate, BookingEvent, ScheduledDate 
                                    FROM BookingStatus 
                                    WHERE BookingID = :bookingID
                                    ORDER BY BookingEventDate ASC");
        $stmt2->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt2->execute();
        $events = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $booking['Events'] = $events; // Le agregamos la lista de eventos a cada booking
      }

      return $bookings;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createBooking($data)
  {
    try {
      $stmt = $this->db->prepare("INSERT INTO Bookings (OfferingID, UserID, Mode, LocationID, CreationDate, ScheduledDate) 
                                  VALUES (:offeringID, :userID, :mode, :locationID, NOW(), :scheduledDate)");
      $stmt->bindParam(':offeringID', $data['OfferingID'], PDO::PARAM_INT);
      $stmt->bindParam(':userID', $data['UserID'], PDO::PARAM_INT);
      $stmt->bindParam(':mode', $data['Mode'], PDO::PARAM_STR);
      $stmt->bindParam(':locationID', $data['LocationID'], $data['LocationID'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $stmt->bindParam(':scheduledDate', $data['ScheduledDate'], PDO::PARAM_STR);
      $stmt->execute();

      $bookingID = $this->db->lastInsertId();

      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, ScheduledDate) 
                                  VALUES (:bookingID, 'created', :scheduledDate)");
      $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);           
      $stmt->bindParam(':scheduledDate', $data['ScheduledDate'], PDO::PARAM_STR);          
      $stmt->execute();

      return $this->getBookingByID($bookingID);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateBooking($bookingID, $mode, $scheduledDate)
  {
    try {
      $fields = [];

      if (!empty($mode)) {
        $stmt = $this->db->prepare("UPDATE Bookings 
                                    SET Mode = :mode, ModificationDate = NOW() 
                                    WHERE BookingID = :bookingID");
        $stmt->bindParam(':mode', $mode, PDO::PARAM_STR);
        $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt->execute();
        $fields[] = 'Mode';
      } 
  
      if (!empty($scheduledDate)) {
        // Validar que la fecha no sea pasada
        $currentDate = new \DateTime();
        $newScheduledDate = new \DateTime($scheduledDate);
        if ($newScheduledDate < $currentDate) {
          throw new \Exception("Scheduled date cannot be in the past.");
        }
  
        $stmt = $this->db->prepare("UPDATE Bookings 
                                    SET ScheduledDate = :scheduledDate 
                                    WHERE BookingID = :bookingID");
        $stmt->bindParam(':scheduledDate', $scheduledDate, PDO::PARAM_STR);
        $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt->execute();
  
        $fields[] = 'ScheduledDate';
  
        // Insertar en BookingStatus
        $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent, ScheduledDate) 
                                    VALUES (:bookingID, 'rescheduling', :scheduledDate)");
        $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt->bindParam(':scheduledDate', $scheduledDate, PDO::PARAM_STR);
        $stmt->execute();
      }
  
      if (empty($fields)) {
        throw new \Exception("No fields to update");
      }
  
      return $this->getBookingByID($bookingID);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function cancelBooking($bookingID)
  {
    try {
      $stmt = $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEvent) 
                                  VALUES (:bookingID, 'cancellation')");
      $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
      $stmt->execute();

      return $this->getBookingByID($bookingID);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createReview($data)
  {
    try {
      $stmt = $this->db->prepare("INSERT INTO Reviews (OfferingID, SUserID, GUserID, Rating, ReviewText, ReviewType, CreationDate) 
                                  VALUES (:offeringID, :seekerID, :guideID, :rating, :reviewText, 'service', NOW())");
      $stmt->bindParam(':offeringID', $data['OfferingID'], PDO::PARAM_INT);
      $stmt->bindParam(':seekerID', $data['SUserID'], PDO::PARAM_INT);
      $stmt->bindParam(':guideID', $data['GUserID'], PDO::PARAM_INT);
      $stmt->bindParam(':rating', $data['Rating'], PDO::PARAM_INT);
      $stmt->bindParam(':reviewText', $data['ReviewText'], PDO::PARAM_STR);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getReviewsByGuide($userID, $limit, $fromDate)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.SUserID AS SeekerID, u.DisplayName AS Seeker, u.CountryCode,
                r.ReviewText, r.Rating, o.Title AS TitleOffering, r.GUserID AS GuideID, u2.DisplayName AS Guide
                FROM Reviews AS r 
                INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
                INNER JOIN Users AS u ON r.SUserID = u.UserID
                INNER JOIN Users AS u2 ON r.GUserID = u2.UserID                
                WHERE r.GUserID = :userID";
                
      if ($fromDate) {
        $query .= " AND r.CreationDate >= :fromDate";
      }
   
      $query .= " ORDER BY r.CreationDate DESC LIMIT :limit";

      $stmt = $this->db->prepare($query);
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      if ($fromDate) {
        $stmt->bindParam(':fromDate', $fromDate);
      }
      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->execute();

      $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $total = $this->db->query("SELECT FOUND_ROWS() as total")->fetch(PDO::FETCH_ASSOC);

      return [
        "Data" => $reviews,
        "Rows" => [
            "total" => (int)$total['total'],
            "fetched" => count($reviews)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getLocation($locationID)
  {
    try {
      $stmt = $this->db->prepare("SELECT LocationID 
                                  FROM OfferingLocations 
                                  WHERE LocationID = :locationID");
      $stmt->bindParam(':locationID', $locationID, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}
