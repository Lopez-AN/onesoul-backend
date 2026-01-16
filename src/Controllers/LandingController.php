<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Landing;
use Predis\Client as RedisClient;
use App\Utils\ParameterValidator;

require_once(ROOT.'/src/Utils/Paginator.php');

class LandingController {
  protected $landing;

  public function __construct(Landing $landing) {
    $this->landing = $landing;
  }

  /**
   * Obtiene las solicitudes de contanto de la landing page
   *
   * Permite filtrar por rango de fechas
   *
   * @param Request $request Objeto de request HTTP con query params opcionales (fromDate, toDate, limit)
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con los datos de contacto recibidos
   *
   * @statusCode 200 Datos de contacto obtenidas exitosamente
   * @statusCode 400 Parámetros inválidos
   * @statusCode 403 Usuario no autorizado
   * @statusCode 500 Error interno del servidor
   */
  public function getContactInfo(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to view contacts info."
        ]
      ]);
    }

    $params = [
      "FromDate" => $queryParams['fromDate'] ?? null, # Id de notificacion minimo
      "ToDate" => $queryParams['toDate'] ?? null, # Id de notificacion maximo
    ];

    $pValidation = ParameterValidator::validate($response, 'landing','get_contact_info', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      $categories = $this->landing->getContactInfo($paginator, $params['FromDate'], $params['ToDate']);
      return $response->withStatus(200)->withJson($categories);
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
   * Envía un formulario de contacto de la landing page
   *
   * @param Request $request Objeto de request HTTP con el mensaje en el body
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response string indicando operación exitosa, u objeto de error
   *
   * @statusCode 200 Datos recibidos correctamente y almacenados en DB
   * @statusCode 400 Parámetros inválidos
   * @statusCode 401 Error de recaptcha
   * @statusCode 500 Error del servidor
   */
  public function saveContactInfo(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();

    $pValidation = ParameterValidator::validate($response, 'landing','send_contact_info', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try{
      # Obtener información del navegador desde el encabezado User-Agent
      $userAgent = $request->getHeader('User-Agent')[0];
      $parser = new \WhichBrowser\Parser($userAgent);

      # Detalles del navegador y del dispositivo
      $browser = [
        "browser" => $parser->browser->getName(),
        "version" => $parser->browser->getVersion(),
        "os" => $parser->browser->getName(),
        "device" => $parser->device->type,
        "ip" => $clientIp
      ];

      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $this->landing->saveContactInfo($params, $browser);
      return $response->withJson("Contact info saved successfully");
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
