<?php

namespace App\Utils;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Clase centralizada para validación de parámetros
 */
class ParameterValidator {
  static function validate(Response $response, $template, $endpoint, $values){
    $template = file_get_contents(ROOT."/src/Controllers/validators/$template.json");
    $template = $template ? @json_decode($template) : null;

    $validation = $template->$endpoint;
    foreach($validation as $i => $v){
      $values[$i] = $values[$i] ?? null; # Valores indefinidos los paso a null

      # Controlo campos requeridos
      if($v->required && is_null($values[$i])){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$i is required"
            ]
          ])
        ];
      }

      # Controlo segun tipo de campo
      switch($v->type){
        case 'string':
          $result = ParameterValidator::validateString($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'integer':
          $result = ParameterValidator::validateInteger($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'boolean':
          $result = ParameterValidator::validateBoolean($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'object':
          $result = ParameterValidator::validateObject($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
      }
    }
    return (object)["valid" => true, "values" => $values];
  }

  static function validateString(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_string($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be a string"
          ]
        ])
      ];
    }

    if(!is_null($value)){
      $value = trim($value);
    }

    if(!is_null($value) && property_exists($validation, 'regex')){
      if(!preg_match($validation->regex, $value)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter does not match regex ({$validation->regex})"
            ]
          ])
        ];
      }
    }

    if(!is_null($value) && property_exists($validation, 'minlength')){
      if(strlen($value) < $validation->minlength){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter is below the minimum length ({$validation->minlength})"
            ]
          ])
        ];
      }
    }

    if(!is_null($value) && property_exists($validation, 'maxlength')){
      if(strlen($value) > $validation->maxlength){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter exceeded the maximum length ({$validation->maxlength})"
            ]
          ])
        ];
      }
    }

    return (object)["valid" => true, "value" => $value];
  }

  static function validateInteger(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !preg_match('/^-?\d+$/', (string)$value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be an integer"
          ]
        ])
      ];
    }

    if(!is_null($value)){
      $value = intval($value);
    }

    if(!is_null($value) && property_exists($validation, 'min')){
      if($value < $validation->min){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter is below the minimum value ({$validation->min})"
            ]
          ])
        ];
      }
    }

    if(!is_null($value) && property_exists($validation, 'max')){
      if($value > $validation->max){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter exceeded the maximum value ({$validation->max})"
            ]
          ])
        ];
      }
    }
    return (object)["valid" => true, "value" => $value];
  }

  static function validateObject(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_object($value) && !is_array($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be an object or array"
          ]
        ])
      ];
    }
    return (object)["valid" => true, "value" => $value];
  }

  static function validateBoolean(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_bool($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be a boolean (true or false)"
          ]
        ])
      ];
    }
    return (object)["valid" => true, "value" => $value];
  }
}