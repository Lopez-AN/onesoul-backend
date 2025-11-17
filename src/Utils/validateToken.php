<?php

/**
 * Valida un token externo (Google, Facebook, reCaptcha)
 *
 * Realiza una solicitud HTTP a la URL proporcionada y retorna
 * la respuesta decodificada en formato JSON.
 *
 * @param  string $url: URL con parámetros para validar el token
 * @return object|false: datos decodificados o false si falla
 *
 * @example
 * $result = validateToken('https://www.google.com/recaptcha/api/siteverify?secret=XXX&response=YYY');
 * if ($result) {
 *   echo $result->success; // true o false
 * }
 **/
function validateToken($url) {
  $ch = curl_init();

  # Configuración de cURL
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_errno($ch);

  curl_close($ch);

  # Verifica si hubo un error en la solicitud
  if ($error || $httpCode !== 200) {
    return false;
  }

  return json_decode($response);
}