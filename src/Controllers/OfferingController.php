<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Currency;
use Firebase\JWT\JWT;
use App\Enums\MediaType;
use App\Utils\ParameterValidator;

require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');
require_once(ROOT . '/src/Utils/AWSRekognition.php');

define("MAX_IMAGES", 8);
define("MAX_VIDEOS", 3);
define("MAX_IMAGE_SIZE", 5 * 1024 * 1024);
define("MAX_VIDEO_SIZE", 50 * 1024 * 1024);

class OfferingController {
  protected $offering;
  protected $user;
  protected $subscription;
  protected $currency;

  public function __construct(Offering $offering, User $user, Subscription $subscription, Currency $currency)  {
    $this->offering = $offering;
    $this->user = $user;
    $this->subscription = $subscription;
    $this->currency = $currency;
  }

  /**
   * Obtiene todas las publicaciones con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con publicaciones o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getOfferings(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    try {
      $result = $this->offering->getOfferings($paginator);
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
   * Busca publicaciones por término de búsqueda
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  string ?query: texto a buscar
   * @return Response: JSON con publicaciones o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function searchOfferings(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();
    $query = $queryParams['query'] ?? '';

    try {
      $offerings = $this->offering->searchOfferings($paginator, $query);
      return $response->withStatus(200)->withJson($offerings);
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
   * Obtiene una publicación por ID
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con datos de la publicación o error
   * @statusCode 200: éxito
   * @statusCode 400: ID de publicación inválido
   * @statusCode 500: error del servidor
   **/
  public function getOfferingById(Request $request, Response $response, $args)  {
    $id = intval($args['OfferingID']);
    try {
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(400)->WithJson([
          "error" => [
            "code" => "INVALID_OFFERING",
            "desc"=> "Provided OfferingID is not valid."
          ]
        ]);
      }

      return $response->withStatus(200)->withJson($offering);
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
   * Obtiene publicaciones por categoría con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (categoryID)
   * @return Response: JSON con publicaciones o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getOfferingsByCategory(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $categoryId = intval($args['CategoryID']);
    try {
      $result = $this->offering->getOfferingsByCategory($paginator, $categoryId);
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
   * Obtiene publicaciones por usuario con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (userID)
   * @return Response: JSON con publicaciones o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getOfferingsByUserId(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $userID = intval($args['UserID']);
    try {
      $result = $this->offering->getOfferingsByUserId($paginator, $userID);
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
   * Crea una nueva publicación (solo para usuarios Guide)
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con campos de publicación)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con publicación creada o error
   * @statusCode 200: publicación creada exitosamente
   * @statusCode 400: parámetros inválidos o contenido inapropiado
   * @statusCode 403: usuario sin permisos (no es Guide)
   * @statusCode 500: error del servidor
   **/
  public function createOffering(Request $request, Response $response, $args)  {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    $pValidation = ParameterValidator::validate($response, 'offerings','create_offering', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    foreach($params['Faqs'] as $i => $faq){
      $pValidation = ParameterValidator::validate($response, 'offerings','offering_faqs', $faq);
      if(!$pValidation->valid){
        return $pValidation->response;
      }
      $params['Faqs'][$i] = $pValidation->values;
    }

    # Verificar si el usuario autenticado es un Guia o un administrador
    if ($jwt->data->UserType !== 'Guide') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to create offerings."
        ]
      ]);
    }

    $params['UserID'] = $userID;
    # Campos hardcodeados por ahora
    $params['SKU'] = null;
    $params['Stock'] = null;
    $params['ServiceType'] = 'Service';

    try {
      $guide = $this->user->getUserById($userID);
      if(!$guide){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified Id."
          ]
        ]);
      }

      $location = $this->user->getUserLocation($userID, $params['LocationID']);
      if(!$location){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_LOCATION_NOT_FOUND",
            "desc" => "Cannot retrieve a guide location with provided ID."
          ]
        ]);
      }

      # Valido si el usuario puede crear publicaciones y no supero el limite
      $pubMax = $this->subscription->getUserSubscriptionFeature($userID, 'PUB_MAX');
      if(!$pubMax){
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "SUBSCRIPTION_NEEDED",
            "desc" => "A subscription is needed to publish."
          ]
        ]);
      }
      if($pubMax->Value !== null && $this->offering->countActiveOfferings($userID) >= $pubMax->Value){
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "HIGHER_PLAN_NEEDED",
            "desc" => "You need a higher subscription to publish more."
          ]
        ]);
      }

      # Validación de contenido inapropiado
      $contentToCheck = implode(" ", [
        $params['Title'] ?? '',
        $params['Description'] ?? '',
        $params['ShortDescription'] ?? '',
        $params['Conditions'] ?? '',
        implode(" ", $params['Tags']),
        implode(" ", array_map(function($e){
          return $e['Question']." ".$e['Answer'];
        }, $params['Faqs'] ?? []))
      ]);

      if($this->_containsInappropriateContent($contentToCheck)){
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $offering = $this->offering->createOffering($params);
      return $response->withStatus(200)->withJson($offering);
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
   * Aprueba una publicación (solo administradores)
   * @param  Request $request: objeto de request HTTP (requiere JWT admin)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: publicación aprobada exitosamente
   * @statusCode 400: publicación eliminada
   * @statusCode 403: usuario sin permisos (no es admin)
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function approveOffering(Request $request, Response $response, $args)  {
    $id = intval($args['OfferingID']);
    $jwt = $request->getAttribute('jwt');

    # Verificar que el usuario sea admin
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to approve this offering"
        ]
      ]);
    }

    try {
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      # Verificar que el offering este pendiente
      if ($offering['Status'] !== 'Active') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_ACTIVE",
            "desc" => "The specified offering must have active status"
          ]
        ]);
      }

      # Aprobar el offering
      $offering = $this->offering->approveOfferingById($id);
      return $response->withStatus(200)->withJson($offering);
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
   * Actualiza una publicación existente
   * @param  Request $request: objeto de request HTTP (requiere JWT, body con campos a actualizar)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con publicación actualizada o error
   * @statusCode 200: publicación actualizada exitosamente
   * @statusCode 400: parámetros inválidos, contenido inapropiado o publicación eliminada
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function updateOffering(Request $request, Response $response, $args)  {
    $params['OfferingID'] = intval($args['OfferingID']);
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

    if (empty($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "NO_FIELDS_TO_UPDATE",
          "desc" => "No valid fields to update"
        ]
      ]);
    }

    # Control de campos que se permiten actualizar
    $allowedFields = [
      'Title',
      'ShortDescription',
      'Description',
      'CategoryID',
      'Tags',
      'Stock',
      'Currency',
      'ServiceType',
      'SKU',
      'Price',
      'SessionType',
      'Conditions',
      'Duration',
      'Faqs'
    ];

    foreach ($data as $key => $value) {
      if (!in_array($key, $allowedFields)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_UPDATE_KEY",
            "desc" => "Key '$key' is not allowed to be updated"
          ]
        ]);
      }
    }

    if(!empty($data['Faqs'])){
      if(!is_array($data['Faqs'])){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "Parameters are missing or invalid"
          ]
        ]);
      }
      foreach($data['Faqs'] as $f){
        if(!isset($f['Position'], $f['Question'], $f['Answer'])){
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "Parameters are missing or invalid"
            ]
          ]);
        }
      }
    }

    try {
      # Valido que el offering exista, no este eliminado y el usuario tenga acceso
      $validation = $this -> _validateOffering($response, $params['OfferingID'], $jwt);
      if(!$validation->valid){
        return $validation->response;
      }
      $offering = $validation->response;

      # Validación de contenido inapropiado
      $contentToCheck = implode(" ", [
        $data['Title'] ?? '',
        $data['Description'] ?? '',
        $data['ShortDescription'] ?? '',
        $data['Conditions'] ?? '',
        implode(" ", $data['Tags'] ?? []),
        implode(" ", array_map(function($e){
          return $e['Question']." ".$e['Answer'];
        }, $data['Faqs'] ?? []))
      ]);

      if($this->_containsInappropriateContent($contentToCheck)){
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      # Actualizar la oferta
      $offering = $this->offering->updateOffering($params['OfferingID'], $data);
      return $response->withStatus(200)->withJson($offering);
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
   * Habilita una publicación
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (OfferingID)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: publicación eliminada exitosamente
   * @statusCode 400: la publicación esta eliminada o parametros incorrectos
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function enableOffering(Request $request, Response $response, $args)  {
    $params['OfferingID'] = $args['OfferingID'];
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'offerings','enable_offering', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valido que el offering exista, no este eliminado y el usuario tenga acceso
      $validation = $this -> _validateOffering($response, $params['OfferingID'], $jwt);
      if(!$validation->valid){
        return $validation->response;
      }
      $offering = $validation->response;

      $offering = $this->offering->enableOffering($params['OfferingID']);
      return $response->withStatus(200)->withJson($offering);
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
   * Deshabilita una publicación
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (OfferingID)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: publicación deshabilitada exitosamente
   * @statusCode 400: la publicación esta eliminada o parametros incorrectos
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function disableOffering(Request $request, Response $response, $args)  {
    $params['OfferingID'] = $args['OfferingID'];
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'offerings','disable_offering', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valido que el offering exista, no este eliminado y el usuario tenga acceso
      $validation = $this -> _validateOffering($response, $params['OfferingID'], $jwt);
      if(!$validation->valid){
        return $validation->response;
      }
      $offering = $validation->response;

      $offering = $this->offering->disableOffering($params['OfferingID']);
      return $response->withStatus(200)->withJson($offering);
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
   * Elimina una publicación (soft delete, cambia estado a 'Deleted')
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (OfferingID)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: publicación eliminada exitosamente
   * @statusCode 400: la publicación esta eliminada, parametros incorrectos
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function deleteOffering(Request $request, Response $response, $args)  {
    $params['OfferingID'] = $args['OfferingID'];
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'offerings','delete_offering', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try {
      # Valido que el offering exista, no este eliminado y el usuario tenga acceso
      $validation = $this -> _validateOffering($response, $params['OfferingID'], $jwt);
      if(!$validation->valid){
        return $validation->response;
      }
      $offering = $validation->response;

      $offering = $this->offering->deleteOffering($params['OfferingID']);
      return $response->withStatus(200)->withJson($offering);
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
   * Agrega un archivo multimedia (imagen o video) a una publicación
   * @param  Request $request: objeto de request HTTP (requiere JWT, archivo 'Media', body con Title y Description opcional)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id - offeringID, position)
   * @return Response: JSON con confirmación, URL y texto detectado o error
   * @statusCode 200: archivo multimedia agregado exitosamente
   * @statusCode 400: archivo inválido, contenido inapropiado, límite de archivos excedido o parámetros faltantes
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación no encontrada
   * @statusCode 500: error del servidor
   **/
  public function createOfferingMedia(Request $request, Response $response, $args) {
    $id = intval($args['OfferingID']); # ID de offering
    # Obtener los metadatos
    $data = $request->getParsedBody();
    # Obtener el archivo adjunto
    $uploadedFiles = $request->getUploadedFiles();
    $uploadedFile = $uploadedFiles['Media'] ?? null;
    # Token JWT
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $title = $data['Title'] ?? null;
    $description = $data['Description'] ?? null;
    $position = $data['Position'] ?? null;

    try {
      # Verificar que el offering existe
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      # Verificar permisos
      if ($offering['UserID'] !== $userID) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      if(!$title){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "Parameters are missing or invalid"
          ]
        ]);
      }

      # Valida contenido con Perspective API
      $contentToCheck = implode(" ", [
        $data['Title'] ?? '',
        $data['Description'] ?? ''
      ]);
      if ($this->_containsInappropriateContent($contentToCheck)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $validation = $this -> _validateUploadedMedia($response, $uploadedFile);
      if(!$validation->valid){
        return $validation->response;
      }
      $media = $validation->response;

      # Analizar la imagen con Amazon Rekognition
      if($media->MediaType === MediaType::IMAGE &&
        (empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode'])
      ){
        $rekognitionResult = analyzeImageWithRekognition($media->TempFilePath);
        if (!empty($rekognitionResult['error'])) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INAPPROPRIATE_IMAGE",
              "desc" => $rekognitionResult['reason']
            ]
          ]);
        }
      }

      # Validar cantidad de archivos existentes
      $mediaCounts = $this->offering->getMediaCountByType($id);
      if ($media->MediaType === MediaType::IMAGE && $mediaCounts['image'] >= MAX_IMAGES) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more photos to this offering"
          ]
        ]);
      }

      # Si se adjunto un video ver si tiene un plan que lo permita
      if ($media->MediaType === MediaType::VIDEO){
        if($mediaCounts['video'] >= MAX_VIDEOS){
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "MEDIA_TOO_MANY",
              "desc" => "Cannot add more videos to this offering"
            ]
          ]);
        }

        $userSubscription = $this->subscription->getSubscriptionByUser($userID);
        $plan = $userSubscription ? $this->subscription->getSubscriptionPlanByID($userSubscription['PlanID']) : null;
        $hasVideo = array_filter($plan['Features'] ?? null, function($e){
          return $e['FeatureCode'] === 'VIDEOS' && $e['Value'];
        });

        if(!$hasVideo){
          return $response->withStatus(403)->withJson([
            "error" => [
              "code" => "HIGHER_PLAN_NEEDED",
              "desc" => "Your subscription plan does not include videos in publicacions"
            ]
          ]);
        }
      }

      # Ruta de archivo y URL
      $uid = uniqid();
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
      $filePath = "$uploadDirectory/offering/$uid.{$media->Extension}";
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.{$media->Extension}";

      # Mover el archivo al destino
      $media->File->moveTo($filePath);

      # Insertar media en la base de datos
      $offering = $this->offering->createOfferingMedia(
        $id,
        $title,
        $description,
        $fileURL,
        $filePath,
        $media->MediaType,
        $position
      );

      return $response->withStatus(200)->withJson($offering);
    } catch (\Throwable $e) {
      if (!empty($filePath) && is_file($filePath)) {
        unlink($filePath); # Eliminar archivo subido en caso de error
      }
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Actualiza un archivo multimedia de una publicación
   * @param  Request $request: objeto de request HTTP (requiere JWT, archivo 'Media' opcional, body con Title y Description)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id - offeringID, mediaID, position)
   * @return Response: JSON con confirmación, URL y texto detectado o error
   * @statusCode 200: archivo multimedia actualizado exitosamente
   * @statusCode 400: archivo inválido, contenido inapropiado o parámetros faltantes
   * @statusCode 401: usuario sin permisos
   * @statusCode 404: publicación o archivo multimedia no encontrado
   * @statusCode 500: error del servidor
   **/
  public function updateOfferingMedia(Request $request, Response $response, $args){
    $id = intval($args['OfferingID']); # ID de offering
    $mediaID = intval($args['MediaID']); # ID del archivo de medios
    # Obtener los metadatos
    $data = $request->getParsedBody();
    # Obtener el archivo adjunto
    $uploadedFiles = $request->getUploadedFiles();
    $uploadedFile = $uploadedFiles['Media'] ?? null;
    # Token JWT
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $title = $data['Title'] ?? null;
    $description = $data['Description'] ?? null;
    $position = $data['Position'] ?? null;

    try {
      # Verificar que el offering existe
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      # Verificar permisos
      if ($offering['UserID'] !== $userID) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      if(!$title){
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "Parameters are missing or invalid"
          ]
        ]);
      }

      # Valida contenido con Perspective API
      $contentToCheck = implode(" ", [
        $title ?? '',
        $description ?? ''
      ]);
      if ($this->_containsInappropriateContent($contentToCheck)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      # Busco el media del offering
      $currentMedia = $this->offering->getMediaById($id, $mediaID);
      if (empty($currentMedia)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "MEDIA_NOT_FOUND",
            "desc" => "Media file not found"
          ]
        ]);
      }

      # Valida contenido con Perspective API
      $contentToCheck = implode(" ", [
        $data['Title'] ?? '',
        $data['Description'] ?? ''
      ]);
      if ($this->_containsInappropriateContent($contentToCheck)) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      # Si se adjunto un archivo lo valido
      if($uploadedFile !== null){
        $validation = $this -> _validateUploadedMedia($response, $uploadedFile);
        if(!$validation->valid){
          return $validation->response;
        }
        $media = $validation->response;

        # Si se adjunto un archivo el tipo de archivo anterior debe ser el mismo
        if($media->MediaType->value !== $currentMedia['MediaType']){
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "MEDIA_TYPE_MISMATCH",
              "desc" => "New media must be of the same type (video/image) as the current one"
            ]
          ]);
        }

        # Si se adjunto una imagen analizarla con Amazon Rekognition
        if($media->MediaType === MediaType::IMAGE &&
          (empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode'])
        ){
          $rekognitionResult = analyzeImageWithRekognition($media->TempFilePath);
          if (!empty($rekognitionResult['error'])) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INAPPROPRIATE_IMAGE",
                "desc" => $rekognitionResult['reason']
              ]
            ]);
          }
        }

        # Si se adjunto un video ver si tiene un plan que lo permita
        if ($media->MediaType === MediaType::VIDEO){
          $userSubscription = $this->subscription->getSubscriptionByUser($userID);
          $plan = $userSubscription ? $this->subscription->getSubscriptionPlanByID($userSubscription['PlanID']) : null;
          $hasVideo = array_filter($plan['Features'] ?? null, function($e){
            return $e['FeatureCode'] === 'VIDEOS' && $e['Value'];
          });

          if(!$hasVideo){
            return $response->withStatus(403)->withJson([
              "error" => [
                "code" => "HIGHER_PLAN_NEEDED",
                "desc" => "Your subscription plan does not include videos in publicacions"
              ]
            ]);
          }
        }

        # Ruta de archivo y URL
        $uid = uniqid();
        $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
        $filePath = "$uploadDirectory/offering/$uid.{$media->Extension}";
        $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.{$media->Extension}";

        # Mover el archivo al destino
        $media->File->moveTo($filePath);
      }

      $position = !is_null($position) && is_numeric($position) ? intval($position) : $currentMedia['Position'];

      # Actualizo el media
      $offering = $this->offering->updateOfferingMedia($id, $title, $description, $position, $mediaID,
        $fileURL ?? null, $filePath ?? null, (empty($media) ? null : $media->MediaType));

      # Borro el archivo antiguo
      if($uploadedFile !== null && file_exists($currentMedia['Path'])){
        @unlink($currentMedia['Path']);
      }

      return $response->withStatus(200)->withJson($offering);
    } catch (\Throwable $e) {
      if (!empty($filePath) && is_file($filePath)) {
        unlink($filePath); # Eliminar archivo subido en caso de error
      }
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Elimina un archivo multimedia de una publicación
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id - offeringID, mediaID)
   * @return Response: JSON con confirmación o error
   * @statusCode 200: archivo multimedia eliminado exitosamente
   * @statusCode 403: usuario sin permisos
   * @statusCode 404: publicación o archivo multimedia no encontrado
   * @statusCode 500: error del servidor o fallo al eliminar archivo del filesystem
   **/
  public function deleteOfferingMedia(Request $request, Response $response, $args)  {
    $id = intval($args['OfferingID']);
    $mediaID = intval($args['MediaID']);
    # Token JWT
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    try {
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      # Verificar si el usuario autenticado es el mismo que creo el offering o un admin
      if ($offering['UserID'] !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      # Obtener el archivo multimedia por mediaId y offeringId
      $currentMedia = $this->offering->getMediaById($id, $mediaID);
      if (empty($currentMedia)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "MEDIA_NOT_FOUND",
            "desc" => "The specified media file does not exist"
          ]
        ]);
      }

      # Eliminar el registro de la tabla MEDIA
      $offering = $this->offering->deleteOfferingMedia($id, $mediaID);

      # Borro el archivo
      if(file_exists($currentMedia['Path'])){
        @unlink($currentMedia['Path']);
      }

      return $response->withStatus(200)->withJson($offering);
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
   * Verifica si el contenido contiene material inapropiado
   * @param  string $text: texto a validar
   * @return bool: true si contiene contenido inapropiado, false si es válido
   **/
  private function _containsInappropriateContent($text)  {
    return validateContentWithPerspective($text);
  }

  /**
   * Valida un archivo multimedia subido al server
   * @param  Response $response: objeto de response HTTP de Slim
   * @param  File $uploadedFile: archivo subido
   * @return object: objeto con datos del archivo si es válido, objeto error si hay problema, false si no es requerido
   * @statusCode error UPLOAD_ERROR: no se puede leer el archivo adjunto
   * @statusCode error MEDIA_TOO_BIG: tamaño excede límite (5MB imágenes, 50MB videos)
   * @statusCode error MEDIA_FORMAT_INVALID: formato no permitido
   **/
  private function _validateUploadedMedia($response, $uploadedFile){
    if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
      return (object) [
        "error" => [
          "code" => "UPLOAD_ERROR",
          "desc" => "Cannot read the attached file"
        ]
      ];
    }

    # Datos del archivo
    $fileSize = $uploadedFile->getSize();
    $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);

    # Validar el formato de archivo usando finfo_file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $filePathTemp = $uploadedFile->getStream()->getMetadata('uri');
    $mimeType = finfo_file($finfo, $filePathTemp);
    finfo_close($finfo);

    # Tipo de archivo multimedia
    $mediaType = $this->_getMediaType($mimeType);

    # Debe ser video o imagen
    if(!in_array($mediaType, [MediaType::IMAGE, MediaType::VIDEO])){
      return (object) [
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_FORMAT_INVALID",
            "desc" => "Allowed formats are JPEG, PNG, GIF, WEBP, MP4, WEBM, MOV, MKV"
          ]
        ])
      ];
    }

    if((MediaType::IMAGE === $mediaType && $fileSize > MAX_IMAGE_SIZE) ||
      (MediaType::VIDEO === $mediaType && $fileSize > MAX_VIDEO_SIZE)
    ){
      return (object) [
        "valid" => false,
        "response" => $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_BIG",
            "desc" => "Maximum size is 5MB for photos and 50MB for videos"
          ]
        ])
      ];
    }

    return (object) [
      "valid" => true,
      "response" => (object)[
        "File" => $uploadedFile,
        "TempFilePath" => $filePathTemp,
        "MimeType" => $mimeType,
        "MediaType" => $mediaType,
        "FileSize" => $fileSize,
        "Extension" => $extension
      ]
    ];
  }

  /**
   * Valida si el archivo es una imagen según su MIME type
   * @param  string $mimeType: tipo MIME del archivo
   * @return bool: true si es imagen válida, false en caso contrario
   **/
  private function _getMediaType($mimeType) {
    if(in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])){
      return MediaType::IMAGE;
    }

    if(in_array($mimeType, ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'])){
      return MediaType::VIDEO;
    }
    MediaType::INVALID;
  }


  /**
   * Valida que la publicacion no este eliminada y el usuario tenga acceso
   * @param Response $response: objeto de response HTTP
   * @param $offeringID: ID de la publicación
   * @param $jwt: Datos del token del usuario autenticado
   * @return Response|array Retorna Response con error si falla, datos del offering si tiene exito
   */
  private function _validateOffering(Response $response, $offeringID, $jwt) {
    $offering = $this->offering->getOfferingById($offeringID);
    if (empty($offering)) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ])
      ];
    }

    # Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
    if ($offering['UserID'] !== $jwt->data->UserID && !$jwt->data->IsAdmin) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(404)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ])
      ];
    }

    # Verificar que el offering no esté eliminado
    if ($offering['Status'] === 'Deleted') {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_DELETED",
            "desc" => "The specified offering is deleted"
          ]
        ])
      ];
    }

    # Validación exitosa
    return (object)["valid" => true, "response" => $offering];
  }
}