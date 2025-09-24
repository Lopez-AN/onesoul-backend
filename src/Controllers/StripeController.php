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

      $firstName = $result->data['FirstName'] ?? '';
      $lastName  = $result->data['LastName'] ?? '';

      // Dirección
      $line1 = trim(($result->data['AddressName'] ?? '') . ' ' . ($result->data['AddressNumber'] ?? ''));
      $line2Parts = [];
      if (!empty($result->data['Floor'])) $line2Parts[] = "Piso " . $result->data['Floor'];
      if (!empty($result->data['Department'])) $line2Parts[] = "Depto " . $result->data['Department'];
      $line2 = !empty($line2Parts) ? implode(' - ', $line2Parts) : null;

      $userInfo = [
        'name' => trim(($result->data['FirstName'] ?? '') . ' ' . ($result->data['LastName'] ?? '')),
        'line1'       => !empty($line1) ? $line1 : null,
        'line2'       => $line2,
        'cp'          => $result->data['Cp'] ?? null,
        'city'        => $result->data['City'] ?? null,
        'state'       => $result->data['State'] ?? null,
        'country'     => $result->data['CountryCode'] ?? null,
      ];

      $result = $this->stripe->createCheckoutSession($priceId, $userEmail, $userID, $planID, $subDomain, $trialDays, $userInfo);
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
    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error processing upgrade info: " . $e->getMessage()
        ]
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

  public function upgradeApply(Request $request, Response $response, array $args) {
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
      $prorationDate = $data['ProrationDate'] ?? time();

      // Tolerancia maxima prorrateo 2min
      if (abs(time() - $prorationDate) > 120) { // 2 minutos
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "EXPIRED_STRIPE_REQUEST",
            "desc" => "The upgrade request must be confirmed within 2 minutes"
          ]
        ]);
      }

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      $itemId = $sub->items->data[0]->id;
      $currentPeriodEnd = $sub->items->data[0]->current_period_end;

      $updated = \Stripe\Subscription::update($platformSubscriptionID, [
        'items' => [[ 'id' => $itemId, 'price' => $newPriceId ]],
        'proration_behavior' => 'always_invoice',   // factura la diferencia ahora
        'proration_date'     => $prorationDate,     // MISMO que el preview
        'payment_behavior'   => 'pending_if_incomplete', // si requiere SCA, queda pendiente
        'expand' => ['latest_invoice.payment_intent', 'latest_invoice.charge'],
      ]);

      $invoice = $updated->latest_invoice ?? null;
      $pi = $invoice ? $invoice->payment_intent : null;

      // Respuestas “sincronas” para la UI
      if ($invoice && $invoice->status === 'paid') {
        return $response->withJson([
          'Status'          => 'paid',
          // 'OperationId'     => $operationId,
          'SubscriptionId'  => $updated->id,
          'InvoiceId'       => $invoice->id
        ]);
      }
      if ($pi && $pi->status === 'requires_action') {
        return $response->withJson([
          'Status'          => 'requires_action',
          'ClientSecret'    => $pi->client_secret,
          // 'OperationId'     => $operationId,
          'SubscriptionId'  => $updated->id,
          'InvoiceId'       => $invoice->id
        ]);
      }
      if ($pi && $pi->status === 'requires_payment_method') {
        return $response->withJson([
          'Status'          => 'requires_payment_method',
          // 'OperationId'     => $operationId,
          'SubscriptionId'  => $updated->id,
          'InvoiceId'       => $invoice->id
        ]);
      }
      
      // Agregar un cambio WAITING en BD
      $changeId = $this->subscription->waitingSubscriptionChange(
        $platformSubscriptionID,
        $newPlanID,
        $currentPeriodEnd
      );

      // Fallback: pendiente (stripe tratara de cobrar)
      return $response->withJson([
        'Status'          => 'pending',
        // 'OperationId'     => $operationId,
        'SubscriptionId'  => $updated->id,
        'InvoiceId'       => $invoice ? $invoice->id : null
      ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error processing upgrade: " . $e->getMessage()
        ]
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

  public function downgradeApply(Request $request, Response $response, array $args) {
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

      // Si ya tiene pendiente un downgrade no seguir
      $pendingChange = $this->subscription->getPendingChange($platformSubscriptionID);
      if(!empty($pendingChange)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "DOWNGRADE_ALREADY_SCHEDULED",
            "desc" => "A pending downgrade is scheduled for this subscription."
          ]
        ]);
      }

      $currentPlanId = $subscription['PlanID'] ?? null;
      $currentPlan = $this->subscription->getSubscriptionPlanByID($currentPlanId);
      if (!$currentPlan || empty($currentPlan['StripeID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "STRIPE_PLAN_MISSING",
            "desc" => "Stripe ID not configured for the current plan"
          ]
        ]);
      }

      $newPlanId = $data['PlanID'] ?? null;
      $newPlan = $this->subscription->getSubscriptionPlanByID($newPlanId);
      if (!$newPlan || empty($newPlan['StripeID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "STRIPE_PLAN_MISSING",
            "desc" => "Stripe ID not configured for the new plan"
          ]
        ]);
      }
      $newPriceId = $newPlan['StripeID'];
      $currentPriceId = $currentPlan['StripeID'];

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      // Traer la suscripción actual
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);

      // Si está en trial → aplicar downgrade inmediato
      if ($sub->status === 'trialing') {
        // Actualizar directamente la suscripción en Stripe
        $updated = \Stripe\Subscription::update($platformSubscriptionID, [
          'items' => [[
            'id' => $sub->items->data[0]->id,
            'price' => $newPriceId,
          ]],
          'proration_behavior' => 'none'
        ]);

        return $response->withJson([
          'Status' => 'applied',
          'SubscriptionId' => $updated->id,
          'NewPrice' => $newPriceId,
          'AppliedAt' => date("Y-m-d H:i:s")
        ]);
      }

      $currentPeriodEnd = $sub->items->data[0]->current_period_end;
      if(!$sub->schedule){
        // Creo el schedule si no existe
        $schedule = \Stripe\SubscriptionSchedule::create([
          'from_subscription' => $platformSubscriptionID]);
      }else{
        $schedule = \Stripe\SubscriptionSchedule::retrieve($sub->schedule);
      }

      // Programo el downgrade
      $schedule = \Stripe\SubscriptionSchedule::update($schedule->id, [
        'phases' => [
          [
            // Fase actual: mantener el plan actual hasta el final del período
            'start_date' => $schedule->current_phase->start_date, // Fecha actual como ancla
            'end_date' => $currentPeriodEnd,
            'items' => [[
              'price' => $currentPriceId,
              'quantity' => 1
            ]],
            'proration_behavior' => 'none'
          ],
          [
            // Fase nueva: cambiar al plan inferior desde la siguiente renovación
            'start_date' => $currentPeriodEnd,
            'items' => [[
              'price' => $newPriceId,
              'quantity' => 1
            ]],
            'proration_behavior' => 'none'
          ]
        ]]);

      // Agregar un cambio PENDING en BD
      $changeId = $this->subscription->scheduleSubscriptionChange(
        $platformSubscriptionID,
        $newPlanId,
        $currentPeriodEnd
      );

      return $response->withJson([
        'Status' => 'scheduled',
        'ChangeId' => $changeId,
        'SubscriptionId' => $schedule->subscription,
        'CurrentPeriodEnd' => date("Y-m-d H:i:s", $currentPeriodEnd),
        'NewPrice' => $newPriceId,
        'ScheduleId' => $schedule->id
      ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error processing downgrade: " . $e->getMessage()
        ]
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

  public function downgradeCancel(Request $request, Response $response, array $args) {
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

      if ($subscription['Status'] == 'TRIALING') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "NO_PENDING_DOWNGRADE",
            "desc" => "The subscription is in trialing status, so the downgrade was applied immediately and cannot be canceled."
          ]
        ]);
      }

      // Si NO tiene pendiente un downgrade no seguir
      $pendingChange = $this->subscription->getPendingChange($platformSubscriptionID);
      if(empty($pendingChange)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "DOWNGRADE_NOT_SCHEDULED",
            "desc" => "No pending downgrade exists for this subscription."
          ]
        ]);
      }

      $currentPlanId = $subscription['PlanID'] ?? null;
      $currentPlan = $this->subscription->getSubscriptionPlanByID($currentPlanId);
      if (!$currentPlan || empty($currentPlan['StripeID'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "STRIPE_PLAN_MISSING",
            "desc" => "Stripe ID not configured for the current plan"
          ]
        ]);
      }
      $currentPriceId = $currentPlan['StripeID'];

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      // Traer la suscripción actual
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      if($sub->schedule){
        $schedule = \Stripe\SubscriptionSchedule::retrieve($sub->schedule);
        $schedule->release();
      }

      return $response->withJson([
        'Status' => 'downgrade_cancel_requested'
      ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error cancelling downgrade: " . $e->getMessage()
        ]
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

  public function cancelSubscription(Request $request, Response $response, array $args) {
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

      // Chequear si ya existe un pending de cancelación
      $pendingCancel = $this->subscription->getPendingCancel($platformSubscriptionID);
      if($pendingCancel){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_ALREADY_CANCELLED",
            "desc" => "This subscription is already cancelled."
          ]
        ]);
      }

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      // Traer la suscripción actual
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      if($sub->schedule){
        $schedule = \Stripe\SubscriptionSchedule::retrieve($sub->schedule);
        $schedule->release();
      }

      // Cancelación directa sobre la suscripción
      $canceled = \Stripe\Subscription::update($platformSubscriptionID, [
        'cancel_at_period_end' => true,
      ]);

      // Refrescar la suscripción para obtener los datos actualizados
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      $currentPeriodEnd = $sub->items->data[0]->current_period_end;

      // Agregar un cambio PENDING en BD
      $changeId = $this->subscription->scheduleSubscriptionChange(
        $platformSubscriptionID,
        null,
        $currentPeriodEnd
      );

      return $response->withJson([
        'Status' => $sub->status,
        'SubscriptionId' => $sub->id,
        'CancelAtPeriodEnd' => $sub->cancel_at_period_end,
        'CancelAt' => $currentPeriodEnd ? date("Y-m-d H:i:s", $currentPeriodEnd) : null
      ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error cancelling this subscription: " . $e->getMessage()
        ]
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

  public function resumeSubscription(Request $request, Response $response, array $args) {
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
            "desc" => "No active subscription to resume."
          ]
        ]);
      }

      $platformSubscriptionID = $subscription['PlatformSubscriptionID'];

      // Chequear si ya existe un pending de cancelación
      $pendingCancel = $this->subscription->getPendingCancel($platformSubscriptionID);
      if(!$pendingCancel){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "CANCEL_NOT_SCHEDULED",
            "desc" => "This subscription is not scheduled for cancel."
          ]
        ]);
      }
      if($pendingCancel['EffectiveDate'] < date("Y-m-d H:i:s")){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_EXPIRED",
            "desc" => "This subscription has already ended and cannot be resumed."
          ]
        ]);
      }

      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      // Traer la suscripción actual
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      if (!empty($sub->schedule)) {
        // La suscripción está controlada por un Schedule
        \Stripe\SubscriptionSchedule::update(
          $sub->schedule,
          ['end_behavior' => 'release'],
        );
      } else {
        // Cancelación directa sobre la suscripción
        $canceled = \Stripe\Subscription::update($platformSubscriptionID, [
          'cancel_at_period_end' => false,
        ]);
      }

      // Refrescar la suscripción para obtener los datos actualizados
      $sub = \Stripe\Subscription::retrieve($platformSubscriptionID);
      $changeId = $this->subscription->resumeSubscription($platformSubscriptionID);
      error_log("Se volvió a activar la sub con ID: $changeId");

      // Quitar el pending el la base
      $this->subscription->cancelSubscriptionChange($platformSubscriptionID);

      return $response->withJson([
        'Status' => $sub->status,
        'SubscriptionId' => $sub->id,
        'CancelAtPeriodEnd' => 0,
        'CancelAt' => null,
      ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => "Error activating subscription : " . $e->getMessage()
        ]
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

  public function getUserPaymentMethod(Request $request, Response $response, array $args) {
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;

    // buscar el customer de Stripe
    $sub = $this->subscription->getSubscriptionByUser($userID);
    $platformCustomerID = $sub['PlatformCustomerID'];

    if (empty($platformCustomerID)) {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "CUSTOMER_NOT_FOUND",
          "desc" => "No Stripe customer found for this user"
        ]
      ]);
    }

    $paymentMethod = $this->stripe->getUserPaymentMethod($platformCustomerID);

    if (!$paymentMethod) {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "PAYMENT_METHOD_NOT_FOUND",
          "desc" => "No payment method found for this user"
        ]
      ]);
    }

    return $response->withJson($paymentMethod);
  }

  public function createSetupIntent(Request $request, Response $response, array $args) {
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;

    // buscar el customer de Stripe
    $sub = $this->subscription->getSubscriptionByUser($userID);
    $platformCustomerID = $sub['PlatformCustomerID'] ?? null;

    if (empty($platformCustomerID)) {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "CUSTOMER_NOT_FOUND",
          "desc" => "No Stripe customer found for this user"
        ]
      ]);
    }

    try {
      $setupIntent = $this->stripe->createSetupIntent($platformCustomerID);

      return $response->withJson([
        "success" => true,
        "SetupIntent" => $setupIntent
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function createBillingPortalSession(Request $request, Response $response, array $args) {
    $jwt = $request->getAttribute('jwt');
    $data = $request->getParsedBody();
    $subDomain = $data['SubDomain'] ?? '';

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

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;

    $sub = $this->subscription->getSubscriptionByUser($userID);
    $platformCustomerID = $sub['PlatformCustomerID'] ?? null;

    if (empty($platformCustomerID)) {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "CUSTOMER_NOT_FOUND",
          "desc" => "No Stripe customer found for this user"
        ]
      ]);
    }

    try {
      $session = $this->stripe->createBillingPortalSession($platformCustomerID, $subDomain);

      return $response->withJson([
        "success" => true,
        "url" => $session['URL']
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "STRIPE_ERROR",
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
            $nextBillingDate = $stripeSub->items->data[0]->current_period_end ? date("Y-m-d H:i:s", $stripeSub->items->data[0]->current_period_end) : null;
            $latestInvoice = $stripeSub->latest_invoice ?? null;

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
              'NextBillingDate' => $nextBillingDate,
              'LatestInvoiceID' => $latestInvoice
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
          $platformSubscriptionID = $invoice->subscription ?? null;

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

            // Si la suscripción estaba PAST_DUE, volverla ACTIVE
            if ($platformSubscriptionID) {
              $this->subscription->clearPastDueOnPaid($platformSubscriptionID);
              error_log("Suscripción activada en BD tras pago exitoso: " . $platformSubscriptionID);
            }

            $coupon = $invoice->discount->coupon ?? null;
            if ($coupon) {
              $this->subscription->updateCouponStatus(
                  $platformSubscriptionID,
                  $coupon->id
              );
            }

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
          $nextBillingDate = isset($sub->items->data[0]->current_period_end) ? date("Y-m-d H:i:s", $sub->items->data[0]->current_period_end) : null;

          error_log("Subscription actualizada en Stripe: $platformSubscriptionID con nuevo PriceID: $newPriceId");

          if ($sub->canceled_at) {
            $this->subscription->markCancelAtPeriodEnd(
              $platformSubscriptionID,
              $nextBillingDate
            );
          }

          if (isset($event->data->previous_attributes->schedule)) {
            // cancelar el pending en la BD
            $this->subscription->cancelSubscriptionChange($platformSubscriptionID);
            error_log("Downgrade pendiente cancelado en BD (prevAttributes->schedule detectado)");
          }

          try {
            // Intentar extraer prevPriceId de previous_attributes (si existe), de forma robusta
            $prevPriceId = null;
            if (isset($event->data->previous_attributes->items)) {
              // previous_attributes->items puede venir como array/obj; buscar la primera price->id disponible
              $itemsPrev = $event->data->previous_attributes->items;
              if (is_array($itemsPrev) && isset($itemsPrev[0]->price->id)) {
                $prevPriceId = $itemsPrev[0]->price->id;
              } elseif (is_object($itemsPrev) && isset($itemsPrev->data) && is_array($itemsPrev->data) && isset($itemsPrev->data[0]->price->id)) {
                $prevPriceId = $itemsPrev->data[0]->price->id;
              }
            }

            // Si Stripe no nos dio previous_attributes (prevPriceId === null), comparamos con la BD
            $subscriptionInDb = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
            $currentPlanIDInDb = $subscriptionInDb['PlanID'] ?? null;
            $currentPlanInfoInDb = null;
            if ($currentPlanIDInDb) {
              $currentPlanInfoInDb = $this->subscription->getSubscriptionPlanByID($currentPlanIDInDb);
            }

            // Obtener planInfo por newPriceId (si existe)
            $planInfo = $newPriceId ? $this->subscription->getSubscriptionPlanByStripeID($newPriceId) : null;

            // Detectamos realmente un cambio de price (usando prevPriceId si está, sino comparando con BD)
            $isDifferent = false;
            if ($prevPriceId !== null) {
              $isDifferent = ($prevPriceId != $newPriceId);
            } else {
              if ($planInfo && $currentPlanInfoInDb) {
                // Validar explícitamente contra la BD
                if ($planInfo['PlanID'] != $currentPlanInfoInDb['PlanID']) {
                  $isDifferent = true;
                }
              } elseif ($planInfo && !$currentPlanInfoInDb) {
                // no tenemos plan en BD: considerarlo diferente para aplicar
                $isDifferent = true;
              }
            }

            // Evitar duplicados: si el plan nuevo es igual al actual en BD → no hacer nada
            if ($planInfo && $currentPlanInfoInDb && $planInfo['PlanID'] == $currentPlanInfoInDb['PlanID']) {
              $isDifferent = false;
            }

            if ($isDifferent && $planInfo) {
              // buscar un cambio pendiente para este plan
              $pending = $this->subscription->getPendingChange($platformSubscriptionID, $planInfo['PlanID']);

              if ($pending) {
                // Si Stripe ya cambió el price.id, significa que el downgrade programado ya se ejecutó
                if ($planInfo && $planInfo['PlanID'] == $pending['NewPlanID']) {
                  $this->subscription->applyScheduledChange($pending['id'], $nextBillingDate);
                  error_log("Cambio pendiente aplicado en BD: changeId {$pending['id']} -> nuevo PlanID {$planInfo['PlanID']}");
                } else {
                  error_log("Stripe aún no aplicó el cambio pendiente, seguimos esperando");
                }
              } else {
                // upgrade → aplicar directamente
                $res = $this->subscription->updateSubscriptionByUser(
                  $platformSubscriptionID,
                  $planInfo['PlanID'],
                  $nextBillingDate,
                  true // applyNow
                );
                error_log("Plan actualizado en BD (upgrade) a PlanID: " . $planInfo['PlanID'] . " - resultado: " . json_encode($res));
              }
            } else {
              error_log("No hay cambio de plan detectado o no se encontró planInfo para priceId: $newPriceId");
            }
          } catch (\Throwable $e) {
            error_log("Error al actualizar suscripción: " . $e->getMessage());
            // Responder 200 para no re-intentar infinitamente; Stripe retryará si retornamos 500.
            return $response->withStatus(200);
          }
        break;

        case 'customer.subscription.deleted':
          $sub = $event->data->object;
          $platformSubscriptionID = $sub->id;
          $endDate = isset($sub->items->data[0]->current_period_end) ? date("Y-m-d H:i:s", $sub->items->data[0]->current_period_end) : null;

          error_log("Subscription eliminada en Stripe: " . $platformSubscriptionID);

          try {
            $this->subscription->cancelSubscription($platformSubscriptionID, $endDate);
            error_log("Subscription cancelada en base de datos.");

            $this->subscription->cancelSubscriptionChange($platformSubscriptionID);
            error_log("Downgrade pendiente cancelado.");
          } catch (\Throwable $e) {
            error_log("Error al cancelar suscripción: " . $e->getMessage());
            // Responder 200 para no re-intentar infinitamente; Stripe retryará si retornamos 500.
            return $response->withStatus(200);
          }
        break;

        case 'customer.subscription.trial_will_end':
          $sub = $event->data->object;
          $platformSubscriptionID = $sub->id;
          $trialEnd = !empty($sub->trial_end)
                    ? date('Y-m-d H:i:s', $sub->trial_end)
                    : null;

          $trialStart = !empty($sub->trial_start)
                    ? date('Y-m-d H:i:s', $sub->trial_start)
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
        // CUPONES
        // ======================
        case 'customer.discount.created':
          $discount = $event->data->object;

          $platformSubscriptionID = $discount->subscription ?? null;
          $coupon = $discount->coupon ?? null;

          if ($platformSubscriptionID && $coupon) {
              $platformCouponID = $discount->id;
              $couponCode = $coupon->id;
              $couponName = $coupon->name ?? null;
              $percentOff = $coupon->percent_off ?? null;
              $amountOff = $coupon->amount_off ?? null;
              $end = $discount->end ? date("Y-m-d H:i:s", $discount->end) : null;

              // Buscar el UserID en base al customer de Stripe
              $userID = $this->subscription->getUserSubscriptionByPlatformSubID($platformSubscriptionID);

              // Guardar el cupón en BD
              $this->subscription->createCoupon([
                  'PlatformSubscriptionID' => $platformSubscriptionID,
                  'PlatformCouponID' => $platformCouponID,
                  'CouponCode' => $couponCode,
                  'CouponName' => $couponName,
                  'UserID' => $userID,
                  'PercentOff' => $percentOff,
                  'AmountOff' => $amountOff,
                  'ExpiresAt' => $end,
              ]);

              error_log("Cupón $couponCode aplicado en la suscripción $platformSubscriptionID para UserID $userID");
          }
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