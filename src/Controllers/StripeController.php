<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\StripeService;

class StripeController{

  protected $stripe;

  public function __construct(StripeService $stripe){
    $this->stripe = $stripe;
  }

  public function createCheckoutSession(Request $request, Response $response, $args)
  {
    $data = $request->getParsedBody();
    $priceId = $data['priceId'] ?? null;
    $userEmail = $data['userEmail'] ?? null;

    // Validación
    if (empty($priceId) || empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Missing or invalid parameters"
        ]
      ]);
    }

    try {
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
}