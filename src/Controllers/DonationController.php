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

  # Obtiene las donaciones de un guia
  public function getDonations(Request $request, Response $response, $args) {
    $userID = $args['userID'];
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt->data->UserID != $userID && !$jwt->data->IsAdmin) {
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

  # Obtiene las donaciones mensuales activas de un guia
  public function getMontlyDonations(Request $request, Response $response, $args) {
    $userID = $args['userID'];
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    # Verificar si el usuario autenticado es el mismo que el que consulta o un administrador
    if ($jwt->data->UserID != $userID && !$jwt->data->IsAdmin) {
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

  # Obtiene una donacion por ID (debe ser propia o ser admin)
  public function getDonationById(Request $request, Response $response, $args) {
    $voucherID = $args['voucherID'];
    $jwt = $request->getAttribute('jwt');

    $userID = $jwt->data->UserID;
    $isAdmin = $jwt->data->IsAdmin;

    try {
      $donation = $this->donation->getDonationById($voucherID, $userID, $isAdmin);
      if ($donation === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DONATION_NOT_FOUND",
            "desc" => "The donation is not found"
          ]
        ]);
      }
      if ($donation['GuideID'] != $userID && !$isAdmin) {
        return $response->withStatus(401)->withJson([
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

  # Obtiene una donacion por redeemCode
  public function validateCoupon(Request $request, Response $response, $args) {
    $redeemCode = $args['redeemCode'];

    try {
      $donation = $this->donation->getDonationByRedeemCode($redeemCode);
      if ($donation === null) {
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
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "COUPON_NOT_DRAWN",
            "desc" => "The coupon has not been drawn yet"
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

  # Crea una o varias donaciones
  public function createDonation(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

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
    if ($jwt->data->UserType != 'Guide') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create a donation"
        ]
      ]);
    }
    $userID = $jwt->data->UserID;

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
      $result = $this->offering->getOfferingById($offeringID);
      if ($result->http_code !== 200) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "No Offering found for this specific ID."
          ]
        ]);
      }
      if($result->data['UserID'] != $userID){
        return $response->withStatus(401)->WithJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "The offering does not belong to you"
          ]
        ]);
      }
      if($result->data['Status'] != 'Active'){
        return $response->withStatus(400)->WithJson([
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

  # Cancela una donacion
  public function cancelDonation(Request $request, Response $response, $args) {
    $voucherID = $args['voucherID'];

    $jwt = $request->getAttribute('jwt');
    # Solo los guias pueden crear donaciones
    if ($jwt->data->UserType != 'Guide') {
      return $response->withStatus(401)->withJson([
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
      if ($donation === null) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "DONATION_NOT_FOUND",
            "desc" => "The donation is not found"
          ]
        ]);
      }
      if ($donation['GuideID'] != $userID && !$isAdmin) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "This donation does not belong to you"
          ]
        ]);
      }
      if ($donation['Status'] != 'draft') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "DONATION_NOT_CANCELABLE",
            "desc" => "Only draft donations can be canceled"
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

  # Cancela una donacion
  public function raffleCoupons(Request $request, Response $response, $args) {
    $quantity = $args['quantity'];
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt->data->UserID != $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to view the referrals of this user."
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
}