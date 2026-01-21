<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Booking;
use App\Models\Offering;
use App\Models\Notification;
use App\Models\Donation;
use App\Models\User;
use DateTime;
use Firebase\JWT\JWT;
use App\Utils\ParameterValidator;

require_once(ROOT . '/src/Utils/PerspectiveText.php');
require_once(ROOT . '/src/Utils/Paginator.php');

class BookingController {
  protected $booking;
  protected $offering;
  protected $user;
  protected $notification;
  protected $donation;

  public function __construct(Booking $booking, Offering $offering,
    User $user, Notification $notification, Donation $donation
  ){
    $this->booking = $booking;
    $this->offering = $offering;
    $this->user = $user;
    $this->notification = $notification;
    $this->donation = $donation;
  }

  /**
   * Obtiene los datos de una reserva específica por su ID numérico
   * Valida que el usuario autenticado sea el cliente o el guía asociado
   * @param Request $request: objeto de la petición HTTP entrante con JWT
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'bookingID'
   * @return Response: JSON con datos de la reserva o error
   * @statusCode 200: éxito - reserva encontrada y usuario autorizado
   * @statusCode 401: usuario no autorizado para ver esta reserva
   * @statusCode 404: reserva no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function getBookingByID(Request $request, Response $response, $args) {
    $params['BookingID'] = $args['BookingID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'bookings','get_booking_by_id', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $booking = $this->booking->getBookingByID($params['BookingID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }

      # Validar si el user es el buscador o el guía
      if ($booking['Seeker']['UserID'] !== $userID && $booking['Guide'] !== $userID) {
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

  /**
   * Obtiene los datos de una reserva específica por su ID público (PublicID)
   * Valida que el usuario autenticado sea el cliente o el guía asociado
   * @param Request $request: objeto de la petición HTTP entrante con JWT
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'publicID'
   * @return Response: JSON con datos de la reserva o error
   * @statusCode 200: éxito - reserva encontrada y usuario autorizado
   * @statusCode 401: usuario no autorizado para ver esta reserva
   * @statusCode 404: reserva no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function getBookingByPublicID(Request $request, Response $response, $args) {
    $params['PublicID'] = $args['PublicID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'bookings','get_booking_by_public_id', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $booking = $this->booking->getBookingByPublicID($params['PublicID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }

      # Validar si el user es el buscador o el guía
      if ($booking['Seeker']['UserID'] !== $userID && $booking['Guide'] !== $userID) {
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

  /**
   * Obtiene todas las reservas asociadas a un guía específico
   * Solo el guía o un administrador puede acceder a esta información
   * Soporta paginación y filtrado por estado (abierto/cerrado)
   * @param Request $request: objeto de la petición HTTP entrante con JWT y query params opcionales (status)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'userID' del guía
   * @return Response: JSON con lista de reservas paginadas o error
   * @statusCode 200: éxito - lista de reservas del guía
   * @statusCode 403: usuario no autorizado (no es el guía ni admin)
   * @statusCode 404: no se encontraron reservas para este guía
   * @statusCode 500: error interno del servidor
   **/
  public function getBookingsByGuide(Request $request, Response $response, $args) {
    $params['GuideID'] = $args['GuideID'];
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'bookings','get_bookings_by_guide', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    # Validar solo el guia o un admin puede consultar sus booking
    if ($userID !== $params['GuideID'] && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to view bookings from this guide."
        ]
      ]);
    }

    try {
      # Filtro solo abiertos
      $onlyOpen = isset($queryParams['status']) && $queryParams['status'] === 'open';

      # Llamar al modelo
      $bookings = $this->booking->getBookingsByGuide($params['GuideID'], $paginator, $onlyOpen);
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

  /**
   * Obtiene todas las reservas asociadas a un buscador específico (cliente)
   * Solo el buscador o un administrador puede acceder a esta información
   * Soporta paginación y filtrado por estado (abierto/cerrado)
   * @param Request $request: objeto de la petición HTTP entrante con JWT y query params opcionales (status)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'userID' del buscador
   * @return Response: JSON con lista de reservas paginadas o error
   * @statusCode 200: éxito - lista de reservas del buscador
   * @statusCode 403: usuario no autorizado (no es el buscador ni admin)
   * @statusCode 404: no se encontraron reservas para este buscador
   * @statusCode 500: error interno del servidor
   **/
  public function getBookingsBySeeker(Request $request, Response $response, $args) {
    $params['SeekerID'] = $args['SeekerID'];
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'bookings','get_bookings_by_seeker', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    # Validar solo el buscador o un admin puede consultar sus booking
    if ($userID !== $params['SeekerID'] && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to view bookings from this seeker."
        ]
      ]);
    }

    try {
      # Filtro solo abiertos
      $onlyOpen = isset($queryParams['status']) && $queryParams['status'] === 'open';

      # Llamar al modelo
      $bookings = $this->booking->getBookingsBySeeker($params['SeekerID'], $paginator, $onlyOpen);
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

  /**
   * Obtiene información detallada del cliente (seeker) asociado a una reserva
   * Retorna datos personales, de contacto, ubicación y rating del buscador
   * @param Request $request: objeto de la petición HTTP entrante con JWT
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'BookingID'
   * @return Response: JSON con información del seeker (UserID, nombre, email, teléfono, ubicación, rating) o error
   * @statusCode 200: éxito - información del seeker obtenida correctamente
   * @statusCode 400: parámetros inválidos (BookingID)
   * @statusCode 404: reserva no encontrada o usuario asociado no encontrado
   * @statusCode 500: error interno del servidor
   **/
  public function getSeekerInfo(Request $request, Response $response, $args) {
    $params['BookingID'] = $args['BookingID'];

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'bookings','get_seeker_info', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $booking = $this->booking->getBookingByID($params['BookingID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }

      $seeker = $this->user->getUserById($booking['Seeker']['UserID']);
      if(!$seeker){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Seeker associated with the booking not found"
          ]
        ]);
      }

      $info = [
        "UserID" => $seeker['UserID'],
        "FirstName" => $seeker['FirstName'],
        "LastName" => $seeker['LastName'],
        "UserName" => $seeker['UserName'],
        "DisplayName" => $seeker['DisplayName'],
        "RegistrationDate" => $seeker['RegistrationDate'],
        "ShortDescription" => $seeker['ShortDescription'],
        "ImgURL" => $seeker['ImgURL'],
        "Rating" => $seeker['Rating'],
        "TotalReviews" => $seeker['TotalReviews'],
        "Email" => $seeker['Email'],
        "Phone" => $seeker['Phone'],
        "CountryCode" => $seeker['Locations'][0]['CountryCode'],
        "State" => $seeker['Locations'][0]['State'],
        "City" => $seeker['Locations'][0]['City'],
        "Cp" => $seeker['Locations'][0]['Cp']
      ];

      return $response->withStatus(200)->withJson($info);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Crea una nueva reserva de un servicio (offering)
   * Valida email/teléfono del usuario, verifica offering, modalidad, cupones y conexión Cal.com
   * Genera notificaciones para guía y buscador
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (OfferingID, SessionType, Message, etc.)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta (no utilizado en este método)
   * @return Response: JSON con datos de la reserva creada o error
   * @statusCode 200: éxito - reserva creada correctamente
   * @statusCode 400: parámetros inválidos, contenido inapropiado, modalidad no válida, cupón inválido o invitee de Cal.com no encontrado
   * @statusCode 401: guía intentando reservar su propio servicio
   * @statusCode 404: usuario, offering, cupón o guía no encontrado
   * @statusCode 409: cupón no asignado a una agencia
   * @statusCode 410: cupón ya utilizado, expirado o cancelado
   * @statusCode 500: error interno del servidor
   **/
  public function createBooking(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $seekerID = $jwt->data->UserID;

    # Validar parametros
    $pValidation = ParameterValidator::validate($response, 'bookings','create_booking', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $seeker = $this->user->getUserById($seekerID);
      if(!$seeker){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Seeker associated with the JWT token not found"
          ]
        ]);
      }

      $emailValidated = filter_var($seeker['ValidatedEmail'], FILTER_VALIDATE_BOOLEAN);
      $phoneValidated = filter_var($seeker['ValidatedPhone'], FILTER_VALIDATE_BOOLEAN);
      if (!$emailValidated){  // || !$phoneValidated) {  // DEBUG DESCOMENTAR LUEGO
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "USER_NOT_VALIDATE_EMAIL_PHONE",
            "desc" => "You must validate your email and phone to booking services."
          ]
        ]);
      }

      # Valida contenido con Perspective API
      if ($this->_containsInappropriateContent($params['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      # Obtener y validar offering
      $offering = $this->offering->getOfferingById($params['OfferingID']);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "Associated offering not found"
          ]
        ]);
      }

      # Verifico si el offering tiene la modalidad seleccionada
      if($offering['SessionType'] !== 'both' && $offering['SessionType'] !== $params['SessionType']){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SESSION_TYPE",
            "desc" => "The offering does not have the selected session mode"
          ]
        ]);
      }

      $guideID = $offering['UserID'];
      if ($guideID === $seekerID) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SELF_BOOKING_NOT_ALLOWED",
            "desc" => "Guides cannot book their own offerings."
          ]
        ]);
      }

      if ($params['SessionType'] === 'in-person') {
        // if (!$locationID) {
        //   return $response->withStatus(400)->withJson([
        //     "error" => [
        //       "code" => "INVALID_LOCATION",
        //       "desc" => "LocationID is required for in-person services."
        //     ]
        //   ]);
        // }

        // --> REWORK PENDIENTE
        // # Validar que el LocationID exista en offeringLocations
        // $location = $this->booking->getLocation($offeringID, $locationID);
        // if (!$location) {
        //   return $response->withStatus(400)->withJson([
        //     "error" => [
        //       "code" => "INVALID_LOCATION",
        //       "desc" => "Provided location ID does not belong to the associated offering or does not exist"
        //     ]
        //   ]);
        // }
      }

      # Obtengo el guia
      $guide = $this->user->getUserById($guideID);
      if(!$guide){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Guide associated with the offering is not found"
          ]
        ]);
      }

      # Verificar si el guia tiene conexión con Cal.com
      if ($this->booking->userHasCal($guideID)) {
        # Buscar el invite en CalendlyWebhooks
        $cal = $this->booking->findCalInvitee($params['AssocUUID'], $guideID);
        if (!$cal) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "CAL_INVITEE_NOT_FOUND",
              "desc" => "Cal.com invitee is required for this service"
            ]
          ]);
        }
        $params['ScheduledDate'] = DateTime::createFromFormat("Y-m-d H:i:s.u", $cal['StartTime']);
      }else{
        $params['ScheduledDate'] = null; # No hay fecha de reserva si no se usa cal.com
        $params['AssocUUID'] = null; # Si no se usa cal.com se descarga el uuid para no asociar nada
      }

      # Valido un cupon
      $voucherID = null;
      if($params['Coupon']){
        $donation = $this->donation->getDonationByRedeemCode($params['Coupon']);
        if (!$donation) {
          return $response->withStatus(404)->withJson([
            "error" => [
              "code" => "COUPON_NOT_FOUND",
              "desc" => "The coupon does not exist"
            ]
          ]);
        }
        if ($donation['Status'] === 'redeemed'){
          return $response->withStatus(410)->withJson([
            "error" => [
              "code" => "COUPON_ALREADY_REDEEMED",
              "desc" => "The coupon has already been redeemed"
            ]
          ]);
        }
        if ($donation['Status'] === 'expired' || ($donation['ExpiredAt'] && $donation['ExpiredAt'] < time())){
          return $response->withStatus(410)->withJson([
            "error" => [
              "code" => "COUPON_EXPIRED",
              "desc" => "The coupon has expired"
            ]
          ]);
        }
        if ($donation['Status'] === 'canceled'){
          return $response->withStatus(410)->withJson([
            "error" => [
              "code" => "COUPON_CANCELED",
              "desc" => "The coupon has been canceled"
            ]
          ]);
        }
        if ($donation['Status'] !== 'assigned'){
          return $response->withStatus(409)->withJson([
            "error" => [
              "code" => "COUPON_NOT_ASSIGNED",
              "desc" => "The coupon has not been assigned to an agency yet"
            ]
          ]);
        }
        $voucherID = $donation['VoucherID'];
      }

      # Si hay voucher es precio 0
      $price = $params['Coupon'] ? 0 : $offering['Price'];
      $conditions = $offering['Conditions'];

      $countryCode = $seeker['CountryCode'] ?? "AR";
      $type = 'B';
      $publicID = $this->_generatePublicId($countryCode, $type);

      # PREPARAR datos para el modelo
      $data = [
        'PublicID' => $publicID,
        'OfferingID' => $params['OfferingID'],
        'SeekerID' => $seekerID,
        'SessionType' => $params['SessionType'],
        'LocationID' => $params['LocationID'],
        'ScheduledDate' => $params['ScheduledDate'],
        'Message' => $params['Message'],
        'Currency' => $offering['Currency'],
        'Amount' => $params['Coupon'] ? 0 : $offering['Price']
      ];

      $origin = $params['SubDomain'] ? "https://{$params['SubDomain']}.onesoul.app" : "https://onesoul.app";
      $booking = $this->booking->createBooking($data, $params['SubDomain'], $params['AssocUUID'], $voucherID);

      try{
        # Notificación para el guía
        $payloadGuide = [
          "YEAR"          => date('Y'),
          "GUIDE_NAME"    => $guide['UserName'],
          "BOOKING_ID"    => $booking['PublicID'],
          "BOOKING_URL"   => "{$origin}/bookings/guide",
          "SCHEDULED"     => $params['ScheduledDate']?->format('d/m/Y H:i') ?? "A convenir",
          "SESSION_TYPE"  => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "PRICE"         => $params['Coupon'] ? $offering['Currency'].' '.$price : 'Sesión de donación',
          "OFFERING_ID"   => $params['OfferingID'],
          "OFFERING_TITLE"  => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : '',
          "SEEKER_NAME" => $seeker['FirstName'] && $seeker['LastName'] ?
            $seeker['FirstName'].' '.$seeker['LastName'] : 'No indicado',
          "SEEKER_EMAIL"=> $seeker['Email'],
          "SEEKER_PHONE"=> $seeker['Phone'] ?? '-',
          "MESSAGE"       => $params['Message'],


        ];
        $this->notification->createNotification(
          $guideID,
          "BOOKING.CREATED_FOR_GUIDE",
          $payloadGuide,
          "BOOKING." . $booking['BookingID'] . ".PENDING.GUIDE"
        );
        # Notificación para el buscador
        $payloadSeeker = [
          "YEAR"         => date('Y'),
          "GUIDE_NAME"   => $guide['FirstName'].' '.$guide['LastName'],
          "GUIDE_EMAIL"  => $guide['Email'],
          "GUIDE_PHONE"  => $guide['Phone'],
          "BOOKING_ID"   => $booking['PublicID'],
          "BOOKING_URL"  => "{$origin}/bookings/user",
          "SCHEDULED"    => $params['ScheduledDate']?->format('d/m/Y H:i') ?? "A convenir",
          "SESSION_TYPE" => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "PRICE"        => $params['Coupon'] ? $offering['Currency'].' '.$price : 'Sesión de donación',
          "CONDITIONS"   => $conditions,
          "OFFERING_ID"  => $params['OfferingID'],
          "OFFERING_TITLE"    => $offering['Title'],
          'OFFERING_IMG' => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : '',
          "SEEKER_USERNAME"     => $seeker['UserName'],
          "MESSAGE"      => $params['Message']
        ];
        $this->notification->createNotification(
          $seekerID,
          "BOOKING.CREATED_FOR_SEEKER",
          $payloadSeeker,
          "BOOKING." . $booking['BookingID'] . ".PENDING.SEEKER"
        );
      }catch(\Throwable $e){}

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

  /**
   * Actualiza datos de una reserva existente (mensaje, tipo de sesión, ubicación, fecha)
   * Solo el cliente, guía o administrador pueden actualizar
   * No permite actualizar reservas canceladas, confirmadas, completadas o calificadas
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (SessionType, Message, LocationID, ScheduledDate, SubDomain)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'bookingID'
   * @return Response: JSON con datos de la reserva actualizada o error
   * @statusCode 200: éxito - reserva actualizada correctamente
   * @statusCode 400: parámetros inválidos, JSON inválido, contenido inapropiado, subdominio inválido, mensaje muy largo, modalidad no válida o reserva ya finalizada/cancelada
   * @statusCode 403: usuario no autorizado para actualizar esta reserva
   * @statusCode 404: reserva u offering no encontrado
   * @statusCode 500: error interno del servidor
   **/
  public function updateBooking(Request $request, Response $response, $args) {
    $bookingID = intval($args['BookingID']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $message = $data['Message'] ?? null;
    $sessionType = $data['SessionType'] ?? null;
    $locationID = $data['LocationID'] ?? null;
    $scheduledDate = $data['ScheduledDate'] ?? null;
    $subDomain = $data['SubDomain'] ?? '';

    if (is_null($sessionType) || !in_array($sessionType, ['in-person', 'virtual'])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    # Validar formato de subdominio (solo letras A-Z, a-z)
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

    # Valida contenido con Perspective API
    if(!empty($message)){
      if ($this->_containsInappropriateContent($message)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
      if (strlen($message) > 1000) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MESSAGE_TOO_LONG",
            "desc" => "The message is too long (max 1000 characters)"
          ]
        ]);
      }
    }

    try {
      # Validar si booking existe
      $booking = $this->booking->getBookingByID($bookingID);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found."
            ]
        ]);
      }

      # Validar si el user es el cliente o el guía o un administrador
      if ($booking['Seeker']['UserID'] !== $userID && $booking['Guide'] !== $userID && !$jwt->data->IsAdmin){
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to cancel this booking."
          ]
        ]);
      }

      # Verificar si el booking está cancelado, confirmado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['Event'], ['Canceled', 'Confirmed', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
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

      # Verifico si el offering tiene la modalidad seleccionada
      if($offering['SessionType'] !== 'both' && $offering['SessionType'] !== $sessionType){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SESSION_TYPE",
            "desc" => "The offering does not have the selected session mode"
          ]
        ]);
      }

      # VALIDAR: LocationID en caso de ser presencial
      if ($sessionType === 'in-person') {
        // if (!$locationID) {
        //   return $response->withStatus(400)->withJson([
        //     "error" => [
        //       "code" => "INVALID_LOCATION",
        //       "desc" => "LocationID is required for in-person services."
        //     ]
        //   ]);
        // }

        # Validar que el LocationID exista en offeringLocations
        // $location = $this->booking->getLocation($id, $locationID);

        // if (!$location) {
          // return $response->withStatus(400)->withJson([
          //   "error" => [
          //     "code" => "INVALID_LOCATION",
          //     "desc" => "Invalid LocationID."
          //   ]
          // ]);
        // }
      } else {
        # Si no es presencial, LocationID puede ser NULL
        // $locationID = null;
      }

      if (!in_array($sessionType, ['in-person', 'virtual'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SESSION_TYPE",
            "desc" => "Invalid session type. Allowed values: in-person, virtual."
          ]
        ]);
      }

      $booking = $this->booking->updateBooking($bookingID, $sessionType, $scheduledDate, $message, $locationID, $subDomain);

      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      # Obtener datos del usuario que hizo la reserva
      $userInfo = $this->user->getUserById($booking['Seeker']['UserID']);
      if ($userInfo) {
        $user = $userInfo;
        $username = $user['UserName'] ?? $user['DisplayName'] ?? 'Usuario';
        $userEmail = $user['Email'] ?? null;

        # Obtener título del servicio
        $offeringName = $offering['Title'] ?? 'Servicio';

        # Enviar email al buscador
        if ($userEmail) {
          // EmailHelper::send(
          //   $username,
          //   $userEmail,
          //   "Reserva modificada en OneSoul",
          //   ROOT . "/src/Templates/email_booking_updated.html",
          //   [
          //     '{YEAR}' => date('Y'),
          //     '{USERNAME}' => $username,
          //     '{OFFERING}' => $offeringName,
          //     '{BOOKING_ID}' => $booking['PublicID'],
          //     '{MESSAGE}' => $message ?? '(El guía no agregó comentarios)',
          //     '{SCHEDULED}' => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
          //     '{MODE}' => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          //     '{BOOKING_URL}' => "{$origin}/bookings/seeker",
          //   ]
          // );
        }

        # Enviar email al guía
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
                // EmailHelper::send(
                //   $guideName,
                //   $guideEmail,
                //   "Reserva modificada en OneSoul",
                //   ROOT . "/src/Templates/email_booking_updated_guide.html",
                //   [
                //     '{YEAR}' => date('Y'),
                //     '{GUIDE_NAME}' => $guideName,
                //     '{SERVICE_NAME}' => $offeringName,
                //     '{SEARCHER_NAME}' => $searcherName,
                //     '{SEARCHER_EMAIL}' => $userEmail,
                //     '{BOOKING_ID}' => $booking['PublicID'],
                //     '{MESSAGE}' => $message,
                //     '{SEARCHER_PHONE}' => $userInfo['Phone'] ?? '-',
                //     '{SCHEDULED}' => date('d/m/Y H:i', strtotime($booking['ScheduledDate'])),
                //     '{MODE}' => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
                //     '{BOOKING_URL}' => "{$origin}/bookings/guide"
                //   ]
                // );
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

  /**
   * Cancela una reserva existente
   * Solo el cliente, guía o administrador pueden cancelar
   * No permite cancelar reservas ya canceladas, completadas o calificadas
   * Genera notificaciones para guía y buscador
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (Message, SubDomain)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'BookingID'
   * @return Response: JSON con datos de la reserva cancelada o error
   * @statusCode 200: éxito - reserva cancelada correctamente
   * @statusCode 400: contenido inapropiado o reserva ya finalizada/cancelada
   * @statusCode 401: usuario no autorizado para cancelar esta reserva
   * @statusCode 404: reserva no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function cancelBooking(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $params['BookingID'] = $args['BookingID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Validar parametros
    $pValidation = ParameterValidator::validate($response, 'bookings','cancel_booking', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valida contenido con Perspective API
      if ($this->_containsInappropriateContent($params['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $booking = $this->booking->getBookingByID($params['BookingID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }
      $seekerID = $booking['Seeker']['UserID'];
      $guideID = $booking['Guide'];

      # Validar si el user es el cliente o el guía o un administrador
      if ($seekerID !== $userID && $guideID !== $userID && !$jwt->data->IsAdmin){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to cancel this booking."
          ]
        ]);
      }

      # Verificar si el booking está cancelado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['Event'], ['Canceled', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
            ]
          ]);
        }
      }

      $booking = $this->booking->cancelBooking($params['BookingID'], $params['Message'], $params['SubDomain']);

      $origin = $params['SubDomain'] ? "https://{$params['SubDomain']}.onesoul.app" : "https://onesoul.app";

      # Obtener info del buscador
      $seeker = $this->user->getUserById($seekerID);
      # Obtener info del servicio
      $offering = $this->offering->getOfferingById($booking['OfferingID']);
      # Obtener info del guia
      $guide = $this->user->getUserById($guideID);

      try{
        # Notificación para el guia
        $payloadGuide = [
          "YEAR"           => date('Y'),
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"   => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "SEEKER_USERNAME"       => $seeker['UserName'],
          "SEEKER_NAME"  => $seeker['FirstName'].' '.$seeker['LastName'],
          "SEEKER_EMAIL" => $seeker['Email'],
          "SEEKER_PHONE" => $seeker['Phone'] ?? '-',
          "BOOKING_ID"     => $booking['PublicID'],
          "MESSAGE"        => $params['Message'],
          "BOOKING_URL"    => "{$origin}/bookings/guide"
        ];
        $this->notification->createNotification(
          $guideID,
          "BOOKING.CANCELED_FOR_GUIDE",
          $payloadGuide,
          "BOOKING." . $booking['BookingID'] . ".CANCELED.GUIDE"
        );
        # Notificación para el buscador
        $payloadSeeker = [
          "YEAR"        => date('Y'),
          "SEEKER_USERNAME"    => $seeker['UserName'],
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"    => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "BOOKING_ID"  => $booking['PublicID'],
          "MESSAGE"     => $params['Message'],
          "BOOKING_URL" => "{$origin}/bookings/seeker"
        ];
        $this->notification->createNotification(
          $seekerID,
          "BOOKING.CANCELED_FOR_SEEKER",
          $payloadSeeker,
          "BOOKING." . $booking['BookingID'] . ".CANCELED.SEEKER"
        );
      }catch(\Throwable $e){}

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

  /**
   * Confirma una reserva existente
   * Solo el guía o administrador pueden confirmar
   * No permite confirmar reservas ya canceladas, confirmadas, completadas o calificadas
   * Genera notificaciones para guía y buscador
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (Message, SubDomain)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'BookingID'
   * @return Response: JSON con datos de la reserva confirmada o error
   * @statusCode 200: éxito - reserva confirmada correctamente
   * @statusCode 400: contenido inapropiado o reserva ya finalizada/cancelada/confirmada
   * @statusCode 401: usuario no autorizado para confirmar (no es el guía ni admin)
   * @statusCode 404: reserva no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function confirmBooking(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $params['BookingID'] = $args['BookingID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Validar parametros
    $pValidation = ParameterValidator::validate($response, 'bookings','confirm_booking', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valida contenido con Perspective API
      if ($params['Message'] && $this->_containsInappropriateContent($params['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $booking = $this->booking->getBookingByID($params['BookingID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }
      $seekerID = $booking['Seeker']['UserID'];
      $guideID = $booking['Guide'];

      # Validar si es el guía o un administrador
      if ($guideID !== $userID && !$jwt->data->IsAdmin){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to confirm this booking."
          ]
        ]);
      }

      # Verificar si el booking está cancelado, completado o calificado (último evento solamente)
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if (in_array($latestEvent['Event'], ['Canceled', 'Confirmed', 'Completed', 'Rated'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_ALREADY_CANCELED_OR_CONFIRMED",
              "desc" => "Cannot update this booking."
            ]
          ]);
        }
      }

      $booking = $this->booking->confirmBooking($params['BookingID'], $params['Message'], $params['SubDomain']);

      $origin = $params['SubDomain'] ? "https://{$params['SubDomain']}.onesoul.app" : "https://onesoul.app";

      # Obtener info del buscador
      $seeker = $this->user->getUserById($seekerID);
      # Obtener info del servicio
      $offering = $this->offering->getOfferingById($booking['OfferingID']);
      # Obtener info del guia
      $guide = $this->user->getUserById($guideID);

      try{
        # Notificación para el guia
        $payloadGuide = [
          "YEAR"           => date('Y'),
          "GUIDE_NAME"    => $guide['UserName'],
          "BOOKING_ID"     => $booking['PublicID'],
          "BOOKING_URL"    => "{$origin}/bookings/guide",
          "SCHEDULED"    => $booking['ScheduledDate'] ?? "A convenir",
          "SESSION_TYPE" => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"   => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "SEEKER_USERNAME"       => $seeker['UserName'],
          "SEEKER_NAME"  => $seeker['FirstName'].' '.$seeker['LastName'],
          "SEEKER_EMAIL" => $seeker['Email'],
          "SEEKER_PHONE" => $seeker['Phone'] ?? '-',
          "MESSAGE"        => $params['Message']
        ];
        $this->notification->createNotification(
          $guideID,
          "BOOKING.CONFIRMED_FOR_GUIDE",
          $payloadGuide,
          "BOOKING." . $booking['BookingID'] . ".CONFIRMED.GUIDE"
        );
        # Notificación para el buscador
        $payloadSeeker = [
          "YEAR"        => date('Y'),
          "GUIDE_NAME"    => $guide['UserName'],
          "BOOKING_ID"  => $booking['PublicID'],
          "BOOKING_URL" => "{$origin}/bookings/seeker",
          "SCHEDULED"    => $booking['ScheduledDate'] ?? "A convenir",
          "SESSION_TYPE" => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"    => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "SEEKER_USERNAME"    => $seeker['UserName'],
          "MESSAGE"     => $params['Message']
        ];
        $this->notification->createNotification(
          $seekerID,
          "BOOKING.CONFIRMED_FOR_SEEKER",
          $payloadSeeker,
          "BOOKING." . $booking['BookingID'] . ".CONFIRMED.SEEKER"
        );
      }catch(\Throwable $e){}

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

  /**
   * Marca una reserva como completada
   * Solo el guía o administrador pueden completar
   * Solo permite completar reservas en estado 'Confirmed'
   * Genera notificaciones para guía y buscador
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (Message, Rating, Fulfilled, SubDomain)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'BookingID'
   * @return Response: JSON con datos de la reserva completada o error
   * @statusCode 200: éxito - reserva completada correctamente
   * @statusCode 400: contenido inapropiado o reserva no está en estado 'Confirmed'
   * @statusCode 401: usuario no autorizado para completar (no es el guía ni admin)
   * @statusCode 404: reserva no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function completeBooking(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $params['BookingID'] = $args['BookingID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Validar parametros
    $pValidation = ParameterValidator::validate($response, 'bookings','complete_booking', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valida contenido con Perspective API
      if ($params['Message'] && $this->_containsInappropriateContent($params['Message'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $booking = $this->booking->getBookingByID($params['BookingID']);
      if (!$booking) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "BOOKING_NOT_FOUND",
            "desc" => "Booking not found"
          ]
        ]);
      }
      $seekerID = $booking['Seeker']['UserID'];
      $guideID = $booking['Guide'];

      # Validar si es el guía o un administrador
      if ($guideID !== $userID && !$jwt->data->IsAdmin){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to confirm this booking."
          ]
        ]);
      }

      # Verificar si el último evento del booking es distinto de 'Confirmed'
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if ($latestEvent['Event'] !== 'Confirmed') {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_NOT_CONFIRMED",
              "desc" => "Cannot update this booking because it is not in Confirmed status."
            ]
          ]);
        }
      }

      $booking = $this->booking->completeBooking($params['BookingID'], $params['Message'], $seekerID, $guideID, $params['Rating'], $params['Fulfilled']);

      $origin = $params['SubDomain'] ? "https://{$params['SubDomain']}.onesoul.app" : "https://onesoul.app";

      # Obtener info del buscador
      $seeker = $this->user->getUserById($seekerID);
      # Obtener info del servicio
      $offering = $this->offering->getOfferingById($booking['OfferingID']);
      # Obtener info del guia
      $guide = $this->user->getUserById($guideID);

      try{
        # Notificación para el guia
        $payloadGuide = [
          "YEAR"           => date('Y'),
          "GUIDE_NAME"    => $guide['UserName'],
          "BOOKING_ID"     => $booking['PublicID'],
          "BOOKING_URL"    => "{$origin}/bookings/guide",
          "SCHEDULED"    => $booking['ScheduledDate'] ?? "A convenir",
          "SESSION_TYPE" => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"   => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "SEEKER_USERNAME"       => $seeker['UserName'],
          "SEEKER_NAME"  => $seeker['FirstName'].' '.$seeker['LastName'],
          "SEEKER_EMAIL" => $seeker['Email'],
          "SEEKER_PHONE" => $seeker['Phone'] ?? '-',
          "MESSAGE"        => $params['Message']
        ];
        $this->notification->createNotification(
          $guideID,
          "BOOKING.CONFIRMED_FOR_GUIDE",
          $payloadGuide,
          "BOOKING." . $booking['BookingID'] . ".CONFIRMED.GUIDE"
        );
        # Notificación para el buscador
        $payloadSeeker = [
          "YEAR"        => date('Y'),
          "GUIDE_NAME"    => $guide['UserName'],
          "BOOKING_ID"  => $booking['PublicID'],
          "BOOKING_URL" => "{$origin}/bookings/seeker",
          "SCHEDULED"    => $booking['ScheduledDate'] ?? "A convenir",
          "SESSION_TYPE" => $booking['SessionType'] === 'in-person' ? 'Presencial' : 'Virtual',
          "OFFERING_ID"   => $offering['OfferingID'],
          "OFFERING_TITLE"    => $offering['Title'],
          'OFFERING_IMG'  => isset($offering['Media']['Images'][0]['Url'])
            ? $offering['Media']['Images'][0]['Url'] : null,
          "SEEKER_USERNAME"    => $seeker['UserName'],
          "MESSAGE"     => $params['Message']
        ];
        $this->notification->createNotification(
          $seekerID,
          "BOOKING.CONFIRMED_FOR_SEEKER",
          $payloadSeeker,
          "BOOKING." . $booking['BookingID'] . ".CONFIRMED.SEEKER"
        );
      }catch(\Throwable $e){}

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

  /**
   * Permite al cliente calificar una reserva completada
   * Solo el cliente o administrador pueden calificar
   * Solo permite calificar reservas en estado 'Completed'
   * @param Request $request: objeto de la petición HTTP entrante con JWT y datos en body (Message, Rating, Fulfilled)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'bookingID'
   * @return Response: JSON con datos de la reserva calificada o error
   * @statusCode 200: éxito - reserva calificada correctamente
   * @statusCode 400: parámetros inválidos, rating fuera de rango (1-5), mensaje muy largo, contenido inapropiado, fulfilled no booleano o reserva no está en estado 'Completed'
   * @statusCode 401: usuario no autorizado para calificar (no es el cliente ni admin)
   * @statusCode 404: reserva u offering no encontrado
   * @statusCode 500: error interno del servidor
   **/
  public function rateBooking(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $bookingID = intval($args['BookingID']);
    $message = $data['Message'] ?? null;
    $rating = $data['Rating'] ?? null;
    $fulfilled = $data['Fulfilled'] ?? null;

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

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

    # Valida contenido con Perspective API
    if(!empty($message)){
      if ($this->_containsInappropriateContent($message)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }
      if (strlen($message) > 1000) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MESSAGE_TOO_LONG",
            "desc" => "The message is too long (max 1000 characters)"
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

    # Validar si el user es el cliente  o un administrador
    if ($booking['Seeker']['UserID'] !== $userID && !$jwt->data->IsAdmin){
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to review this booking."
        ]
      ]);
    }

      # Verificar si el último evento del booking es distinto de 'Confirmed'
      if (!empty($booking['Events'])) {
        $latestEvent = $booking['Events'][0];

        if ($latestEvent['Event'] !== 'Completed') {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "BOOKING_NOT_COMPLETED",
              "desc" => "Cannot update this booking because it is not in Completed status."
            ]
          ]);
        }
      }

      $seekerID = $booking['Seeker']['UserID'];
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

  /**
   * Obtiene todas las reseñas del sistema
   * Permite filtrado por rango de fechas, calificación y límite de resultados
   * @param Request $request: objeto de la petición HTTP entrante con query params opcionales (from, to, rating, limit)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta (no utilizado en este método)
   * @return Response: JSON con lista de reseñas o error
   * @statusCode 200: éxito - lista de reseñas
   * @statusCode 400: formato de fecha inválido (debe ser YYYYMMDD) o rating inválido (debe estar entre 1 y 5)
   * @statusCode 404: no se encontraron reseñas
   * @statusCode 500: error interno del servidor
   **/
  public function getReviews(Request $request, Response $response, $args) {
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    # Validar formato YYYYMMDD
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

  /**
   * Obtiene todas las reseñas recibidas por un guía específico
   * Permite filtrado por rango de fechas, calificación y límite de resultados
   * @param Request $request: objeto de la petición HTTP entrante con query params opcionales (from, to, rating, limit)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'userID' del guía
   * @return Response: JSON con lista de reseñas del guía o error
   * @statusCode 200: éxito - lista de reseñas del guía
   * @statusCode 400: formato de fecha inválido (debe ser YYYYMMDD) o rating inválido (debe estar entre 1 y 5)
   * @statusCode 404: no se encontraron reseñas para este guía
   * @statusCode 500: error interno del servidor
   **/
  public function getReviewsByGuide(Request $request, Response $response, $args){
    $userID = intval($args['UserID']);
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    # Validar formato YYYYMMDD
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

  /**
   * Obtiene todas las reseñas realizadas por un buscador específico (cliente)
   * Permite filtrado por rango de fechas, calificación y límite de resultados
   * @param Request $request: objeto de la petición HTTP entrante con query params opcionales (from, to, rating, limit)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'userID' del buscador
   * @return Response: JSON con lista de reseñas del buscador o error
   * @statusCode 20/0: éxito - lista de reseñas del buscador
   * @statusCode 400: formato de fecha inválido (debe ser YYYYMMDD) o rating inválido (debe estar entre 1 y 5)
   * @statusCode 404: no se encontraron reseñas para este buscador
   * @statusCode 500: error interno del servidor
   **/
  public function getReviewsBySeeker(Request $request, Response $response, $args) {
    $userID = intval($args['UserID']);
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    # Validar formato YYYYMMDD
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

  /**
   * Obtiene todas las reseñas asociadas a un usuario (como guía y como buscador)
   * Permite filtrado por rango de fechas, calificación y límite de resultados
   * @param Request $request: objeto de la petición HTTP entrante con query params opcionales (from, to, rating, limit)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'userID' del usuario
   * @return Response: JSON con lista de reseñas del usuario o error
   * @statusCode 200: éxito - lista de reseñas del usuario
   * @statusCode 400: formato de fecha inválido (debe ser YYYYMMDD) o rating inválido (debe estar entre 1 y 5)
   * @statusCode 404: no se encontraron reseñas para este usuario
   * @statusCode 500: error interno del servidor
   **/
  public function getReviewsByUser(Request $request, Response $response, $args){
    $userID = intval($args['UserID']);
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    # Validar formato YYYYMMDD
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

  /**
   * Obtiene los datos de una reseña específica por su ID
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'reviewID'
   * @return Response: JSON con datos de la reseña o error
   * @statusCode 200: éxito - reseña encontrada
   * @statusCode 404: reseña no encontrada
   * @statusCode 500: error interno del servidor
   **/
  public function getReviewsByID(Request $request, Response $response, $args) {
    $reviewID = intval($args['ReviewID']);

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

  /**
   * Obtiene todas las reseñas de un servicio (offering) específico
   * Permite filtrado por rango de fechas, calificación y límite de resultados
   * @param Request $request: objeto de la petición HTTP entrante con query params opcionales (from, to, rating, limit)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de ruta, debe incluir 'offeringID'
   * @return Response: JSON con lista de reseñas del offering o error
   * @statusCode 200: éxito - lista de reseñas del offering
   * @statusCode 400: formato de fecha inválido (debe ser YYYYMMDD) o rating inválido (debe estar entre 1 y 5)
   * @statusCode 404: no se encontraron reseñas para este offering
   * @statusCode 500: error interno del servidor
   **/
  public function getReviewsByOffering(Request $request, Response $response, $args) {
    $offeringID = intval($args['OfferingID']);
    $queryParams = $request->getQueryParams();

    $from = $queryParams['from'] ?? null;
    $to = $queryParams['to'] ?? null;
    $rating = $queryParams['rating'] ?? null;
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

    # Validar formato YYYYMMDD
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

  /**
   * Valida si un texto contiene contenido inapropiado usando Perspective API
   * Método privado auxiliar para filtrado de contenido
   * @param string $text: texto a validar
   * @return bool: true si contiene contenido inapropiado, false en caso contrario
   **/
  private function _containsInappropriateContent($text) {
    return validateContentWithPerspective($text);
  }

  /**
   * Genera un ID público único para una reserva
   * Formato: {COUNTRYCODE}-{YYMMDD}-{TYPE}-{HASH}
   * Ejemplo: AR-2412-B-A1B2C3D4
   * @param string $countryCode: código del país (ej: AR, US, MX)
   * @param string $type: tipo de entidad (ej: B para booking)
   * @return string: ID público único en formato estandarizado
   **/
  private function _generatePublicId ($countryCode, $type) {
    $dateCode = date('ym'); # AñoMes
    $random = substr(bin2hex(random_bytes(5)), 0, 8); # Hash corto
    return strtoupper("{$countryCode}-{$dateCode}-{$type}-{$random}");
  }
}