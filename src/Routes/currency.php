<?php

use Slim\App;
use App\Controllers\CurrencyController;
use App\Models\Currency;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  $pdo = $app->getContainer()->get('pdo');
  $currency = new Currency($pdo);
  $currencyController = new CurrencyController($currency);

  $app->get('/currency/exchange/{Code}', [$currencyController, 'getCurrencyExchangeRates']);
  $app->get('/currency/country/{CountryCode}', [$currencyController, 'getCountryDefaultCurrency']);
};
