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

  /**
   * Obtiene todos los planes de suscripción disponibles
   * @param  Request $request: objeto de solicitud HTTP
   * @param  Response $response: objeto de respuesta HTTP
   * @return Response: JSON con listado de planes o error
   * @statusCode 200: planes obtenidos exitosamente
   * @statusCode 404: no hay planes disponibles
   * @statusCode 500: error del servidor
   */
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

  /**
   * Obtiene un plan de suscripción por ID
   * @param  Request $request: objeto de solicitud HTTP
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con datos del plan o error
   * @statusCode 200: plan obtenido exitosamente
   * @statusCode 404: plan no encontrado
   * @statusCode 500: error del servidor
   */
  public function getSubscriptionPlanByID(Request $request, Response $response, $args) {
    $id = intval($args['id']);

    try {
      $subscription = $this->subscription->getSubscriptionPlanByID($id);
      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_PLAN_NOT_FOUND",
            "desc" => "No subscription plan was found with the provided ID"
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

  /**
   * Obtiene un plan de suscripción por Stripe ID
   * @param  Request $request: objeto de solicitud HTTP
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (stripeID)
   * @return Response: JSON con datos del plan o error
   * @statusCode 200: plan obtenido exitosamente
   * @statusCode 404: plan no encontrado
   * @statusCode 500: error del servidor
   */
  public function getSubscriptionPlanByStripeID(Request $request, Response $response, $args) {
    $stripeID = $args['stripeID'];

    try {
      $subscription = $this->subscription->getSubscriptionPlanByStripeID($stripeID);
      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_PLAN_NOT_FOUND",
            "desc" => "No subscription plan was found with the provided Stripe ID"
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

  /**
   * Obtiene la suscripción activa de un usuario (requiere autenticación)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT)
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (userID)
   * @return Response: JSON con datos de suscripción o error
   * @statusCode 200: suscripción obtenida exitosamente
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos para acceder a este usuario
   * @statusCode 404: usuario sin suscripción activa
   * @statusCode 500: error del servidor
   */
  public function getSubscriptionByUser(Request $request, Response $response, $args) {
    $userID = intval($args['userID']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo o un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user"
        ]
      ]);
    }

    try {
      $subscription = $this->subscription->getSubscriptionByUser($userID);
      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_NOT_FOUND",
            "desc" => "No active subscription found for UserID."
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

  /**
   * Obtiene suscripción activa por ID de plataforma (requiere autenticación)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT)
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (subId)
   * @return Response: JSON con datos de suscripción o error
   * @statusCode 200: suscripción obtenida exitosamente
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos para acceder a este usuario
   * @statusCode 404: suscripción no encontrada
   * @statusCode 500: error del servidor
   */
  public function getUserSubscriptionByPlatformSubID(Request $request, Response $response, $args) {
    $platformSubscriptionID = $args['subId'];
    $jwt = $request->getAttribute('jwt');

    try {
      $subscription = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_NOT_FOUND",
            "desc" => "No active subscription found for the provided SubID."
          ]
        ]);
      }
      $userID = $subscription['UserID'];

      # Verificar si el usuario autenticado es un administrador
      if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
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

  /**
   * Crea sesión de checkout para nueva suscripción (requiere autenticación)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT, body JSON)
   * @param  Response $response: objeto de respuesta HTTP
   * @return Response: JSON con URL de checkout o error
   * @statusCode 200: sesión creada exitosamente
   * @statusCode 400: parámetros inválidos o usuario ya tiene suscripción
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos para modificar este usuario
   * @statusCode 500: error del servidor
   */
  public function updateSubscriptionByUser(Request $request, Response $response,$args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data -> UserID;

    $trialFlag = filter_var($trial, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $trialDays = ($trialFlag === true) ? 90 : 0;

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $subDomain = $data['SubDomain'] ?? '';
    $trial = $data['Trial'] ?? false;
    $newPlanID = $data['PlanID'] ?? null;

    if(is_null($newPlanID)){
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

    try {
      # Obtener el email del usuario
      $user = $this->user->getUserById($userID);
      if (empty($user['Email'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "USER_EMAIL_NOT_FOUND",
            "desc" => "Could not retrieve user email"
          ]
        ]);
      }

      # Obtener el ID de Stripe desde el plan
      $plan = $this->subscription->getSubscriptionPlanByID($newPlanID);
      if (!$plan || empty($plan['StripeID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "STRIPE_PLAN_MISSING",
            "desc" => "Stripe ID not configured for this plan"
          ]
        ]);
      }

      $subscription = $this->subscription->getSubscriptionByUser($userID);
      if ($subscription) {
        return $response->withStatus(400)->withJson((object)[
          "error" => [
            "code" => "SUBSCRIPTION_ALREADY_EXISTS",
            "desc" => "User already has an active or trialing subscription."
          ]
        ]);
      }

      # Crear sesión de checkout
      $stripePriceId = $plan['StripeID'];
      $userEmail = $user['Email'];

      $firstName = $user['FirstName'] ?? '';
      $lastName  = $user['LastName'] ?? '';

      $userInfo = [
        'name' => trim(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')),
      ];

      $checkout = $this->stripe->createCheckoutSession($stripePriceId, $userEmail, $userID, $newPlanID, $subDomain, $trialDays, $userInfo);

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

  /**
   * Actualiza un plan de suscripción (requiere permisos de admin)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT admin, body JSON)
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con resultado de operación
   * @statusCode 200: plan actualizado exitosamente
   * @statusCode 400: JSON inválido o parámetros faltantes
   * @statusCode 403: sin permisos de administrador
   * @statusCode 500: error del servidor
   */
  public function updateSubscriptionPlan(Request $request, Response $response, $args) {
    $id = intval($args['id']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user"
        ]
      ]);
    }

    try {
      $plan = $this->subscription->updateSubscriptionPlan($id, $data);
      return $response->withStatus(200)->withJson($plan);
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
   * Activa o desactiva una característica de suscripción (requiere permisos de admin)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT admin, body JSON)
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (featureCode)
   * @return Response: JSON con estado de característica o error
   * @statusCode 200: estado actualizado exitosamente
   * @statusCode 400: JSON inválido o parámetros inválidos
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos de administrador
   * @statusCode 404: característica no encontrada
   * @statusCode 500: error del servidor
   */
  public function updateFeatureStatus(Request $request, Response $response, $args) {
    $featureCode = $args['featureCode'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es un administrador o el mismo usuario
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
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

    try {
      $feature = $this->subscription->getFeatureStatus($featureCode);
      return $response->withStatus(404)->withJson([
        'error' => [
          'code' => 'FEATURE_NOT_FOUND',
          'desc' => 'No feature was found with this code.'
        ]
      ]);

      $feature = $this->subscription->updateFeatureStatus($featureCode, $data['IsActive']);
      return $response->withJson($feature);
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
   * Obtiene historial de pagos de un usuario (requiere autenticación)
   * @param  Request $request: objeto de solicitud HTTP (requiere JWT)
   * @param  Response $response: objeto de respuesta HTTP
   * @param  array $args: argumentos de ruta (userID)
   * @return Response: JSON con historial de pagos o error
   * @statusCode 200: pagos obtenidos exitosamente
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos para acceder a este usuario
   * @statusCode 404: usuario sin suscripción o sin pagos registrados
   * @statusCode 500: error del servidor
   */
  public function getPaymentsByUser(Request $request, Response $response, $args) {
    $userID = intval($args['userID']);
    $paginator = paginator($request);
    $jwt = $request->getAttribute('jwt');

    # Validar si el user es el cliente o el guía
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to view payments or invoices of this user."
        ]
      ]);
    }

    try {
      $subscription = $this->subscription->getSubscriptionByUser($userID);
      if (!$subscription) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_NOT_FOUND",
            "desc" => "No active subscription found for the provided user."
          ]
        ]);
      }
      $customerID = $subscription['PlatformCustomerID'];

      $payments = $this->subscription->getPaymentsByUser($customerID, $paginator);
      if ($payments) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "PAYMENTS_NOT_FOUND",
            "desc" => "No payments or invoices found for this specific user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($payments);
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