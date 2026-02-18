<?php

use Slim\App;
use App\Controllers\LandingController;
use App\Models\Landing;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Secret;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication(
    new Options(),
    new FirebaseDecoder(new Secret($GLOBALS['config']['jwt']['secret'], 'HS256'))
  );

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  $pdo = $app->getContainer()->get('pdo');

  $landing = new Landing($pdo);
  $landingController = new LandingController($landing);

  $app->get('/landing/contact', [$landingController, 'getContactInfo'])->add($requiredJwt);
  $app->post('/landing/contact', [$landingController, 'saveContactInfo']);
  $app->post('/landing/email', [$landingController, 'saveEmail']);
};
