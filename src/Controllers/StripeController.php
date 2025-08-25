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

  public function __construct(StripeService $stripe, User $user, Subscription $subscription) {
    $this->stripe = $stripe;
    $this->user = $user;
    $this->subscription = $subscription;
  }

  public function createCheckoutSession(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $priceId = $data['PriceId'] ?? null;
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

  public function upgradeInfo(Request $request, Response $response, array $args) {
    $subId = $args['subId'];
    $body = $request->getParsedBody();
    $newPriceId = $body['NewPriceId'] ?? null;

    if (!$newPriceId) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

    // Traer sub e item actual
    $sub = \Stripe\Subscription::retrieve($subId);
    if (!$sub || $sub->status === 'canceled') {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "PLAN_NOT_FOUND",
          "desc" => "No matching plan found for the given Stripe Price ID."
        ]
      ]);
    }

    $item = $sub->items->data[0]; 
    $prorationDate = time();

    // Preview del próximo invoice simulando el cambio
    $preview = \Stripe\Invoice::createPreview([
      'customer' => $sub->customer,
      'subscription' => $sub->id,
      'subscription_details' => [
        'items' => [[ 'id' => $item->id, 'price' => $newPriceId ]],
        'proration_date' => $prorationDate,
        'proration_behavior'  => 'always_invoice',
      ],
    ]);

    // Armar respuesta amigable
    $lines = array_map(function($l) {
      return [
        'Description' => $l->description,
        'Amount'      => $l->amount / 100,
        'Proration'   => $l->proration === true,
      ];
    }, $preview->lines->data);

    return $response->withJson([
      'Currency'    => strtoupper($preview->currency),
      'AmountDue'  => $preview->amount_due / 100,           // Diferencia a cobrar ahora
      'ProrationDate' => $prorationDate,                     // Guárdalo para aplicar el mismo cálculo
      'Lines'       => $lines,
      'CurrentPeriodEnd' => $sub->current_period_end
    ]);
  }

  public function upgradeApply(Request $request, Response $response, array $args) {
    $subId = $args['subId'];
    $body = $request->getParsedBody();
    $newPriceId = $body['NewPriceId'] ?? null;
    $prorationDate = $body['ProrationDate'] ?? null;

    if (!$newPriceId || !$prorationDate) {
      return $response->withStatus(400)->withJson(['error' => 'MISSING_PARAMS']);
    }

    \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

    $sub = \Stripe\Subscription::retrieve($subId);
    $itemId = $sub->items->data[0]->id;

    $updated = \Stripe\Subscription::update($subId, [
      'items' => [[ 'id' => $itemId, 'price' => $newPriceId ]],
      'proration_behavior' => 'always_invoice',   // factura la diferencia ahora
      'proration_date'     => $prorationDate,     // MISMO que el preview
      'payment_behavior'   => 'pending_if_incomplete', // si requiere SCA, queda pendiente
      'expand' => ['latest_invoice.payment_intent', 'latest_invoice.charge'],
    ]);

    $invoice = $updated->latest_invoice ?? null;
    $pi = $invoice ? $invoice->payment_intent : null;

    // Respuestas típicas:
    if ($invoice && $invoice->status === 'paid') {
      return $response->withJson(['Status' => 'paid', 'SubscriptionId' => $updated->id]);
    }

    if ($pi && $pi->status === 'requires_action') {
      // Devolvés client_secret para confirmar 3DS en el front
      return $response->withJson([
        'Status' => 'requires_action',
        'ClientSecret' => $pi->client_secret,
        'SubscriptionId' => $updated->id
      ]);
    }

    if ($pi && $pi->status === 'requires_payment_method') {
      // No hay método de pago válido. Mostrá UI para actualizar tarjeta (Billing Portal o Payment Element)
      return $response->withJson(['Status' => 'requires_payment_method']);
    }

    // Fallback: pendiente (Stripe intentará cobrar)
    return $response->withJson(['Status' => 'pending', 'SubscriptionId' => $updated->id]);
  }

  public function downgradeApply(Request $request, Response $response, array $args) {
    $subId = $args['subId'];
    $body = $request->getParsedBody();
    $newPriceId = $body['NewPriceId'] ?? null;

    if (!$newPriceId) {
      return $response->withStatus(400)->withJson(['error' => 'MISSING_PRICE']);
    }

    \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

    // 1) Traer la suscripción actual
    $sub = \Stripe\Subscription::retrieve($subId);
    if (!$sub || $sub->status === 'canceled') {
      return $response->withStatus(404)->withJson(['error' => 'SUB_NOT_FOUND_OR_CANCELED']);
    }

    // Tomamos el item/price actual (ajustar si hay múltiples)
    $currentItem   = $sub->items->data[0];
    $currentPriceId = is_string($currentItem->price) ? $currentItem->price : $currentItem->price->id;
    $quantity       = $currentItem->quantity ?? 1;
    $effectiveAt    = $sub->current_period_end; // timestamp (segundos)

    // 2) Si ya tiene un schedule, lo actualizamos; si no, lo creamos desde la sub
    if ($sub->schedule) {
      // Actualizar fases de un schedule existente
      $schedule = \Stripe\SubscriptionSchedule::retrieve($sub->schedule);
      $schedule = \Stripe\SubscriptionSchedule::update($schedule->id, [
        'phases' => [
          [
            'items' => [[ 'price' => $currentPriceId, 'quantity' => $quantity ]],
            'end_date' => $effectiveAt,
            'proration_behavior' => 'none',
          ],
          [
            'items' => [[ 'price' => $newPriceId, 'quantity' => $quantity ]],
            'proration_behavior' => 'none',
          ],
        ],
      ]);
    } else {
      // Crear un schedule nuevo que “tome” la sub actual y agregue la fase futura
      $schedule = \Stripe\SubscriptionSchedule::create([
        'from_subscription' => $sub->id,
        'phases' => [
          [
            'items' => [[ 'price' => $currentPriceId, 'quantity' => $quantity ]],
            'end_date' => $effectiveAt,
            'proration_behavior' => 'none',
          ],
          [
            'items' => [[ 'price' => $newPriceId, 'quantity' => $quantity ]],
            'proration_behavior' => 'none',
          ],
        ],
      ]);
    }

    // 3) (Opcional pero recomendado) Persistí en tu BD:
    // - status_local = 'downgrade_pending'
    // - next_plan_price_id = $newPriceId
    // - effective_at = $effectiveAt (fecha del cambio)
    // Concedé beneficios del plan actual hasta esa fecha.

    return $response->withJson([
      'status'         => 'scheduled',
      'schedule_id'    => $schedule->id,
      'effective_at'   => $effectiveAt,             
      'current_price'  => $currentPriceId,
      'next_price'     => $newPriceId,
      'subscription_id'=> $sub->id,
    ]);
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

          if (!isset($session->metadata->UserID) || !isset($session->metadata->PlanID)) {
            error_log("❌ Metadata faltante en session.");
            return $response->withStatus(400);
          }

          $userID = (int) $session->metadata->UserID;
          $planID = (int) $session->metadata->PlanID;
          $subDomain = $session->metadata->SubDomain ?? '';
          $platformSubscriptionID = $session->subscription;
          $platformCustomerID = $session->customer;

          if (!$platformSubscriptionID) {
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
            $this->subscription->createConfirmedSubscription($userID, $planID, $platformSubscriptionID, $platformCustomerID, $subDomain, $userData);
            error_log("✅ Subscription creada para UserID: $userID | PlanID: $planID | StripeID: $stripeSubscriptionID | SubDomain: $subDomain");
          } catch (\Throwable $e) {
            error_log("❌ Error al crear la suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'invoice.paid':
          $invoice = $event->data->object;
          $line = !empty($invoice->lines->data) ? $invoice->lines->data[0] : null;

          $invoiceData = [
            'InvoiceID'       => $invoice->id,
            'SubscriptionID'  => $invoice->parent->subscription_details->subscription ?? null,
            'CustomerID'      => $invoice->customer,
            'Currency'        => $invoice->currency,
            'AmountDue'       => $invoice->amount_due,
            'AmountPaid'      => $invoice->amount_paid,
            'AmountRemaining' => $invoice->amount_remaining,
            'Status'          => $invoice->status,
            'PriceID'         => $line?->pricing->price_details->price,
            'ProductID'       => $line?->pricing->price_details->product,
            'Quantity'        => $line?->quantity ?? 1,
            'PeriodStart'     => $line ? date("Y-m-d", $line->period->start) : null,
            'PeriodEnd'       => $line ? date("Y-m-d", $line->period->end) : null,
            'InvoicePDF'      => $invoice->invoice_pdf ?? null,
            'HostedInvoiceURL'=> $invoice->hosted_invoice_url ?? null,
            'CreatedAt'       => date("Y-m-d", $invoice->created),
            'PaidAt'          => $invoice->status_transitions->paid_at ? date("Y-m-d", $invoice->status_transitions->paid_at) : null,
          ];

          error_log("ℹ️ Invoice paid para subscription: " . $invoice->subscription);

          try {
            $this->subscription->createSubscriptionPayment($invoiceData);
            error_log("✅ Pago aprobado para UserID (InvoiceID: " . $invoice->id . ")");
          } catch (\Throwable $e) {
            error_log("❌ Error al registrar el pago: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'customer.subscription.updated':
          $sub = $event->data->object;

          $stripeSubscriptionID = $sub->id;
          $newPriceId = $sub->items->data[0]->price->id ?? null;

          error_log("ℹ️ Subscription actualizada en Stripe: $stripeSubscriptionID con nuevo PriceID: $newPriceId");

          try {
            // Mapear PriceID -> PlanID (según tu tabla de planes)
            $planInfo = $this->subscription->getPlanByStripePrice($newPriceId);
            if ($planInfo) {
              $this->subscription->updateUserSubscriptionPlan($stripeSubscriptionID, $planInfo['PlanID']);
              error_log("✅ Plan actualizado en BD a PlanID: " . $planInfo['PlanID']);
            } else {
              error_log("⚠️ No se encontró plan para PriceID: $newPriceId");
            }
          } catch (\Throwable $e) {
            error_log("❌ Error al actualizar suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
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