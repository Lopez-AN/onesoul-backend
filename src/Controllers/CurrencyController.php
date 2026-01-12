<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Currency;
use App\Utils\ParameterValidator;

class CurrencyController {
  protected $currency;

  public function __construct(Currency $currency) {
    $this->currency = $currency;
  }

  /**
   * Obtiene las cotizaciones de una maneda base contra las demas
   * Si no se especifica una fecha (queryParam date=YYYYMMDD)
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (Code - Código de moneda base)
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getCurrencyExchangeRates(Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    $params['Code'] = $args['Code'];

    $pValidation = ParameterValidator::validate($response, 'currency','get_currency_exchange_rates', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $exchangeRates = $this->currency->getCurrencyExchangeRates($params);
      return $response->withStatus(200)->withJson($exchangeRates);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Obtiene la moneda que vamos a usar para un determinado pais
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (CountryCode - Código de pais)
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getCountryDefaultCurrency(Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    $params['CountryCode'] = $args['CountryCode'];

    $pValidation = ParameterValidator::validate($response, 'currency','get_country_default_currency', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $currency = $this->currency->getCountryDefaultCurrency($params['CountryCode']);
      return $response->withStatus(200)->withJson($currency);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

}
