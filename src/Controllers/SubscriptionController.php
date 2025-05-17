<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Subscription;
use Firebase\JWT\JWT;

class SubscriptionController {
  protected $subscription;

  public function __construct(Subscription $subscription)  {
    $this->subscription = $subscription;
  }

  public function getSubscriptionPlans(Request $request, Response $response) {
    try {
      $plans = $this->subscription->getSubscriptionPlans();

      if (empty($plans)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_PLANS_FOUND",
            "desc" => "No subscription plans available"
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($plans);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getSubscriptionPlanByID(Request $request, Response $response, array $args) {
    $id = $args['id'];

    try {
      $subscription = $this->subscription->getSubscriptionPlanByID($id);

      if (!$subscription) {
        return $response->withStatus(404)->withJson((object)["error" => [
          "code" => "SUBSCRIPTION_NOT_FOUND",
          "desc" => "Plan not found with the ID {$id}."
        ]]);
      }

      return $response->withStatus(200)->withJson($subscription);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getSubscriptionByUser(Request $request, Response $response, array $args) {
    $userID = $args['userID'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      # Verificar si el usuario autenticado es un administrador
      if ($jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      $subscription = $this->subscription->getSubscriptionByUser($userID);

      if (!$subscription) {
        return $response->withStatus(404)->withJson((object)["error" => [
          "code" => "SUBSCRIPTION_NOT_FOUND",
          "desc" => "No active subscription found for UserID {$userID}."
        ]]);
      }

      return $response->withStatus(200)->withJson($subscription);

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateSubscriptionPlan(Request $request, Response $response, array $args) {
    $id = $args['id'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      # Verificar si el usuario autenticado es un administrador
      if ($jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      $result = $this->subscription->updateSubscriptionPlan($id, $data);

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

  public function updateSubscriptionByUser(Request $request, Response $response, array $args)
  {
    $userID = $args['userID'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
  
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }
  
    try {
      if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }
  
      $newPlanID = $data['PlanID'] ?? null;

      // Actualizar suscripción
      $result = $this->subscription->updateSubscriptionByUser($userID, $newPlanID);
  
      // Si requiere cobro inmediato (upgrade), podrías aquí llamar la API de pago
      // if ($result['isUpgrade'] && $result['chargeAmount'] > 0) {
      //   Aquí llamarías a la API de cobro con $result['chargeAmount']
      //   // Por ejemplo:
      //   // $paymentResponse = $this->paymentService->charge($userID, $result['chargeAmount']);
  
      //   $result['payment_required'] = true;
      //   $result['message'] .= ' Payment required for upgrade.';
      // }

      return $response->withStatus(200)->withJson([
        "Subscription" => $result['Suscription'],
        "ProportionalCharge" => $result['ProportionalCharge']
      ]);
  
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

  public function updateFeatureStatus(Request $request, Response $response, array $args)
  {
    $featureCode = $args['featureCode'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      # Verificar si el usuario autenticado es un administrador o el mismo usuario
      if ($jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      if (!isset($data['IsActive']) || !in_array($data['IsActive'], [0, 1], true)) {
        return $response->withStatus(400)->withJson([
          'error' => [
            'code' => 'INVALID_INPUT',
            'desc' => 'IsActive must be 0 (inactive) or 1 (active).'
          ]
        ]);
      }

      $result = $this->subscription->updateFeatureStatus($featureCode, $data['IsActive']);

      return $response->withJson([
        'success' => true,
        'message' => $result
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

  public function getPriceInfo(Request $request, Response $response, array $args)
  {
    $jwt = $request->getAttribute('jwt');
    $targetPlanID = $args['planID'];
  
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }
  
    try {
      $userID = $jwt['data']->UserID;
      $result = $this->subscription->getPriceInfo($userID, $targetPlanID);
  
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
}