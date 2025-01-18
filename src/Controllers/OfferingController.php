<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use Firebase\JWT\JWT;

require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');

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

  public function getOfferingById(Request $request, Response $response, $args)  {
    $id = $args['id'];
    try {
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getOfferingsByCategoryId(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $categoryId = $args['categoryID'];
    try {
      $result = $this->offering->getOfferingsByCategoryId($paginator, $categoryId);
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
    $userId = $args['userID'];
    try {
      $result = $this->offering->getOfferingsByUserId($paginator, $userId);
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
    $jwt = $request->getAttribute('jwt');

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;

    $data = $request->getParsedBody();

    $data['UserID'] = $userID;
    $data['SKU'] = null;
    $data['Stock'] = null;
    $data['ServiceType'] = 'Service';

    // Evaluar si es necesario que solo se permitan crear offering con las categorias del usuario
    // // Validar `CategoryID`
    // if (!isset($data['CategoryID']) || !$this->userBelongsToCategory($userID, $data['CategoryID'])) {
    //   return $response->withStatus(400)->withJson([
    //     "error" => [
    //       "code" => "WRONG_CATEGORY",
    //       "desc" => "The user does not belong to the selected category"
    //     ]
    //   ]);
    // }

    try {
      # Verificar si el usuario autenticado es un Guia o un administrador
      if ($jwt['data']->UserType != 'Guide' && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to create offerings."
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
          if ((!empty($faq['question']) && $this->containsInappropriateContent($faq['question'])) ||
            (!empty($faq['answer']) && $this->containsInappropriateContent($faq['answer']))) {
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
          if ((!empty($package['conditions']) && $this->containsInappropriateContent($package['conditions'])) ||
            (!empty($package['description']) && $this->containsInappropriateContent($package['description']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "Packages contain inappropriate content. Please review and try again."
            ]);
          }
        } 
      }

      $result = $this->offering->createOffering($data);
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

  public function approveOffering(Request $request, Response $response, $args)  {
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

    try {
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $offeringData = $result->data;

      // Verificar que el token contenga UserType y sea un administrador
      if ($jwt['data']->UserType !== 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to approve this offering"
          ]
        ]);
      }

      // Verificar que el offering no esté eliminado
      if ($offeringData['Status'] === 'Deleted') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "OFFERING_DELETED",
            "desc" => "The specified offering is deleted"
          ]
        ]);
      }

      // Aprobar el offering (cambiar el estado a 'Active')
      $this->offering->approveOfferingById($id);

      return $response->withStatus(200)->withJson([
        "message" => "Offering approved successfully"
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

  public function updateOffering(Request $request, Response $response, $args)  {
    $jwt = $request->getAttribute('jwt');
    $id = $args['id'];

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data']->UserID;

    $data = $request->getParsedBody();

    try {
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $offeringData = $result->data;

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offeringData['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }
      
      // Evaluar si es necesario que solo se permitan crear offering con las categorias del usuario
      // if (!empty($data['CategoryID'])) {
      //   //Validación contra la suscripción del usuario para la categoría
      //   if (!$this->userBelongsToCategory($userID, $data['CategoryID'])) {
      //     return $response->withStatus(400)->withJson([
      //       "code" => "WRONG_CATEGORY",
      //       "desc" => "The user does not belong to the selected category"
      //     ]);
      //   }
      // }

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
          if ((!empty($faq['question']) && $this->containsInappropriateContent($faq['question'])) ||
            (!empty($faq['answer']) && $this->containsInappropriateContent($faq['answer']))) {
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
          if ((!empty($package['conditions']) && $this->containsInappropriateContent($package['conditions'])) ||
            (!empty($package['description']) && $this->containsInappropriateContent($package['description']))) {
            return $response->withStatus(400)->withJson([
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => "Packages contain inappropriate content. Please review and try again."
            ]);
          }
        } 
      }

      // Actualizar la oferta
      $result = $this->offering->updateOffering($id, $data);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result->data);
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

    $userID = $jwt['data']->UserID;
    $data = $request->getParsedBody();

    try {
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $offeringData = $result->data;

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offeringData['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }

      if ($offeringData['Status'] === 'Deleted') {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "OFFERING_DELETED",
            "desc" => "The specified offering is deleted"
          ]
        ]);
      }

      $this->offering->deleteOffering($id);

      return $response->withStatus(200)->withJson([
        "message" => "Offering deleted successfully"
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

  public function createOfferingMedia(Request $request, Response $response, $args){
    $id = $args['id']; // ID de offering
    $position = $args['position']; // Posicion del archivo multimedia

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID;

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      // Verificar que el offering existe
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $offeringData = $result->data;

      // Verificar permisos
      if ($offeringData['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      // Verifico si el archivo multimedia es valido
      $uploadedMedia = $this -> _getUploadedMedia($request);
      if($uploadedMedia-> error){
        return $response->withStatus(400)->withJson($uploadedMedia -> error);
      }

      // Validar cantidad de archivos existentes
      $mediaCounts = $this->offering->getMediaCountByType($id);
      if ($this->_isImage($uploadedMedia-> mimeType) && $mediaCounts['image'] >= MAX_IMAGES) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more photos to this offering"
          ]
        ]);
      }
      if (!$this->_isImage($uploadedMedia-> mimeType) && $mediaCounts['video'] >= MAX_VIDEOS) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more videos to this offering"
          ]
        ]);
      }

      // Obtener datos del cuerpo de la petición
      $data = $request->getParsedBody();
      $title = $data['title'] ?? null;
      $description = $data['description'] ?? null;

      // Valida contenido con Perspective API
      if ($title && $description) {
        if (
          $this->containsInappropriateContent($data['title']) ||
          $this->containsInappropriateContent($data['description'])
        ) {
          return $response->withStatus(400)->withJson([
            "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
          ]);
        }
      } 

      // Ruta de archivo y URL
      $fileExtension = $uploadedMedia-> extension;
      $uid = uniqid();
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
      $filePath = "$uploadDirectory/offering/$uid.$fileExtension";
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.$fileExtension";

      // Mover el archivo al destino
      $uploadedMedia->file->moveTo($filePath);

      // Insertar media en la base de datos
      $this->offering->createOfferingMedia($id, $title, $description, $position, $fileURL, $filePath,
        $this->_isImage($uploadedMedia-> mimeType) ? 'image' : 'video');

      return $response->withStatus(200)->withJson([
        "message" => "Media file added successfully",
        "URL" => $fileURL
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
    $id = $args['id']; // ID de offering
    $media_id = $args['media_id']; // ID del archivo de medios
    $position = $args['position']; // Posicion del archivo multimedia

    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID;

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      // Verificar que el offering existe
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      
      $offeringData = $result->data;

      // Verificar permisos
      if ($offeringData['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      // Busco el media del offering
      $media = $this->offering->getMediaById($id,$media_id);
      if(empty($media)){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "MEDIA_NOT_FOUND",
            "desc" => "Media file not found"
          ]
        ]);
      }
      // Verifico si el archivo multimedia es valido (si se subio)
      $uploadedMedia = $this -> _getUploadedMedia($request);

      
      // Obtener datos del cuerpo de la petición
      $data = $request->getParsedBody();
      $title = $data['title'] ?? null;
      $description = $data['description'] ?? null;

      // Valida contenido con Perspective API
      if ($title && $description) {
        if (
          $this->containsInappropriateContent($data['title']) ||
          $this->containsInappropriateContent($data['description'])
        ) {
          return $response->withStatus(400)->withJson([
            "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
          ]);
        }
      } 

      if($uploadedMedia !== false){
        if($uploadedMedia-> error){
          return $response->withStatus(400)->withJson($uploadedMedia -> error);
        }

        // Ruta de archivo y URL
        $fileExtension = $uploadedMedia-> extension;
        $uid = uniqid();
        $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
        $filePath = "$uploadDirectory/offering/$uid.$fileExtension";
        $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$uid.$fileExtension";

        // Mover el archivo al destino
        $uploadedMedia->file->moveTo($filePath);

        $this->offering->updateOfferingMedia($id, $title, $description, $position, $media_id, $fileURL, $filePath, $this->_isImage($uploadedMedia-> mimeType) ? 'image' : 'video');
        // Elimino el archivo antiguo si se actualizo con uno nuevo
        unlink($media['Path']);
      } else {
        $this->offering->updateOfferingMedia($id, $title, $description, $position, $media_id);
      }

      return $response->withStatus(200)->withJson([
        "message" => "Media file updated successfully"
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
  private function _getUploadedMedia($request){
    // Obtener el archivo del request
    $uploadedFiles = $request->getUploadedFiles();
    $uploadedFile = $uploadedFiles['media'] ?? null;

    // Si no hay un archivo MEDIA que leer devuelvo false
    if(!$uploadedFile){
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
      "file" => $uploadedFile,
      "mimeType" => $mimeType,
      "fileSize" => $fileSize,
      "extension" => $extension,
      "error" => false
    ];
  }

  // Función para validar si el archivo es imagen
  private function _isImage($mimeType)  {
    return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
  }

  public function deleteOfferingMedia(Request $request, Response $response, $args)  {
    $id = $args['id'];
    $media_id = $args['media_id'];
    $jwt = $request->getAttribute('jwt');
    $userId = $jwt['data']->UserID;

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      $result = $this->offering->getOfferingById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $offeringData = $result->data;

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offeringData['UserID'] != $userId && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      // Obtener el archivo multimedia por mediaId y offeringId
      $media = $this->offering->getMediaById($id, $media_id);

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
      $this->offering->deleteOfferingMedia($media_id);

      return $response->withStatus(200)->withJson(["message" => "Media file deleted successfully"]);

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