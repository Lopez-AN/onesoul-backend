<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Chatbot;
use Predis\Client as RedisClient;
use App\Utils\ParameterValidator;

require_once(ROOT.'/src/Utils/Paginator.php');

class ChatbotController {
  protected $chatbot;
  protected $redis;

  public function __construct(Chatbot $chatbot, RedisClient $redisClient) {
    $this->chatbot = $chatbot;
    $this->redis = $redisClient;
  }

  /**
   * Envía un mensaje al asistente virtual del chatbot
   *
   * @param Request $request Objeto de request HTTP con el mensaje en el body
   * @param Response $response Objeto de response HTTP
   * @param array $args Argumentos de ruta
   * @return Response JSON con la respuesta del chatbot o error
   *
   * @statusCode 200 Mensaje enviado exitosamente y respuesta del chatbot
   * @statusCode 400 Parámetros inválidos (mensaje faltante o inválido)
   * @statusCode 500 Error del servidor o error de comunicación con el chatbot
   */
  public function sendMessage(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();

    $pValidation = ParameterValidator::validate($response, 'chatbot','send_message', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try{
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $token = $this->_checkToken();
      if(!$token){
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "CHATBOT_TOKEN_ERROR",
            "desc" => "Error refreshing access token"
          ]
        ]);
      }

      $params = [
        'query' => $params['Message'],
        'context' => $GLOBALS['config']['chatbot']['context'],
        'provider' => $GLOBALS['config']['chatbot']['provider'],
        'model' => $GLOBALS['config']['chatbot']['model'],
        'temperature' => $GLOBALS['config']['chatbot']['temperature'],
        'max_tokens' => $GLOBALS['config']['chatbot']['max_tokens']
      ];

      $ch = curl_init($GLOBALS['config']['chatbot']['message_url']);
      curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => [
          'Content-Type: application/json',
          'Authorization: Bearer '.$token
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
      ]);
      $curlResp = curl_exec($ch);

      # capturar errores y status antes de cerrar
      $curlErrno = curl_errno($ch);
      $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      if ($curlErrno || $httpCode !== 200) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "CHATBOT_ERROR",
            "desc" => "Unable to connect to the virtual assistant."
          ]
        ]);
      }
      return $response->withJson($curlResp);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  private function _checkToken(){
    # Extraigo el token actual
    $token = $this->redis->get("chatbot:token") ?: null;

    if(!$token){
      return $this->_refreshToken();
    }

    # Verifico que el token tenga el formato correcto (3 partes)
    $parts = explode(".", $token);
    if(count($parts) !== 3){
      return $this->_refreshToken();
    }

    $payload = $parts[1];

    # Decodificar base64url
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $payload = base64_decode($payload);

    if(!$payload){
      return $this->_refreshToken();
    }

    $verify = json_decode($payload);

    if(!$verify || !isset($verify->exp)){
      return $this->_refreshToken();
    }

    # Debe refrescar si exp < tiempo actual (más margen)
    if($verify->exp < (time() + 30)){ # Token expira en menos de 30 segundos
      return $this->_refreshToken();
    }
    return $token;
  }

  private function _refreshToken(){
    $params = [
      'grant_type' => 'client_credentials',
      'client_id' => $GLOBALS['config']['chatbot']['client_id'],
      'client_secret' => $GLOBALS['config']['chatbot']['secret'],
      'scope' => $GLOBALS['config']['chatbot']['scope'],
    ];

    $ch = curl_init($GLOBALS['config']['chatbot']['token_url']);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => http_build_query($params),
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded'
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $curlResp = curl_exec($ch);

    # capturar errores y status antes de cerrar
    $curlErrno = curl_errno($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlErrno || $httpCode !== 200) {
      return $httpCode;
    }

    $json = json_decode($curlResp, true);
    $token = $json['access_token'] ?? false;

    if($token){
      $this->redis->setex("chatbot:token", 3600, $token);
    }

    return $token;
  }
}
