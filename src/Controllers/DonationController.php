<?php

namespace App\Controllers;

use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Donation;
use App\Models\Offering;

require_once(ROOT . '/src/Utils/Paginator.php');

class DonationController {
  protected $donation;
  protected $offering;

  public function __construct(Donation $donation, Offering $offering) {
    $this->donation = $donation;
    $this->offering = $offering;
  }

  /**
   * Obtiene todas las donaciones de un guía
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'userID')
   * @return Response: JSON con array paginado de donaciones
   * @statusCode 200: éxito
   * @statusCode 403: usuario no autorizado para ver donaciones ajenas
   * @statusCode 500: error del servidor
   **/
  public function getDonations(Request $request, Response $response, $args) {
    $userID = intval($args['userID']);
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access other guide donations"
        ]
      ]);
    }

    try {
      $donations = $this->donation->getDonations($userID, $paginator);
      return $response->withStatus(200)->withJson($donations);
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
   * Obtiene las donaciones mensuales activas (no canceladas) de un guía
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'userID')
   * @return Response: JSON con array paginado de donaciones mensuales activas
   * @statusCode 200: éxito
   * @statusCode 403: usuario no autorizado
   * @statusCode 500: error del servidor
   **/
  public function getMontlyDonations(Request $request, Response $response, $args) {
    $userID = intval($args['userID']);
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo que el que consulta o un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access other guide donations"
        ]
      ]);
    }

    try {
      $donations = $this->donation->getMontlyDonations($userID, $paginator);
      return $response->withStatus(200)->withJson($donations);
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
   * Obtiene una donación específica por ID de voucher
   * El usuario solo puede ver sus propias donaciones (a menos que sea admin)
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'voucherID')
   * @return Response: JSON con datos de la donación
   * @statusCode 200: éxito
   * @statusCode 403: donación no pertenece al usuario
   * @statusCode 404: voucher no encontrado
   * @statusCode 500: error del servidor
   **/
  public function getDonationById(Request $request, Response $response, $args) {
    $voucherID = intval($args['voucherID']);
    $jwt = $request->getAttribute('jwt');

    $userID = $jwt->data->UserID;
    $isAdmin = $jwt->data->IsAdmin;

    try {
      $donation = $this->donation->getDonationById($voucherID);
      if (!$donation) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DONATION_NOT_FOUND",
            "desc" => "The donation is not found"
          ]
        ]);
      }
      if ($donation['GuideID'] !== $userID && !$isAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "This donation does not belong to you"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($donation);
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
   * Valida y obtiene una donación usando su RedeemCode
   * Verifica estado: asignado, expirado, canjeado, cancelado
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'redeemCode')
   * @return Response: JSON con datos de la donación o error específico
   * @statusCode 200: cupón válido y asignado
   * @statusCode 404: cupón no encontrado
   * @statusCode 409: cupón no asignado aún
   * @statusCode 410: cupón expirado, canjeado o cancelado
   * @statusCode 500: error del servidor
   **/
  public function validateCoupon(Request $request, Response $response, $args) {
    $redeemCode = $args['redeemCode'];

    try {
      $donation = $this->donation->getDonationByRedeemCode($redeemCode);
      if (!$donation) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "COUPON_NOT_FOUND",
            "desc" => "The coupon does not exist"
          ]
        ]);
      }
      if ($donation['Status'] === 'Redeemed'){
        return $response->withStatus(410)->withJson([
          "error" => [
            "code" => "COUPON_ALREADY_REDEEMED",
            "desc" => "The coupon has already been redeemed"
          ]
        ]);
      }

      if ($donation['Status'] === 'Expired' ||
          ($donation['ExpiredAt'] && strtotime($donation['ExpiredAt']) < time())) {
        return $response->withStatus(410)->withJson([
          "error" => [
            "code" => "COUPON_EXPIRED",
            "desc" => "The coupon has expired"
          ]
        ]);
      }
      if ($donation['Status'] === 'Canceled'){
        return $response->withStatus(410)->withJson([
          "error" => [
            "code" => "COUPON_CANCELED",
            "desc" => "The coupon has been canceled"
          ]
        ]);
      }
      if ($donation['Status'] !== 'Assigned'){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "COUPON_NOT_ASSIGNED",
            "desc" => "The coupon has not been assigned to an agency yet"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($donation);
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
   * Crea una o varias donaciones (cupones) para un servicio
   * Solo guías pueden crear donaciones. Respeta límite mensual de cupones activos
   * @param Request $request: objeto de la petición HTTP (body: OfferingID, Quantity)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta
   * @return Response: JSON con resultado
   * @statusCode 200: donaciones creadas exitosamente
   * @statusCode 400: parámetros inválidos o JSON mal formado
   * @statusCode 403: usuario no es guía
   * @statusCode 404: offering no encontrado
   * @statusCode 406: límite mensual de donaciones excedido
   * @statusCode 409: offering no está activo
   * @statusCode 500: error del servidor
   **/
  public function createDonation(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);
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

    $offeringID = $data['OfferingID'] ?? null;
    $quantity = $data['Quantity'] ?? null;
    if (empty($offeringID) || empty($quantity)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    # Solo los guias pueden crear donaciones
    if ($jwt->data->UserType !== 'Guide') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create a donation"
        ]
      ]);
    }

    # Ahora busco si no supero el límite de donaciones
    try {
      $result = $this->donation->getMontlyDonations($userID, $paginator);
      if(count($result->data) + $quantity > $GLOBALS['config']['donations']['max_montly_donations']){
        return $response->withStatus(406)->withJson([
          "error" => [
            "code" => "MONTLY_DONATIONS_EXCEDED",
            "desc" => "Monthly active donations limit reached"
          ]
        ]);
      }

      # Compruebo que el offering exista
      $offering = $this->offering->getOfferingById($offeringID);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "No Offering found for this specific ID."
          ]
        ]);
      }
      if($offering['UserID'] !== $userID){
        return $response->withStatus(403)->WithJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "The offering does not belong to you"
          ]
        ]);
      }
      if($offering['Status'] !== 'Active'){
        return $response->withStatus(409)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_ACTIVE",
            "desc" => "The offering must be active"
          ]
        ]);
      }
      $this->donation->createDonation($userID, $offeringID, $quantity);
      return $response->withJson("Donation added");
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
   * Asigna una donación a una agencia
   * Solo administradores pueden asignar donaciones
   * @param Request $request: objeto de la petición HTTP (body: VoucherID, AgencyID)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta
   * @return Response: JSON con datos de la donación actualizada
   * @statusCode 200: asignación exitosa
   * @statusCode 400: parámetros inválidos o JSON mal formado
   * @statusCode 403: usuario no es administrador
   * @statusCode 404: agencia o voucher no encontrado
   * @statusCode 500: error del servidor
   **/
  public function assignDonation(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access agencies"
        ]
      ]);
    }

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $voucherID = $data['VoucherID'] ?? null;
    $agencyID = $data['AgencyID'] ?? null;
    if (!is_int($voucherID) || !is_int($agencyID)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    try {
      $agency = $this->donation->getAgencyById($agencyID);
      if (!$agency) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "AGENCY_NOT_FOUND",
            "desc"=> "No agency found for this specific ID."
          ]
        ]);
      }

      $donation = $this->donation->getDonationById($voucherID);
      if (!$donation) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DONATION_NOT_FOUND",
            "desc" => "The donation is not found"
          ]
        ]);
      }

      $this->donation->assignDonation($agencyID, $voucherID);
      $donation = $this->donation->getDonationById($voucherID);
      return $response->withStatus(200)->withJson($donation);
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
   * Cancela una donación en estado 'Pending'
   * Solo el propietario de la donación o administrador pueden cancelarla
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'voucherID')
   * @return Response: JSON con resultado
   * @statusCode 200: donación cancelada exitosamente
   * @statusCode 403: usuario no autorizado o no es guía
   * @statusCode 404: voucher no encontrado
   * @statusCode 409: donación no está en estado 'Pending'
   * @statusCode 500: error del servidor
   **/
  public function cancelDonation(Request $request, Response $response, $args) {
    $voucherID = intval($args['voucherID']);

    $jwt = $request->getAttribute('jwt');
    # Solo los guias pueden crear donaciones
    if ($jwt->data->UserType !== 'Guide') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to cancel a donation"
        ]
      ]);
    }
    $userID = $jwt->data->UserID;
    $isAdmin = $jwt->data->IsAdmin;

    # Ahora busco si no supero el límite de donaciones
    try {
      $donation = $this->donation->getDonationById($voucherID);
      if (!$donation) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DONATION_NOT_FOUND",
            "desc" => "The donation is not found"
          ]
        ]);
      }
      if ($donation['GuideID'] !== $userID && !$isAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "This donation does not belong to you"
          ]
        ]);
      }
      if ($donation['Status'] !== 'Pending') {
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DONATION_NOT_CANCELABLE",
            "desc" => "Only pending donations can be canceled"
          ]
        ]);
      }

      $this->donation->cancelDonation($voucherID);
      return $response->withJson("Donation canceled");
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
   * Sortea (raffle) un número aleatorio de cupones en estado 'Assigned'
   * Solo administradores pueden hacer sorteos
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'quantity': 1-1000)
   * @return Response: JSON con array de cupones sorteados
   * @statusCode 200: sorteo realizado
   * @statusCode 400: cantidad fuera de rango (1-1000)
   * @statusCode 403: usuario no es administrador
   * @statusCode 500: error del servidor
   **/
  public function raffleCoupons(Request $request, Response $response, $args) {
    $quantity = intval($args['quantity']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to raffle donations"
        ]
      ]);
    }

    if($quantity < 1 || $quantity > 1000){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RAFFLE_QUANTITY",
          "desc" => "Quantity must be in 1-1000 range"
        ]
      ]);
    }

    try{
      $result = $this->donation->raffleCoupons($quantity);
      return $response->withJson($result);
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
   * Obtiene lista paginada de todas las agencias
   * Solo administradores pueden acceder
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta
   * @return Response: JSON con array paginado de agencias
   * @statusCode 200: éxito
   * @statusCode 403: usuario no es administrador
   * @statusCode 500: error del servidor
   **/
  public function getAgencies(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access agencies"
        ]
      ]);
    }

    try {
      $donations = $this->donation->getAgencies($paginator);
      return $response->withStatus(200)->withJson($donations);
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
   * Obtiene una agencia específica por ID
   * Solo administradores pueden acceder
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'agencyID')
   * @return Response: JSON con datos de la agencia
   * @statusCode 200: éxito
   * @statusCode 403: usuario no es administrador
   * @statusCode 404: agencia no encontrada
   * @statusCode 500: error del servidor
   **/
  public function getAgencyById(Request $request, Response $response, $args) {
    $agencyID = intval($args['agencyID']);
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access agencies"
        ]
      ]);
    }

    try {
      $agency = $this->donation->getAgencyById($agencyID);
      if (!$agency) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "AGENCY_NOT_FOUND",
            "desc"=> "No agency found for this specific ID."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($agency);
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
   * Crea una nueva agencia
   * Solo administradores pueden crear agencias
   * @param Request $request: objeto de la petición HTTP (body: Name, ContactEmail)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta
   * @return Response: JSON con resultado
   * @statusCode 200: agencia creada exitosamente
   * @statusCode 400: parámetros inválidos o JSON mal formado
   * @statusCode 403: usuario no es administrador
   * @statusCode 500: error del servidor
   **/
  public function createAgency(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to access agencies"
        ]
      ]);
    }

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $name = $data['Name'] ?? null;
    $contactEmail = $data['ContactEmail'] ?? null;
    if(empty($name) || empty($contactEmail)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    try {
      $this->donation->createAgency($name, $contactEmail);
      return $response->withJson("Agency added");
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
   * Elimina una agencia existente
   * Solo administradores pueden eliminar agencias
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta (parámetro 'agencyID')
   * @return Response: JSON con resultado
   * @statusCode 200: agencia eliminada exitosamente
   * @statusCode 404: agencia no encontrada
   * @statusCode 500: error del servidor
   **/
  public function deleteAgency(Request $request, Response $response, $args)  {
    $agencyID = intval($args['agencyID']);
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    try {
      $agency = $this->donation->getAgencyById($agencyID);
      if (!$agency) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "AGENCY_NOT_FOUND",
            "desc"=> "No agency found for this specific ID."
          ]
        ]);
      }

      $this->donation->deleteAgency($agencyID);
      return $response->withStatus(200)->withJson("Agency deleted successfully");
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