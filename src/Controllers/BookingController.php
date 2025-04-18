<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Booking;
use App\Models\Offering;
use \DateTime;
use Firebase\JWT\JWT;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class BookingController
{
  protected $booking;
  protected $offering;

  public function __construct(Booking $booking, Offering $offering)
  {
    $this->booking = $booking;
    $this->offering = $offering;
  }

  public function getBookingByID(Request $request, Response $response, $args)
  {
    $bookingID = $args['bookingID'];
    
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

    try {
      $booking = $this->booking->getBookingByID($bookingID);
      
      if (!$booking) {
        return $response->withJson(['error' => 'Booking not found'], 404);
      }
      
      // Validar si el user es el cliente o el guía
      if ($booking['UserID'] != $userID && $booking['Guide'] != $userID) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to view this booking."
          ]
        ]);
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

  public function getBookingsByGuide(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];

    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userJWT = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;

    try {
      $bookings = $this->booking->getBookingsByGuide($userID);

      if (!$bookings) {
        return $response->withJson(['error' => 'No bookings found for this user.'], 404);
      }

      // Validar si el user es el cliente o el guía
      if ($userJWT != $userID && $userType != 'Admin') {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to view this booking."
          ]
        ]);
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

  public function getBookingsBySeeker(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];

    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN", 
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userJWT = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    try {
      $bookings = $this->booking->getBookingsBySeeker($userID);

      if (!$bookings) {
        return $response->withJson(['error' => 'Booking not found'], 404);
      }

      // Validar si el user es el cliente o el guía
      if ($userJWT != $userID && $userType != 'Admin') {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to view this booking."
          ]
        ]);
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
        "Message" => "Booking created successfully", 
        "Booking" => $booking
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

    # Verificar si el usuario autenticado es un Guia o un administrador
    if ($jwt['data']->UserType != 'Guide' && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to update Bookings."
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

      return $response->withStatus(200)->withJson([
        "Message" => "Booking updated successfully", 
        "Booking" => $booking
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
      $guide = $booking['Guide'];

      // Validar permisos
      if (($userID != $seeker && $userID != $guide && $userType != 'Admin')) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED_ACTION",
            "desc" => "You don't have permission to cancel this booking."
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

      // Verificar que la reserva NO haya sucedido
      $scheduledDate = $booking['ScheduledDate'];
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
        "Message" => "Booking cancelled successfully", 
        "Booking" => $booking
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

    if (!in_array($data['Rating'], [1, 2, 3, 4, 5])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING", 
          "desc" => "Rating must be between 1 and 5"
        ]
      ]);
    }

    try {
      // Buscar el guía asociado al offering
      $reviewID = $data['OfferingID'];
      if (!$reviewID) {
        return $response->withStatus(400)->withJson(['error' => 'OfferingID is required.']);
      }

      $result = $this->offering->getOfferingById($reviewID);
      if (!$result) {
        return $response->withStatus(400)->withJson(['error' => 'Offering not found.']);
      }

      $offering = $result->data;
      $guide = $offering['Author']['UserID'];

      $data = [
        'OfferingID' => $reviewID,
        'SUserID' => $seekerID,
        'GUserID' => $guide,
        'Rating' => $data['Rating'],
        'ReviewText' => $data['ReviewText']
      ];

      $review = $this->booking->createReview($data);

      return $response->withStatus(201)->withJson([
        "Message" => "Review created successfully",
        "Review" => $review
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

  public function getReviews(Request $request, Response $response, $args)
  {
    $queryParams = $request->getQueryParams();

    $fromDate = $queryParams['from'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    try {
      $reviews = $this->booking->getReviews($limit, $fromDate);
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

  public function getReviewsByID(Request $request, Response $response, $args)
  {
    $reviewID = $args['reviewID'];
    
    try {
      $review = $this->booking->getReviewsByID($reviewID);
      
      if (!$review) {
        return $response->withJson(['error' => 'Booking not found'], 404);
      }

      return $response->withStatus(200)->withJson($review);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR", 
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getReviewsByOffering(Request $request, Response $response, $args)  {
    $offeringID = $args['offeringID'];
    try {
      $reviews = $this->booking->getReviewsByOffering($offeringID);

      if ($reviews === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_REVIEWS_FOUND",
            "desc" => "No reviews found for this specific Offering."
          ]
        ]);
      }
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
