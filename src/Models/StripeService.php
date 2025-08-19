<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeService 
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function createCheckoutSession($priceId, $userEmail, $userID, $planID, $subDomain) 
  {
    try {
      // Configurar la clave secreta de Stripe
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
      
      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      $session = Session::create([
        'payment_method_types' => ['card'],
        'mode' => 'subscription',
        'line_items' => [[
          'price' => $priceId,
          'quantity' => 1
        ]],
        'customer_email' => $userEmail,
        'success_url' =>  $origin . "/profile?subscription_success&session_id={CHECKOUT_SESSION_ID}",
        'cancel_url' => $origin . "/profile?subscription_cancel&session_id={CHECKOUT_SESSION_ID}",
        'metadata' => [
          'userID' => $userID,
          'planID' => $planID,
          'subDomain' => $subDomain
        ]
      ]);

      return [
        "sessionId" => $session->id,
        "url" => $session->url
      ];
    } catch (\Exception $e) {
      return [
        "error" => [
          "code" => "STRIPE_ERROR",
          "desc" => $e->getMessage()
        ]
      ];
    }
  }

  public function getStripeSession($sessionID)
  {
    try {
      // Configurar la clave secreta de Stripe
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
      $session = \Stripe\Checkout\Session::retrieve($sessionID, [
        'expand' => ['subscription', 'customer']
      ]);

      if (!$session) return null;
        // Datos de la sesión formateados
        $data = [
          'ID' => $session->id,
          'Status' => $session->payment_status,
          'Customer' => $session->customer,
          'Subscription' => $session->subscription,
          'AmountTotal' => $session->amount_total / 100,
          'Currency' => strtoupper($session->currency),
          'Metadata' => $session->metadata ? $session->metadata->toArray() : [],
        ];

      return $data;

    } catch (\Exception $e) {
      // Log de error opcional
      error_log("Stripe session retrieval error: " . $e->getMessage());
      return null;
    }
  }

  public function cancelStripeSubscription($stripeSubscriptionID)
  {
    try {
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      $subscription = \Stripe\Subscription::retrieve($stripeSubscriptionID);
      $subscription->cancel();

      return true;
    } catch (\Exception $e) {
      return [
        "error" => [
          "code" => "STRIPE_CANCEL_ERROR",
          "desc" => $e->getMessage()
        ]
      ];
    }
  }
}
