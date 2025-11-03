<?php

require_once ROOT . '/src/Utils/validateToken.php';

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Valida un token reCaptcha con score mínimo
 *
 * Verifica la validez del token reCaptcha v3 contra Google y valida que el score
 * sea superior al mínimo configurado. Si la validación falla, retorna un objeto
 * con la respuesta HTTP correspondiente.
 *
 * @param  Response $response: objeto de response HTTP de Slim
 * @param  string $recaptchaToken: token de reCaptcha a validar
 * @param  string $clientIp: dirección IP del cliente
 * @return object: {valid: bool, response: Response|null}
 *
 * @example
 * $validation = validateReCaptcha($response, $token, $_SERVER['REMOTE_ADDR']);
 * if (!$validation->valid) {
 *   return $validation->response; // Retorna error HTTP 401
 * }
 * // Continuar con el proceso
 *
 * @note En modo debug retorna validación exitosa sin verificar Google
 **/
function validateReCaptcha($response, $recaptchaToken, $clientIp) {
  # Si está el modo debug no se valida
  if(!empty($GLOBALS['config']['debug_mode']) && $GLOBALS['config']['debug_mode']){
    return (object)["valid" => true, "response" => null];
  }

  $secret = $GLOBALS['config']['recaptcha']['secret'];
  $minScore = $GLOBALS['config']['recaptcha']['min_score'];
  $url = "https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$recaptchaToken&remoteip=$clientIp";

  # Hacer la petición a la API de reCAPTCHA
  $recaptcha = validateToken($url);

  if($recaptcha === false || empty($recaptcha->success)){
    return (object)[
      "valid" => false,
      "response" => $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_RECAPTCHA_TOKEN",
          "desc" => "Invalid reCaptcha token"
        ]
      ])
    ];
  }

  # Si el score es muy bajo
  if ($recaptcha->score < $minScore) {
    return (object)[
      "valid" => false,
      "response" => $response->withStatus(401)->withJson([
        "error" => [
          "code" => "RECAPTCHA_LOW_SCORE",
          "desc" => "reCaptcha score is too low"
        ]
      ])
    ];
  }

  # Validación exitosa
  return (object)["valid" => true, "response" => null];
}