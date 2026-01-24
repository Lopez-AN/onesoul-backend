<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Countries;
use Predis\Client as RedisClient;
use App\Utils\ParameterValidator;

class CountriesController {
  protected $countries;

  public function __construct(Countries $countries) {
    $this->countries = $countries;
  }

  /**
   * Obtiene los paises con sus estados
   *
   * Permite filtrar por un pais en particular
   *
   * @param Request $request Objeto de request HTTP
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta (Código pais ISO 3166-1 alpha-2 [opcional])
   * @return Response JSON con los datos de contacto recibidos
   *
   * @statusCode 200 Correcto
   * @statusCode 400 Parámetros inválidos
   * @statusCode 500 Error interno del servidor
   */
  public function getCountries(Request $request, Response $response, $args) {
    $params['CountryCode'] = $args['CountryCode'] ?? null;
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'countries','get_countries', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $countries = $this->countries->getCountries($params['CountryCode']);
      return $response->withStatus(200)->withJson($countries);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Obtiene los estados de un pais en particular
   *
   * Permite filtrar por un pais en particular
   *
   * @param Request $request Objeto de request HTTP
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta (Código pais ISO 3166-1 alpha-2)
   * @return Response JSON con los datos de contacto recibidos
   *
   * @statusCode 200 Correcto
   * @statusCode 400 Parámetros inválidos
   * @statusCode 500 Error interno del servidor
   */
  public function getStates(Request $request, Response $response, $args) {
    $params['CountryCode'] = $args['CountryCode'];
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'countries','get_states', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $states = $this->countries->getStates($params['CountryCode']);
      return $response->withStatus(200)->withJson($states);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}
