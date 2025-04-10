<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Booking
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function createBooking($data)
  {
    try {
      $stmt = $this->db->prepare("INSERT INTO Bookings (OfferingID, UserID, Mode, LocationID, CreationDate, ScheduledDate) 
                                  VALUES (:offeringID, :userID, :mode, :locationid, NOW(), :scheduledDate)");
      $stmt->bindParam(':offeringID', $data['OfferingID'], PDO::PARAM_INT);
      $stmt->bindParam(':userID', $data['UserID'], PDO::PARAM_INT);
      $stmt->bindParam(':mode', $data['Mode'], PDO::PARAM_STR);
      $stmt->bindParam(':locationid', $data['LocationID'], $data['LocationID'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $stmt->bindParam(':scheduledDate', $data['ScheduledDate'], PDO::PARAM_STR);
      $stmt->execute();

      $bookingID = $this->db->lastInsertId();

      $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEventDate, BookingEvent, BookingDate) 
                          VALUES (:bookingID, NOW(), 'created', NOW())")
               ->execute([':bookingID' => $bookingID]);

      return $bookingID;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getBookingsByGuide($userID)
  {
    try {
      $stmt = $this->db->prepare("SELECT b.*, o.Title 
                                  FROM Bookings AS b 
                                  INNER JOIN Offerings AS o ON b.OfferingID = o.OfferingID 
                                  WHERE b.UserID = :userID");
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateBooking($bookingID, $data)
  {
    try {
      $stmt = $this->db->prepare("UPDATE Bookings SET Mode = :mode, ScheduledDate = :scheduledDate WHERE BookingID = :bookingID");
      $stmt->bindParam(':mode', $data['Mode'], PDO::PARAM_STR);
      $stmt->bindParam(':scheduledDate', $data['ScheduledDate'], PDO::PARAM_STR);
      $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
      $stmt->execute();

      $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEventDate, BookingEvent) 
                          VALUES (:bookingID, NOW(), 'rescheduling')")
                ->execute([':bookingID' => $bookingID]);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function cancelBooking($bookingID)
  {
    try {
      $stmt = $this->db->prepare("DELETE FROM Bookings WHERE BookingID = :bookingID");
      $stmt->bindParam(':bookingID', $bookingID, PDO::PARAM_INT);
      $stmt->execute();

      $this->db->prepare("INSERT INTO BookingStatus (BookingID, BookingEventDate, BookingEvent) 
                          VALUES (:bookingID, NOW(), 'cancellation')")
               ->execute([':bookingID' => $bookingID]);

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

  public function getOfferingById($offeringID)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Offerings WHERE OfferingID = :offeringID");
      $stmt->bindParam(':offeringID', $offeringID, PDO::PARAM_INT);
      $stmt->execute();

      $offering = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$offering) {
        throw new ValidationException("Offering not found");
      }

      return $offering;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getReviewsByGuide($userID, $limit, $fromDate)
  {
    try {
      $query = "SELECT SQL_CALC_FOUND_ROWS r.SUserID, r.ReviewText, r.Rating, o.Title
                FROM Reviews AS r 
                INNER JOIN Offerings AS o ON r.OfferingID = o.OfferingID
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
}
