<?php

namespace App\Controllers;

use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use App\Models\Auth;
use App\Models\Category;
use App\Models\Subscription;

require_once ROOT . '/src/Utils/validateReCaptcha.php';
require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');
require_once(ROOT . '/src/Utils/AWSRekognition.php');

class UserController{
  protected $user;
  protected $auth;
  protected $category;
  protected $subscription;

  public function __construct(User $user, Auth $auth, Category $category, Subscription $subscription){
    $this->user = $user;
    $this->auth = $auth;
    $this->category = $category;
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
    $jwt = $request->getAttribute('jwt');

    try {
      $users = $this->user->getUsers($paginator);
      $users->data = $this->_filterByScope($users->data, $jwt);
      return $response->withStatus(200)->withJson($users);
    } catch (Throwable $e) {
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
    $jwt = $request->getAttribute('jwt');

    try {
      $users = $this->user->getUsersByType($paginator, $type);
      $users->data = $this->_filterByScope($users->data, $jwt);
      return $response->withStatus(200)->withJson($users);
    } catch (Throwable $e) {
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
    $categoryID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    try {
      $users = $this->user->getUsersByCategory($paginator, $categoryID);
      $users->data = $this->_filterByScope($users->data, $jwt);
      return $response->withStatus(200)->withJson($users);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Busca guias usando un string
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  string ?query: texto a buscar
   * @return Response: JSON con usuarios o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function searchGuides(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();
    $query = $queryParams['query'] ?? '';

    try {
      $users = $this->user->searchGuides($paginator, $query);
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    try {
      $user = $this->user->getUserById($userID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }
      $user = $this->_filterByScope([$user], $jwt);
      return $response->withStatus(200)->withJson($user[0]);
    } catch (Throwable $e) {
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
    $jwt = $request->getAttribute('jwt');

    try {
      $user = $this->user->getUserByEmail($email);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified email account was found"
          ]
        ]);
      }
      $user = $this->_filterByScope([$user], $jwt);
      return $response->withStatus(200)->withJson($user[0]);
    } catch (Throwable $e) {
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
    $userName = $args['userName'];
    $jwt = $request->getAttribute('jwt');

    try {
      $user = $this->user->getUserByUserName($userName);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified username was found"
          ]
        ]);
      }
      $user = $this->_filterByScope([$user], $jwt);
      return $response->withStatus(200)->withJson($user[0]);
    } catch (Throwable $e) {
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
    $jwt = $request->getAttribute('jwt');

    try {
      $user = $this->user->getUserByRefCode($referralCode);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified referral code was found"
          ]
        ]);
      }
      $user = $this->_filterByScope([$user], $jwt);
      return $response->withStatus(200)->withJson($user[0]);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    try {
      $result = $this->user->latestConsentByUser($userID);

      if (!$result) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CONSENT_NOT_FOUND",
            "desc" => "No consent associated for this specified user."
          ]
        ]);
      }

      # Verificar si el usuario autenticado es el mismo o si es un administrador
      if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to view the consents of this user."
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($result);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to view the referrals of this user."
        ]
      ]);
    }

    try {
      $result = $this->user->referralsByUser($userID);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "REFERRED_USER_NOT_FOUND",
            "desc" => "There are no referred users associated with this specific user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($result);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to view the rewards of this user."
        ]
      ]);
    }

    try {
      $result = $this->user->rewardsByUser($userID);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "REWARDS_NOT_FOUND",
            "desc" => "There are no rewards associated with this specific user."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($result);
    } catch (Throwable $e) {
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

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $email = $data['Email'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $subDomain = $data['SubDomain'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    # Validación de parámetros
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    # Validar formato de subdominio (solo letras A-Z, a-z)
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
      $validation = validateReCaptcha($response, $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $userID = $jwt->data -> UserID;
      $user = $this->user->getUserById($userID);
      if (empty($user['ReferralCode'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "Could not retrieve referral code for the user"
          ]
        ]);
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
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user"
        ]
      ]);
    }

    $email = $data['Email'] ?? null;
    $biography = $data['Biography'] ?? null;
    $shortDescription = $data['ShortDescription'] ?? null;

    $user = $this->user->getUserById($userID);
    if (empty($user)) {
      return $response->withStatus(404)->withJson([
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user was found with the specified ID"
        ]
      ]);
    }

    # Lista de campos permitidos para actualizar
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

    # Filtrar y preparar los campos a actualizar
    $fields = [];
    foreach ($data AS $key => $value) {
      if (!in_array($key, $allowedFields)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_UPDATE_KEY",
            "desc" => "Key '$key' present in the JSON is not supported"
          ]
        ]);
      }
      $fields[] = "$key = :$key";
    }

    # Valido que no se repita el email
    if(!empty($email)){
      $user = $this->user->getUserByEmail($email);
      if($user && $user['UserID'] !== $userID){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_EMAIL",
            "desc" => "A user with the specified email already exists"
          ]
        ]);
      }
    }

    try {
      # Valida contenido con Perspective API
      if(!empty($biography) && $this->_containsInappropriateContent($biography)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      if (!empty($shortDescription) && $this->_containsInappropriateContent($shortDescription)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $user = $this->user->updateUser($userID, $user['Email'], $fields, $data);

      # --- Sincronizar con Stripe si corresponde ---
      $subscription = $this->subscription->getSubscriptionByUser($userID);
      if ($subscription && $subscription['PaymentPlatform'] === 'STRIPE' && in_array($subscription['Status'], ['ACTIVE', 'TRIALING'])) {
        try {
          \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);

          # Dirección
          $line1 = trim(($user['AddressName'] ?? '') . ' ' . ($user['AddressNumber'] ?? ''));
          $line2Parts = [];
          if (!empty($user['Floor'])) $line2Parts[] = "Piso " . $user['Floor'];
          if (!empty($user['Department'])) $line2Parts[] = "Depto " . $user['Department'];
          $line2 = !empty($line2Parts) ? implode(' - ', $line2Parts) : null;

          # Datos permitidos
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

          # Limpiar nulls para no borrar datos en Stripe
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
        } catch (Throwable $e) {
          # Loguear pero no romper la actualización del usuario en DB
          error_log("Error actualizando usuario en Stripe: " . $e->getMessage());
        }
      }

      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($user);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
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
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ]);
      }
      if ($user['DeactivationDate'] !== null) {
        return $response->withStatus(410)->withJson([
          "error" => [
            "code" => "USER_ALREADY_DISABLED",
            "desc" => "this user was disabled at ".$user['DeactivationDate']
          ]
        ]);
      }

      $this->user->disableUser($userID);
      return $response->withStatus(200)->withJson("User disabled");
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
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
      $uploadedFiles = $request->getUploadedFiles();
      $uploadedFile = $uploadedFiles['ProfilePhoto'] ?? null;

      if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "UPLOAD_ERROR",
            "desc" => "Cannot read the attached file"
          ]
        ]);
      }

      # Validar tipo de archivo
      $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
      $fileName = $uploadedFile->getClientFilename();
      $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

      if (!in_array($fileExtension, $allowedExtensions)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_FILE_TYPE",
            "desc" => "Only JPG, PNG, and GIF files are allowed"
          ]
        ]);
      }

      $imgID = uniqid();
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];

      # Crear directorio si no existe
      if (!is_dir($uploadDirectory . "/user")) {
        mkdir($uploadDirectory . "/user", 0755, true);
      }

      # Ruta temporal
      $tempFilePath = $uploadDirectory . "/user/" . $imgID . "." . $fileExtension;

      # Grabar archivo
      $uploadedFile->moveTo($tempFilePath);

      # Validar con Amazon Rekognition
      if(empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode']){
        $rekognitionResult = analyzeImageWithRekognition($tempFilePath);
        if ($rekognitionResult['error']) {
          if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
          }
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => $rekognitionResult['reason'] # CORREGIDO: era 'reASon'
            ]
          ]);
        }
      }

      # Optimizar imagen - retorna la ruta final (webp)
      $optimizedPath = optimizeImage($tempFilePath);

      # Si optimization falla, borrar archivo temporal
      if (!$optimizedPath || !file_exists($optimizedPath)) {
        if (file_exists($tempFilePath)) {
          unlink($tempFilePath);
        }
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "IMAGE_OPTIMIZATION_FAILED",
            "desc" => "Could not optimize the image"
          ]
        ]);
      }

      # Borrar archivo temporal si optimizeImage ya lo hace
      if (file_exists($tempFilePath) && $tempFilePath !== $optimizedPath) {
        unlink($tempFilePath);
      }

      # Generar URL - IMPORTANTE: debe coincidir con la ruta guardada
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/user/" . $imgID . ".webp";

      # Pasar los datos al modelo
      $updatedUser = $this->user->updateProfilePhoto($userID, $fileURL, $optimizedPath);
      if (!$updatedUser) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "FETCH_ERROR",
            "desc" => "Could not retrieve updated user"
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($updatedUser);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
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
      return $response->withStatus(200)->withJson($result);
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user's categories"
        ]
      ]);
    }

    $categories = $data['Categories'] ?? null;
    if (!is_array($categories)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_DATA",
          "desc" => "Categories should be an array"
        ]
      ]);
    }

    try {
      # Obtener todas las categorías válidas de una sola vez
      $validCategories = $this->category->getCategoriesByIds($categories);

      # Comparar cantidad: si no coinciden, hay IDs inválidos
      if (count($validCategories) !== count($categories)) {
        $validIds = array_column($validCategories, 'CategoryID');
        $invalidIds = array_diff($categories, $validIds);

        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_CATEGORIES",
            "desc" => "The following categories don't exist: " . implode(', ', $invalidIds)
          ]
        ]);
      }

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
    } catch (Throwable $e) {
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
    $userID = intval($args['id']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    if ($jwt->data->UserID !== $userID && !$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to modify this user's social accounts"
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

      # Normalizar URL
      $formattedUrl = $this->user->formatSocialUrl($name, $url);

      $newMap[$name] = [
        'typeID' => $validTypes[$name]['SocialAccountTypeID'],
        'url' => $formattedUrl
      ];
    }

    try {
      # Detectar cambios
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

    } catch (Throwable $e) {
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
    $userID = intval($args['id']);

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

      # Simplificar salida
      $accounts = [];
      foreach ($result as $acc) {
        $accounts[] = [
          "Nombre" => strtolower($acc['Name']),
          "URL"    => $acc['AccountName']
        ];
      }

      return $response->withStatus(200)->withJson($accounts);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Filtra los usuarios segun si es un admin, o el mismo usuario,
   * para mostrar o no campos privados o internos
   * @param  array $users: usuarios a filtrar
   * @param  object $jwt: token JWT decodificado
   * @return array: usuarios con campos filtrados
   **/
  private function _filterByScope($users, $jwt) {
    return array_map(function($e) use ($jwt){
      # Si no es admin estos campos internos no los muestro
      if(!$jwt || !$jwt->data->IsAdmin){
        unset($e['MfaSecret'],
        $e['OTPDate'],
        $e['OTPDate'],
        $e['OTPCode'],
        $e['OTPAttemps'],
        $e['FailedLoginAttempts']);
      }

      # Si no es admin ni el mismo user no muestro campos privados
      if(!$jwt || (!$jwt->data->IsAdmin && $jwt->data->UserID !== $e['UserID'])){
        unset($e['FirstName'],
        $e['LastName'],
        $e['Email'],
        $e['Phone'],
        $e['AddressName'],
        $e['AddressNumber'],
        $e['Floor'],
        $e['Department'],
        $e['Cp'],
        $e['DateOfBirth'],
        $e['Gender'],
        $e['ValidatedEmail'],
        $e['ValidatedPhone'],
        $e['TwoFactorAuth'],
        $e['RegistrationDate'],
        $e['LastLogin'],
        $e['DeactivationDate'],
        $e['SignedContract'],
        $e['LegalDocuments'],
        $e['LockedUntil'],
        $e['ReferralCode'],
        $e['Oauth2ID'],
        $e['Oauth2Service'],
        $e['IsAdmin']);
      }
      return $e;
    }, $users);
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
