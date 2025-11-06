<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\CalModel;
use App\Models\User;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class CalController{

  protected $cal;
  protected $user;

  public function __construct(CalModel $cal, User $user) {
    $this->cal = $cal;
    $this->user = $user;
  }

  /**
  * Inicia el flujo de autenticacion con Cal.com, es lanzado por el frontend
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  * @return: redireccion al oAUTH de Cal.com
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
      'uid'   => $jwt->data -> UserID, // Lo uso para identificar al usuario
      'exp'   => time()+600, # 5 min expedicion
      'nonce' => bin2hex(random_bytes(8)),
      'redirect' => $redirect # URL de redireccion (del frontend) luego de obtener los tokens
    ];
    $data = base64_encode(json_encode($payload));
    $mac  = hash_hmac('sha256', $data, $GLOBALS['config']['sig_secret']); # Firmo el payload con mi secret
    $state = rtrim(strtr(base64_encode($data.'.'.$mac), '+/','-_'), '=');

    # Envio al frontend un redirect para que se autentifique con Cal.com y autorize nuestra APP
    $url = sprintf(
      'https://app.cal.com/auth/oauth2/authorize?client_id=%s&state=%s',
      urlencode($GLOBALS['config']['cal']['client_id']),
      urlencode($state)
    );
    return $response->withStatus(200)->withJson($url);
  }

  /**
  * Desvincula una cuenta de Cal.com de onesoul, desuscribe webhook y la quita de la base
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
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
            "desc" => "No se encontro el usuario de Cal.com"
          ]
        ]);
      }

      $accessToken = $user['AccessToken'];
      $refreshToken = $user['RefreshToken'];
      $webhook = $user['Webhook'];
      $calUserID = $user['CalUserID'];

      # ---- BORRADO WEBHOOK ----
      if($webhook !== null){ # Si tiene webhook registrado procedo
        $accessToken = $this -> _refreshCalUserToken($response, $refreshToken, $accessToken);
        # Si tengo un access token valido procedo a borrar el webhook
        if($accessToken){
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
  * Es llamado por Cal.com luego que el usuario se autentica y hace las siguientes acciones:
  *  1) Obtiene los tokens del usuario en Cal.com
  *  2) Obtiene los datos del usuario de Cal.com, UUID, etc
  *  3) Genera un webhook para recibir las reservas del usuario
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  * @return: redireccion al oAUTH de Cal.com
  **/
  public function callback(Request $request, Response $response, array $args) {
    $state = $_GET['state'] ?? ''; # Obtengo el state firmado
    $code = $_GET['code'] ?? ''; # Obtengo el code enviado por Cal.com

    if(empty($state) || empty($code)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Obtengo datos y firma
      [$dataB64, $macHex] = explode('.', base64_decode(strtr($state, '-_','+/')), 2);
      # Desencripto y verifico firma
      $calc = hash_hmac('sha256', $dataB64, $GLOBALS['config']['sig_secret']);

      # Error 401 si la firma es invalida
      if (!hash_equals($calc, $macHex)) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "EXPIRED_REQUEST",
            "desc" => "The payload has expired"
          ]
        ]);
      }

      # Decodifico el payload y verifico que no este caducado
      $payload = json_decode(base64_decode($dataB64));
      if (!$payload || time() > $payload->exp + 7200){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "EXPIRED_REQUEST",
            "desc" => "The payload has expired"
          ]
        ]);
      }
      $userID = $payload->uid; # ID del usuario onesoul
      $redirect = $payload->redirect; # url del frontend a donde debe redirigir luego de autenticar

      # 1) Obtener los token del usuario
      # --------------------------------------------
      $clientId = $GLOBALS['config']['cal']['client_id'];
      $secret = $GLOBALS['config']['cal']['secret'];

      $headers = [
        "Content-Type: application/x-www-form-urlencoded"
      ];

      $postData = http_build_query([
        'code'         => $code,
        'client_id'   => $clientId,
        'client_secret'   => $secret,
        'grant_type' =>  'authorization_code',
        'redirect_uri' => $GLOBALS['config']['base_url']."/cal/callback"
      ]);

      $result = $this->_calRequest($response, "POST", "app", "/api/auth/oauth/token", $headers, $postData);
      if(!$result->valid){
        return $result->response;
      }

      $refreshToken = $result->response->refresh_token;
      $accessToken = $this -> _refreshCalUserToken($response, $refreshToken);
      if(!$accessToken){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "CAL_INVALID_TOKEN",
            "desc" => "Invalid Cal.com refresh token"
          ]
        ]);
      }

      # 2) Obtener los datos del usuario
      # --------------------------------------------
      $headers = [
        "Authorization: Bearer $accessToken"
      ];

      $result = $this->_calRequest($response, "GET", "app", "/api/v2/me", $headers);
      if(!$result->valid){
        return $result->response;
      }
      $calUserID = $result->response->data->id;
      $timeZone = $result->response->data->timeZone;

      $result = $this->_calRequest($response, "GET", "api", "/v2/event-types", $headers);
      if(!$result->valid){
        return $result->response;
      }

      $bookerUrl = $result->response->data->eventTypeGroups[0]->bookerUrl;
      $slug = $result->response->data->eventTypeGroups[0]->profile->slug;
      $eventTypes = $result->response->data->eventTypeGroups[0]->eventTypes;

      $schedulingUrl = "$bookerUrl/$slug/30min";

      # Guardo el usuario cal.com en la base
      $this->cal->saveCalUser(
        $calUserID, $userID, $accessToken, $refreshToken, $slug, $schedulingUrl, $timeZone
      );

      # 3) Genero los webhook
      # --------------------------------------------
      # Consulto webhooks existentes
      $result = $this->_calRequest($response, "GET", "api", "/v2/webhooks", $headers);
      if(!$result->valid){
        return $result->response;
      }

      # Busco si ya tiene un webhook con onesoul en ese caso no creo otro
      $webhooks = array_values(array_filter($result->response->data, function($e){
        return strstr($e->subscriberUrl, "onesoul.app") !== false;
      }));
      if(!empty($webhooks)){
        $webhook = array_pop($webhooks);
        # Guardo el webhook del usuario Cal.com en la base
        $this->cal->saveCalUserWebhook($calUserID, $webhook->id);
        return $response->withHeader('Location', $redirect)->withStatus(302);
      }

      $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
      ];

      # Creo el webhook con nosotros si este no existe
      $result = $this->_calRequest($response, "POST", "api", "/v2/webhooks", $headers, json_encode([
        "active" => true,
        "subscriberUrl" => "https://api.onesoul.app/cal/webhook",
        "triggers" => [
          "BOOKING_CREATED",
          "BOOKING_RESCHEDULED",
          "BOOKING_CANCELLED"
        ],
        "secret" => $GLOBALS['config']['cal']['secret']
      ]));
      if(!$result->valid){
        return $result->response;
      }

      $webhook = $result->response->data;
      # Guardo el webhook en la base
      $this->cal->saveCalUserWebhook($calUserID, $webhook->id);
      return $response->withHeader('Location', $redirect)->withStatus(302);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }


  // /**
  // * Busca si un usuario esta vinculado con calendly
  // * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  // * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  // * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  // **/
  // public function checkUser(Request $request, Response $response, array $args) {
  //   $userID = $args['id'];

  //   # Lo busco en la base
  //   try{
  //     $user = $this->calendly->getCalendlyUser($userID);
  //     if(!$user){
  //       return $response->withStatus(404)->withJson([
  //         "error" => [
  //           "code" => "CALENDLY_USER_NOT_FOUND",
  //           "desc" => "No se encontro el usuario de Calendly"
  //         ]
  //       ]);
  //     }
  //   } catch (\Throwable $e) {
  //     return $response->withStatus(500)->withJson([
  //       "error" => [
  //         "code" => "INTERNAL_SERVER_ERROR",
  //         "desc" => $e->getMessage()
  //       ]
  //     ]);
  //   }

  //   # 1) HEAD público (sin OAuth)
  //   $url = $user['SchedulingUrl'];
  //   $result = $this->calendly->httpHead($url);

  //   if ($result->http_code == 200) {
  //     return $response->withJson(["status" => "LINKED", "data" => [
  //       "UserUUID" => $user['UserUUID'],
  //       "Url"      => $url,
  //       "UserName" => $user['Slug'],
  //       "TimeZone" => $user['Timezone']
  //     ]]);
  //   }

  //   # Si hay redireccion busco aca tambien
  //   if (in_array($result->http_code, [301,302])) {
  //     $loc = $result->headers['Location'] ?? null;
  //     if ($loc) {
  //       $newSlug = basename($loc);
  //       try {
  //         $this->calendly->updateCalendlyUserData($user['UserUUID'], $newSlug, $loc, $user['Timezone']);
  //       } catch (\Throwable $e) {
  //         return $response->withStatus(500)->withJson([
  //           "error" => [
  //             "code" => "INTERNAL_SERVER_ERROR",
  //             "desc" => $e->getMessage()
  //           ]
  //         ]);
  //       }
  //       return $response->withJson(["status" => "LINKED", "redirect" => true, "data" => [
  //         "UserUUID" => $user['UserUUID'],
  //         "Url"      => $loc,
  //         "UserName" => $newSlug,
  //         "TimeZone" => $user['Timezone']
  //       ]]);
  //     }
  //   }

  //   # Si no lo encontro verifico si sus token funcionan
  //   # -----------------------------------------
  //   # 2) Si accesstoken no expiro, lo pruebo
  //   if (time() < $user['TokenExpiresAt']) {
  //     $headers = ["Authorization: Bearer ".$user['AccessToken']];

  //     $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);
  //     if($result->http_code == 200){
  //       # Veo si algun campo cambio y lo grabo
  //       if($user['Timezone'] == $result->data->resource->timezone ||
  //         $user['Slug'] == $result->data->resource->slug ||
  //         $user['SchedulingUrl'] == $result->data->resource->scheduling_url
  //       ){
  //         try {
  //           $this->calendly->updateCalendlyUserData(basename($result->data->resource->uri),
  //             $result->data->resource->slug, $result->data->resource->scheduling_url, $result->data->resource->timezone);
  //         } catch (\Throwable $e) {
  //           return $response->withStatus(500)->withJson([
  //             "error" => [
  //               "code" => "INTERNAL_SERVER_ERROR",
  //               "desc" => $e->getMessage()
  //             ]
  //           ]);
  //         }
  //       }

  //       return $response->withJson(["status" => "LINKED", "data" => [
  //         "UserUUID" => basename($result->data->resource->uri),
  //         "Url" => $result->data->resource->scheduling_url,
  //         "UserName" => $result->data->resource->slug,
  //         "TimeZone" => $result->data->resource->timezone
  //       ]]);
  //     }
  //   }

  //   # 3) Si accesstoken expiro o fallo pruebo el refresh token
  //   $clientId = $GLOBALS['config']['calendly']['client_id'];
  //   $secret = $GLOBALS['config']['calendly']['calendly_secret'];

  //   $auth = base64_encode($clientId.':'.$secret);
  //   $headers = [
  //     "Authorization: Basic $auth",
  //     "Content-Type: application/x-www-form-urlencoded"
  //   ];

  //   $postData = http_build_query([
  //     'grant_type'   => 'refresh_token',
  //     'refresh_token' => $user['RefreshToken']
  //   ]);

  //   $result = $this->calendly->calendlyRequest("POST", "auth", "/oauth/token", $headers, $postData);
  //   if(in_array($result->http_code, [400,401])){
  //     return $response->withJson(["status" => "UNLINKED"]);
  //   }
  //   if($result->http_code != 200){
  //     return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
  //   }
  //   $accessToken = $result->data->access_token;
  //   $refreshToken = $result->data->refresh_token;
  //   $tokenExpiresAt = $result->data->created_at + $result->data->expires_in;

  //   # Grabo los nuevos tokens
  //   $this->calendly->updateCalendlyUserTokens(basename($result->data->owner),
  //     $accessToken, $refreshToken, $tokenExpiresAt);

  //   # Vuelvo a consultar el usuario con el nuevo token
  //   $headers = ["Authorization: Bearer ".$accessToken];
  //   $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);
  //   if($result->http_code != 200){
  //     return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
  //   }

  //   # Veo si algun campo cambio y lo grabo
  //   if($user['Timezone'] == $result->data->resource->timezone ||
  //     $user['Slug'] == $result->data->resource->slug ||
  //     $user['SchedulingUrl'] == $result->data->resource->scheduling_url
  //   ){
  //     try {
  //       $this->calendly->updateCalendlyUserData(basename($result->data->resource->uri),
  //         $result->data->resource->slug, $result->data->resource->scheduling_url, $result->data->resource->timezone);
  //     } catch (\Throwable $e) {
  //       return $response->withStatus(500)->withJson([
  //         "error" => [
  //           "code" => "INTERNAL_SERVER_ERROR",
  //           "desc" => $e->getMessage()
  //         ]
  //       ]);
  //     }
  //   }

  //   return $response->withJson(["status" => "REFRESHED", "data" => [
  //     "UserUUID" => basename($result->data->resource->uri),
  //     "Url" => $result->data->resource->scheduling_url,
  //     "UserName" => $result->data->resource->slug,
  //     "TimeZone" => $result->data->resource->timezone
  //   ]]);
  // }

  // public function handleWebhook(Request $request, Response $response, $args) {
  //   $payload = (string)$request->getBody(); # Payload en bruto
  //   # Firma del payload
  //   $sig = $request->getHeaderLine('Calendly-Webhook-Signature');
  //   if (!$sig) {
  //     return $response->withStatus(400); # Sin firma no sigo
  //   }

  //   # Parseo t y v1
  //   $parts = [];
  //   foreach (explode(',', $sig) as $pair) {
  //     [$k, $v] = array_map('trim', explode('=', $pair, 2));
  //     $parts[$k] = $v;
  //   }
  //   $t  = $parts['t']  ?? null;
  //   $v1 = $parts['v1'] ?? null;
  //   if (!$t || !$v1) {
  //     return $response->withStatus(400); # firma invalida
  //   }

  //   # Tolerancia solo 5minutos
  //   if (abs(time() - (int)$t) > 300) {
  //     return $response->withStatus(400);
  //   }

  //   # Valido la firma
  //   $webhookSign = $GLOBALS['config']['calendly']['calendly_webhook_sign'];
  //   $signedPayload = $t . '.' . $payload; # Payload firmado
  //   if (!hash_equals(hash_hmac('sha256', $signedPayload, $webhookSign), $v1)) {
  //     return $response->withStatus(401); # invalid signature
  //   }

  //   $payload = json_decode($payload);
  //   if(empty($payload->payload->tracking->utm_content)){
  //     return $response->withStatus(400);
  //   }
  //   $utmContent = @json_decode(base64_decode($payload->payload->tracking->utm_content));
  //   if(!$utmContent){
  //     return $response->withStatus(400);
  //   }

  //   # Si todo esta bien proceso el payload
  //   try{
  //     if($payload->event == "invitee.created"){
  //       $this->calendly->inviteCreated($payload);
  //     }
  //   } catch (\Throwable $e) {
  //     return $response->withStatus(500)->withJson([
  //       "error" => [
  //         "code" => "INTERNAL_SERVER_ERROR",
  //         "desc" => $e->getMessage()
  //       ]
  //     ]);
  //   }

  //   return $response->withStatus(200);
  //}


  /**
   *  Hace una request por cURL a calendly
   *  @param  method: metodo HTTP a utilizar GET | POST | PATCH ...
   *  @param  subdomain: subdominio de calendly, auth, api
   *  @param  headers: cabeceras de la consulta HTTP
   *  @param  postFields: body de la consulta HTTP (opcional)
   *  @return object: { http_code: 200, data: datos }
   *  @return object: { http_code: cod_http_error, error: objeto error }
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

    // capturar errores y status antes de cerrar
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno || ($httpCode >= 400 && $httpCode <= 599)) {
      return (object) [
        "valid" => false,
        "response" => $response->withStatus($httpCode)->withJson([
          "error" => [
            "code" => "CAL_API_ERROR",
            "desc" => $curlErrno
              ? "cURL error: $curlError"
              : "Calendly returned HTTP $httpCode",
            "cal_response" => $curlResp // opcional, útil para debug
          ]
        ])
      ];
    }

    return (object)["valid" => true, "response" => json_decode($curlResp)];
  }

  private function _refreshCalUserToken($response, $refreshToken, $accessToken = false){
    # Si se proporciono token se verifica su validez
    if($accessToken){
      # Extraigo la expiracion del access_token
      $tokenExpiresAt = json_decode(base64_decode(explode(".",$accessToken)[1])) -> exp;

      # 1) Si accesstoken no expiro, lo pruebo
      if (time() < $tokenExpiresAt) {
        $headers = [
          "Authorization: Bearer ".$accessToken
        ];
        $result = $this->_calRequest($response, "GET", "app", "/api/v2/me", $headers);
        if($result->valid){ # Si se acepto el token no hace falta refrescarlo
          return $accessToken;
        }
      }
    }

    # 2) Si el access token expiro o no se proporciono lo renuevo con el refresh token
    if(!$accessToken){
      $clientId = $GLOBALS['config']['cal']['client_id'];
      $secret = $GLOBALS['config']['cal']['secret'];

      $headers = [
        "Authorization: Bearer $refreshToken"
      ];

      $postData = http_build_query([
        'client_id'   => $clientId,
        'client_secret'   => $secret,
        'grant_type' =>  'refresh_token'
      ]);

      $result = $this->_calRequest($response, "POST", "app", "/api/auth/oauth/refreshToken", $headers, $postData);
      return $result->valid ? $result->response->access_token : false;
    }
  }
}
