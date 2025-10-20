<?php

/* ----------------------------------------------------
 * Middleware que permite incluir un token JWT opcional
 ----------------------------------------------------*/

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tuupola\Middleware\JwtAuthentication;

class OptionalJwtMiddleware implements MiddlewareInterface {
  private $jwtMiddleware;

  public function __construct(JwtAuthentication $jwtMiddleware) {
    $this->jwtMiddleware = $jwtMiddleware;
  }

  public function process(Request $request, RequestHandlerInterface $handler): Response {
    $token = $this->extractToken($request);

    // Si hay token, valídalo
    if ($token !== null) {
        return $this->jwtMiddleware->process($request, $handler);
    }

    // Si no hay token, continúa sin validar
    return $handler->handle($request);
  }

  private function extractToken(Request $request) {
    $header = $request->getHeaderLine('Authorization');

    if (empty($header)) {
      return null;
    }

    if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
      return null;
    }

    return $matches[1];
  }
}