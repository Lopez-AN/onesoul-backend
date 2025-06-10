<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\StripeService;
use App\Models\User;
use Firebase\JWT\JWT;

class StripeController{

  protected $stripe;
  protected $user;

  public function __construct(StripeService $stripe, User $user){
    $this->stripe = $stripe;
    $this->user = $user;
  }

  public function createCheckoutSession(Request $request, Response $response, $args)
  {
    $data = $request->getParsedBody();
    $priceId = $data['priceId'] ?? null;
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

      $result = $this->stripe->createCheckoutSession($priceId, $userEmail);
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

      // Lógica según el tipo de evento
      switch ($event->type) {
        case 'checkout.session.completed':
          $session = $event->data->object;
          // Aquí podés guardar la sesión en la base de datos
          error_log("Checkout session completed: " . $session->id);
          break;

        case 'invoice.paid':
          $invoice = $event->data->object;
          // Registrar pago exitoso de suscripción
          error_log("Invoice paid for subscription: " . $invoice->subscription);
          break;

        case 'customer.subscription.deleted':
          $subscription = $event->data->object;
          // Cancelar o marcar como terminada la suscripción del usuario
          error_log("Subscription deleted: " . $subscription->id);
          break;

        default:
          error_log("Unhandled event type: " . $event->type);
      }

      return $response->withStatus(200);
    } catch (\UnexpectedValueException $e) {
      // Error de decodificación del payload
      return $response->withStatus(400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      // Firma inválida
      return $response->withStatus(400);
    }
  }
}