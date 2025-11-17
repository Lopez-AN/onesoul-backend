<?php

namespace App\Utils;

/**
 * Clase centralizada para validación de parámetros
 */
class ParameterValidator {
  # Patrones regex comunes
  private const PATTERNS = [
    'email'           => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
    'username'        => '/^[a-zA-Z0-9_-]{3,20}$/',
    'phone'           => '/^\+?[1-9]\d{1,14}$/',
    'url'             => '/^https?:\/\/.+\..+/',
    'subdomain'       => '/^[a-zA-Z]+$/',
    'numeric_id'      => '/^\d+$/',
    'uuid'            => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    'iso_date'        => '/^\d{4}-\d{2}-\d{2}$/',
    'slug'            => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
    'alphanumeric'    => '/^[a-zA-Z0-9]+$/',
    'alpha_only'      => '/^[a-zA-Z\s]+$/',
  ];

  /**
   * Valida un parámetro requerido
   *
   * @param mixed $value
   * @param string $fieldName Para mensajes de error
   * @param array $options ['type' => 'string|int|float|array', 'pattern' => 'email|phone|...']
   * @return array ['valid' => bool, 'error' => string|null]
   */
  public static function required($value, string $fieldName, array $options = []): array {
    # Verificar si existe
    if ($value === null || $value === '') {
      return [
        'valid' => false,
        'error' => "$fieldName is required"
      ];
    }

    # Validar tipo si se especifica
    if (isset($options['type'])) {
      $typeCheck = self::validateType($value, $options['type'], $fieldName);
      if (!$typeCheck['valid']) {
        return $typeCheck;
      }
    }

    # Validar patrón si se especifica
    if (isset($options['pattern'])) {
      $patternCheck = self::validatePattern($value, $options['pattern'], $fieldName);
      if (!$patternCheck['valid']) {
        return $patternCheck;
      }
    }

    # Validaciones adicionales
    if (isset($options['min_length']) && strlen($value) < $options['min_length']) {
      return [
        'valid' => false,
        'error' => "$fieldName must be at least {$options['min_length']} characters long"
      ];
    }

    if (isset($options['max_length']) && strlen($value) > $options['max_length']) {
      return [
        'valid' => false,
        'error' => "$fieldName must not exceed {$options['max_length']} characters"
      ];
    }

    if (isset($options['enum']) && !in_array($value, $options['enum'])) {
      return [
        'valid' => false,
        'error' => "$fieldName must be one of: " . implode(', ', $options['enum'])
      ];
    }

    return ['valid' => true];
  }

  /**
   * Valida un parámetro opcional
   */
  public static function optional($value, string $fieldName, array $options = []): array {
    if ($value === null || $value === '') {
      return ['valid' => true];
    }

    return self::required($value, $fieldName, $options);
  }

  /**
   * Valida el tipo de dato
   */
  private static function validateType($value, string $type, string $fieldName): array {
    $types = [
      'string'  => 'is_string',
      'int'     => 'is_int',
      'integer' => 'is_int',
      'float'   => 'is_float',
      'bool'    => 'is_bool',
      'array'   => 'is_array',
      'numeric' => 'is_numeric',
    ];

    if (!isset($types[$type])) {
      return ['valid' => false, 'error' => "Unknown type: $type"];
    }

    $validator = $types[$type];
    if (!$validator($value)) {
      return [
        'valid' => false,
        'error' => "$fieldName must be of type $type"
      ];
    }

    return ['valid' => true];
  }

  /**
   * Valida contra patrones regex predefinidos
   */
  private static function validatePattern($value, string $pattern, string $fieldName): array {
    if (!isset(self::PATTERNS[$pattern])) {
      return ['valid' => false, 'error' => "Unknown pattern: $pattern"];
    }

    if (!preg_match(self::PATTERNS[$pattern], $value)) {
      return [
        'valid' => false,
        'error' => "$fieldName has invalid format"
      ];
    }

    return ['valid' => true];
  }

  /**
   * Valida múltiples parámetros de una vez
   *
   * @param array $params Array asociativo con los parámetros
   * @param array $rules Array asociativo con las reglas de validación
   * @return array ['valid' => bool, 'errors' => array]
   */
  public static function validateAll(array $params, array $rules): array {
    $errors = [];

    foreach ($rules as $fieldName => $rule) {
      $value = $params[$fieldName] ?? null;
      $isRequired = $rule['required'] ?? true;

      $result = $isRequired
        ? self::required($value, $fieldName, $rule)
        : self::optional($value, $fieldName, $rule);

      if (!$result['valid']) {
        $errors[$fieldName] = $result['error'];
      }
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors
    ];
  }

  /**
   * Sanitiza un valor (elimina espacios, etc.)
   */
  public static function sanitize($value, string $type = 'string'): mixed {
    if ($value === null) {
      return null;
    }

    switch ($type) {
      case 'string':
        return trim($value);
      case 'email':
        return filter_var($value, FILTER_SANITIZE_EMAIL);
      case 'url':
        return filter_var($value, FILTER_SANITIZE_URL);
      case 'int':
        return (int)$value;
      case 'float':
        return (float)$value;
      default:
        return $value;
    }
  }

  /**
   * Valida un email correctamente
   */
  public static function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
  }

  /**
   * Valida una URL
   */
  public static function isValidUrl(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
  }
}