<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use Firebase\JWT\JWT;

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

  public function __construct(Offering $offering)  {
    $this->offering = $offering;
  }

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
   * Obtiene una categoría por su ID
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

  public function getOfferingById(Request $request, Response $response, $args)  {
    $id = intval($args['id']);
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

  public function getOfferingsByCategory(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $categoryId = intval($args['categoryID']);
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

  public function getOfferingsByUserId(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $userID = intval($args['userID']);
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

  public function createOffering(Request $request, Response $response, $args)  {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    // Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
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

    $data['UserID'] = $userID;
    $data['SKU'] = null;
    $data['Stock'] = null;
    $data['ServiceType'] = 'Service';

    // Validar datos obligatorios
    if (!isset($data['Title'], $data['ShortDescription'], $data['Description'], $data['CategoryID'], $data['UserID'])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "MISSING_REQUIRED_FIELDS",
          "desc" => "Missing required fields: Title, ShortDescription, Description, CategoryID, or UserID."
        ]
      ]);
    }

    try {
      // Validación de contenido inapropiado
      if($this->containsInappropriateContent($data['Title']) ||
        $this->containsInappropriateContent($data['Description']) ||
        $this->containsInappropriateContent($data['ShortDescription'])
      ){
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      // Validar FAQs
      if (!empty($data['faqs']) && is_array($data['faqs'])) {
        foreach ($data['faqs'] as $faq) {
          if ((!empty($faq['Question']) && $this->containsInappropriateContent($faq['Question'])) ||
            (!empty($faq['Answer']) && $this->containsInappropriateContent($faq['Answer']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "FAQs contain inappropriate content. Please review and try again."
            ]);
          }
        }
      }

      // Validar Packages
      if (!empty($data['packages']) && is_array($data['packages'])) {
        foreach ($data['packages'] as $package) {
          if ((!empty($package['Conditions']) && $this->containsInappropriateContent($package['Conditions'])) ||
            (!empty($package['Description']) && $this->containsInappropriateContent($package['Description']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "Packages contain inappropriate content. Please review and try again."
            ]);
          }
        }
      }

      $offering = $this->offering->createOffering($data);
      if(!$offering){
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "FETCH_ERROR",
            "desc" => "Could not retrieve created offering"
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

  public function approveOffering(Request $request, Response $response, $args)  {
    $id = intval($args['id']);
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

      // Verificar que el offering no esté eliminado
      if ($offering['Status'] === 'Deleted') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "OFFERING_DELETED",
            "desc" => "The specified offering is deleted"
          ]
        ]);
      }

      // Aprobar el offering (cambiar el estado a 'Active')
      $this->offering->approveOfferingById($id);

      return $response->withStatus(200)->withJson("Offering approved successfully");

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateOffering(Request $request, Response $response, $args)  {
    $id = intval($args['id']);
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    // Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
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

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offering['UserID'] !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }

      // Validación de contenido inapropiado
      if((!empty($data['Title']) && $this->containsInappropriateContent($data['Title'])) ||
        (!empty($data['Description']) && $this->containsInappropriateContent($data['Description'])) ||
        (!empty($data['ShortDescription']) && $this->containsInappropriateContent($data['ShortDescription']))){
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      // Validar FAQs
      if (!empty($data['faqs']) && is_array($data['faqs'])) {
        foreach ($data['faqs'] as $faq) {
          if ((!empty($faq['Question']) && $this->containsInappropriateContent($faq['Question'])) ||
            (!empty($faq['Answer']) && $this->containsInappropriateContent($faq['Answer']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "FAQs contain inappropriate content. Please review and try again."
            ]);
          }
        }
      }

      // Validar Packages
      if (!empty($data['packages']) && is_array($data['packages'])) {
        foreach ($data['packages'] as $package) {
          if ((!empty($package['Conditions']) && $this->containsInappropriateContent($package['Conditions'])) ||
            (!empty($package['Description']) && $this->containsInappropriateContent($package['Description']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "Packages contain inappropriate content. Please review and try again."
            ]);
          }
        }
      }

      // Actualizar la oferta
      $offering = $this->offering->updateOffering($id, $data);
      if (!$offering) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "FETCH_ERROR",
            "desc" => "Could not retrieve updated offering"
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

  public function deleteOffering(Request $request, Response $response, $args)  {
    $id = intval($args['id']);
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

      // Verificar si el usuario autenticado es el mismo que creo el offering o un admin
      if ($offering['UserID'] !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }

      if ($offering['Status'] === 'Deleted') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "OFFERING_DELETED",
            "desc" => "The specified offering is deleted"
          ]
        ]);
      }

      $this->offering->deleteOffering($id);

      return $response->withStatus(200)->withJson([
        "Message" => "Offering deleted successfully"
      ]);

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function createOfferingMedia(Request $request, Response $response, $args) {
    $id = intval($args['id']); // ID de offering
    $position = intval($args['position']); // Posicion del archivo multimedia
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    // Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    try {
      // Verificar que el offering existe
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      // Verificar permisos
      if ($offering['UserID'] !== $userID) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      // Verifico si el archivo multimedia es valido
      $uploadedMedia = $this->_getUploadedMedia($request, true);
      if (isset($uploadedMedia->error)) {
        return $response->withStatus(400)->withJson($uploadedMedia->error);
      }

      // Obtengo la extención del archivo media
      $fileExtension = $uploadedMedia->Extension;

      // Ruta temporal del archivo
      $tempFilePath = $uploadedMedia->File->getStream()->getMetadata('uri');

      // Analizar la imagen con Amazon Rekognition
      if(empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode']){
        if (in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
          $rekognitionResult = analyzeImageWithRekognition($tempFilePath);
          if (!empty($rekognitionResult['error'])) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INAPPROPRIATE_IMAGE",
                "desc" => $rekognitionResult['reason']
              ]
            ]);
          }
        }
      }

      // Validar cantidad de archivos existentes
      $mediaCounts = $this->offering->getMediaCountByType($id);
      if ($this->_isImage($uploadedMedia->MimeType) && $mediaCounts['image'] >= MAX_IMAGES) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more photos to this offering"
          ]
        ]);
      }
      if (!$this->_isImage($uploadedMedia->MimeType) && $mediaCounts['video'] >= MAX_VIDEOS) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more videos to this offering"
          ]
        ]);
      }

      $title = $data['Title'];
      $description = $data['Description'] ?? null;

      // Valida contenido con Perspective API
      if ($title && $description) {
        if (
          $this->containsInappropriateContent($data['Title']) ||
          $this->containsInappropriateContent($data['Description'])
        ) {
          return $response->withStatus(400)->withJson([
            "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
          ]);
        }
      }

      // Ruta de archivo y URL
      $fileExtension = $uploadedMedia->Extension;
      $uid = uniqid();
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
      $filePath = "$uploadDirectory/offering/$uid.$fileExtension";
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.$fileExtension";

      // Mover el archivo al destino
      $uploadedMedia->File->moveTo($filePath);

      // Insertar media en la base de datos
      $this->offering->createOfferingMedia(
        $id,
        $title,
        $description,
        $position,
        $fileURL,
        $filePath,
        $this->_isImage($uploadedMedia->MimeType) ? 'image' : 'video'
      );

      return $response->withStatus(200)->withJson([
        "Message" => "Media file added successfully",
        "URL" => $fileURL,
        "DetectedText" => isset($rekognitionResult['text']) ? $rekognitionResult['text'] : ''
      ]);
    } catch (\Throwable $e) {
      if (!empty($filePath) && is_file($filePath)) {
        unlink($filePath); // Eliminar archivo subido en caso de error
      }
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateOfferingMedia(Request $request, Response $response, $args){
    $id = intval($args['id']); // ID de offering
    $mediaID = intval($args['mediaID']); // ID del archivo de medios
    $position = intval($args['position']); // Posicion del archivo multimedia
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt->data->UserID;

    // Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    try {
      // Verificar que el offering existe
      $offering = $this->offering->getOfferingById($id);
      if (!$offering) {
        return $response->withStatus(404)->WithJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc"=> "No Offering found for this specific ID."
          ]
        ]);
      }

      // Verificar permisos
      if ($offering['UserID'] !== $userID) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      // Busco el media del offering
      $media = $this->offering->getMediaById($id, $mediaID);
      if (empty($media)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "MEDIA_NOT_FOUND",
            "desc" => "Media file not found"
          ]
        ]);
      }

      // Verifico si el archivo multimedia es valido (si se subio)
      $uploadedMedia = $this->_getUploadedMedia($request, false);
      if (isset($uploadedMedia->error)) {
        return $response->withStatus(400)->withJson($uploadedMedia->error);
      }

      // Analizar la imagen con Amazon Rekognition
      if(empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode']){
        if (in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
          $rekognitionResult = analyzeImageWithRekognition($tempFilePath);

          if (!empty($rekognitionResult['error'])) {
            return $response->withStatus(400)->withJson([
              "error" => [
                "code" => "INAPPROPRIATE_IMAGE",
                "desc" => $rekognitionResult['reason']
              ]
            ]);
          }
        }
      }

      $title = $data['Title'];
      $description = $data['Description'] ?? null;

      // Valida contenido con Perspective API
      if ($title && $description) {
        if (
          $this->containsInappropriateContent($data['Title']) ||
          $this->containsInappropriateContent($data['Description'])
        ) {
          return $response->withStatus(400)->withJson([
            "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
          ]);
        }
      }

      if ($uploadedMedia !== false) {
        // Obtengo la extención del archivo media
        $fileExtension = $uploadedMedia->Extension;
        // Ruta temporal del archivo
        $tempFilePath = $uploadedMedia->File->getStream()->getMetadata('uri');

        if (isset($uploadedMedia->error)) {
          return $response->withStatus(400)->withJson($uploadedMedia->error);
        }

        // Ruta de archivo y URL
        $uid = uniqid();
        $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
        $filePath = "$uploadDirectory/offering/$uid.$fileExtension";
        $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.$fileExtension";

        // Mover el archivo al destino
        $uploadedMedia->File->moveTo($filePath);

        $this->offering->updateOfferingMedia($id, $title, $description, $position, $mediaID, $fileURL, $filePath,
          $this->_isImage($uploadedMedia->MimeType) ? 'image' : 'video');
        // Elimino el archivo antiguo si se actualizo con uno nuevo
        unlink($media['Path']);
      } else {
        $fileURL = null;
        $this->offering->updateOfferingMedia($id, $title, $description, $position, $mediaID);
      }

      return $response->withStatus(200)->withJson([
        "Message" => "Media file updated successfully",
        "URL" => $fileURL,
        "DetectedText" => isset($rekognitionResult['text']) ? $rekognitionResult['text'] : ''
      ]);
    } catch (\Throwable $e) {
      if (!empty($filePath) && is_file($filePath)) {
        unlink($filePath); // Eliminar archivo subido en caso de error
      }
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  // Extrae el archivo multimedia del request y analiza si es valido
  private function _getUploadedMedia($request, $required){
    // Obtener el archivo del request
    $uploadedFiles = $request->getUploadedFiles();
    $uploadedFile = $uploadedFiles['Media'] ?? null;

    if(!$uploadedFile){
      if($required){
        return (object) [
          "error" => [
            "code" => "UPLOAD_ERROR",
            "desc" => "Cannot read the attached file"
          ]
        ];
      }
      return false;
    }

    if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
      return (object) [
        "error" => [
          "code" => "UPLOAD_ERROR",
          "desc" => "Cannot read the attached file"
        ]
      ];
    }

    // Datos del archivo
    $fileSize = $uploadedFile->getSize();
    $mimeType = $uploadedFile->getClientMediaType();
    $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);

    // Validar el tamaño del archivo
    if(($this->_isImage($mimeType) && $fileSize > MAX_IMAGE_SIZE) ||
      (!$this->_isImage($mimeType) && $fileSize > MAX_VIDEO_SIZE)
    ){
      return (object) [
        "error" => [
          "code" => "MEDIA_TOO_BIG",
          "desc" => "Maximum size is 5MB for photos and 50MB for videos"
        ]
      ];
    }

    // Validar el formato de archivo usando finfo_file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $filePathTemp = $uploadedFile->getStream()->getMetadata('uri');
    $actualMimeType = finfo_file($finfo, $filePathTemp);
    finfo_close($finfo);

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/x-matroska'];
    if(!in_array($actualMimeType, $allowedMimeTypes)){
      return (object) [
        "error" => [
          "code" => "MEDIA_FORMAT_INVALID",
          "desc" => "Allowed formats are JPEG, PNG, GIF, WEBP, MP4, MKV"
        ]
      ];
    }

    return (object) [
      "File" => $uploadedFile,
      "MimeType" => $mimeType,
      "FileSize" => $fileSize,
      "Extension" => $extension
    ];
  }

  // Función para validar si el archivo es imagen
  private function _isImage($mimeType)  {
    return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
  }

  public function deleteOfferingMedia(Request $request, Response $response, $args)  {
    $id = intval($args['id']);
    $mediaID = intval($args['mediaID']);
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

      // Verificar si el usuario autenticado es el mismo que creo el offering o un admin
      if ($offering['UserID'] !== $userID && !$jwt->data->IsAdmin) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      // Obtener el archivo multimedia por mediaId y offeringId
      $media = $this->offering->getMediaById($id, $mediaID);

      if (empty($media)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "MEDIA_NOT_FOUND",
            "desc" => "The specified media file does not exist"
          ]
        ]);
      }

      // Eliminar el archivo físico usando unlink()
      if (!empty($media['Path']) && is_file($media['Path']) && !unlink($media['Path'])) {
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "DELETE_FAILED",
            "desc" => "Failed to delete the media file from the filesystem"
          ]
        ]);
      }

      // Eliminar el registro de la tabla MEDIA
      $this->offering->deleteOfferingMedia($mediaID);

      return $response->withStatus(200)->withJson("Media file deleted successfully");

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  // Función para verificar que el usuario esté suscrito a la categoría
  private function userBelongsToCategory($userID, $categoryID)  {
    return $this->offering->checkUserCategorySubscription($userID, $categoryID);
  }

  // Función para verificar contenido inapropiado utilizando Perspective API
  private function containsInappropriateContent($text)  {
    return validateContentWithPerspective($text);
  }
}