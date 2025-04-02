<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Subscription;
use Firebase\JWT\JWT;

class SubscriptionController {
  protected $subscription;

  public function __construct(Subscription $subscription)  {
    $this->subscription = $subscription;
  }

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

}  