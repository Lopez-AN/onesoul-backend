<?php

use Slim\App;
use App\Controllers\CountriesController;
use App\Models\Countries;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $pdo = $app->getContainer()->get('pdo');

  $countries = new Countries($pdo);
  $countriesController = new CountriesController($countries);

  $app->get('/countries', [$countriesController, 'getCountries']);
  $app->get('/countries/{CountryCode}', [$countriesController, 'getCountries']);
  $app->get('/countries/{CountryCode}/states', [$countriesController, 'getStates']);
};
