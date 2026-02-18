<?php

/* -----------------------------------------------
 * Middleware que permite varios modos de tokens
 * REQUIRED = se requiere un token valido y vigente
 * OPTIONAL = el token es opcional pero si esta presente debe ser valido y vigente
 * NO_EXPIRE = se admiten tokens vencidos, usado para /refresh_token
 * -----------------------------------------------*/

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Enums\JwtValidationMode;

class JwtTokenMiddleware implements MiddlewareInterface {
  private $jwtMiddleware;
  private $jwtValidationMode;

  public function __construct(JwtAuthentication $jwtMiddleware, JwtValidationMode $jwtValidationMode) {
    $this->jwtMiddleware = $jwtMiddleware;
    $this->jwtValidationMode = $jwtValidationMode;
  }

  public function process(Request $request, RequestHandlerInterface $handler): Response {
    $token = $this->extractToken($request);

    if ($token === null) {
      if($this->jwtValidationMode === JwtValidationMode::OPTIONAL){
        return $handler->handle($request);
      }else{
        return $this->unauthorizedResponse(new \Slim\Psr7\Response(), 'INVALID_TOKEN', 'Token not found');
      }
    }

    try {
      $secret = $GLOBALS['config']['jwt']['secret'];
      # Decodificar SIN validar expiración
      $decoded = JWT::decode($token, new Key($secret, 'HS256'));
      if(!$GLOBALS['config']['debug_mode'] && $this->jwtValidationMode !== JwtValidationMode::NO_EXPIRE && $decoded -> expire <= time()){
        return $this->unauthorizedResponse(new \Slim\Psr7\Response(), 'INVALID_TOKEN', 'Expired token');
      }
      if(!$this->isValidJwtStructure($decoded)){
        return $this->unauthorizedResponse(new \Slim\Psr7\Response(), 'INVALID_TOKEN', 'Invalid token');
      }
    } catch (\Exception $e) {
      return $this->unauthorizedResponse(new \Slim\Psr7\Response(), 'INVALID_TOKEN', 'Invalid token');
    }

    # Asignar al request
    $request = $request->withAttribute('jwt', $decoded);

    return $handler->handle($request);
  }

  private function isValidJwtStructure($jwt): bool {
    if (!isset($jwt -> data)) {
      return false;
    }

    $requiredFields = [
      'UserID',
      'UserName',
      'UserType',
      'UserLevel',
      'ValidatedEmail',
      'ValidatedPhone',
      'TwoFactorAuth',
      'IsAdmin'
    ];

    foreach ($requiredFields as $field) {
      if (!property_exists($jwt -> data, $field)) {
        return false;
      }
    }

    if (!is_int($jwt -> data -> UserID) || $jwt -> data -> UserID <= 0
      || !is_string($jwt -> data -> UserName) || !is_string($jwt -> data -> UserType)
      || !is_int($jwt -> data -> UserLevel) || $jwt -> data -> UserLevel <= 0
      || !is_bool($jwt -> data -> ValidatedEmail) || !is_bool($jwt -> data -> ValidatedPhone)
      || !is_bool($jwt -> data -> TwoFactorAuth) || !is_bool($jwt -> data -> IsAdmin)
    ) {
      return false;
    }

    return true;
  }

  private function extractToken(Request $request): ?string {
    $header = $request->getHeaderLine('Authorization');

    if (empty($header)) {
      return null;
    }

    if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
      return null;
    }

    return $matches[1];
  }

  private function unauthorizedResponse(Response $response, string $code, string $desc): Response {
    $payload = json_encode([
      "error" => [
        "code" => $code,
        "desc" => $desc
      ]
    ]);

    $response->getBody()->write($payload);
    return $response
      ->withStatus(401)
      ->withHeader('Content-Type', 'application/json');
  }
}
