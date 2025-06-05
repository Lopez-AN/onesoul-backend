<?php

namespace App\Models;

use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeService {
  public function createCheckoutSession($priceId, $userEmail) 
  {
    try {
      // Configurar la clave secreta de Stripe
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
      
      $session = Session::create([
        'payment_method_types' => ['card'],
        'mode' => 'subscription',
        'line_items' => [[
          'price' => $priceId,
          'quantity' => 1
        ]],
        'customer_email' => $userEmail,
        'success_url' => 'https://onesoul.app/success?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://onesoul.app/cancel'
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
}
