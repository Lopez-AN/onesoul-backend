<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\StripeService;
use App\Models\User;
use App\Models\Subscription;
use Firebase\JWT\JWT;

class StripeController{

  protected $stripe;
  protected $user;
  protected $subscription;

  public function __construct(StripeService $stripe, User $user, Subscription $subscription){
    $this->stripe = $stripe;
    $this->user = $user;
    $this->subscription = $subscription;
  }

  public function createCheckoutSession(Request $request, Response $response, $args)
  {
    $data = $request->getParsedBody();
    $priceId = $data['priceId'] ?? null;
    $subDomain = $data['SubDomain'] ?? '';
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;
    $result = $this->user->getUserById($userID);

    if ($result->http_code !== 200 || empty($result->data['Email'])) {
      return [
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "Could not retrieve an email for the user."
        ]
      ];
    }

    $userEmail = $result->data['Email'];

    // Validación
    if (empty($priceId) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
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
      # Verificar si el usuario autenticado es un guía
      if ($jwt['data']->UserType != 'Guide') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You are not authorized to subscribe."
          ]
        ]);
      }

      $plan = $this->subscription->getSubscriptionPlanByStripeID($priceId); // Debés tener esta función
      if (!$plan || empty($plan['PlanID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "PLAN_NOT_FOUND",
            "desc" => "No matching plan found for the given Stripe Price ID."
          ]
        ]);
      }
      $planID = $plan['PlanID'];

      $result = $this->stripe->createCheckoutSession($priceId, $userEmail, $userID, $planID, $subDomain);
      if (isset($result['error'])) {
        return $response->withStatus(400)->withJson(["error" => $result['error']]);
      }

      return $response->withJson([
        "sessionId" => $result['sessionId'],
        "url" => $result['url']
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function handleWebhook(Request $request, Response $response, $args)
  {
    $payload = $request->getBody()->getContents();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $webhookSecret = $GLOBALS['config']['stripe']['STRIPE_WEBHOOK_SECRET'];

    try {
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
      $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhookSecret);

      switch ($event->type) {
        case 'checkout.session.completed':
          $session = $event->data->object;

          if (!isset($session->metadata->userID) || !isset($session->metadata->planID)) {
            error_log("❌ Metadata faltante en session.");
            return $response->withStatus(400);
          }

          $userID = (int) $session->metadata->userID;
          $planID = (int) $session->metadata->planID;
          $subDomain = $session->metadata->subdomain ?? '';
          $stripeSubscriptionID = $session->subscription ?? null;

          if (!$stripeSubscriptionID) {
            error_log("❌ Falta subscription ID de Stripe.");
            return $response->withStatus(400);
          }

          // Obtener datos del usuario
          $userResult = $this->user->getUserById($userID);
          $userData = [];

          if ($userResult->http_code === 200 && !empty($userResult->data['UserName']) && !empty($userResult->data['Email'])) {
            $userData = [
              'UserName' => $userResult->data['UserName'],
              'Email' => $userResult->data['Email']
            ];
          } else {
            error_log("❌ No se pudo obtener usuario con ID $userID");
          }

          try {
            $this->subscription->createConfirmedSubscription($userID, $planID, $stripeSubscriptionID, $subDomain, $userData);
            error_log("✅ Subscription creada para UserID: $userID | PlanID: $planID | StripeID: $stripeSubscriptionID | SubDomain: $subDomain");
          } catch (\Throwable $e) {
            error_log("❌ Error al crear la suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
          break;

        case 'invoice.paid':
          $invoice = $event->data->object;
          error_log("ℹ️ Invoice paid para subscription: " . $invoice->subscription);
          break;

        case 'customer.subscription.deleted':
          $subscription = $event->data->object;
          $stripeSubscriptionID = $subscription->id;

          error_log("ℹ️ Subscription eliminada en Stripe: " . $stripeSubscriptionID);

          try {
            $this->subscription->cancelSubscription($stripeSubscriptionID);
            error_log("✅ Subscription cancelada en base de datos.");
          } catch (\Throwable $e) {
            error_log("❌ Error al cancelar suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
          break;

        default:
          error_log("⚠️ Evento no manejado: " . $event->type);
      }

      return $response->withStatus(200);

    } catch (\UnexpectedValueException $e) {
      error_log("❌ Payload inválido: " . $e->getMessage());
      return $response->withStatus(400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      error_log("❌ Firma inválida del webhook: " . $e->getMessage());
      return $response->withStatus(400);
    } catch (\Throwable $e) {
      error_log("❌ Error general en webhook: " . $e->getMessage());
      return $response->withStatus(500);
    }
  }

  public function getStripeSession(Request $request, Response $response, $args)
  {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID ?? null;

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $sessionID = $args['sessionID'] ?? null;
    if (!$sessionID) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $sessionData = $this->stripe->getStripeSession($sessionID);

      if (!$sessionData) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SESSION_NOT_FOUND",
            "desc"=> "No Stripe Session found for this specific ID."
          ]
        ]);
      }

      // Validación: que la sesión corresponda al usuario logueado
      $userIdFromSession = $sessionData['metadata']['userID'] ?? null;
      if ($userIdFromSession && $userIdFromSession != $userID) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "INVALID_STRIPE_SESSION",
            "desc" => "Cannot validate the checkout session"
          ]
        ]);
      }

      return $response->withStatus(200)->withJson([
        "Message" => "Session retrieved successfully",
        "Data" => $sessionData
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
}