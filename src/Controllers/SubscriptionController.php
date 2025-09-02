<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Auth;
use App\Models\StripeService;
use App\Utils\EmailHelper;
use Firebase\JWT\JWT;

class SubscriptionController {
  protected $subscription;
  protected $user;
  protected $auth;
  protected $stripe;

  public function __construct(Subscription $subscription, User $user, Auth $auth, StripeService $stripe)  {
    $this->subscription = $subscription;
    $this->user = $user;
    $this->auth = $auth;
    $this->stripe = $stripe;
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

  public function getSubscriptionPlanByID(Request $request, Response $response, $args) {
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

  public function getSubscriptionPlanByStripeID(Request $request, Response $response, $args) {
    $stripeID = $args['stripeID'];

    try {
      $subscription = $this->subscription->getSubscriptionPlanByStripeID($stripeID);

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

  public function getSubscriptionByUser(Request $request, Response $response, $args) {
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
      # Verificar si el usuario autenticado es el mismo o un administrador
      if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
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

  public function getUserSubscriptionByPlatformSubID(Request $request, Response $response, $args) {
    $platformSubscriptionID = $args['subId'];
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
      $subscription = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);

      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_NOT_FOUND",
            "desc" => "No active subscription found for this ID: {$platformSubscriptionID}."
          ]
        ]);
      }

      $userID = $subscription['UserID'];

      # Verificar si el usuario autenticado es un administrador
      if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
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

  public function updateSubscriptionByUser(Request $request, Response $response,$args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data'] -> UserID;
    $subDomain = $data['SubDomain'] ?? '';

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
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

      // Obtener el email del usuario
      $userResult = $this->user->getUserById($userID);
      if ($userResult->http_code !== 200 || empty($userResult->data['Email'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Could not retrieve user email"
          ]
        ]);
      }

      // Obtener el ID de Stripe desde el plan
      $plan = $this->subscription->getSubscriptionPlanByID($newPlanID);
      if (!$plan || empty($plan['StripeID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "STRIPE_PLAN_MISSING",
            "desc" => "Stripe ID not configured for this plan"
          ]
        ]);
      }

      // Crear sesión de checkout
      $stripePriceId = $plan['StripeID'];
      $userEmail = $userResult->data['Email'];
      $checkout = $this->stripe->createCheckoutSession($stripePriceId, $userEmail, $userID, $newPlanID, $subDomain);

      if (isset($checkout['error'])) {
        return $response->withStatus(400)->withJson([
          "error" => $checkout['error']
        ]);
      }

      return $response->withStatus(200)->withJson([
        "payment_required" => true,
        "checkout_url" => $checkout['url'],
        "session_id" => $checkout['sessionId']
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

  public function updateSubscriptionPlan(Request $request, Response $response, $args) {
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

  public function updateFeatureStatus(Request $request, Response $response, $args) {
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

  public function getPriceInfo(Request $request, Response $response, $args) {
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