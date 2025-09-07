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
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "Could not retrieve an email for the user."
        ]
      ]);
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

      $plan = $this->subscription->getSubscriptionPlanByStripeID($priceId);
      if (!$plan || empty($plan['PlanID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "PLAN_NOT_FOUND",
            "desc" => "No matching plan found for the given Stripe Price ID."
          ]
        ]);
      }
      $planID = $plan['PlanID'];

      $trialDays = (!empty($data['Trial']) && $data['Trial'] === true) ? 90 : 0;

      $result = $this->stripe->createCheckoutSession($priceId, $userEmail, $userID, $planID, $subDomain, $trialDays);
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
    $platformSubscriptionID = $args['subId'];
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

    # Verificar si el usuario autenticado es el mismo o un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You are not authorized to subscribe."
        ]
      ]);
    }    

    $newPlanID = $data['PlanID'] ?? null;
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

    $newPriceId = $plan['StripeID'];

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
    $sub = \Stripe\Subscription::retrieve($platformSubscriptionID, [
      'expand' => ['items.data.price.product', 'default_payment_method']
    ]);

    if (!$sub || $sub->status === 'canceled') {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "PLAN_NOT_FOUND",
          "desc" => "No matching plan found for the given Stripe Price ID."
        ]
      ]);
    }

    $paymentMethod = null;

    if (!empty($sub->default_payment_method)) {
      $paymentMethod = \Stripe\PaymentMethod::retrieve($sub->default_payment_method);
    } elseif (!empty($sub->latest_invoice) && !empty($sub->latest_invoice->payment_intent->payment_method)) {
      $paymentMethod = $sub->latest_invoice->payment_intent->payment_method;
      if (is_string($paymentMethod)) {
        $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethod);
      }
    }

    $pmInfo = null;
    if ($paymentMethod && $paymentMethod->type === 'card') {
      $pmInfo = [
        'Brand'  => $paymentMethod->card->brand,
        'Last4'  => $paymentMethod->card->last4,
        'Exp'    => $paymentMethod->card->exp_month . '/' . $paymentMethod->card->exp_year,
      ];
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
      'AmountDue'  => $preview->amount_due / 100,
      'ProrationDate' =>  $prorationDate,
      'Lines'       => $lines,
      'CurrentPeriodEnd' => $sub->items->data[0]->current_period_end ? date("Y-m-d", $sub->current_period_end) : null,
      'PaymentMethod' => $pmInfo,
    ]);
  }

  public function upgradeApply(Request $request, Response $response, array $args) {
    $platformSubscriptionID = $args['subId'];
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

    $subscription = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
    $userID = $subscription['UserID'];

    # Verificar si el usuario autenticado es el mismo o un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You are not authorized to subscribe."
        ]
      ]);
    }    

    $newPlanID = $data['PlanID'] ?? null;
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

    $newPriceId = $plan['StripeID'];
    $prorationDate = $data['ProrationDate'] ?? null;

    if (!$prorationDate) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

    $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
    $itemId = $sub->items->data[0]->id;

    $updated = \Stripe\Subscription::update($platformSubscriptionID, [
      'items' => [[ 'id' => $itemId, 'price' => $newPriceId ]],
      'proration_behavior' => 'always_invoice',   // factura la diferencia ahora
      'proration_date'     => $prorationDate,     // MISMO que el preview
      'payment_behavior'   => 'pending_if_incomplete', // si requiere SCA, queda pendiente
      'expand' => ['latest_invoice.payment_intent', 'latest_invoice.charge'],
    ]);

    $invoice = $updated->latest_invoice ?? null;
    $pi = $invoice ? $invoice->payment_intent : null;

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
    $platformSubscriptionID = $args['subId'];
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

    $subscription = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
    $userID = $subscription['UserID'];

    # Verificar si el usuario autenticado es el mismo o un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You are not authorized to subscribe."
        ]
      ]);
    }    

    $newPlanID = $data['PlanID'] ?? null;
    $plan = $this->subscription->getSubscriptionPlanByID($newPlanID);
    if (!$plan || empty($plan['StripeID'])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_PLAN_MISSING",
          "desc" => "Stripe ID not configured for this plan"
        ]
      ]);
    }

    $newPriceId = $plan['StripeID'];

    \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

    // Traer la suscripción actual
    $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
    $itemId = $sub->items->data[0]->id;

    // aplicar downgrade al final del ciclo (sin prorrateo)
    $updated = \Stripe\Subscription::update($platformSubscriptionID, [
      'items' => [[ 'id' => $itemId, 'price' => $newPriceId ]],
      'proration_behavior' => 'none',   // no factura diferencia ahora
      'billing_cycle_anchor' => 'unchanged', // se mantiene hasta el próximo ciclo
      'payment_behavior' => 'pending_if_incomplete',
    ]);

    return $response->withJson([
      'Status' => 'scheduled',
      'SubscriptionId' => $updated->id,
      'CurrentPeriodEnd' => $sub->items->data[0]->current_period_end ? date("Y-m-d", $sub->current_period_end) : null,
      'NewPrice' => $newPriceId
    ]);
  }

  public function cancelSubscription(Request $request, Response $response, array $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

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
      $subscription = $this->subscription->getSubscriptionByUser($userID);

      if (!$subscription || empty($subscription['PlatformSubscriptionID'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "NO_ACTIVE_SUBSCRIPTION",
            "desc" => "No active subscription to cancel."
          ]
        ]);
      }

      $platformSubscriptionID = $subscription['PlatformSubscriptionID'];

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      // Traer la suscripción actual
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);

      if (!empty($sub->schedule)) {
        // La suscripción está controlada por un Schedule
        \Stripe\SubscriptionSchedule::update(
          $sub->schedule,
          ['end_behavior' => 'cancel']
        );

        // Refrescar la suscripción para obtener los datos actualizados
        $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
        $canceled = $sub;

        $canceled = (object)[
          'id' => $sub->id,
          'status' => $sub->status,
          'cancel_at_period_end' => true, // lo forzamos a true ya que el schedule definió la cancelación
          'cancel_at' => $sub->cancel_at, // se cancela al final del ciclo
        ];

      } else {
        // Cancelación directa sobre la suscripción
        $canceled = \Stripe\Subscription::update($platformSubscriptionID, [
          'cancel_at_period_end' => true,
        ]);
      }

      return $response->withJson([
        'Status' => $canceled->status,
        'SubscriptionId' => $canceled->id,
        'CancelAtPeriodEnd' => $canceled->cancel_at_period_end,
        'CancelAt' => $canceled->cancel_at ? date("Y-m-d H:i:s", $canceled->cancel_at) : null,
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
    $rawJson = (string)$request->getBody();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $webhookSecret = $GLOBALS['config']['stripe']['STRIPE_WEBHOOK_SECRET'];

    try {
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
      $event = \Stripe\Webhook::constructEvent($rawJson, $sig_header, $webhookSecret);

      $this->stripe->logEvent('Stripe', json_decode($rawJson), $rawJson);

      switch ($event->type) {

        // ======================
        // CHECKOUT
        // ======================

        case 'checkout.session.completed':
          $session = $event->data->object;

          if (!isset($session->metadata->UserID) || !isset($session->metadata->PlanID)) {
            error_log("Metadata faltante en session.");
            return $response->withStatus(400);
          }

          $userID = (int) $session->metadata->UserID;
          $planID = (int) $session->metadata->PlanID;
          $subDomain = $session->metadata->SubDomain ?? '';
          $platformSubscriptionID = $session->subscription;
          $platformCustomerID = $session->customer;

          if (!$platformSubscriptionID) {
            error_log("Falta subscription ID de Stripe.");
            return $response->withStatus(400);
          }

            \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
            $stripeSub = \Stripe\Subscription::retrieve($platformSubscriptionID);

            $trialStartTs = $stripeSub->trial_start ?? null;
            $trialEndTs   = $stripeSub->trial_end   ?? null;

            $trialStart = $trialStartTs ? date('Y-m-d H:i:s', $trialStartTs) : null;
            $trialEnd   = $trialEndTs   ? date('Y-m-d H:i:s', $trialEndTs)   : null;

          // Obtener datos del usuario
          $userResult = $this->user->getUserById($userID);
          $userData = [];

          if ($userResult->http_code === 200 && !empty($userResult->data['UserName']) && !empty($userResult->data['Email'])) {
            $userData = [
              'UserName' => $userResult->data['UserName'],
              'Email' => $userResult->data['Email'],
              'TrialStart' => $trialStart,
              'TrialEnd' => $trialEnd,
              'TrialSource' => 'PLATFORM',
              'PaymentPlatform' => 'STRIPE',
            ];
          } else {
            error_log("No se pudo obtener usuario con ID $userID");
          }

          try {
            $this->subscription->createConfirmedSubscription($userID, $planID, $platformSubscriptionID, $platformCustomerID, $subDomain, $userData);
            error_log("Subscription creada para UserID: $userID | PlanID: $planID | StripeID: $platformSubscriptionID | SubDomain: $subDomain");
          } catch (\Throwable $e) {
            error_log("Error al crear la suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'checkout.session.async_payment_succeeded':
        case 'checkout.session.async_payment_failed':
        case 'checkout.session.expired':
        break;

        // ======================
        // INVOICES (ciclo de cobro)
        // ======================

        case 'invoice.finalized':
          $invoice = $event->data->object;
          
          if (empty($invoice)) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_INVOICE",
                "desc" => "Invalid invoice"
              ]
            ]);
          }

          $line = !empty($invoice->lines->data) ? $invoice->lines->data[0] : null;

          $invoiceData = [
            'InvoiceID'        => $invoice->id,
            'BillingReason'    => $invoice->billing_reason,
            'SubscriptionID'   => $invoice->parent?->subscription_details?->subscription ?? null,
            'CustomerID'       => $invoice->customer,
            'Currency'         => $invoice->currency,
            'AmountDue'        => $invoice->amount_due / 100,
            'AmountPaid'       => $invoice->amount_paid / 100,
            'AmountRemaining'  => $invoice->amount_remaining / 100,
            'Status'           => $invoice->status,
            'PriceID'          => $line?->pricing?->price_details?->price,
            'ProductID'        => $line?->pricing?->price_details?->product,
            'Quantity'         => $line?->quantity ?? 1,
            'PeriodStart'      => $line?->period?->start ? date("Y-m-d", $line->period->start) : null,
            'PeriodEnd'        => $line?->period?->end ? date("Y-m-d", $line->period->end) : null,
            'InvoicePDF'       => $invoice->invoice_pdf ?? null,
            'HostedInvoiceURL' => $invoice->hosted_invoice_url ?? null,
            'CreatedAt'        => date("Y-m-d", $invoice->created),
            'PaidAt'           => $invoice->status_transitions?->paid_at 
                                  ? date("Y-m-d", $invoice->status_transitions->paid_at) 
                                  : null
          ];

          try {
            $this->subscription->upsertInvoice($invoiceData);
            error_log("Factura creada para UserID (InvoiceID: " . $invoice->id . ")");
          } catch (\Throwable $e) {
            error_log("Error al registrar la factura: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        // case 'invoice.paid':
        case 'invoice.payment_succeeded':
          $invoice = $event->data->object;
          
          if (empty($invoice)) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_INVOICE",
                "desc" => "Invalid invoice"
              ]
            ]);
          }

          $line = !empty($invoice->lines->data) ? $invoice->lines->data[0] : null;

          $invoiceID = $invoice->id;

          $invoiceData = [
            'SubscriptionID'   => $invoice->parent?->subscription_details?->subscription ?? null,
            'AmountDue'        => $invoice->amount_due / 100,
            'AmountPaid'       => $invoice->amount_paid / 100,
            'AmountRemaining'  => $invoice->amount_remaining / 100,
            'Status'           => $invoice->status,
            'PaidAt'           => $invoice->status_transitions?->paid_at 
                                  ? date("Y-m-d", $invoice->status_transitions->paid_at) 
                                  : null
          ];

          try {
            $this->subscription->updateSubscriptionPayment($invoiceID, $invoiceData);
            error_log("Pago aprobado para UserID (InvoiceID: " . $invoice->id . ")");
          } catch (\Throwable $e) {
            error_log("Error al registrar el pago: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'invoice.payment_failed':
          $invoice = $event->data->object;

          if (empty($invoice)) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_INVOICE",
                "desc" => "Invalid failed invoice"
              ]
            ]);
          }

          if (!empty($invoice->subscription)) {
            $this->subscription->markPastDue($invoice->subscription);
          }

          $line = !empty($invoice->lines->data) ? $invoice->lines->data[0] : null;

          $invoiceID = $invoice->id;

          $invoiceData = [
            'SubscriptionID'   => $invoice->parent?->subscription_details?->subscription ?? null,
            'AmountDue'        => $invoice->amount_due / 100,
            'AmountPaid'       => $invoice->amount_paid / 100,
            'AmountRemaining'  => $invoice->amount_remaining / 100,
            'Status'           => $invoice->status,
            'PaidAt'           => $invoice->status_transitions?->paid_at 
                                  ? date("Y-m-d", $invoice->status_transitions->paid_at) 
                                  : null
          ];

          try {
            $this->subscription->updateSubscriptionPayment($invoiceID, $invoiceData);
            error_log("Se registró el fallo por el pago del InvoiceID (InvoiceID: " . $invoice->id . ")");
          } catch (\Throwable $e) {
            error_log("Error al registrar el fallo del pago: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'invoice.voided':
          $invoice = $event->data->object;
          $this->subscription->markInvoiceVoided($invoice->id);
        break;

        case 'invoice.marked_uncollectible':
          $invoice = $event->data->object;
          $this->subscription->markInvoiceUncollectible($invoice->id);
        break;
        
        // ======================
        // SUBSCRIPTIONS
        // ======================

        case 'customer.subscription.updated':
          $sub = $event->data->object;

          $platformSubscriptionID = $sub->id;
          $newPriceId = $sub->items->data[0]->price->id ?? null;
          $nextBillingDate = $sub->items->data[0]->current_period_end ? date("Y-m-d", $sub->items->data[0]->current_period_end) : null;

          error_log("Subscription actualizada en Stripe: $platformSubscriptionID con nuevo PriceID: $newPriceId");

          if ($sub->canceled_at) {
            $this->subscription->markCancelAtPeriodEnd(
              $platformSubscriptionID,
              date("Y-m-d H:i:s", $sub->canceled_at),
              $nextBillingDate,
            );
          }

          try {
            $planInfo = $this->subscription->getSubscriptionPlanByStripeID($newPriceId);
            if ($planInfo) {
              $this->subscription->updateSubscriptionByUser($platformSubscriptionID, $planInfo['PlanID'], $nextBillingDate);
              error_log("Plan actualizado en BD a PlanID: " . $planInfo['PlanID']);
            } else {
              error_log("No se encontró plan para PriceID: $newPriceId");
            }
          } catch (\Throwable $e) {
            error_log("Error al actualizar suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'customer.subscription.deleted':
          $subscription = $event->data->object;
          $platformSubscriptionID = $subscription->id;
          $cancelAt = $subscription->cancel_at;

          error_log("Subscription eliminada en Stripe: " . $platformSubscriptionID);

          try {
            $this->subscription->cancelSubscription($platformSubscriptionID, $cancelAt);
            error_log("Subscription cancelada en base de datos.");
          } catch (\Throwable $e) {
            error_log("Error al cancelar suscripción: " . $e->getMessage());
            return $response->withStatus(500);
          }
        break;

        case 'customer.subscription.trial_will_end':
          $subscription = $event->data->object;
          $platformSubscriptionID = $subscription->id;
          $trialEnd = !empty($subscription->trial_end)
                    ? date('Y-m-d H:i:s', $subscription->trial_end)
                    : null;

          $trialStart = !empty($subscription->trial_start)
                    ? date('Y-m-d H:i:s', $subscription->trial_start)
                    : null;

          $this->subscription->handleTrialWillEnd($platformSubscriptionID, $trialEnd, $trialStart);
        break;

        case 'customer.subscription.paused':
          // La suscripción pasó a status=paused
          $sub = $event->data->object;
          $this->subscription->markPaused($sub->id, $sub->pause_collection?->behavior ?? null);
        break;

        case 'customer.subscription.resumed':
          // Se reanuda desde paused -> active (puede requerir pago de invoice de reanudación)
          $sub = $event->data->object;
          $nextBillingDate = $sub->current_period_end ? date("Y-m-d", $sub->current_period_end) : null;
          $this->subscription->markResumed($sub->id, $nextBillingDate);
        break;

        case 'customer.subscription.pending_update_applied':
          $sub = $event->data->object;
          // Actualizar plan si cambió el Price
          $newPriceId = $sub->items->data[0]->price->id ?? null;
          if ($newPriceId) {
            $planInfo = $this->subscription->getSubscriptionPlanByStripeID($newPriceId);
            if ($planInfo) {
              $this->subscription->updateSubscriptionByUser($sub->id, $planInfo['PlanID'],
                $sub->current_period_end ? date("Y-m-d", $sub->current_period_end) : null);
            }
          }
        break;

        case 'customer.subscription.pending_update_expired':
        break;

        // ======================
        // PAYMENT METHODS
        // ======================

        case 'payment_method.attached' :
          $method = $event->data->object;
          if (empty($method)) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_METHOD",
                "desc" => "Invalid payment method"
              ]
            ]);
          }

          $brand = $method->card->brand;
          $last4 = $method->card->last4;

        break;  

        case 'payment_method.updated':
        case 'payment_method.automatically_updated':
        case 'payment_method.detached':
        break;

        // ======================
        // PAYMENT INTENTS (one-shot o reintentos de invoice)
        // ======================
        case 'payment_intent.succeeded':
        case 'payment_intent.payment_failed':
        break;

        // ======================
        // DISPUTAS / REEMBOLSOS
        // ======================
        case 'charge.dispute.created':
        case 'charge.dispute.closed':
        case 'charge.refunded':
        break;

        // ======================
        // CUSTOMER (actualización de datos)
        // ======================

        case 'customer.updated':
          $customer = $event->data->object;
          $this->subscription->handleCustomerUpdated($customer);
        break;

        // ======================
        // SCHEDULES (programaciones)
        // ======================

        case 'subscription_schedule.created' :
        case 'subscription_schedule.updated' :
        break;  

      default:
        error_log("Evento no manejado: " . $event->type);
      }

      $this->stripe->markProcessed($event->id, 'Stripe');
      return $response->withStatus(200);

    } catch (\UnexpectedValueException $e) {
      error_log("Payload inválido: " . $e->getMessage());
      return $response->withStatus(400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      error_log("Firma inválida del webhook: " . $e->getMessage());
      return $response->withStatus(400);
    } catch (\Throwable $e) {
      error_log("Error general en webhook: " . $e->getMessage());
      return $response->withStatus(200);
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