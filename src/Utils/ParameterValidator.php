<?php

namespace App\Utils;

define('STRICT_FIELD_VALIDATION', true);
define('LOOSE_FIELD_VALIDATION', false);
define('NULLIFY_MISSING_FIELDS', true);
define('IGNORE_MISSING_FIELDS', false);

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Clase centralizada para validación de parámetros
 */
class ParameterValidator {
  static function validate(Response $response, $template, $endpoint, $values, $strict = false, $nullifyMissing = true){
    if (!is_array($values) && !is_object($values)) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_JSON",
            "desc" => "Request body must be valid JSON"
          ]
        ])
      ];
    }

    $template = file_get_contents(ROOT."/src/Controllers/validators/$template.json");
    $template = $template ? @json_decode($template) : null;

    $validation = $template->$endpoint;

    if($strict === STRICT_FIELD_VALIDATION){
      foreach($values as $i => $v){
        if(!property_exists($validation, $i)){
          return (object)[
            "valid" => false,
            "response" => $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INVALID_UPDATE_KEY",
                "desc" => "Key '$i' present in the JSON is not supported"
              ]
            ])
          ];
        }
      }
    }

    foreach($validation as $i => $v){
      $fieldExists = isset($values[$i]);

      # Solo agregar null si nullifyMissing está activado
      if($nullifyMissing && !$fieldExists){
        $values[$i] = null;
      }

      # Controlo campos requeridos
      if($v->required && (!$fieldExists || is_null($values[$i]))){
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

      # Si el campo no existe no seguimos con el resto de validaciones
      if(!$fieldExists){
        continue;
      }

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

      // Solo agrega si debe nullificar
      if($nullifyMissing && !$fieldExists){
        $values[$i] = null;
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
        case 'float':
          $result = ParameterValidator::validateFloat($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'boolean':
          $result = ParameterValidator::validateBoolean($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'email':
          $result = ParameterValidator::validateEmail($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'object':
          $result = ParameterValidator::validateObject($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'date':
          $result = ParameterValidator::validateDate($response, $v, $i, $values[$i]);
          if (!$result->valid) return $result;
          $values[$i] = $result->value ?? $values[$i];
          break;
        case 'datetime':
          $result = ParameterValidator::validateDateTime($response, $v, $i, $values[$i]);
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
        $regex = substr($validation->regex, 1, strlen($validation->regex) - 1);
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter does not match regex ({$regex})"
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

  static function validateFloat(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_numeric($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be a numeric value"
          ]
        ])
      ];
    }

    if(!is_null($value)){
      $value = floatval($value);
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

    if(!is_null($value) && property_exists($validation, 'precision')){
      $value = round($value, $validation->precision);
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

  static function validateEmail(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL))){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "desc" => "$parameter must be a valid email"
          ]
        ])
      ];
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

  static function validateDate(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_string($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be a string in date format (YYYY-MM-DD)"
          ]
        ])
      ];
    }

    if(!is_null($value)){
      # Valida formato YYYY-MM-DD
      if(!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $value)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter must be a valid date in format YYYY-MM-DD"
            ]
          ])
        ];
      }

      # Valida que sea una fecha real
      $date = \DateTime::createFromFormat('Y-m-d', $value);
      if(!$date || $date->format('Y-m-d') !== $value){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter is not a valid date"
            ]
          ])
        ];
      }
    }

    return (object)["valid" => true, "value" => $value];
  }

  static function validateDateTime(Response $response, $validation, $parameter, $value){
    if(!is_null($value) && !is_string($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter must be a string in datetime format (YYYY-MM-DD HH:mm:ss)"
          ]
        ])
      ];
    }

    if(!is_null($value)){
      # Valida formato YYYY-MM-DD HH-mm-ss
      if(!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01]) ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $value)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter must be a valid datetime in format YYYY-MM-DD HH:mm:ss"
            ]
          ])
        ];
      }

      # Valida que sea una fecha real
      $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
      if(!$date || $date->format('Y-m-d H:i:s') !== $value){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "$parameter is not a valid datetime"
            ]
          ])
        ];
      }
    }

    return (object)["valid" => true, "value" => $value];
  }
}