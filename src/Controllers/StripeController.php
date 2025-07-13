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

          // Validar que tenga metadata
          if (!isset($session->metadata->user_id) || !isset($session->metadata->plan_id)) {
            error_log("Session missing metadata");
            return $response->withStatus(400);
          }

          $userID = $session->metadata->user_id;
          $planID = $session->metadata->plan_id;
          $stripeSubscriptionID = $session->subscription ?? null;

          if (!$stripeSubscriptionID) {
            error_log("Stripe subscription ID missing");
            return $response->withStatus(400);
          }

          // Crear suscripción directamente en la base de datos
          $subscription = $this->subscription->createConfirmedSubscription($userID, $planID, $stripeSubscriptionID);

          error_log("✅ Subscription created for UserID: $userID | PlanID: $planID | StripeID: $stripeSubscriptionID");
          break;

        case 'invoice.paid':
          $invoice = $event->data->object;
          error_log("Invoice paid for subscription: " . $invoice->subscription);
          break;

        case 'customer.subscription.deleted':
          $subscription = $event->data->object;
          error_log("Subscription deleted: " . $subscription->id);

          // Cancelar suscripción en la base de datos
          $subscription = $this->subscription->cancelSubscription($stripeSubscriptionID);

          error_log("✅ Subscription canceled.");
          break;

        default:
          error_log("Unhandled event type: " . $event->type);
      }

      return $response->withStatus(200);

    } catch (\UnexpectedValueException $e) {
      return $response->withStatus(400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      return $response->withStatus(400);
    } catch (\Throwable $e) {
      error_log("Webhook error: " . $e->getMessage());
      return $response->withStatus(500);
    }
  }
}