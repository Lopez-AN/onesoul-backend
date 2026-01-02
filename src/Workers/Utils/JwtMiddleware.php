<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtMiddleware
{
  private $secret;

  public function __construct($secret)
  {
    $this->secret = $secret;
  }

  public function __invoke(Request $request, Response $response, callable $next): Response
  {
    $authHeader = $request->getHeaderLine('Authorization');
    if (!$authHeader) {
      $response->getBody()->write('Token not provided');
      return $response->withStatus(401);
    }

    list($jwt) = sscanf($authHeader, 'Bearer %s');

    if (!$jwt) {
      $response->getBody()->write('Token not provided');
      return $response->withStatus(401);
    }

    try {
      $decoded = JWT::decode($jwt, new Key($this->secret, 'HS256'));
      $request = $request->withAttribute('jwt', $decoded);
    } catch (\Exception $e) {
      $response->getBody()->write('Invalid token');
      return $response->withStatus(401);
    }

    return $next($request, $response);
  }
}
