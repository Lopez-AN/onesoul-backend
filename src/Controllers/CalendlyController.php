<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\CalendlyService;
use App\Models\User;
use DateTime;
use Firebase\JWT\JWT;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class CalendlyController{

  protected $calendly;
  protected $user;

  public function __construct(CalendlyService $calendly, User $user) {
    $this->calendly = $calendly;
    $this->user = $user;
  }

  /**
  * Inicia el flujo de autenticacion con Calendly, es lanzado por el frontend
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  * @return: redireccion al oAUTH de calendly
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

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    // Creo un State firmado + PKCE para mayor seguridad
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/','-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/','-_'), '=');

    $payload = [
      'uid'   => $jwt['data'] -> UserID,
      'exp'   => time()+600, // 5min expedicion
      'nonce' => bin2hex(random_bytes(8)),
      'redirect' => $redirect, // URL de redireccion (del frontend) luego de obtener los tokens
      'ver'   => $verifier
    ];
    $data = base64_encode(json_encode($payload));
    $mac  = hash_hmac('sha256', $data, $GLOBALS['config']['sig_secret']); // Firmo el payload con mi secret
    $state = rtrim(strtr(base64_encode($data.'.'.$mac), '+/','-_'), '=');

    // Envio al frontend un redirect para que se autentifique con calendly y autorize nuestra APP
    $url = sprintf(
      'https://auth.calendly.com/oauth/authorize?response_type=code&client_id=%s&redirect_uri=%s&state=%s',
      urlencode($GLOBALS['config']['calendly']['client_id']),
      urlencode($GLOBALS['config']['base_url']."/calendly/callback"),
      urlencode($state),
      urlencode($challenge)
    );
    return $response->withStatus(200)->withJson($url);
  }

  /**
  * Desvincula una cuenta de calendly de onesoul, desuscribe webhook y la quita de la base
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  **/
  public function disconnect(Request $request, Response $response, array $args) {
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }
    $userID = $jwt['data']->UserID;

    // Lo busco en la base
    try{
      $user = $this->calendly->getCalendlyUser($userID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CALENDLY_USER_NOT_FOUND",
            "desc" => "No se encontro el usuario de Calendly"
          ]
        ]);
      }
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }

    $accessToken = $user['AccessToken'];
    $refreshToken = $user['RefreshToken'];
    $tokenExpiresAt = $user['TokenExpiresAt'];
    $webhook = $user['Webhook'];
    $userUUID = $user['UserUUID'];
    $orgUUID = $user['OrgUUID'];

    $refreshToken = true; // Indica que se debe refrescar el accesstoken
    $deleteWebhook = false; // Indica que se debe solicitar la eliminacion del webhook

    // 1) Si accesstoken no expiro, lo pruebo
    if (time() < $tokenExpiresAt) {
      $headers = ["Authorization: Bearer ".$accessToken];
      $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);

      // Si se acepto el accesstoken, no se refresca y se procede a eliminar el webhook
      if($result->http_code == 200){
        $refreshToken = false;
        $deleteWebhook = true;
      }
    }

    // 2) Si el token expiro lo renuevo con el refresh token
    if($refreshToken){
      $clientId = $GLOBALS['config']['calendly']['client_id'];
      $secret = $GLOBALS['config']['calendly']['calendly_secret'];

      $auth = base64_encode($clientId.':'.$secret);
      $headers = [
        "Authorization: Basic $auth",
        "Content-Type: application/x-www-form-urlencoded"
      ];

      $postData = http_build_query([
        'grant_type'   => 'refresh_token',
        'refresh_token' => $user['RefreshToken']
      ]);

      $result = $this->calendly->calendlyRequest("POST", "auth", "/oauth/token", $headers, $postData);
      if($result->http_code == 200){
        $accessToken = $result->data->access_token;
        $deleteWebhook = true;
      }else{
        // Si tambien se rechaza el refresh token solo borro de la base, otros mensajes de error de la api lanzo error
        if(!in_array($result->http_code, [400,401])){
          return $response->withStatus(500)->withJson(["error" => $result->error]);
        }
      }
    }

    if($deleteWebhook){
      $headers = ["Authorization: Bearer ".$accessToken];
      $result = $this->calendly->calendlyRequest("DELETE", "api", "/webhook_subscriptions/$webhook", $headers);
      if($result->http_code == 404){ // Si dio 404 busco si hay algun webhook registrado con nosotros
        $url = sprintf("/webhook_subscriptions?scope=user&organization=%s&user=%s&count=100",
          urlencode("https://api.calendly.com/organizations/$orgUUID"),
          urlencode("https://api.calendly.com/users/$userUUID")
        );
        $result = $this->calendly->calendlyRequest("GET", "api", $url, $headers);
        if($result->http_code != 200){
          return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
        }

        $webhook = array_filter($result->data->collection, function($e){
          return strstr($e->callback_url,"onesoul.app") !== false;
        });
        // Si encontro un webhook apuntando a onesoul lo elimino
        if(!empty($webhook)){
          $webhook = basename($webhook[0]->uri);
          $result = $this->calendly->calendlyRequest("DELETE", "api", "/webhook_subscriptions/$webhook", $headers);
          if($result->http_code != 200){
            return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
          }
        }
      }else{
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
    }

    try{
      $this->calendly->deleteCalendlyUser($userUUID);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
    return $response->withJson("ok");
  }

  /**
  * Busca si un usuario esta vinculado con calendly
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  **/
  public function checkUser(Request $request, Response $response, array $args) {
    $userID = $args['id'];

    // Lo busco en la base
    try{
      $user = $this->calendly->getCalendlyUser($userID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CALENDLY_USER_NOT_FOUND",
            "desc" => "No se encontro el usuario de Calendly"
          ]
        ]);
      }
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }

    // 1) HEAD público (sin OAuth)
    $url = $user['SchedulingUrl'];
    $result = $this->calendly->httpHead($url);

    if ($result->http_code == 200) {
      return $response->withJson(["status" => "LINKED", "data" => [
        "UserUUID" => $user['UserUUID'],
        "Url"      => $url,
        "UserName" => $user['Slug'],
        "TimeZone" => $user['Timezone']
      ]]);
    }

    // Si hay redireccion busco aca tambien
    if (in_array($result->http_code, [301,302])) {
      $loc = $result->headers['Location'] ?? null;
      if ($loc) {
        $newSlug = basename($loc);
        try {
          $this->calendly->updateCalendlyUserData($user['UserUUID'], $newSlug, $loc, $user['Timezone']);
        } catch (\Throwable $e) {
          return $response->withStatus(500)->withJson([
            "error" => [
              "code" => "INTERNAL_SERVER_ERROR",
              "desc" => $e->getMessage()
            ]
          ]);
        }
        return $response->withJson(["status" => "LINKED", "redirect" => true, "data" => [
          "UserUUID" => $user['UserUUID'],
          "Url"      => $loc,
          "UserName" => $newSlug,
          "TimeZone" => $user['Timezone']
        ]]);
      }
    }

    // Si no lo encontro verifico si sus token funcionan
    // -----------------------------------------
    // 2) Si accesstoken no expiro, lo pruebo
    if (time() < $user['TokenExpiresAt']) {
      $headers = ["Authorization: Bearer ".$user['AccessToken']];

      $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);
      if($result->http_code == 200){
        // Veo si algun campo cambio y lo grabo
        if($user['Timezone'] == $result->data->resource->timezone ||
          $user['Slug'] == $result->data->resource->slug ||
          $user['SchedulingUrl'] == $result->data->resource->scheduling_url
        ){
          try {
            $this->calendly->updateCalendlyUserData(basename($result->data->resource->uri),
              $result->data->resource->slug, $result->data->resource->scheduling_url, $result->data->resource->timezone);
          } catch (\Throwable $e) {
            return $response->withStatus(500)->withJson([
              "error" => [
                "code" => "INTERNAL_SERVER_ERROR",
                "desc" => $e->getMessage()
              ]
            ]);
          }
        }

        return $response->withJson(["status" => "LINKED", "data" => [
          "UserUUID" => basename($result->data->resource->uri),
          "Url" => $result->data->resource->scheduling_url,
          "UserName" => $result->data->resource->slug,
          "TimeZone" => $result->data->resource->timezone
        ]]);
      }
    }

    // 3) Si accesstoken expiro o fallo pruebo el refresh token
    $clientId = $GLOBALS['config']['calendly']['client_id'];
    $secret = $GLOBALS['config']['calendly']['calendly_secret'];

    $auth = base64_encode($clientId.':'.$secret);
    $headers = [
      "Authorization: Basic $auth",
      "Content-Type: application/x-www-form-urlencoded"
    ];

    $postData = http_build_query([
      'grant_type'   => 'refresh_token',
      'refresh_token' => $user['RefreshToken']
    ]);

    $result = $this->calendly->calendlyRequest("POST", "auth", "/oauth/token", $headers, $postData);
    if(in_array($result->http_code, [400,401])){
      return $response->withJson(["status" => "UNLINKED"]);
    }
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }
    $accessToken = $result->data->access_token;
    $refreshToken = $result->data->refresh_token;
    $tokenExpiresAt = $result->data->created_at + $result->data->expires_in;

    // Grabo los nuevos tokens
    $this->calendly->updateCalendlyUserTokens(basename($result->data->owner),
      $accessToken, $refreshToken, $tokenExpiresAt);

    // Vuelvo a consultar el usuario con el nuevo token
    $headers = ["Authorization: Bearer ".$accessToken];
    $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    // Veo si algun campo cambio y lo grabo
    if($user['Timezone'] == $result->data->resource->timezone ||
      $user['Slug'] == $result->data->resource->slug ||
      $user['SchedulingUrl'] == $result->data->resource->scheduling_url
    ){
      try {
        $this->calendly->updateCalendlyUserData(basename($result->data->resource->uri),
          $result->data->resource->slug, $result->data->resource->scheduling_url, $result->data->resource->timezone);
      } catch (\Throwable $e) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "INTERNAL_SERVER_ERROR",
            "desc" => $e->getMessage()
          ]
        ]);
      }
    }

    return $response->withJson(["status" => "REFRESHED", "data" => [
      "UserUUID" => basename($result->data->resource->uri),
      "Url" => $result->data->resource->scheduling_url,
      "UserName" => $result->data->resource->slug,
      "TimeZone" => $result->data->resource->timezone
    ]]);
  }

  /**
  * Es llamado por Calendly luego que el usuario se autentica y hace las siguientes acciones:
  *  1) Obtiene los tokens del usuario en Calendly
  *  2) Obtiene los datos del usuario de Calendly, UUID, etc
  *  3) Genera un webhook para recibir las reservas del usuario
  * @param Request  $request   Objeto de la petición HTTP entrante (Slim\Http\Request).
  * @param Response $response  Objeto de la respuesta HTTP (Slim\Http\Response).
  * @param array    $args      Argumentos de la ruta definidos en el enrutador.
  * @return: redireccion al oAUTH de calendly
  **/
  public function callback(Request $request, Response $response, array $args) {
    $state = $_GET['state'] ?? ''; // Obtengo el state firmado
    $code = $_GET['code'] ?? ''; // Obtengo el code enviado por calendly

    if(empty($state) || empty($code)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    // Obtengo datos y firma
    [$dataB64, $macHex] = explode('.', base64_decode(strtr($state, '-_','+/')), 2);
    // Desencripto y verifico firma
    $calc = hash_hmac('sha256', $dataB64, $GLOBALS['config']['sig_secret']);

    // Error 401 si la firma es invalida
    if (!hash_equals($calc, $macHex)) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "EXPIRED_REQUEST",
          "desc" => "The payload has expired"
        ]
      ]);
    }

    // Decodifico el payload y verifico que no este caducado
    $payload = json_decode(base64_decode($dataB64));
    if (!$payload || time() > $payload->exp + 7200){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "EXPIRED_REQUEST",
          "desc" => "The payload has expired"
        ]
      ]);
    }

    $userID = $payload->uid; // ID del usuario onesoul
    $redirect = $payload->redirect; // url del frontend a donde debe redirigir luego de autenticar

    // 1) Solicito el access_token y refresh_token
    // --------------------------------------------
    $clientId = $GLOBALS['config']['calendly']['client_id'];
    $secret = $GLOBALS['config']['calendly']['calendly_secret'];

    $auth = base64_encode($clientId.':'.$secret);
    $headers = [
      "Authorization: Basic $auth",
      "Content-Type: application/x-www-form-urlencoded"
    ];

    $postData = http_build_query([
      'grant_type'   => 'authorization_code',
      'code'         => $code,
      'redirect_uri' => $GLOBALS['config']['base_url']."/calendly/callback"
    ]);

    $result = $this->calendly->calendlyRequest("POST", "auth", "/oauth/token", $headers, $postData);
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    // 2) Obtener los token del usuario
    // --------------------------------------------
    $accessToken = $result->data->access_token;
    $refreshToken = $result->data->refresh_token;
    $tokenExpiresAt = $result->data->created_at + $result->data->expires_in;

    $headers = [
      "Authorization: Bearer $accessToken"
    ];

    $result = $this->calendly->calendlyRequest("GET", "api", "/users/me", $headers);
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    $uri = $result->data->resource->uri;
    $orgUri = $result->data->resource->current_organization;
    $userUuid = basename($result->data->resource->uri);

    // Guardo el usuario calendly en la base
    try {
      $this->calendly->saveCalendlyUser($userID, $accessToken, $refreshToken, $tokenExpiresAt, $result->data->resource);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }

    // 3) Genero los webhook
    // --------------------------------------------
    $url = sprintf(
      "/webhook_subscriptions?scope=user&organization=%s&user=%s&count=100",
      urlencode($orgUri),
      urlencode($uri)
    );

    $result = $this->calendly->calendlyRequest("GET", "api", $url, $headers);
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    // Busco si ya tiene un webhook con onesoul. en ese caso no creo otro
    $webhook = array_filter($result->data->collection, function($e){
      return strstr($e->callback_url,"onesoul.app") !== false;
    });

    if(!empty($webhook)){
      // Guardo el webhook del usuario calendly en la base
      try {
        $webhook = basename($webhook[0]->uri);
        $this->calendly->saveCalendlyUserWebhook($userUuid, $webhook);
      } catch (\Throwable $e) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "INTERNAL_SERVER_ERROR",
            "desc" => $e->getMessage()
          ]
        ]);
      }
      return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json"
    ];

    $postData = [
      'url'           => $GLOBALS['config']['base_url']."/calendly/webhook",
      'events'        => ['invitee.created','invitee.canceled'],
      'organization'  => $orgUri,
      'user'          => $uri,
      'scope'         => 'user',
      'signing_key'   => $GLOBALS['config']['calendly']['calendly_webhook_sign']
    ];

    $result = $this->calendly->calendlyRequest("POST", "api", "/webhook_subscriptions", $headers, json_encode($postData));
    if($result->http_code != 200){
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }
    $webhook = basename($result->data->resource->uri);

    // Guardo el webhook del usuario calendly en la base
    try {
      $this->calendly->saveCalendlyUserWebhook($userUuid, $webhook);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }

    return $response->withHeader('Location', $redirect)->withStatus(302);
  }

  public function handleWebhook(Request $request, Response $response, $args) {
    $payload = (string)$request->getBody(); // Payload en bruto
    // Firma del payload
    $sig = $request->getHeaderLine('Calendly-Webhook-Signature');
    if (!$sig) {
      return $response->withStatus(400); // Sin firma no sigo
    }

    // Parseo t y v1
    $parts = [];
    foreach (explode(',', $sig) as $pair) {
      [$k, $v] = array_map('trim', explode('=', $pair, 2));
      $parts[$k] = $v;
    }
    $t  = $parts['t']  ?? null;
    $v1 = $parts['v1'] ?? null;
    if (!$t || !$v1) {
      return $response->withStatus(400); // firma invalida
    }

    // Tolerancia solo 5minutos
    if (abs(time() - (int)$t) > 300) {
      return $response->withStatus(400);
    }

    // Valido la firma
    $webhookSign = $GLOBALS['config']['calendly']['calendly_webhook_sign'];
    $signedPayload = $t . '.' . $payload; // Payload firmado
    if (!hash_equals(hash_hmac('sha256', $signedPayload, $webhookSign), $v1)) {
      return $response->withStatus(401); // invalid signature
    }

    $payload = json_decode($payload);
    if(empty($payload->payload->tracking->utm_content)){
      return $response->withStatus(400);
    }
    $utmContent = @json_decode(base64_decode($payload->payload->tracking->utm_content));
    if(!$utmContent){
      return $response->withStatus(400);
    }

    // Si todo esta bien proceso el payload
    try{
      if($payload->event == "invitee.created"){
        $this->calendly->inviteCreated($payload);
      }
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }

    return $response->withStatus(200);
  }
}
