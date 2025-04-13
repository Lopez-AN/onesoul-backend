<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Booking;
use App\Models\Offering;
use \DateTime;

class BookingController
{
  protected $booking;
  protected $offering;

  public function __construct(Booking $booking, Offering $offering)
  {
    $this->booking = $booking;
    $this->offering = $offering;
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
      // VALIDAR: Offering si existe 
      $id = $data['OfferingID'] ?? null;
      if (!$id) {
        return $response->withStatus(400)->withJson(['error' => 'OfferingID is required.']);
      }

      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(400)->withJson(['error' => 'Offering not found.']);
      }

      // VALIDAR: Fecha de cita
      $scheduledDate = $data['ScheduledDate'] ?? null;
      if (!$scheduledDate) {
        return $response->withStatus(400)->withJson(['error' => 'ScheduledDate is required.']);
      }

      $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledDate);
      if (!$scheduledDateTime || $scheduledDateTime->format('Y-m-d H:i:s') !== $scheduledDate) {
        return $response->withStatus(400)->withJson(['error' => 'Invalid date format. Must be Y-m-d H:i:s']);
      }
      if ($scheduledDateTime < new DateTime()) {
        return $response->withStatus(400)->withJson(['error' => 'You cannot schedule an appointment in the past.']);
      }

      // VALIDAR: LocationID en caso de ser presencial
      $mode = $data['Mode'] ?? null;
      $locationID = $data['LocationID'] ?? null;

      if ($mode === 'in-person') {
        if (!$locationID) {
          return $response->withStatus(400)->withJson(['error' => 'LocationID is required for in-person services.']);
        }

        // Validar que el LocationID exista en offeringLocations
        $location = $this->booking->getLocation($locationID);

        if (!$location) {
          return $response->withStatus(400)->withJson(['error' => 'Invalid LocationID.']);
        }
      } else {
        // Si no es presencial, LocationID puede ser NULL
        $locationID = null;
      }

      // PREPARAR datos para el modelo
      $data = [
        'OfferingID' => $id,
        'UserID' => $userID,
        'Mode' => $mode,
        'LocationID' => $locationID,
        'ScheduledDate' =>$scheduledDate,
      ];

      $booking = $this->booking->createBooking($data);

      return $response->withStatus(201)->withJson([
        "message" => "Booking created successfully", 
        "booking" => $booking
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
      $bookings = $this->booking->getBookingsByGuide($userID);

      if (!$bookings) {
        return $response->withJson(['error' => 'Booking not found'], 404);
      }

      return $response->withStatus(200)->withJson($bookings);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getBookingByID(Request $request, Response $response, $args)
  {
    $bookingID = $args['bookingID'];

    try {
      $booking = $this->booking->getBookingByID($bookingID);
      
      if (!$booking) {
        return $response->withJson(['error' => 'Booking not found'], 404);
      }

      return $response->withStatus(200)->withJson($booking);
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

    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;

    if (!in_array($userType, ['Guide', 'Admin'])) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED_ACTION",
          "desc" => "Only guides or admins can update bookings."
        ]
      ]);
    }

    $bookingID = $args['bookingID'];
    $data = $request->getParsedBody();
    $mode = $data['Mode'] ?? null;
    $scheduledDate = $data['ScheduledDate'] ?? null;

    try {
      // Validar si booking existe y no esta cancelado
      $booking = $this->booking->getBookingByID($bookingID);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found."
            ]
        ]);
      }

      // Verificar si el booking está cancelado (buscar eventos de tipo "cancellation")
      if (!empty($booking['Events'])) {
        foreach ($booking['Events'] as $event) {
          if ($event['BookingEvent'] === 'cancellation') {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "BOOKING_ALREADY_CANCELLED",
                "desc" => "Cannot update a cancelled booking."
              ]
            ]);
          }
        }
      }

      // Validar que al menos uno venga definido
      if (empty($scheduledDate) && empty($mode)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "NO_FIELDS_TO_UPDATE",
            "desc" => "No valid fields provided to update."
          ]
        ]);
      }

      if ($scheduledDate) {
        // Verificar que la reserva NO haya sucedido
        $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledDate);
        if (!$scheduledDateTime || $scheduledDateTime->format('Y-m-d H:i:s') !== $scheduledDate) {
          return $response->withStatus(400)->withJson(['error' => 'Invalid date format. Must be Y-m-d H:i:s']);
        }
        if ($scheduledDateTime < new DateTime()) {
          return $response->withStatus(400)->withJson(['error' => 'You cannot schedule an appointment in the past.']);
        }
      }

      $booking = $this->booking->updateBooking($bookingID, $mode, $scheduledDate);

      return $response->withStatus(200)->withJson(["message" => "Booking updated successfully", "booking" => $booking]);
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

    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    $bookingID = $args['bookingID'];

    try {
      $booking = $this->booking->getBookingByID($bookingID);

      if (!$booking) {
        return $response->withStatus(404)->withJson([
            "error" => [
                "code" => "BOOKING_NOT_FOUND",
                "desc" => "Booking not found"
            ]
        ]);
      }

      $seeker = $booking['UserID'];
      $id = $booking['OfferingID'];
      $scheduledDate = $booking['ScheduledDate'];

      // Obtener el dueño del offering
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "Offering linked to booking not found"
          ]
        ]);
      }

      $guide = $offering['Author']['UserID'];

      // Validar permisos
      if (!($userID == $seeker || $userID == $guide || $userType == 'Admin')) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED_ACTION",
            "desc" => "You don't have permission to cancel this booking."
          ]
        ]);
      }

      // Verificar que la reserva NO haya sucedido
      $today = new \DateTime();
      $scheduled = new \DateTime($scheduledDate);

      if ($scheduled < $today) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_BOOKING_DATE",
            "desc" => "Cannot cancel a booking that is already in the past."
          ]
        ]);
      }


      $booking = $this->booking->cancelBooking($bookingID);

      return $response->withStatus(201)->withJson([
        "message" => "Booking cancelled successfully", 
        "booking" => $booking
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
      $id = $data['OfferingID'];
      $offering = $this->booking->getOfferingById($id);

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
