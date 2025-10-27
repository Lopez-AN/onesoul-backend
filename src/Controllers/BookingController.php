<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Booking;
use App\Models\Offering;
use App\Models\Notification;
use App\Models\User;
use App\Utils\EmailHelper;
use \DateTime;
use Firebase\JWT\JWT;

require_once(ROOT . '/src/Utils/PerspectiveText.php');
require_once(ROOT . '/src/Utils/Paginator.php');

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class BookingController
{
  protected $booking;
  protected $offering;
  protected $user;
  protected $notification;

  public function __construct(Booking $booking, Offering $offering, User $user, Notification $notification)
  {
    $this->booking = $booking;
    $this->offering = $offering;
    $this->user = $user;
    $this->notification = $notification;
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
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }

      // Validar si el user es el cliente o el guía
      if ($booking['UserID'] != $userID && $booking['Guide'] != $userID) {
        return $response->withStatus(401)->withJson([
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

  public function getBookingByPublicID(Request $request, Response $response, $args)
  {
    $publicID = $args['publicID'];

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
      $booking = $this->booking->getBookingByPublicID($publicID);

      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }

      // Validar si el user es el cliente o el guía
      if ($booking['UserID'] != $userID && $booking['Guide'] != $userID) {
        return $response->withStatus(401)->withJson([
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
    $paginator = paginator($request);
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
      // Leer filtros desde query string
      $params = $request->getQueryParams();
      $filters = [];

      if (isset($params['status']) && $params['status'] === 'open') {
        $filters['status'] = 'open';
      }

      if (isset($params['count']) && $params['count'] == 'true') {
        $filters['count'] = true;
      }

      // Llamar al modelo
      $bookings = $this->booking->getBookingsByGuide($userID, $paginator, $filters);

      if ($bookings === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "No Bookings found for this specific user."
          ]
        ]);
      }

      // Validar si el user es el cliente o el guía
      if ($userJWT != $userID && $userType != 'Admin') {
        return $response->withStatus(401)->withJson([
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
    $paginator = paginator($request);
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
      $bookings = $this->booking->getBookingsBySeeker($userID, $paginator);

      if ($bookings === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "No Bookings found for this specific user."
          ]
        ]);
      }

      // Validar si el user es el cliente o el guía
      if ($userJWT != $userID && $userType != 'Admin') {
        return $response->withStatus(401)->withJson([
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

  public function createBooking(Request $request, Response $response, $args) {
    $userID = $jwt['data']->UserID;
    $data = $request->getParsedBody();

    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $message = $data['Message'] ?? null;
    $subDomain = $data['SubDomain'] ?? null;
    $assocUUID = $data['AssocUUID'] ?? null;
    $coupon = $data['Coupon'] ?? null;
    $userInfo = $this->user->getUserById($userID);
    if(!$userInfo){
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified id was found"
        ]
      ]);
    }

    $emailValidated = !empty($userInfo) && filter_var($userInfo['ValidatedEmail'], FILTER_VALIDATE_BOOLEAN);
    $phoneValidated = !empty($userInfo) && filter_var($userInfo['ValidatedPhone'], FILTER_VALIDATE_BOOLEAN);

    if (!$userInfo || !$emailValidated || !$phoneValidated) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "USER_NOT_VALIDATE_EMAIL_PHONE",
          "desc" => "You must validate your email and phone to booking services."
        ]
      ]);
    }

    // Validar formato de subdominio (solo letras A-Z, a-z)
    if (!empty($subDomain)) {
      if (!preg_match('/^[a-zA-Z]+$/', $subDomain)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SUBDOMAIN",
            "desc" => "Subdomain must contain only letters A-Z"
          ]
        ]);
      }
    }

    // Valida contenido con Perspective API
    if($message){
      if ($this->containsInappropriateContent($message)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if ($message && strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

    try {
      // Verificar si el usuario tiene conexión con Calendly
      $hasCalendly = false; // $this->booking->userHasCalendly($userID);  // DEBUG!!!

      if ($hasCalendly) {
        // Buscar el webhook en CalendlyWebhooks
        $webhook = $this->booking->findCalendlyWebhook($assocUUID, $userID);

        if (!$webhook) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "CALENDLY_INVITEE_NOT_FOUND",
              "desc" => "Calendly invitee is required for this service"
            ]
          ]);
        }
      }

      // VALIDAR: Offering si existe
      $id = $data['OfferingID'] ?? null;
      if (!$id) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_OFFERING",
            "desc" => "Offering is required."
          ]
        ]);
      }

      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "No Offering found for this specific ID."
          ]
        ]);
      }

      if ($offering['UserID'] === $userID) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SELF_BOOKING_NOT_ALLOWED",
            "desc" => "Guides cannot book their own offerings."
          ]
        ]);
      }

      // VALIDAR: Fecha de cita
      $scheduledDate = $data['ScheduledDate'] ?? '2025-11-20 12:00:00';  // null;   // DEBUG!!!
      if (!$scheduledDate) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_BOOKING_DATE",
            "desc" => "ScheduledDate is required."
          ]
        ]);
      }

      $scheduledDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledDate);
      if (!$scheduledDateTime || $scheduledDateTime->format('Y-m-d H:i:s') !== $scheduledDate) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_BOOKING_DATE",
            "desc" => "Invalid date format. Must be Y-m-d H:i:s"
          ]
        ]);
      }

      if ($scheduledDateTime < new DateTime()) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_BOOKING_DATE",
            "desc" => "Cannot create a booking that is already in the past."
          ]
        ]);
      }

      $mode = strtolower($data['Mode'] ?? '');
      $allowedModes = ['in-person', 'virtual'];

      if (!in_array($mode, $allowedModes)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_MODE",
            "desc" => "Invalid session mode. Allowed values: in-person, virtual."
          ]
        ]);
      }

      // VALIDAR: LocationID en caso de ser presencial
      $locationID = $data['LocationID'] ?? null;

      if ($mode === 'in-person') {
        if (!$locationID) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_LOCATION",
              "desc" => "LocationID is required for in-person services."
            ]
          ]);
        }

        // Validar que el LocationID exista en offeringLocations
        $location = $this->booking->getLocation($id, $locationID);

        if (!$location) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_LOCATION",
              "desc" => "Invalid LocationID."
            ]
          ]);
        }
      } else {
        // Si no es presencial, LocationID puede ser NULL
        $locationID = null;
      }

      // Revisar si hay al menos un OfferingPackage con un SessionType válido
      $sessionTypes = [
        'in-person' => ['in-person', 'both'],
        'virtual' => ['virtual', 'both']
      ];

      $validTypes = $sessionTypes[$mode];
      $hasValidPackage = false;
      $price = null;
      $conditions = null;

      if (!empty($offering['Packages'])) {
        foreach ($offering['Packages'] as $package) {
          if (in_array(strtolower($package['SessionType']), $validTypes)) {
            $price = $package['Price'] ?? null;
            $conditions = $package['Conditions'] ?? null;
            $hasValidPackage = true;
            break;
          }
        }
      }

      if (!$hasValidPackage) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SESSION_TYPE",
            "desc" => "The offering does not support the selected mode: $mode"
          ]
        ]);
      }

      $countryCode = $userInfo['CountryCode'] ?? "AR";
      $type = 'B';
      $publicID = $this->booking->generatePublicId($countryCode, $type);

      // PREPARAR datos para el modelo
      $data = [
        'PublicID' => $publicID,
        'OfferingID' => $id,
        'UserID' => $userID,
        'Mode' => $mode,
        'LocationID' => $locationID,
        'ScheduledDate' => $scheduledDate,
        'Message' => $message
      ];

      $booking = $this->booking->createBooking($data, $subDomain, $assocUUID, $coupon);

      // Si había Calendly y se encontró el webhook → asociar BookingID en CalendlyWebhooks
      if ($hasCalendly && isset($booking['BookingID'])) {
        $this->booking->linkBookingWithCalendly($assocUUID, $booking['BookingID']);
      }

      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      $guideID = $offering['UserID'] ?? null;
      $guideInfo = $this->user->getUserById($guideID);
      if(!$guideInfo){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }

      $guide = $guideInfo['FirstName'] . ' ' . $guideInfo['LastName'];
      $guideEmail = $guideInfo['Email'] ?? null;
      $guidePhone = $guideInfo['Phone'] ?? null;

      // Obtener info del usuario (quien reserva)
      if ($userInfo) {
        $username = $userInfo['UserName'] ?? 'Usuario';
        $userEmail = $userInfo['Email'] ?? null;
        $searcherName = $userInfo['FirstName'] . ' ' . $userInfo['LastName'];
        $searcherPhone = $userInfo['Phone'] ?? '-';

        // Notificación para el guía
        if ($guideID && $guideInfo) {
          $payloadGuide = [
            "YEAR"          => date('Y'),
            "GUIDE_NAME"    => $guideInfo['UserName'] ?? 'Guía',
            "BOOKING_ID"    => $booking['PublicID'],
            "SERVICE_NAME"  => $offering['Title'] ?? 'Servicio',
            "SEARCHER_NAME" => $searcherName,
            "SEARCHER_EMAIL"=> $userEmail,
            "SEARCHER_PHONE"=> $searcherPhone,
            "MESSAGE"       => $message,
            "SCHEDULED"     => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
            "MODE"          => $booking['Mode'] === 'in-person' ? 'Presencial' : 'Virtual',
            "PRICE"         => $offering['Currency'] . ' ' . $price,
            "BOOKING_URL"   => "{$origin}/bookings/guide"
          ];

          $this->notification->createNotification(
            $guideID,
            "BOOKING.CREATED_FOR_GUIDE",
            $payloadGuide,
            "BOOKING." . $booking['BookingID'] . ".PENDING.GUIDE"
          );
        }

        // Notificación para el usuario que hizo la reserva
        $payloadUser = [
          "YEAR"        => date('Y'),
          "USERNAME"    => $username,
          "OFFERING"    => $offering['Title'] ?? 'Servicio',
          "GUIDE_NAME"  => $guide,
          "GUIDE_EMAIL" => $guideEmail,
          "GUIDE_PHONE" => $guidePhone,
          "SCHEDULED"   => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
          "MODE"        => $booking['Mode'] === 'in-person' ? 'Presencial' : 'Virtual',
          "PRICE"       => $offering['Currency'] . ' ' . $price,
          "CONDITIONS"  => $conditions,
          "BOOKING_ID"  => $booking['PublicID'],
          "MESSAGE"     => $message,
          "BOOKING_URL" => "{$origin}/bookings/user"
        ];

        $this->notification->createNotification(
          $userID,
          "BOOKING.CREATED_FOR_SEEKER",
          $payloadUser,
          "BOOKING." . $booking['BookingID'] . ".PENDING.SEEKER"
        );
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
    $bookingID = $args['bookingID'];
    $data = $request->getParsedBody();
    $scheduledDate = $data['ScheduledDate'] ?? '2025-11-20 12:00:00';  // null;   // DEBUG!!!
    $mode = strtolower($data['Mode'] ?? '');
    $message = $data['Message'] ?? null;
    $locationID = $data['LocationID'] ?? null;
    $subDomain = $data['SubDomain'] ?? '';

    // Validar formato de subdominio (solo letras A-Z, a-z)
    if (!empty($subDomain)) {
      if (!preg_match('/^[a-zA-Z]+$/', $subDomain)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SUBDOMAIN",
            "desc" => "Subdomain must contain only letters A-Z"
          ]
        ]);
      }
    }

    // Valida contenido con Perspective API
    if(!empty($data['Message'])){
      if ($this->containsInappropriateContent($data['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if (strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

    try {
      // Validar si booking existe
      $booking = $this->booking->getBookingByID($bookingID);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found."
            ]
        ]);
      }

      // Validar si el user es el cliente o el guía o un administrador
      if ($booking['UserID'] != $userID && $booking['Guide'] != $userID && $userType != 'Admin'){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to cancel this booking."
          ]
        ]);
      }

      // Verificar si el booking está cancelado, confirmado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['BookingEvent'], ['Canceled', 'Confirmed', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
            ]
          ]);
        }
      }

      // Validar que al menos uno venga definido
      if (empty($scheduledDate) && empty($mode) && empty($locationID)) {
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
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_BOOKING_DATE",
              "desc" => "Invalid date format. Must be Y-m-d H:i:s"
            ]
          ]);
        }

        if ($scheduledDateTime < new DateTime()) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_BOOKING_DATE",
              "desc" => "Cannot cancel a booking that is already in the past."
            ]
          ]);
        }
      }

      $id = $booking['OfferingID'];

      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "No Offering found for this specific ID."
          ]
        ]);
      }

      if ($mode) {
        $allowedModes = ['in-person', 'virtual'];

        if (!in_array($mode, $allowedModes)) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_MODE",
              "desc" => "Invalid session mode. Allowed values: in-person, virtual."
            ]
          ]);
        }

        // Revisar si hay al menos un OfferingPackage con un SessionType válido
        $sessionTypes = [
          'in-person' => ['in-person', 'both'],
          'virtual' => ['virtual', 'both']
        ];

        // VALIDAR: LocationID en caso de ser presencial
        if ($mode === 'in-person') {
          if (!$locationID) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_LOCATION",
                "desc" => "LocationID is required for in-person services."
              ]
            ]);
          }

          // Validar que el LocationID exista en offeringLocations
          $location = $this->booking->getLocation($id, $locationID);

          if (!$location) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_LOCATION",
                "desc" => "Invalid LocationID."
              ]
            ]);
          }
        } else {
          // Si no es presencial, LocationID puede ser NULL
          $locationID = null;
        }

        $validTypes = $sessionTypes[$mode];
        $hasValidPackage = false;

        if (!empty($offering['Packages'])) {
          foreach ($offering['Packages'] as $package) {
            if (in_array(strtolower($package['SessionType']), $validTypes)) {
              $hasValidPackage = true;
              break;
            }
          }
        }

        if (!$hasValidPackage) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_SESSION_TYPE",
              "desc" => "The offering does not support the selected mode: $mode"
            ]
          ]);
        }
      }

      $booking = $this->booking->updateBooking($bookingID, $mode, $scheduledDate, $message, $locationID, $subDomain);

      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      // Obtener datos del usuario que hizo la reserva
      $userInfo = $this->user->getUserById($booking['UserID']);
      if ($userInfo) {
        $user = $userInfo;
        $username = $user['UserName'] ?? $user['DisplayName'] ?? 'Usuario';
        $userEmail = $user['Email'] ?? null;

        // Obtener título del servicio
        $offeringName = $offering['Title'] ?? 'Servicio';

        // Enviar email al buscador
        if ($userEmail) {
          EmailHelper::send(
            $username,
            $userEmail,
            "Reserva modificada en OneSoul",
            ROOT . "/src/templates/email_booking_updated.html",
            [
              '{YEAR}' => date('Y'),
              '{USERNAME}' => $username,
              '{OFFERING}' => $offeringName,
              '{BOOKING_ID}' => $booking['PublicID'],
              '{MESSAGE}' => $message,
              '{SCHEDULED}' => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
              '{MODE}' => $booking['Mode'] === 'in-person' ? 'Presencial' : 'Virtual',
              '{BOOKING_URL}' => "{$origin}/bookings/seeker",
            ]
          );
        }

        // Enviar email al guía
        if ($offering) {
          $guideID = $offering['UserID'] ?? null;
          $offeringName = $offering['Title'] ?? 'Servicio';
          $searcherName = $userInfo['FirstName'] . ' ' . $userInfo['LastName'];

          if ($guideID) {
            $guideInfo = $this->user->getUserById($guideID);
            if ($guideInfo) {
              $guideName = $guideInfo['UserName'] ?? 'Guía';
              $guideEmail = $guideInfo['Email'] ?? null;

              if ($guideEmail) {
                EmailHelper::send(
                  $guideName,
                  $guideEmail,
                  "Reserva modificada en OneSoul",
                  ROOT . "/src/templates/email_booking_updated_guide.html",
                  [
                    '{YEAR}' => date('Y'),
                    '{GUIDE_NAME}' => $guideName,
                    '{SERVICE_NAME}' => $offeringName,
                    '{SEARCHER_NAME}' => $searcherName,
                    '{SEARCHER_EMAIL}' => $userEmail,
                    '{BOOKING_ID}' => $booking['PublicID'],
                    '{MESSAGE}' => $message,
                    '{SEARCHER_PHONE}' => $userInfo['Phone'] ?? '-',
                    '{SCHEDULED}' => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
                    '{MODE}' => $booking['Mode'] === 'in-person' ? 'Presencial' : 'Virtual',
                    '{BOOKING_URL}' => "{$origin}/bookings/guide"
                  ]
                );
              }
            }
          }
        }
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

    $data = $request->getParsedBody();
    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    $bookingID = $args['bookingID'];
    $message = $data['Message'] ?? null;

    // Validar que defina el motivo de la anulación (se guarda en campo Message)
    if (!$message) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "The reason is required for cancellation."
        ]
      ]);
    }

    // Valida contenido con Perspective API
    if(!empty($data['Message'])){
      if ($this->containsInappropriateContent($data['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if (strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

    $subDomain = $data['SubDomain'] ?? '';

    // Validar formato de subdominio (solo letras A-Z, a-z)
    if (!empty($subDomain)) {
      if (!preg_match('/^[a-zA-Z]+$/', $subDomain)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SUBDOMAIN",
            "desc" => "Subdomain must contain only letters A-Z"
          ]
        ]);
      }
    }

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

      // Validar si el user es el cliente o el guía o un administrador
      if ($booking['UserID'] != $userID && $booking['Guide'] != $userID && $userType != 'Admin'){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to cancel this booking."
          ]
        ]);
      }

      // Verificar si el booking está cancelado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['BookingEvent'], ['Canceled', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
            ]
          ]);
        }
      }

      $booking = $this->booking->cancelBooking($bookingID, $message, $subDomain);

      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      // Obtener datos del usuario que hizo la reserva
      $userInfo = $this->user->getUserById($booking['UserID']);
      if ($userInfo) {
        $user = $userInfo;
        $username = $user['UserName'] ?? $user['DisplayName'] ?? 'Usuario';
        $userEmail = $user['Email'] ?? null;

        // Obtener info del servicio
        $offering = $this->offering->getOfferingById($booking['OfferingID']);
        $offeringName = $offering['Title'] ?? 'Servicio';

        // Enviar email al buscador
        if ($userEmail) {
          EmailHelper::send(
            $username,
            $userEmail,
            "La reserva {$booking['PublicID']} fue cancelada",
            ROOT . "/src/templates/email_booking_canceled.html",
            [
              '{YEAR}' => date('Y'),
              '{USERNAME}' => $username,
              '{OFFERING}' => $offeringName,
              '{BOOKING_ID}' => $booking['PublicID'],
              '{MESSAGE}' => $message,
              '{BOOKING_URL}' => "{$origin}/bookings/seeker",
            ]
          );
        }

        // Enviar email al guía
        if ($offering) {
          $guideID = $offering['UserID'] ?? null;
          $offeringName = $offering['Title'] ?? 'Servicio';
          $searcherName = $userInfo['FirstName'] . ' ' . $userInfo['LastName'];

          if ($guideID) {
            $guideInfo = $this->user->getUserById($guideID);
            if ($guideInfo) {
              $guideName = $guideInfo['UserName'] ?? 'Guía';
              $guideEmail = $guideInfo['Email'] ?? null;

              if ($guideEmail) {
                EmailHelper::send(
                  $guideName,
                  $guideEmail,
                  "La reserva {$booking['PublicID']} fue cancelada",
                  ROOT . "/src/templates/email_booking_canceled_guide.html",
                  [
                    '{YEAR}' => date('Y'),
                    '{GUIDE_NAME}' => $guideName,
                    '{SERVICE_NAME}' => $offeringName,
                    '{BOOKING_ID}' => $booking['PublicID'],
                    '{SEARCHER_NAME}' => $searcherName,
                    '{SEARCHER_EMAIL}' => $userEmail,
                    '{SEARCHER_PHONE}' => $userInfo['Phone'] ?? '-',
                    '{MESSAGE}' => $message,
                    '{BOOKING_URL}' => "{$origin}/bookings/guide"
                  ]
                );
              }
            }
          }
        }
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

  public function confirmBooking(Request $request, Response $response, $args)
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

    $data = $request->getParsedBody();
    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    $bookingID = $args['bookingID'];
    $message = $data['Message'] ?? null;

    // Valida contenido con Perspective API
    if(!empty($data['Message'])){
      if ($this->containsInappropriateContent($data['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if (strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

    $subDomain = $data['SubDomain'] ?? '';

    // Validar formato de subdominio (solo letras A-Z, a-z)
    if (!empty($subDomain)) {
      if (!preg_match('/^[a-zA-Z]+$/', $subDomain)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SUBDOMAIN",
            "desc" => "Subdomain must contain only letters A-Z"
          ]
        ]);
      }
    }

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

      // Validar si es el guía o un administrador
      if ($booking['Guide'] != $userID && $userType != 'Admin'){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to confirm this booking."
          ]
        ]);
      }

      // Verificar si el booking está cancelado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['BookingEvent'], ['Canceled', 'Confirmed', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
            ]
          ]);
        }
      }

      $booking = $this->booking->confirmBooking($bookingID, $message, $subDomain);

      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      // Obtener datos del usuario que hizo la reserva
      $userInfo = $this->user->getUserById($booking['UserID']);
      if ($userInfo) {
        $user = $userInfo;
        $username = $user['UserName'] ?? $user['DisplayName'] ?? 'Usuario';
        $userEmail = $user['Email'] ?? null;

        // Obtener info del servicio
        $offering = $this->offering->getOfferingById($booking['OfferingID']);
        $offeringName = $offering['Title'] ?? 'Servicio';

        // Enviar email al buscador
        if ($userEmail) {
          EmailHelper::send(
            $username,
            $userEmail,
            "La reserva {$booking['PublicID']} fue confirmada",
            ROOT . "/src/templates/email_booking_confirmed.html",
            [
              '{YEAR}' => date('Y'),
              '{USERNAME}' => $username,
              '{OFFERING}' => $offeringName,
              '{BOOKING_ID}' => $booking['PublicID'],
              '{SCHEDULED}' => $booking['ScheduledDate'] ? $booking['ScheduledDate'] : 'A confirmar',
              '{MODE}' => $booking['Mode'],
              '{MESSAGE}' => $message,
              '{BOOKING_URL}' => "{$origin}/bookings/seeker",
            ]
          );
        }

        // Enviar email al guía
        if ($offering) {
          $guideID = $offering['UserID'] ?? null;
          $offeringName = $offering['Title'] ?? 'Servicio';
          $searcherName = $userInfo['FirstName'] . ' ' . $userInfo['LastName'];

          if ($guideID) {
            $guideInfo = $this->user->getUserById($guideID);
            if ($guideInfo) {
              $guideName = $guideInfo['UserName'] ?? 'Guía';
              $guideEmail = $guideInfo['Email'] ?? null;

              if ($guideEmail) {
                EmailHelper::send(
                  $guideName,
                  $guideEmail,
                  "La reserva {$booking['PublicID']} fue confirmada",
                  ROOT . "/src/templates/email_booking_confirmed_guide.html",
                  [
                    '{YEAR}' => date('Y'),
                    '{GUIDE_NAME}' => $guideName,
                    '{SERVICE_NAME}' => $offeringName,
                    '{BOOKING_ID}' => $booking['PublicID'],
                    '{SCHEDULED}' => $booking['ScheduledDate'] ? $booking['ScheduledDate'] : 'A confirmar',
                    '{MODE}' => $booking['Mode'],
                    '{SEARCHER_NAME}' => $searcherName,
                    '{SEARCHER_EMAIL}' => $userEmail,
                    '{SEARCHER_PHONE}' => $userInfo['Phone'] ?? '-',
                    '{MESSAGE}' => $message,
                    '{BOOKING_URL}' => "{$origin}/bookings/guide"
                  ]
                );
              }
            }
          }
        }
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

  public function completeBooking(Request $request, Response $response, $args)
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

    $data = $request->getParsedBody();
    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    $bookingID = $args['bookingID'];
    $message = $data['Message'] ?? null;
    $rating = $data['Rating'] ?? null;
    $fulfilled = $data['Fulfilled'] ?? null;

    if (!is_bool($fulfilled)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_FULFILLED_VALUE",
          "desc" => "The 'fulfilled' parameter must be true or false."
        ]
      ]);
    }

    if (empty($rating)) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
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

    // Valida contenido con Perspective API
    if(!empty($data['Message'])){
      if ($this->containsInappropriateContent($data['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if (strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

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

      // Validar si es el guía o un administrador
      if ($booking['Guide'] != $userID && $userType != 'Admin'){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to confirm this booking."
          ]
        ]);
      }

      // Verificar si el último evento del booking es distinto de 'Confirmed'
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if ($latestEvent['BookingEvent'] !== 'Confirmed') {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_NOT_CONFIRMED",
              "desc" => "Cannot update this booking because it is not in Confirmed status."
            ]
          ]);
        }
      }

      $seekerID = $booking['UserID'];
      $guideID = $booking['Guide'];

      $booking = $this->booking->completeBooking($bookingID, $message, $seekerID, $guideID, $rating, $fulfilled);

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

  public function rateBooking(Request $request, Response $response, $args)
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

    $data = $request->getParsedBody();
    $userID = $jwt['data']->UserID;
    $userType = $jwt['data']->UserType;
    $bookingID = $args['bookingID'];
    $message = $data['Message'] ?? null;
    $rating = $data['Rating'] ?? null;
    $fulfilled = $data['Fulfilled'] ?? null;

    if (!is_bool($fulfilled)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_FULFILLED_VALUE",
          "desc" => "The 'fulfilled' parameter must be true or false."
        ]
      ]);
    }

    if (empty($rating)) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
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

    // Valida contenido con Perspective API
    if(!empty($data['Message'])){
      if ($this->containsInappropriateContent($data['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
    }

    if (strlen($message) > 1000) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MESSAGE_TOO_LONG",
          "desc" => "The message is too long (max 1000 characters)"
        ]
      ]);
    }

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

    // Validar si el user es el cliente  o un administrador
    if ($booking['UserID'] != $userID && $userType != 'Admin'){
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to review this booking."
        ]
      ]);
    }

      // Verificar si el último evento del booking es distinto de 'Confirmed'
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if ($latestEvent['BookingEvent'] !== 'Completed') {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_NOT_COMPLETED",
              "desc" => "Cannot update this booking because it is not in Completed status."
            ]
          ]);
        }
      }

      $seekerID = $booking['UserID'];
      $guideID = $booking['Guide'];
      $offeringID = $booking['OfferingID'];

      $offering = $this->offering->getOfferingById($offeringID);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      $booking = $this->booking->rateBooking($offeringID, $bookingID, $message, $seekerID, $guideID, $rating, $fulfilled);

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

  /*
  REVIEWS
  */

  public function getReviews(Request $request, Response $response, $args)
  {
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    // Validar formato YYYYMMDD
    $isValidDate = function($date) {
      return preg_match('/^\d{8}$/', $date) && DateTime::createFromFormat('Ymd', $date) !== false;
    };

    if (($to !== null && !$isValidDate($to)) || ($from !== null && !$isValidDate($from))) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TO_DATE",
          "desc" => "El parámetro debe tener el formato YYYYMMDD"
        ]
      ]);
    }

    if ($rating !== null && (!is_numeric($rating) || $rating < 1 || $rating > 5)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING",
          "desc" => "El parámetro 'rating' debe estar entre 1 y 5."
        ]
      ]);
    }

    try {
      $reviews = $this->booking->getReviews($limit, $from, $to, $rating);

      if ($reviews === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_REVIEWS_FOUND",
            "desc" => "No reviews found."
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

  public function getReviewsByGuide(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    // Validar formato YYYYMMDD
    $isValidDate = function($date) {
      return preg_match('/^\d{8}$/', $date) && DateTime::createFromFormat('Ymd', $date) !== false;
    };

    if (($to !== null && !$isValidDate($to)) || ($from !== null && !$isValidDate($from))) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TO_DATE",
          "desc" => "El parámetro debe tener el formato YYYYMMDD"
        ]
      ]);
    }

    if ($rating !== null && (!is_numeric($rating) || $rating < 1 || $rating > 5)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING",
          "desc" => "El parámetro 'rating' debe estar entre 1 y 5."
        ]
      ]);
    }

    try {
      $reviews = $this->booking->getReviewsByGuide($userID, $limit, $from, $to, $rating);

      if ($reviews === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_REVIEWS_FOUND",
            "desc" => "No reviews found for this specific user."
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

  public function getReviewsBySeeker(Request $request, Response $response, $args)
  {
    $userID = $args['userID'];
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    // Validar formato YYYYMMDD
    $isValidDate = function($date) {
      return preg_match('/^\d{8}$/', $date) && DateTime::createFromFormat('Ymd', $date) !== false;
    };

    if (($to !== null && !$isValidDate($to)) || ($from !== null && !$isValidDate($from))) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TO_DATE",
          "desc" => "El parámetro debe tener el formato YYYYMMDD"
        ]
      ]);
    }

    if ($rating !== null && (!is_numeric($rating) || $rating < 1 || $rating > 5)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING",
          "desc" => "El parámetro 'rating' debe estar entre 1 y 5."
        ]
      ]);
    }

    try {
      $reviews = $this->booking->getReviewsBySeeker($userID, $limit, $from, $to, $rating);

      if ($reviews === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_REVIEWS_FOUND",
            "desc" => "No reviews found for this specific user."
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

  public function getReviewsByUser(Request $request, Response $response, $args){
    $userID = $args['userID'];
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    // Validar formato YYYYMMDD
    $isValidDate = function($date) {
      return preg_match('/^\d{8}$/', $date) && DateTime::createFromFormat('Ymd', $date) !== false;
    };

    if (($to !== null && !$isValidDate($to)) || ($from !== null && !$isValidDate($from))) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_DATE",
          "desc" => "El parámetro debe tener el formato YYYYMMDD"
        ]
      ]);
    }

    if ($rating !== null && (!is_numeric($rating) || $rating < 1 || $rating > 5)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING",
          "desc" => "El parámetro 'rating' debe estar entre 1 y 5."
        ]
      ]);
    }

    try {
      $reviews = $this->booking->getReviewsByUser($userID, $limit, $from, $to, $rating);

      if ($reviews === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_REVIEWS_FOUND",
            "desc" => "No reviews found for this specific user."
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

  public function getReviewsByID(Request $request, Response $response, $args)
  {
    $reviewID = $args['reviewID'];

    try {
      $review = $this->booking->getReviewsByID($reviewID);

      if (!$review) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "REVIEW_NOT_FOUND",
            "desc" => "Review not found."
          ]
        ]);
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

  public function getReviewsByOffering(Request $request, Response $response, $args)
  {
    $offeringID = $args['offeringID'];
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    // Validar formato YYYYMMDD
    $isValidDate = function($date) {
      return preg_match('/^\d{8}$/', $date) && DateTime::createFromFormat('Ymd', $date) !== false;
    };

    if (($to !== null && !$isValidDate($to)) || ($from !== null && !$isValidDate($from))) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TO_DATE",
          "desc" => "El parámetro debe tener el formato YYYYMMDD"
        ]
      ]);
    }

    if ($rating !== null && (!is_numeric($rating) || $rating < 1 || $rating > 5)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RATING",
          "desc" => "El parámetro 'rating' debe estar entre 1 y 5."
        ]
      ]);
    }

    try {
      $reviews = $this->booking->getReviewsByOffering($offeringID, $limit, $from, $to, $rating);

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

  private function containsInappropriateContent($text)
  {
    return validateContentWithPerspective($text);
  }
}