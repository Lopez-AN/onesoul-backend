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
      switch($v->type){
        case 'string':
          return ParameterValidator::validateString($response, $validation->$i, $i, $values[$i] ?? null);
        case 'integer':
          return ParameterValidator::validateInteger($response, $validation->$i, $i, $values[$i] ?? null);
      }
    }
  }

  static function validateString(Response $response, $validation, $parameter, $value){
    if($validation->required && is_null($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter is required"
          ]
        ])
      ];
    }

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

    return (object)["valid" => true, "response" => null];
  }

  static function validateInteger(Response $response, $validation, $parameter, $value){
    if($validation->required && is_null($value)){
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "$parameter is required"
          ]
        ])
      ];
    }

    if(!is_null($value) && filter_var($value, FILTER_VALIDATE_INT) === false){
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

    return (object)["valid" => true, "response" => null];
  }
}