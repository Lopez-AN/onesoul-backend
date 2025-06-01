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
                                        b.Mode, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate, b.Message,
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
                                        b.Mode, b.LocationID, b.CreationDate, b.ScheduledDate, b.ModificationDate, b.Message,
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
      $stmt = $this->db->prepare("INSERT INTO Bookings (OfferingID, UserID, Mode, LocationID, CreationDate, ScheduledDate, Message) 
                                  VALUES (:offeringID, :userID, :mode, :locationID, NOW(), :scheduledDate, :message)");
      $stmt->bindParam(':offeringID', $data['OfferingID'], PDO::PARAM_INT);
      $stmt->bindParam(':userID', $data['UserID'], PDO::PARAM_INT);
      $stmt->bindParam(':mode', $data['Mode'], PDO::PARAM_STR);
      $stmt->bindParam(':locationID', $data['LocationID'], $data['LocationID'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $stmt->bindParam(':scheduledDate', $data['ScheduledDate'], PDO::PARAM_STR);
      $stmt->bindParam(':message', $data['Message'], $data['Message'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
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

  public function updateBooking($bookingID, $mode, $scheduledDate, $message)
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
  
      if (!empty($message)) {
        $stmt = $this->db->prepare("UPDATE Bookings 
                                    SET ModificationDate = NOW(), Message = :message
                                    WHERE BookingID = :bookingID");
        $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->execute();
        $fields[] = 'Message';
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

      $reviewID = $this->db->lastInsertId();

      if (!$reviewID) {
        return null; // No se encontraron reviews
      }

      $update = $this->db->prepare("UPDATE Bookings 
                                    SET ReviewID = :reviewID 
                                    WHERE OfferingID = :offeringID AND UserID = :seekerID");
      $update->bindParam(':reviewID', $reviewID, PDO::PARAM_INT);
      $update->bindParam(':offeringID', $data['OfferingID'], PDO::PARAM_INT);
      $update->bindParam(':seekerID', $data['SUserID'], PDO::PARAM_INT);
      $update->execute();

      return $this->getReviewsByID($reviewID);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getReviews($limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating, 
              r.OfferingID, o.Title AS TitleOffering,
              r.GUserID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SUserID = u.UserID
              INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
              LEFT JOIN Media AS m ON r.SUserID = m.UserID";
                
      // Construimos el WHERE condicionalmente
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
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating, 
              r.OfferingID, o.Title AS TitleOffering,
              r.GUserID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SUserID = u.UserID
              INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
              LEFT JOIN Media AS m ON r.SUserID = m.UserID";
                
      // Construimos el WHERE condicionalmente
      $whereClauses = ["r.GUserID = :userID"];
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
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating, 
              r.OfferingID, o.Title AS TitleOffering,
              r.GUserID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SUserID = u.UserID
              INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
              LEFT JOIN Media AS m ON r.SUserID = m.UserID";
                
      // Construimos el WHERE condicionalmente
      $whereClauses = ["r.SUserID = :userID"];
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
  
  //TRAE TODAS LAS REVIEWS DEL USUARIO, TANTO COMO GUIA Y COMO BUSCADOR 
  public function getReviewsByUser($userID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      // Determinar el rol del usuario en las reviews
      $stmt = $this->db->prepare("SELECT UserType FROM Users WHERE UserID = :userID");
      $stmt->execute([':userID' => $userID]);
      $role = $stmt->fetch(PDO::FETCH_ASSOC);
  
      if (!$role) {
        throw new \Exception("User not found.");
      }
  
      $isGuide = $role['UserType'] == 'Guide';

      $type = $isGuide ? 'r.GUserID' : 'r.SUserID';
  
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
                IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
                u.CountryCode, r.ReviewText, r.Rating, 
                r.OfferingID, o.Title AS TitleOffering,
                r.GUserID AS GuideID,
                IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
                m.URL as ReviewerProfilePhoto
                FROM Reviews AS r
                INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
                INNER JOIN Users AS u ON r.SUserID = u.UserID
                INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
                LEFT JOIN Media AS m ON r.SUserID = m.UserID
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
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating, 
              r.OfferingID, o.Title AS TitleOffering,
              r.GUserID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SUserID = u.UserID
              INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
              LEFT JOIN Media AS m ON r.SUserID = m.UserID       
              WHERE r.ReviewID = :reviewID");
      $stmt->bindParam(':reviewID', $reviewID, PDO::PARAM_INT);
      $stmt->execute();

      $review = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$review) {
        return null; // No se encontró booking
      }

      return $review;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getReviewsByOffering($offeringID, $limit, $from = null, $to = null, $rating = null)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.ReviewID, r.CreationDate, r.SUserID AS SeekerID, 
              IF(u.DisplayName IS NULL, CONCAT(u.FirstName, ' ', u.LastName), u.DisplayName) AS Reviewer,
              u.CountryCode, r.ReviewText, r.Rating, 
              r.OfferingID, o.Title AS TitleOffering,
              r.GUserID AS GuideID,
              IF(u2.DisplayName IS NULL, CONCAT(u2.FirstName, ' ', u2.LastName), u2.DisplayName) AS Guide,
              m.URL as ReviewerProfilePhoto
              FROM Reviews AS r
              INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
              INNER JOIN Users AS u ON r.SUserID = u.UserID
              INNER JOIN Users AS u2 ON r.GUserID = u2.UserID
              LEFT JOIN Media AS m ON r.SUserID = m.UserID";
      
      // Construimos el WHERE condicionalmente
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

  public function getLocation($offeringID, $locationID)
  {
    try {
      $stmt = $this->db->prepare("SELECT LocationID 
                                  FROM OfferingLocations 
                                  WHERE LocationID = :locationID 
                                  AND OfferingID = :offeringID");
      $stmt->bindParam(':locationID', $locationID, PDO::PARAM_INT);
      $stmt->bindParam(':offeringID', $offeringID, PDO::PARAM_INT);
      $stmt->execute();

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      
      return $result ?: null;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}
