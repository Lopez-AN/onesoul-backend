<?php

use Slim\App;
use App\Controllers\LandingController;
use App\Models\Landing;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  $pdo = $app->getContainer()->get('pdo');

  $landing = new Landing($pdo);
  $landingController = new LandingController($landing);

  $app->get('/landing/contact', [$landingController, 'getContactInfo'])->add($requiredJwt);
  $app->post('/landing/contact', [$landingController, 'saveContactInfo']);
  $app->post('/landing/email', [$landingController, 'saveEmail']);
};
