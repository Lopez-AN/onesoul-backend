<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Cal;
use App\Models\User;
use App\Models\Offering;
use Predis\Client as RedisClient;
use App\Utils\ParameterValidator;

class CalController{

  protected $cal;
  protected $user;
  protected $offering;
  protected $redis;

  public function __construct(Cal $cal, User $user, Offering $offering, RedisClient $redisClient) {
    $this->cal = $cal;
    $this->user = $user;
    $this->offering = $offering;
    $this->redis = $redisClient;
  }

  /**
   * Inicia el flujo de autenticación OAuth 2.0 con Cal.com
   * Genera un estado firmado que será enviado al usuario para autenticarse en Cal.com
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con URL de redirección a Cal.com o error
   * @statusCode 200: éxito - URL de OAuth de Cal.com
   * @statusCode 400: parámetros inválidos (RedirectUrl faltante)
   **/
  public function connect(Request $request, Response $response, array $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $redirect = $data['RedirectUrl'] ?? '';

    if(empty($redirect)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $payload = [
      'uid'   => $jwt->data -> UserID, # Lo uso para identificar al usuario
      'exp'   => time()+600, # 5 min expedicion
      'nonce' => bin2hex(random_bytes(8)),
      'redirect' => $redirect # URL de redireccion (del frontend) luego de obtener los tokens
    ];
    $data = base64_encode(json_encode($payload));
    $mac  = hash_hmac('sha256', $data, $GLOBALS['config']['sig_secret']); # Firmo el payload con mi secret
    $state = rtrim(strtr(base64_encode($data.'.'.$mac), '+/','-_'), '=');

    # Envio al frontend un redirect para que se autentifique con Cal.com y autorize nuestra APP
    $url = sprintf('https://app.cal.com/auth/oauth2/authorize?client_id=%s&state=%s&redirect_uri=%s',
      urlencode($GLOBALS['config']['cal']['client_id']),
      urlencode($state),
      urlencode($GLOBALS['config']['base_url']."/cal/callback")
    );
    return $response->withStatus(200)->withJson($url);
  }

  /**
   * Desvincula una cuenta Cal.com de OneSoul
   * Elimina el webhook en Cal.com y borra la vinculación de la base de datos
   * @param Request $request: objeto de la petición HTTP entrante
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con estado de desvinculación o error
   * @statusCode 200: desvinculación exitosa
   * @statusCode 404: usuario Cal.com no encontrado
   * @statusCode 500: error en operación (Cal.com API o base de datos)
   **/
  public function disconnect(Request $request, Response $response, array $args) {
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Lo busco en la base
    try{
      $user = $this->cal->getCalUser($userID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CAL_USER_NOT_FOUND",
            "desc" => "No associated Cal.com profile found"
          ]
        ]);
      }

      $accessToken = $user['AccessToken'];
      $refreshToken = $user['RefreshToken'];
      $webhook = $user['Webhook'];
      $calUserID = $user['CalUserID'];

      # ---- BORRADO WEBHOOK ----
      if($webhook !== null){ # Si tiene webhook registrado procedo
        $result = $this -> _refreshCalUserToken($response, $refreshToken, $accessToken);
        # Si tengo un access token valido procedo a borrar el webhook
        if($result->valid){
          $accessToken = $result->access_token;
          $headers = [
            "Authorization: Bearer $accessToken"
          ];
          $result = $this->_calRequest($response, "DELETE", "api", "/v2/webhooks/$webhook", $headers);
        }
      }
      # Elimino el usuario de la base
      $this->cal->deleteCalUser($calUserID);
      return $response->withStatus(200)->withJson("Cal.com user deleted");
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
   * Callback de OAuth 2.0 - Se ejecuta después que el usuario se autentica en Cal.com
   * Realiza: 1) obtiene tokens, 2) obtiene datos del usuario, 3) crea webhook
   * @param Request $request: objeto de la petición HTTP (contiene 'state' y 'code' en query)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: redirección a URL de frontend (o onesoul.app como fallback)
   **/
  public function callback(Request $request, Response $response, array $args) {
    $state = $_GET['state'] ?? ''; # Obtengo el state firmado
    $code = $_GET['code'] ?? ''; # Obtengo el code enviado por Cal.com

    if(empty($state) || empty($code)){
      return $response->withHeader('Location', 'https://onesoul.app')->withStatus(302);
    }

    try {
      # Obtengo datos y firma
      [$dataB64, $macHex] = explode('.', base64_decode(strtr($state, '-_','+/')), 2);
      # Desencripto y verifico firma
      $calc = hash_hmac('sha256', $dataB64, $GLOBALS['config']['sig_secret']);

      # Decodifico el payload
      $payload = @json_decode(base64_decode($dataB64));
      $userID = $payload?->uid ?? null; # ID del usuario onesoul
      $redirect = $payload?->redirect ?? null; # url del frontend a donde debe redirigir luego de autenticar

      # Payload invalido
      if(!$payload || is_null($userID) || is_null($redirect)){
        return $response->withHeader('Location', 'https://onesoul.app')->withStatus(302);
      }

      # Error si la firma es invalida
      if (!hash_equals($calc, $macHex)) {
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # Error si el payload esta caducado
      if (!$payload || time() > $payload->exp + 7200){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # 1) Obtengo la info del usuario
      # --------------------------------------------
      $user = $this->user->getUserById($userID);
      if(!$user){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # 2) Obtener los token del usuario
      # --------------------------------------------
      $clientId = $GLOBALS['config']['cal']['client_id'];
      $secret = $GLOBALS['config']['cal']['secret'];

      $headers = [
        'Content-Type: application/json'
      ];

      $postData = json_encode([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $secret,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $GLOBALS['config']['base_url']."/cal/callback"
      ]);

      $result = $this->_calRequest($response, 'POST', 'api', '/v2/auth/oauth2/token', $headers, $postData);
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $accessToken = $result->response->access_token ?? null;
      $refreshToken = $result->response->refresh_token ?? null;
      if(empty($accessToken) || empty($refreshToken)){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # 3) Actualizar metadatos Cal.com
      # --------------------------------------------
      $result = $this -> _updateCalUser($response, $accessToken, $user);
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # 4) Chequear schedules y event-types
      # --------------------------------------------
      $result = $this -> _checkUserSchedule($response, $accessToken);
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $result = $this -> _checkUserEventTypes($response, $accessToken);
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      if(empty($result->response->data->eventTypeGroups) || empty($result->response->data->eventTypeGroups[0]->eventTypes)){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $calUserID = $result->response->data->eventTypeGroups[0]->eventTypes[0]->userId;
      $timeZone = $result->response->data->eventTypeGroups[0]->eventTypes[0]->owner->timeZone;
      $bookerUrl = $result->response->data->eventTypeGroups[0]->bookerUrl;
      $slug = $result->response->data->eventTypeGroups[0]->profile->slug;
      $eventTypes = $result->response->data->eventTypeGroups[0]->eventTypes;
      $schedulingUrl = "$bookerUrl/$slug";

      # Guardo el usuario cal.com en la base
      $this->cal->saveCalUser(
        $calUserID, $userID, $accessToken, $refreshToken, $slug, $schedulingUrl, $timeZone
      );

      # 5) Genero los webhook
      # --------------------------------------------

      $headers = [
        "Authorization: Bearer $accessToken"
      ];

      # Consulto webhooks existentes
      $result = $this->_calRequest($response, "GET", "api", "/v2/webhooks", $headers);
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      # Busco si ya tiene un webhook con onesoul en ese caso no creo otro
      $webhooks = array_values(array_filter($result->response->data, function($e){
        return strstr($e->subscriberUrl, $GLOBALS['config']['base_url']) !== false;
      }));
      if(!empty($webhooks)){
        $webhook = array_pop($webhooks);
        # Guardo el webhook del usuario Cal.com en la base
        $this->cal->updateCalUserWebhook($calUserID, $webhook->id);
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
      ];

      # Creo el webhook con nosotros si este no existe
      $result = $this->_calRequest($response, "POST", "api", "/v2/webhooks", $headers, json_encode([
        "active" => true,
        "subscriberUrl" => $GLOBALS['config']['base_url']."/cal/webhook",
        "triggers" => [
          "BOOKING_CREATED",
          "BOOKING_RESCHEDULED",
          "BOOKING_CANCELLED"
        ],
        "secret" => $GLOBALS['config']['cal']['secret']
      ]));
      if(!$result->valid){
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $webhook = $result->response->data;
      # Guardo el webhook en la base
      $this->cal->updateCalUserWebhook($calUserID, $webhook->id);
      return $response->withHeader('Location', $redirect)->withStatus(302);
    } catch (\Throwable $e) {
      return $response->withHeader('Location', $redirect)->withStatus(302);
    }
  }

  /**
   * Verifica el estado de vinculación de un usuario con Cal.com
   * Realiza múltiples intentos: HEAD request público, consulta API con OAuth
   * @param Request $request: objeto de la petición HTTP (parámetro 'id' = UserID)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con estado (LINKED, UNLINKED, REFRESHED, API_ERROR, NOT_FOUND)
   * @statusCode 200: información de estado devuelta en JSON
   **/
  public function checkUser(Request $request, Response $response, array $args) {
    $params['UserID'] = intval($args['UserID']);

    $pValidation = ParameterValidator::validate($response, 'cal','checkuser', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    # Lo busco en la base
    try{
      $user = $this->cal->getCalUser($params['UserID']);
      if(!$user){
        return $response->withJson(["Status" => "NOT_FOUND"]);
      }
      $calUserID = $user['CalUserID'];
      $slug = $user['Slug'];
      $timeZone = $user['TimeZone'];
      $schedulingUrl = $user['SchedulingUrl'];

      $accessToken = $user['AccessToken'];
      $refreshToken = $user['RefreshToken'];

      # 1) HEAD público (sin OAuth)
      $result = $this->_httpHead($schedulingUrl);
      if ($result->http_code === 200) {
        return $response->withJson(["Status" => "LINKED", "CalData" => [
          "CalUserID" => $calUserID,
          "Url"      => $schedulingUrl,
          "UserName" => $slug,
          "TimeZone" => $timeZone
        ]]);
      }

      # 2 Si hay redireccion busco en el destino tambien
      if (in_array($result->http_code, [301,302])) {
        $loc = $result->headers['Location'] ?? null;
        if ($loc) {
          $newSlug = basename($loc);
          $this->cal->updateCalUserData($calUserID, $newSlug, $loc, $timeZone);
          return $response->withJson(["Status" => "LINKED", "CalData" => [
            "CalUserID" => $calUserID,
            "Url"      => $loc,
            "UserName" => $newSlug,
            "TimeZone" => $timeZone
          ]]);
        }
      }

      # Si no lo encontro consultar al usuario con sus tokens
      # -----------------------------------------
      $result = $this -> _refreshCalUserToken($response, $refreshToken, $accessToken);
      # Si no se puede consultar al usuario por token lo doy de baja ya que esta inaccesible
      if(!$result->valid){
        if (($result->error_code ?? '') === 'CAL_TOKEN_REFRESH_IN_PROGRESS') {
          return $response->withJson(["Status" => "API_ERROR", "Error" => "Token refresh in progress"]);
        }
        $this->cal->deleteCalUser($calUserID);
        return $response->withJson(["Status" => "UNLINKED"]);
      }
      $accessToken = $result->access_token;

      $headers = [
        "Authorization: Bearer $accessToken"
      ];
      $result = $this->_calRequest($response, "GET", "api", "/v2/event-types", $headers);
      if(!$result->valid){
        return $response->withJson(["Status" => "API_ERROR", "Error" => "Cant retrieve the user info"]);
      }

      if(empty($result->response->data->eventTypeGroups) || empty($result->response->data->eventTypeGroups[0]->eventTypes)){
        return $response->withJson(["Status" => "API_ERROR", "Error" => "Cant retrieve the user schedule"]);
      }

      $bookerUrl = $result->response->data->eventTypeGroups[0]->bookerUrl;
      $newTimeZone = $result->response->data->eventTypeGroups[0]->eventTypes[0]->owner->timeZone;
      $newSlug = $result->response->data->eventTypeGroups[0]->profile->slug;
      $newSchedulingUrl = "$bookerUrl/$slug/30min";

      # Veo si algun campo cambio y lo grabo
      if($timeZone === $newTimeZone ||
        $slug === $newSlug ||
        $schedulingUrl === $newSchedulingUrl
      ){
        $this->cal->updateCalUserData($calUserID, $newSlug, $newSchedulingUrl, $newTimeZone);
      }

      return $response->withJson(["Status" => "REFRESHED", "CalData" => [
        "CalUserID" => $calUserID,
        "Url" => $newSchedulingUrl,
        "UserName" => $newSlug,
        "TimeZone" => $newTimeZone
      ]]);
    } catch (\Throwable $e) {
      return $response->withJson(["Status" => "API_ERROR", "Error" => $e->getMessage()]);
    }
  }

  /**
   * Obtiene un schedule por UUID
   * @param Request $request: objeto de la petición HTTP
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con los datos del schedule
   * @statusCode 200: información de estado devuelta en JSON
   * @statusCode 403: no autorizado a ver el schedule (no es el guia ni el buscador)
   * @statusCode 404: schedule no encontrado
   **/
  public function getScheduleByAssocUUID(Request $request, Response $response, array $args) {
    $params['AssocUUID'] = $args['AssocUUID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'cal','get_schedule_by_uuid', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try{
      $schedule = $this->cal->getScheduleByAssocUUID($params['AssocUUID']);
      if(!$schedule){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SCHEDULE_NOT_FOUND",
            "desc" => "No schedule was found with provided UUID"
          ]
        ]);
      }

      if($userID !== $schedule['SeekerID'] && $userID !== $schedule['GuideID']){
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "FORBIDDEN",
            "desc" => "You are not authorized to view this schedule."
          ]
        ]);
      }

      return $response->withJson($schedule);
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
   * Obtiene la disponibilidad de un guia
   * @param Request $request: objeto de la petición HTTP
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con los datos del schedule
   * @statusCode 200: información de estado devuelta en JSON
   * @statusCode 403: no autorizado a ver la disponibilidad
   * @statusCode 404: usuario no encontrado
   **/
  public function getAvailability(Request $request, Response $response, array $args) {
    $params['UserID'] = $args['UserID'];

    $pValidation = ParameterValidator::validate($response, 'cal','get_availability', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try{
      $result = $this->_getValidAccessTokenByUserID($response, $params['UserID']);
      if(!$result->valid){
        return $result->response;
      }
      $accessToken = $result->access_token;

      $result = $this -> _checkUserSchedule($response, $accessToken);
      if(!$result->valid){
        return $result->response;
      }

      $availability = [];
      foreach($result->response->availability as $v){
        foreach($v->days as $d){
          $availability[$d] = [
            "StartTime" => $v->startTime,
            "EndTime" => $v->endTime
          ];
        }
      }

      return $response->withJson(["Id" => $result->response->id, "Availability" => $availability]);
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
   * Modifica la disponibilidad de un guia
   * @param Request $request: objeto de la petición HTTP
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: JSON con los datos del schedule
   * @statusCode 200: información de estado devuelta en JSON
   * @statusCode 403: no autorizado a cambiar el schedule
   * @statusCode 404: schedule no encontrado
   **/
  public function updateAvailability(Request $request, Response $response, array $args) {
    $params = $request->getParsedBody();
    $params['UserID'] = $args['UserID'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'cal','update_availability',
      $params, STRICT_FIELD_VALIDATION, IGNORE_MISSING_FIELDS);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    foreach($params as $i => $v){
      if($i === 'UserID'){
        continue;
      }
      $pValidation = ParameterValidator::validate($response, 'cal','update_availability_day', $params[$i]);
      if(!$pValidation->valid){
        return $pValidation->response;
      }
      $params[$i] = $pValidation->values;
    }

    if($userID !== $params['UserID'] && !$jwt->data->IsAdmin){
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "FORBIDDEN",
          "desc" => "You are not authorized to change this guide availability."
        ]
      ]);
    }

    try{
      $result = $this->_getValidAccessTokenByUserID($response, $params['UserID']);
      if(!$result->valid){
        return $result->response;
      }
      $accessToken = $result->access_token;

      $schedule = $this -> _checkUserSchedule($response, $accessToken);
      if(!$schedule->valid){
        return $schedule->response;
      }
      $scheduleID = $schedule->response->id;

      unset($params['UserID']);
      $result = $this -> _updateUserSchedule($response, $accessToken, $scheduleID, $params);
      if(!$result->valid){
        return $result->response;
      }

      return $response->withJson($result->response);
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
   * Procesa webhooks recibidos desde Cal.com
   * Valida firma HMAC-SHA256, verifica duplicados y procesa eventos de reservas
   * Siempre responde 200 OK (incluso con errores) para evitar reintentos de Cal.com
   * @param Request $request: objeto de la petición HTTP (body contiene payload JSON)
   * @param Response $response: objeto de la respuesta HTTP
   * @param array $args: argumentos de la ruta definidos en el enrutador
   * @return Response: HTTP 200 OK (siempre)
   * @statusCode 200: webhook procesado o descartado
   * @statusCode 400: sin firma X-Cal-Signature-256
   * @statusCode 401: firma inválida
   * @statusCode 500: error no controlado
   **/
  public function handleWebhook(Request $request, Response $response, $args) {
    $payload = (string)$request->getBody(); # Payload en bruto
    $headers = $request->getHeaders();

    try{
      # Firma del payload
      $sig = $request->getHeaderLine('X-Cal-Signature-256');
      if (!$sig) {
        return $response->withStatus(400); # Sin firma no sigo
      }

      $secret = $GLOBALS['config']['cal']['secret'];
      $expectedSig = hash_hmac('sha256', $payload, $secret);

      if (!hash_equals($sig, $expectedSig)) {
        return $response->withStatus(401); # Firma inválida
      }

      # Verifico que no sea un reenvio repetido
      if ($this->redis->get("calwebhook:{$sig}")) {
        return $response->withStatus(200); # Ya fue procesado, ignorar
      }
      # Si es la primera vez lo guardo en el cache 2horas
      $this->redis->setex("calwebhook:{$sig}", 7200, '1');

      $payloadJson = json_decode($payload);
      switch($payloadJson->triggerEvent){
        case 'BOOKING_CREATED':
          # Los metadatos deben estar presentes y poder decodificarse
          $metadata = $payloadJson->payload?->metadata?->onesoul ?? null;
          $metadata = $metadata ? @json_decode(base64_decode($metadata)) : null;

          # Si no hay metadatos no procesar
          if (!$metadata || !is_int($metadata->SeekerID) || !is_int($metadata->OfferingID) || empty($metadata->AssocUUID)){
            return $response->withStatus(200);
          }

          # Busco el offering y extraigo el guia asociado
          $offering = $this->offering->getOfferingById($metadata->OfferingID);
          if(!$offering){
            return $response->withStatus(200);
          }
          $guideID = $offering['UserID'];

          # Busco al buscador y verifico que exista
          $user = $this->user->getUserById($metadata->SeekerID);
          if(!$user){
            return $response->withStatus(200);
          }

          $this->cal->bookingCreated($payloadJson->createdAt, $metadata->SeekerID,
            $guideID, $metadata->OfferingID, $metadata->AssocUUID, $payloadJson->payload);
        break;
      }
      return $response->withStatus(200);
    } catch (\Throwable $e) {
      return $response->withStatus(500);
    }
  }


  /**
   * Realiza una request a la API de Cal.com
   * Encapsula llamadas cURL con manejo de errores y respuestas JSON
   * @param Response $response: objeto de respuesta (para retornar errores)
   * @param string $method: método HTTP (GET, POST, PATCH, PUT, DELETE)
   * @param string $subdomain: subdominio de Cal.com (app, api, auth)
   * @param string $path: ruta de la API (ej: /v2/webhooks)
   * @param array $headers: array de headers HTTP personalizados
   * @param string ?$postData: body de la request (para POST/PATCH/PUT)
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _calRequest($response, $method, $subdomain, $path, $headers, $postData = null){
    $ch = curl_init("https://$subdomain.cal.com$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if(in_array($method, ["POST","PATCH","PUT"])){
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    $curlResp = curl_exec($ch);

    # capturar errores y status antes de cerrar
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlErrno || ($httpCode >= 400 && $httpCode <= 599)) {
      return (object) [
        "valid" => false,
        "response" => $response->withStatus($httpCode)->withJson([
          "error" => [
            "code" => "CAL_API_ERROR",
            "desc" => $curlErrno
              ? "cURL error: $curlError"
              : "Cal.com returned HTTP $httpCode",
            "cal_response" => json_decode($curlResp) ?: "" # opcional, útil para debug
          ]
        ])
      ];
    }

    return (object)["valid" => true, "response" => json_decode($curlResp)];
  }

  /**
   * Refresca el access token OAuth con Cal.com
   * Valida y renueva tokens cuando expiran usando refresh token
   * @param Response $response: objeto de respuesta (para cal request)
   * @param string $refreshToken: token para renovación
   * @param string|bool $accessToken: token actual (opcional, por defecto false)
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _refreshCalUserToken($response, $refreshToken, $accessToken = false){
    $calUserID = $this -> _getUserFromToken($refreshToken);
    if(!$calUserID){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(401)->withJson([
          "error" => [
            "code" => "CAL_TOKEN_REFRESH_FAILED",
            "desc" => "Invalid refresh token"
          ]
        ])
      ];
    }

    # Si se proporciono token se verifica su validez
    if($accessToken){
      # Extraigo la expiracion del access_token
      $tokenExpiresAt = json_decode(base64_decode(explode(".",$accessToken)[1])) -> exp;
      # 1) Si accesstoken no expiro, lo pruebo
      if (time() < $tokenExpiresAt) {
        $headers = [
          "Authorization: Bearer ".$accessToken
        ];
        $result = $this->_calRequest($response, "GET", "api", "/v2/me", $headers);
        if($result->valid){ # Si se acepto el token no hace falta refrescarlo
          return (object)[
            "valid" => true,
            "access_token" => $accessToken
          ];
        }
      }

      $accessToken = null;
    }

    # 2) Si el access token expiro o no se proporciono lo renuevo con el refresh token
    if(!$accessToken){
      $clientId = $GLOBALS['config']['cal']['client_id'];
      $secret = $GLOBALS['config']['cal']['secret'];

      # Evito condiciones de carrera: un solo refresh por usuario a la vez
      $lockKey = "cal:refresh_lock:$calUserID";
      $lockAcquired = $this->redis->set($lockKey, '1', 'EX', 20, 'NX') === 'OK';
      if (!$lockAcquired) {
        return (object)[
          'valid' => false,
          'error_code' => 'CAL_TOKEN_REFRESH_IN_PROGRESS',
          'response' => $response->withStatus(409)->withJson([
            'error' => [
              'code' => 'CAL_TOKEN_REFRESH_IN_PROGRESS',
              'desc' => 'Token refresh is already in progress for this user'
            ]
          ])
        ];
      }

      try {
        # Releo token desde base por si fue rotado en una request previa
        $calUser = $this->cal->getCalUserByCalID($calUserID);
        if ($calUser && !empty($calUser['RefreshToken'])) {
          $refreshToken = $calUser['RefreshToken'];
        }

        $headers = [
          'Content-Type: application/json'
        ];

        $postData = json_encode([
          'client_id' => $clientId,
          'client_secret' => $secret,
          'grant_type' => 'refresh_token',
          'refresh_token' => $refreshToken
        ]);

        $result = $this->_calRequest($response, 'POST', 'api', '/v2/auth/oauth2/token', $headers, $postData);
        if(!$result->valid){
          return $result;
        }

        # Grabo los nuevos tokens (si cal no rota refresh, conservo el actual)
        $newRefreshToken = $result->response->refresh_token ?? $refreshToken;
        $this->cal->updateCalUserTokens($calUserID, $result->response->access_token, $newRefreshToken);
        return (object)[
          "valid" => true,
          "access_token" => $result->response->access_token
        ];
      } finally {
        $this->redis->del([$lockKey]);
      }
    }
  }

  private function _getValidAccessTokenByUserID($response, $userID) {
    $user = $this->cal->getCalUser($userID);
    if(!$user){
      return (object)[
        'valid' => false,
        'response' => $response->withStatus(404)->withJson([
          'error' => [
            'code' => 'CAL_USER_NOT_FOUND',
            'desc' => 'No user associated with the specified id was found.'
          ]
        ])
      ];
    }

    return $this->_refreshCalUserToken($response, $user['RefreshToken'], $user['AccessToken']);
  }

  /**
   * Busca si el usuario tiene un schedule de OneSoul asignado y si no lo tiene le creo uno por defecto
   * @param Response $response: objeto de respuesta (para retornar errores)
   * @param string $accessToken: token para acceder a la API del usuario
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _checkUserSchedule($response, $accessToken){
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json",
      "cal-api-version: 2024-06-11"
    ];

    $result = $this->_calRequest($response, "GET", "api", "/v2/schedules", $headers);
    if(!$result->valid){
      return $result;
    }
    $schedules = $result;

    $exists = in_array('OneSoul', array_column($result->response->data, 'name'));
    # Si no existe lo creo
    if(!$exists){
      $result = $this->_calRequest($response, "POST", "api", "/v2/schedules", $headers, json_encode([
        "name" => "OneSoul",
        "timeZone" => "America/Argentina/Buenos_Aires",
        "isDefault" => true
      ]));
      if(!$result->valid){
        return $result;
      }

      $events = $this->_calRequest($response, "GET", "api", "/v2/event-types", $headers);
      if(!$result->valid){
        return $result;
      }
      $schedules = $result->response;
    }

    $availability = array_filter($schedules->response->data, function($e){
      return $e->name === "OneSoul";
    });

    if(empty($availability)){
      return (object) [
        "valid" => false,
        "response" => $response->withStatus(500)->withJson([
          "error" => [
            "code" => "CAL_SCHEDULE_ERROR",
            "desc" => "Error retrieving cal.com user schedule named 'OneSoul'"
          ]
        ])
      ];
    }

    # Control duplicaciones (no debe haber mas de un schedule onesoul)
    if(count($availability) > 1){
      for($x = 1; $x < count($availability); $x++){
        $this->_calRequest($response, "DELETE", "api", "/v2/schedules/{$availability[$x]->id}", $headers);
      }
    }

    return (object)["valid" => true, "response" => array_shift($availability)];
  }


  /**
   * Actualiza el schedule de OneSoul si no existe lo crea
   * @param Response $response: objeto de respuesta (para retornar errores)
   * @param string $accessToken: token para acceder a la API del usuario
   * @param integer $scheduleID: ID del schedule de cal
   * @param array[] $availability: array dias y horarios
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _updateUserSchedule($response, $accessToken, $scheduleID, $values){
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json",
      "cal-api-version: 2024-06-11"
    ];

    $values = array_map(function($e){
      return [
        "startTime" => $e['StartTime'],
        "endTime" => $e['EndTime']
      ];
    }, $values);

    $availability = [];
    foreach($values as $i => $v){
      $availability[] = (object)array_merge(["days" => [$i]] ,(array)$v);
    }

    $result = $this->_calRequest($response, "PATCH", "api", "/v2/schedules/$scheduleID", $headers, json_encode([
      "name" => "OneSoul",
      "timeZone" => "America/Argentina/Buenos_Aires",
      "isDefault" => true,
      "availability" => $availability
    ]));
    if(!$result->valid){
      return $result;
    }

    return (object)["valid" => true, "response" => $result->response];
  }

  /**
   * Busca si el usuario tiene los event-types de OneSoul asignado
   * @param Response $response: objeto de respuesta (para retornar errores)
   * @param string $accessToken: token para acceder a la API del usuario
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _checkUserEventTypes($response, $accessToken){
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json"
    ];

    $result = $this->_calRequest($response, "GET", "api", "/v2/event-types", $headers);
    if(!$result->valid){
      return $result;
    }
    $events = $result;

    # 30min
    $types = [[30, '30 minutos'], [60, '1 hora'], [90, '1 hora y media'],
      [120, '2 horas'], [150, '2 horas y media'], [180, '3 horas'], [210, '3 horas y media'],
      [240, '4 horas'], [270, '4 horas y media'], [300, '5 horas']];

    $eventCreated = false; # Indica si se creo un evento para volver a consultar la API
    foreach($types as $t){
      if(!empty($result->response->data->eventTypeGroups)){
        $slugs = array_column($result->response->data->eventTypeGroups[0]->eventTypes, 'slug');
      }
      if(empty($result->response->data->eventTypeGroups) || !in_array("onesoul{$t[0]}min", $slugs)){
        $result = $this->_calRequest($response, "POST", "api", "/v2/event-types", $headers, json_encode([
          "length" => $t[0],
          "title" => "Sesión OneSoul de {$t[1]}",
          "slug" => "onesoul{$t[0]}min",
          "bookingFields" => [
            [
                "name" => "name",
                "type" => "text",
                "label" => "Nombre de usuario",
                "required" => true,
                "editable" => "system",
                "disableOnPrefill" => true
            ],
            [
                "name" => "email",
                "type" => "email",
                "label" => "Email",
                "defaultLabel" => "email_address",
                "required" => true,
                "editable" => "system",
                "disableOnPrefill" => true
            ]
          ],
          "disableGuests" => true
        ]));
        if(!$result->valid){
          return $result;
        }
      }
      $eventCreated = true;
    }

    if($eventCreated){
      $result = $this->_calRequest($response, "GET", "api", "/v2/event-types", $headers);
      if(!$result->valid){
        return $result;
      }
      $events = $result->response;
    }

    return (object)["valid" => true, "response" => $events];
  }

  /**
   * Actualiza los datos del user de Cal.com
   * @param Response $response: objeto de respuesta (para retornar errores)
   * @param string $accessToken: token para acceder a la API del usuario
   * @param object $user: datos del usuario de OneSoul
   * @return object: {valid: bool, response: Response|object}
   **/
  private function _updateCalUser($response, $accessToken, $user){
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json"
    ];

    return $this->_calRequest($response, "PATCH", "api", "/v2/me", $headers, json_encode([
      "name" => $user->DisplayName ?? $user['UserName'],
      "timeFormat" => 24,
      "weekStart" => "Monday",
      "timeZone" => "America/Argentina/Buenos_Aires",
      "locale" => "es",
      "avatarUrl" => $user['ImgURL'],
      "bio" => $user['ShortDescription']
    ]));
  }

  /**
   * Obtiene el ID de usuario de Cal.com a partir de un token
   * @param string $url: URL destino
   * @return int|null: ID del usuario de Cal.com o null si hubo un error
   **/
  private function _getUserFromToken($token){
    [$_, $payload] = explode(".", $token);
    $r = json_decode(base64_decode($payload ?? ""));
    return $r->userId ?? null;
  }

  /**
   * Realiza un HEAD request a una URL sin seguir redirecciones
   * Útil para verificar disponibilidad y obtener headers sin descargar el body
   * @param string $url: URL destino
   * @return object: {http_code: int, headers: array}
   **/
  private function _httpHead($url) {
    $ch = curl_init();

    curl_setopt_array($ch, [
      CURLOPT_URL            => $url,
      CURLOPT_NOBODY         => true,   # HEAD en lugar de GET
      CURLOPT_FOLLOWLOCATION => false,  # no seguir 301/302
      CURLOPT_HEADER         => true,   # incluir headers en la respuesta
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 5,
    ]);

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $headers = [];
    if ($raw !== false) {
      $lines = explode("\r\n", $raw);
      foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
          [$k, $v] = explode(':', $line, 2);
          $headers[trim($k)] = trim($v);
        }
      }
    }

    return (object)[
      'http_code' => $code,
      'headers'   => $headers
    ];
  }
}
