<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Booking;

class BookingController
{
  protected $booking;

  public function __construct(Booking $booking)
  {
    $this->booking = $booking;
  }

  public function createBooking(Request $request, Response $response, $args)
  {
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;
    $data = $request->getParsedBody();

    try {
      // VALIDAR: Offering existe y pertenece a un guía válido
      $offeringID = $data['OfferingID'] ?? null;
      if (!$offeringID) {
        return $response->withStatus(400)->withJson(['error' => 'OfferingID es obligatorio.']);
      }

      $stmt = $this->db->prepare("SELECT o.OfferingID, u.UserType 
                                  FROM Offerings o
                                  INNER JOIN Users u ON o.UserID = u.UserID
                                  WHERE o.OfferingID = :offeringID");
      $stmt->execute([':offeringID' => $offeringID]);
      $offering = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$offering) {
        return $response->withStatus(400)->withJson(['error' => 'Offering no encontrado.']);
      }
      
      // VALIDAR: Fecha de cita
      $scheduledDate = $data['ScheduledDate'] ?? null;
      if (!$scheduledDate) {
        return $response->withStatus(400)->withJson(['error' => 'ScheduledDate es obligatorio.']);
      }

      $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledDate);
      if (!$scheduledDateTime || $scheduledDateTime->format('Y-m-d H:i:s') !== $scheduledDate) {
        return $response->withStatus(400)->withJson(['error' => 'Formato de fecha inválido. Debe ser Y-m-d H:i:s']);
      }
      if ($scheduledDateTime < new DateTime()) {
        return $response->withStatus(400)->withJson(['error' => 'No se puede agendar una cita en el pasado.']);
      }

      $mode = $data['Mode'] ?? null;

      // VALIDAR: LocationID en caso de ser presencial
      $locationID = $data['LocationID'] ?? null;

      if ($mode === 'presencial') {
        if (!$locationID) {
          return $response->withStatus(400)->withJson(['error' => 'LocationID es obligatorio para servicios presenciales.']);
        }
        // Validar que el LocationID exista en offeringLocations
        $stmt = $this->db->prepare("SELECT LocationID FROM OfferingLocations WHERE LocationID = :locationID");
        $stmt->execute([':locationID' => $locationID]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
           return $response->withStatus(400)->withJson(['error' => 'LocationID no válido.']);
        }
      } else {
        // Si no es presencial, LocationID puede ser NULL
        $locationID = null;
      }

      // PREPARAR datos para el modelo
      $data = [
        'OfferingID' => $offeringID,
        'UserID' => $userID,
        'Mode' => $mode,
        'LocationID' => $locationID,
      ]

      $bookingID = $this->booking->createBooking($data);

      return $response->withStatus(201)->withJson([
        "message" => "Booking created successfully", 
        "bookingID" => $bookingID
      ]);

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getBookingsByGuide(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];

    try {
      $result = $this->booking->getBookingsByGuide($userID);
      return $response->withStatus(200)->withJson($result);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateBooking(Request $request, Response $response, $args)
  {
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $bookingID = $args['bookingID'];
    $data = $request->getParsedBody();

    try {
      $payload = [
        'Mode' => $data['SessionType'] === 'in-person' ? 'presencial' : 'virtual',
        'ScheduledDate' => $data['ScheduleDate']
      ];

      $this->booking->updateBooking($bookingID, $payload);

      return $response->withStatus(200)->withJson(["message" => "Booking updated successfully"]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function cancelBooking(Request $request, Response $response, $args)
  {
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $bookingID = $args['bookingID'];

    try {
      $this->booking->cancelBooking($bookingID);

      return $response->withStatus(200)->withJson(["message" => "Booking cancelled successfully"]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function createReview(Request $request, Response $response, $args)
  {
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $seekerID = $jwt['data']->UserID;
    $data = $request->getParsedBody();

    if (!isset($data['Rating']) || !isset($data['ReviewText']) || !isset($data['OfferingID'])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS", 
          "desc" => "Missing required fields"
        ]
      ]);
    }

    if (!in_array((int)$data['Rating'], [1, 2, 3, 4, 5])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING", 
          "desc" => "Rating must be between 1 and 5"
        ]
      ]);
    }

    try {
      // Buscar el guía asociado al offering
      $offering = $this->booking->getOfferingById($data['OfferingID']);

      $payload = [
        'OfferingID' => (int)$data['OfferingID'],
        'SUserID' => (int)$seekerID,
        'GUserID' => (int)$offering['UserID'],
        'Rating' => (int)$data['Rating'],
        'ReviewText' => $data['ReviewText']
      ];

      $this->booking->createReview($payload);

      return $response->withStatus(201)->withJson(["message" => "Review created successfully"]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getReviewsByGuide(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];
    $queryParams = $request->getQueryParams();

    $fromDate = $queryParams['from'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    try {
      $reviews = $this->booking->getReviewsByGuide($userID, $limit, $fromDate);
      return $response->withStatus(200)->withJson($reviews);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}
