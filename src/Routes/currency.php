<?php

use Slim\App;
use App\Controllers\CurrencyController;
use App\Models\Currency;
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
  $currency = new Currency($pdo);
  $currencyController = new CurrencyController($currency);

  $app->get('/currency/exchange/{Code}', [$currencyController, 'getCurrencyExchangeRates']);
  $app->get('/currency/country/{CountryCode}', [$currencyController, 'getCountryDefaultCurrency']);
};
