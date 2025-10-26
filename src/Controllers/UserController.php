<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use App\Models\Auth;
use App\Models\Subscription;

require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');
require_once(ROOT . '/src/Utils/AWSRekognition.php');

class UserController{
  protected $user;
  protected $auth;
  protected $subscription;

  public function __construct(User $user, Auth $auth, Subscription $subscription){
    $this->user = $user;
    $this->auth = $auth;
    $this->subscription = $subscription;
  }

  /**
   * Obtiene todos los usuarios con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getUsers(Request $request, Response $response, $args) {
    $paginator = paginator($request);

    try {
      $users = $this->user->getUsers($paginator);
      return $response->withStatus(200)->withJson($users);
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
   * Obtiene un usuario por ID
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con datos del usuario o error
   * @statusCode 200: éxito
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function getUserById(Request $request, Response $response, $args) {
    $id = $args['id'];

    try {
      $result = $this->user->getUserById($id);
      if(!$result){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($result);
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
   * Obtiene un usuario por email
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (email)
   * @return Response: JSON con datos del usuario o error
   * @statusCode 200: éxito
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function getUserByEmail(Request $request, Response $response, $args) {
    $email = $args['email'];
    try {
      $result = $this->user->getUserByEmail($email);
      if(!$result){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified email account was found"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($result);
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
   * Obtiene un usuario por username
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (username)
   * @return Response: JSON con datos del usuario o error
   * @statusCode 200: éxito
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function getUserByUserName(Request $request, Response $response, $args) {
    $username = $args['username'];
    try {
      $result = $this->user->getUserByUserName($username);
      if(!$result){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified username was found"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($result);
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
   * Obtiene usuarios por tipo con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (type)
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getUsersByType(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $type = $args['type'];

    try {
      $users = $this->user->getUsersByType($paginator, $type);
      return $response->withStatus(200)->withJson($users);
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
   * Obtiene usuarios por categoría con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id - categoryID)
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getUsersByCategory(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $categoryID = $args['id'];

    try {
      $result = $this->user->getUsersByCategory($paginator, $categoryID);
      return $response->withStatus(200)->withJson($result);
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
   * Obtiene un usuario por código de referencia
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (referralCode)
   * @return Response: JSON con datos del usuario o error
   * @statusCode 200: éxito
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function getUserByRefCode(Request $request, Response $response, $args) {
    $referralCode = $args['referralCode'];

    try {
      $result = $this->user->getUserByRefCode($referralCode);
      if(!$result){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified referral code was found"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($result);
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
   * Obtiene el consentimiento legal más reciente de un usuario
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id - userID)
   * @return Response: JSON con consentimiento o error
   * @statusCode 200: éxito
   * @statusCode 404: consentimiento no encontrado
   * @statusCode 500: error del servidor
   **/
  public function latestConsentByUser(Request $request, Response $response, $args) {
    $id = $args['id'];
    try {
      $result = $this->user->latestConsentByUser($id);

      if (!$result) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CONSENT_NOT_FOUND",
            "desc" => "No consent associated for this specified user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($result);
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
   * Obtiene los referidos de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con referidos o error
   * @statusCode 200: éxito
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: sin referidos
   * @statusCode 500: error del servidor
   **/
  public function referralsByUser(Request $request, Response $response, $args) {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt['data']->UserID != $id && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to view the referrals of this user."
        ]
      ]);
    }

    try {
      $result = $this->user->referralsByUser($id);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "REFERRED_USER_NOT_FOUND",
            "desc" => "There are no referred users associated with this specific user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($result);
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
   * Obtiene las recompensas de referencia de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con recompensas o error
   * @statusCode 200: éxito
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: sin recompensas
   * @statusCode 500: error del servidor
   **/
  public function rewardsByUser(Request $request, Response $response, $args) {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt['data']->UserID != $id && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to view the rewards of this user."
        ]
      ]);
    }

    try {
      $result = $this->user->rewardsByUser($id);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "REWARDS_NOT_FOUND",
            "desc" => "There are no rewards associated with this specific user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($result);
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
   * Envía un email de invitación con código de referencia
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con Email, RecaptchaToken, SubDomain)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: email enviado exitosamente
   * @statusCode 400: parámetros inválidos o subdominio inválido
   * @statusCode 401: JWT inválido o parámetros faltantes
   * @statusCode 500: error del servidor
   **/
  public function inviteByEmail(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $email = $data['Email'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $subDomain = $data['SubDomain'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    // Validación de parámetros
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($recaptchaToken)) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    // Validar formato de subdominio (solo letras A-Z, a-z)
    if (!empty($subDomain)) {
      if (!preg_match('/^[a-zA-Z]+$/', $subDomain)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SUBDOMAIN",
            "desc" => "Subdomain must contain only letters A-Z"
          ]
        ]);
      }
    }

    try {
      $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      $userID = $jwt['data'] -> UserID;
      $user = $this->user->getUserById($userID);
      if (empty($user['ReferralCode'])) {
        return [
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Could not retrieve referral code for the user"
          ]
        ];
      }

      $result = $this->user->inviteByEmail($user['UserName'], $user['ReferralCode'], $email, $subDomain);
      if (!$result) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "OTP_NOT_SENT",
            "desc" => "Cannot send the invite email, try again later"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson("Invite email sent successfully");

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
   * Actualiza datos de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con campos a actualizar)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con usuario actualizado o error
   * @statusCode 200: usuario actualizado
   * @statusCode 400: contenido inapropiado detectado
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function updateUser(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $data = $request->getParsedBody();

    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user"
        ]
      ]);
    }

    $user = $this->user->getUserById($userID);
    if (empty($user)) {
      return (object) [
        "http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user was found with the specified ID"
        ]
      ];
    }

    // Lista de campos permitidos para actualizar
    $allowedFields = [
      'FirstName',
      'LastName',
      'DisplayName',
      'Email',
      'Phone',
      'AddressName',
      'AddressNumber',
      'Floor',
      'Department',
      'Cp',
      'City',
      'State',
      'CountryCode',
      'DateOfBirth',
      'Gender',
      'Biography',
      'UserType',
      'SignedContract',
      'LegalDocuments',
      'ShortDescription'
    ];

    // Filtrar y preparar los campos a actualizar
    $fields = [];
    foreach ($data AS $key => $value) {
      if (!in_array($key, $allowedFields)) {
        return (object) [
          "http_code" => 400,
          "error" => [
            "code" => "INVALID_UPDATE_KEY",
            "desc" => "Key '$key' present in the JSON is not supported"
          ]
        ];
      }
      $fields[] = "$key = :$key";
    }

    try {
      // Valida contenido con Perspective API
      if(!empty($data['Biography']) && $this->_containsInappropriateContent($data['Biography'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      if (!empty($data['ShortDescription']) && $this->_containsInappropriateContent($data['ShortDescription'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $this->user->updateUser($userID, $fields, $data);
      // Devolver los datos actualizados del usuario
      $user = $this->user->getUserById($userID);

      # --- Sincronizar con Stripe si corresponde ---
      $subscription = $this->subscription->getSubscriptionByUser($userID);
      if ($subscription && $subscription['PaymentPlatform'] === 'STRIPE' && in_array($subscription['Status'], ['ACTIVE', 'TRIALING'])) {
        try {
          \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

          // Dirección
          $line1 = trim(($user['AddressName'] ?? '') . ' ' . ($user['AddressNumber'] ?? ''));
          $line2Parts = [];
          if (!empty($user['Floor'])) $line2Parts[] = "Piso " . $user['Floor'];
          if (!empty($user['Department'])) $line2Parts[] = "Depto " . $user['Department'];
          $line2 = !empty($line2Parts) ? implode(' - ', $line2Parts) : null;

          // Datos permitidos
          $updateData = [
            'name' => trim(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')),
            'email' => $user['Email'] ?? null,
            'phone' => $user['Phone'] ?? null,
            'address' => [
              'line1'       => !empty($line1) ? $line1 : null,
              'line2'       => $line2,
              'postal_code' => $user['Cp'] ?? null,
              'city'        => $user['City'] ?? null,
              'state'       => $user['State'] ?? null,
              'country'     => $user['CountryCode'] ?? null,
            ]
          ];

          // Limpiar nulls para no borrar datos en Stripe
          $updateData = array_filter($updateData, fn($v) => $v !== null && $v !== '');
          if (isset($updateData['address'])) {
            $updateData['address'] = array_filter($updateData['address'], fn($v) => $v !== null && $v !== '');
          }

          if (!empty($updateData)) {
            \Stripe\Customer::update(
              $subscription['PlatformCustomerID'],
              $updateData
            );
          }
        } catch (\Throwable $e) {
          // Loguear pero no romper la actualización del usuario en DB
          error_log("Error actualizando usuario en Stripe: " . $e->getMessage());
        }
      }

      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($user);
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
   * Desactiva un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: usuario desactivado
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function disableUser(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to disable this user"
        ]
      ]);
    }

    try {
      # Ver si estan las propiedades del token jwt
      $user = $this->user->getUserById($userID);
      if (empty($user)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }

      $this->user->disableUser($userID);
      return $response->withStatus(200)->withJson("User disabled");
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
   * Actualiza la foto de perfil de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT, archivo profilePhoto)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con usuario actualizado o error
   * @statusCode 200: foto actualizada
   * @statusCode 400: error de upload o contenido inapropiado
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor o FETCH_ERROR
   **/
  public function updateProfilePhoto(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user"
        ]
      ]);
    }

    $user = $this->user->getUserById($userID);
    if(!$user){
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified id was found"
        ]
      ]);
    }

    try {
      # Obtengo el archivo del body del request
      $uploadedFiles = $request->getUploadedFiles();
      $uploadedFile = $uploadedFiles['profilePhoto'] ?? null;

      if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "UPLOAD_ERROR",
            "desc" => "Cannot read the attached file"
          ]
        ]);
      }

      # Extraigo el nombre del archivo y su extension
      $fileName = $uploadedFile->getClientFilename();
      $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
      $imgID = uniqid(); #Le doy un ID unico a la imagen

      # Directorio destino
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];

      # El archivo destino se guarda con ID unico
      $filePath = $uploadDirectory . "/user/" . $imgID . "." . $fileExtension;

      #Grabo el archivo en el FS
      $uploadedFile->moveTo($filePath);

      // Validar con Amazon Rekognition
      if(empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode']){
        $rekognitionResult = analyzeImageWithRekognition($filePath);
        if ($rekognitionResult['error']) {
          unlink($filePath); // Borrar la imagen si es inapropiada
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => $rekognitionResult['reASon']
            ]
          ]);
        }
      }

      // Aquí optimizamos la imagen usando la función optimizeImage
      $optimizedPath = optimizeImage($filePath);
      unlink($filePath);
      $filePath = $optimizedPath;

      # Genero la URL del archivo
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/user/" . $imgID . ".webp";

      $this->user->updateProfilePhoto($userID, $fileURL, $filePath);
      # Retornar el usuario actualizado
      $updatedUser = $this->user->getUserById($userID);
      if (!$updatedUser) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "FETCH_ERROR",
            "desc" => "Could not retrieve updated user"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($updatedUser);
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
   * Elimina la foto de perfil de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: foto eliminada
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 404: foto no encontrada
   * @statusCode 500: error del servidor
   **/
  public function deleteProfilePhoto(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to delete this user's profile photo"
        ]
      ]);
    }

    try {
      $result = $this->user->deleteProfilePhoto($userID);
      if (!$result) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "PHOTO_NOT_FOUND",
            "desc" => "No profile photo found for this user"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson("Profile photo deleted");
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
   * Actualiza las categorías de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con array Categories)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con usuario actualizado o error
   * @statusCode 200: categorías actualizadas
   * @statusCode 400: datos inválidos
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 500: error del servidor
   **/
  public function updateUserCategories(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $jwt = $request->getAttribute('jwt');
    $data = $request->getParsedBody();
    $categories = $data['Categories'] ?? [];

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user's categories"
        ]
      ]);
    }

    if (!is_array($categories)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_DATA",
          "desc" => "Categories should be an array"
        ]
      ]);
    }

    try {
      # Obtener las categorías actuales del usuario
      $existingCategories = $this->user->getUserCategories($userID);
      $existingCategoryIds = array_column($existingCategories, 'CategoryID');

      # Categorías a agregar y eliminar
      $categoriesToAdd = array_diff($categories, $existingCategoryIds);
      $categoriesToDelete = array_diff($existingCategoryIds, $categories);

      # Agregar nuevas asociaciones
      if (!empty($categoriesToAdd)) {
        foreach ($categoriesToAdd as $categoryId) {
          $this->user->addUserCategory($userID, $categoryId);
        }
      }

      # Eliminar asociaciones que no están en el array enviado
      if (!empty($categoriesToDelete)) {
        foreach ($categoriesToDelete as $categoryId) {
          $this->user->deleteUserCategory($userID, $categoryId);
        }
      }

      $user = $this->user->getUserById($userID);
      return $response->withStatus(200)->withJson($user);
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
   * Actualiza las cuentas sociales de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con array de cuentas {Name, URL})
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con cuentas actualizadas o error
   * @statusCode 200: cuentas actualizadas
   * @statusCode 400: datos inválidos o red social no permitida
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos
   * @statusCode 500: error del servidor
   **/
  public function updateUserSocialAccounts(Request $request, Response $response, $args) {
    $userID = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to modify this user's social accounts"
        ]
      ]);
    }

    $data = $request->getParsedBody();
    if (!is_array($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_DATA",
          "desc" => "SocialAccounts should be an array"
        ]
      ]);
    }

    $currentAccounts = $this->user->getUserSocialAccounts($userID);
    $validTypes = $this->user->getActiveSocialAccountsTypes();

    $currentMap = [];
    foreach ($currentAccounts as $acc) {
        $currentMap[strtolower($acc['Name'])] = [
            'accountID' => $acc['SocialAccountID'],
            'typeID'    => $acc['SocialAccountTypeID'],
            'url'       => $acc['AccountName']
        ];
    }

    $newMap = [];
    foreach ($data as $item) {
      if (!isset($item['Name']) || !isset($item['URL'])) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_DATA",
            "desc" => "Each account must include 'Name' and 'URL'"
          ]
        ]);
      }

      $name = strtolower(trim($item['Name']));
      $url = trim($item['URL']);

      if (!array_key_exists($name, $validTypes)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_SOCIAL_ACCOUNT",
            "desc" => "Social account '$name' is not allowed or not active"
          ]
        ]);
      }

      // Normalizar URL
      $formattedUrl = $this->user->formatSocialUrl($name, $url);

      $newMap[$name] = [
        'typeID' => $validTypes[$name]['SocialAccountTypeID'],
        'url' => $formattedUrl
      ];
    }

    try {
      // Detectar cambios
      $toAdd = array_diff_key($newMap, $currentMap);
      $toRemove = array_diff_key($currentMap, $newMap);
      $toUpdate = [];

      foreach ($newMap as $name => $item) {
        if (isset($currentMap[$name]) && $currentMap[$name]['url'] !== $item['url']) {
          $toUpdate[$name] = $item;
        }
      }

      if (!empty($toRemove)) {
        foreach ($toRemove as $name => $item) {
          $this->user->deleteUserSocialAccount($userID, $item['typeID']);
        }
      }

      if (!empty($toAdd)) {
        foreach ($toAdd as $name => $item) {
          $this->user->addUserSocialAccount($userID, $item['typeID'], $item['url']);
        }
      }

      foreach ($toUpdate as $name => $item) {
        $this->user->updateUserSocialAccount($userID, $item['typeID'], $item['url']);
      }

      $result = $this->user->getUserSocialAccounts($userID);
      $accounts = [];
      foreach ($result as $acc) {
        $accounts[] = [
          'Name' => strtolower($acc['Name']),
          'URL'    => $acc['AccountName']
        ];
      }

      return $response->withStatus(200)->withJson($accounts);

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
   * Obtiene las cuentas sociales de un usuario
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con cuentas sociales o error
   * @statusCode 200: éxito
   * @statusCode 404: sin cuentas sociales
   * @statusCode 500: error del servidor
   **/
  public function getUserSocialAccounts(Request $request, Response $response, $args) {
    $userID = $args['id'];

    try {
      $result = $this->user->getUserSocialAccounts($userID);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "SOCIAL_ACCOUNTS_NOT_FOUND",
            "desc" => "No Social Accounts found for this specific user."
          ]
        ]);
      }

      // Simplificar salida
      $accounts = [];
      foreach ($result as $acc) {
        $accounts[] = [
          "Nombre" => strtolower($acc['Name']),
          "URL"    => $acc['AccountName']
        ];
      }

      return $response->withStatus(200)->withJson($accounts);
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
   * Valida si un texto contiene contenido inapropiado
   * @param  string $text: texto a validar
   * @return bool: true si contiene contenido inapropiado, false en caso contrario
   * @access private
   **/
  private function _containsInappropriateContent($text) {
    return validateContentWithPerspective($text);
  }
}
