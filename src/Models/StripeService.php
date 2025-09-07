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

  public function createCheckoutSession($priceId, $userEmail, $userID, $planID, $subDomain, $trialDays) {
    try {
      // Configurar la clave secreta de Stripe
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      $hasTrial = ($trialDays !== null && (int)$trialDays > 0);
      $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";

      $params = [
        'payment_method_types' => ['card'],
        'mode' => 'subscription',
        'line_items' => [[
          'price' => $priceId,
          'quantity' => 1
        ]],
        'customer_email' => $userEmail,
        'success_url' => $origin . "/profile/subscription/success?session_id={CHECKOUT_SESSION_ID}",
        'cancel_url' => $origin . "/profile/subscription/cancel?session_id={CHECKOUT_SESSION_ID}",
        'metadata' => [
          'UserID' => $userID,
          'PlanID' => $planID,
          'SubDomain' => $subDomain,
          'TrialDays' => $hasTrial ? $trialDays : 0,
          'DonationsRequired'  => $hasTrial ? '3' : '0',
        ],
        // 'allow_promotion_codes' => true,
        'payment_method_collection' => 'always',
        'subscription_data' => [
          'metadata' => [
            'UserID' => $userID,
            'PlanID'  => $planID,
            'SubDomain'  => $subDomain,
            'TrialDays' => $hasTrial ? $trialDays : 0,
            'DonationsRequired' => $hasTrial ? '3' : '0',
          ],
        ],
      ];

      if ($hasTrial) {
        $params['subscription_data']['trial_period_days'] = (int)$trialDays;
      }
      $session = \Stripe\Checkout\Session::create($params);
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

  public function getStripeSession($sessionID) {
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
      error_log("Stripe session retrieval error: " . $e->getMessage());
      return null;
    }
  }

  public function cancelStripeSubscription($platformSubscriptiontID) {
    try {
      \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

      $subscription = \Stripe\Subscription::retrieve($platformSubscriptiontID);
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

  public function logEvent($platform, $event, $rawJson) {
    try {
      $stmt = $this->db->prepare("INSERT INTO PaymentPlatformEvents (PaymentPlatform, EventType, 
                                  PlatformEventID, RelatedObjectID, RawJSON) 
                                  VALUES (:platform, :eventType, :platformEventID, 
                                  :relatedObjectID, :rawJson)
                                  ON DUPLICATE KEY UPDATE RawJSON = VALUES(RawJSON)");

      $stmt->execute([
        ':platform'        => $platform,
        ':eventType'       => $event->type,
        ':platformEventID' => $event->id,
        ':relatedObjectID' => $event->data->object->id ?? null,
        ':rawJson'         => $rawJson
      ]);
      
      $platformEventID = $this->db->lastInsertId();

      return $this->getEventByPlatformID($platformEventID, $platform);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function markProcessed($platformEventID, $platform) {
    try {
      $stmt = $this->db->prepare("UPDATE PaymentPlatformEvents
                                  SET Processed = 1
                                  WHERE PlatformEventID = :platformEventID
                                  AND PaymentPlatform = :platform");

      $stmt->execute([
        ':platformEventID' => $platformEventID,
        ':platform'        => $platform
      ]);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    } 
  }

  public function getEventByPlatformID($platformEventID, $platform) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM PaymentPlatformEvents
                                  WHERE PlatformEventID = :platformEventID
                                  AND PaymentPlatform = :platform
                                  LIMIT 1");
      
      $stmt->execute([
        ':platformEventID' => $platformEventID,
        ':platform'        => $platform
      ]);

      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return $result ?: null;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    } 
  }
}
