<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Firebase\JWT\JWT;

require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');

class OfferingController
{
  protected $offering;

  public function __construct(Offering $offering)  {
    $this->offering = $offering;
  }

  public function getOfferings(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    try {
      $offerings = $this->offering->getOfferings($paginator);
      $response->getBody()->write(json_encode($offerings));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getOfferingById(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $id = $args['id'];
    try {
      $offering = $this->offering->getOfferingById($paginator, $id);
      if ($offering) {
        $response->getBody()->write(json_encode($offering));
      } else {
        throw new NotFoundException('Offering not found');
      }
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getOfferingsByCategoryId(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $categoryId = $args['categoryID'];
    try {
      $offerings = $this->offering->getOfferingsByCategoryId($paginator, $categoryId);
      $response->getBody()->write(json_encode($offerings));
    } catch (\Exception $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => 'Internal Server Error', 'error' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getOfferingsByUserId(Request $request, Response $response, $args)  {
    $paginator = paginator($request);
    $userId = $args['userID'];
    try {
      $offerings = $this->offering->getOfferingsByUserId($paginator, $userId);
      $response->getBody()->write(json_encode($offerings));
    } catch (\Exception $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => 'Internal Server Error', 'error' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
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

    // Placeholders
    $data['SKU'] = null;
    $data['Stock'] = null;
    $data['ServiceType'] = 'Service';

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

      // Valida categoryID contra suscripción del usuario
      // if (!$this->userBelongsToCategory($userID, $data['CategoryID'])) {
      //   return $response->withStatus(400)->withJson([
      //     "code" => "WRONG_CATEGORY",
      //     "desc" => "The user does not belong to selected category"
      //   ]);
      // }

      // Valida contenido con Perspective API
      if (
        $this->containsInappropriateContent($data['Title']) ||
        $this->containsInappropriateContent($data['Description'])
      ) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      $offering = $this->offering->createOffering($data);

      return $response->withStatus(201)->withJson($offering['data']);

    } catch (DatabaseException $e) {
      return $response->withStatus(500)->withJson(["error" => $e->getMessage()]);
    }
  }

  public function approveOffering(Request $request, Response $response, $args)  {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      $offering = $this->offering->getOfferingById($paginator, $id);

      // Verificar que el token contenga UserType y sea un administrador
      if ($jwt['data']->UserType !== 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to approve this offering"
          ]
        ]);
      }

      // Verificar que la oferta se obtuvo correctamente
      if (empty($offering['data'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "The specified offering does not exist"
          ]
        ]);
      }

      $offeringData = $offering['data'][0];

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

      return $response->withStatus(201)->withJson([
        "message" => "Offering approved successfully",
        "offering" => $offering
      ]);

    } catch (DatabaseException $e) {
      return $response->withStatus(500)->withJson(["error" => $e->getMessage()]);
    }
  }

  // public function updateOffering(Request $request, Response $response, $args) {
  //     $jwt = $request->getAttribute('jwt');
  //     $id = $args['id'];
  //     $paginator = paginator($request);

  //     if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
  //         return $response->withStatus(401)->withJson([
  //             "error" => [
  //                 "code" => "INVALID_TOKEN",
  //                 "desc" => "Invalid JWT token"
  //             ]
  //         ]);
  //     }

  //     $userID = $jwt['data']->UserID;
  //     $data = $request->getParsedBody();

  //     try {
  //         $offering = $this->offering->getOfferingById($paginator, $id);

  //         // Verificar que la oferta se obtuvo correctamente
  //         if (empty($offering['data'])) {
  //             return $response->withStatus(404)->withJson([
  //                 "error" => [
  //                     "code" => "OFFERING_NOT_FOUND",
  //                     "desc" => "The specified offering does not exist"
  //                 ]
  //             ]);
  //         }

  //         // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
  //         if ($offering['data'][0]['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
  //             return $response->withStatus(401)->withJson([
  //                 "error" => [
  //                     "code" => "UNAUTHORIZED",
  //                     "desc" => "You don't have permission to modify this offering."
  //                 ]
  //             ]);
  //         }

  //         // Valida categoryID contra suscripción del usuario
  //         if (!$this->userBelongsToCategory($userID, $data['CategoryID'])) {
  //             return $response->withStatus(400)->withJson([
  //                 "code" => "WRONG_CATEGORY",
  //                 "desc" => "The user does not belong to selected category"
  //             ]);
  //         }

  //         // Valida contenido con Perspective API
  //         if ($this->containsInappropriateContent($data['Title']) ||
  //         $this->containsInappropriateContent($data['Description'])) {
  //             return $response->withStatus(400)->withJson([
  //                 "code" => "INAPPROPRIATE_CONTENT",
  //                 "desc" => "Please remove inappropriate content and try again."
  //             ]);
  //         }

  //         $offering = $this->offering->updateOffering($id, $data);

  //         return $response->withStatus(201)->withJson([
  //             "message" => "Offering updated successfully",
  //             "offering" => $offering
  //         ]);

  //     } catch (ValidationException $e) {
  //         $response = $response->withStatus(422);
  //         $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
  //     } catch (DatabaseException $e) {
  //         $response = $response->withStatus(500);
  //         $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
  //     }
  //     return $response->withHeader('Content-Type', 'application/json');
  // }

  public function updateOffering(Request $request, Response $response, $args)  {
    $jwt = $request->getAttribute('jwt');
    $id = $args['id'];
    $paginator = paginator($request);

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
      $offering = $this->offering->getOfferingById($paginator, $id);

      // Verificar que la oferta se obtuvo correctamente
      if (empty($offering['data'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "The specified offering does not exist"
          ]
        ]);
      }

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offering['data'][0]['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }

      // Validación contra la suscripción del usuario para la categoría
      if (!$this->userBelongsToCategory($userID, $data['CategoryID'])) {
        return $response->withStatus(400)->withJson([
          "code" => "WRONG_CATEGORY",
          "desc" => "The user does not belong to the selected category"
        ]);
      }

      // Validación de contenido inapropiado
      if ($this->containsInappropriateContent($data['Title']) || $this->containsInappropriateContent($data['Description'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
          "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      // Actualizar la oferta
      $offering = $this->offering->updateOffering($id, $data);

      return $response->withStatus(200)->withJson([
        "message" => "Offering updated successfully",
        "offering" => $offering
      ]);

    } catch (ValidationException $e) {
      $response = $response->withStatus(422);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function deleteOffering(Request $request, Response $response, $args)  {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);

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
      $offering = $this->offering->getOfferingById($paginator, $id);

      // Verificar que el servicio se obtuvo correctamente
      if (empty($offering['data'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "The specified offering does not exist"
          ]
        ]);
      }

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offering['data'][0]['UserID'] != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You don't have permission to modify this offering."
          ]
        ]);
      }

      $offeringData = $offering['data'][0];

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

    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  // public function updateOfferingMedia(Request $request, Response $response, $args)  {
  //     $jwt = $request->getAttribute('jwt');
  //     $userId = $jwt['data']->UserID;
  //     $id = $args['id'];
  //     $paginator = paginator($request);

  //     if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
  //         return $response->withStatus(401)->withJson([
  //             "error" => [
  //                 "code" => "INVALID_TOKEN",
  //                 "desc" => "Invalid JWT token"
  //             ]
  //         ]);
  //     }

  //     try {
  //         $offering = $this->offering->getOfferingById($paginator, $id);

  //         // Verificar que la oferta se obtuvo correctamente
  //         if (empty($offering['data'])) {
  //             return $response->withStatus(404)->withJson([
  //                 "error" => [
  //                     "code" => "OFFERING_NOT_FOUND",
  //                     "desc" => "The specified offering does not exist"
  //                 ]
  //             ]);
  //         }

  //         // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
  //         if ($offering['data'][0]['UserID'] != $userId && $jwt['data']->UserType != 'Admin') {
  //             return $response->withStatus(401)->withJson([
  //                 "error" => [
  //                     "code" => "UNAUTHORIZED",
  //                     "desc" => "You do not have permission to modify this user"
  //                 ]
  //             ]);
  //         }

  //         # Obtengo el archivo del body del request
  //         $uploadedFiles = $request->getUploadedFiles();
  //         $uploadedFile = $uploadedFiles['media'] ?? null;

  //         if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
  //             return $response->withStatus(400)->withJson([
  //                 "error" => [
  //                     "code" => "UPLOAD_ERROR",
  //                     "desc" => "Cannot read the attached file"
  //                 ]
  //             ]);
  //         }

  //         $result = $this->offering->updateOfferingMedia($id, $uploadedFile);
  //         if($result->http_code != 200){
  //             return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
  //         }

  //         # Retornar el usuario actualizado
  //         return $response->withStatus(200)->withJson($result->data);

  //     } catch (\Throwable $e) {
  //         return $response->withStatus(500)->withJson([
  //             "error" => [
  //                 "code" => "INTERNAL_SERVER_ERROR",
  //                 "desc" => $e->getMessage()
  //             ]
  //         ]);
  //     }
  // }

  public function updateOfferingMedia(Request $request, Response $response, $args)  {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');
    $userID = $jwt['data']->UserID;
    $paginator = paginator($request);

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
      $offering = $this->offering->getOfferingById($paginator, $id);
      if (empty($offering['data'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "The specified offering does not exist"
          ]
        ]);
      }

      // Verificar permisos
      $offeringData = $offering['data'][0];
      if ($offeringData != $userID && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this offering"
          ]
        ]);
      }

      // Obtener el archivo del request
      $uploadedFiles = $request->getUploadedFiles();
      $uploadedFile = $uploadedFiles['media'] ?? null;

      if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "UPLOAD_ERROR",
            "desc" => "Cannot read the attached file"
          ]
        ]);
      }

      // Validar el tamaño del archivo
      $fileSize = $uploadedFile->getSize();
      $mimeType = $uploadedFile->getClientMediaType();
      if (
        ($this->isImage($mimeType) && $fileSize > 5 * 1024 * 1024) ||
        (!$this->isImage($mimeType) && $fileSize > 50 * 1024 * 1024)
      ) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_BIG",
            "desc" => "Maximum size is 5MB for photos and 50MB for videos"
          ]
        ]);
      }

      // Validar el formato de archivo usando finfo_file
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $filePathTemp = $uploadedFile->getStream()->getMetadata('uri');
      $actualMimeType = finfo_file($finfo, $filePathTemp);
      finfo_close($finfo);

      $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/x-matroska'];
      if (!in_array($actualMimeType, $allowedMimeTypes)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_FORMAT_INVALID",
            "desc" => "Allowed formats are JPEG, PNG, GIF, WEBP, MP4, MKV"
          ]
        ]);
      }

      // Validar cantidad de archivos existentes
      $mediaCounts = $this->offering->getMediaCountByType($id);
      if ($this->isImage($mimeType) && $mediaCounts['image'] >= 2) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more photos to this offering"
          ]
        ]);
      }
      if (!$this->isImage($mimeType) && $mediaCounts['video'] >= 2) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MEDIA_TOO_MANY",
            "desc" => "Cannot add more videos to this offering"
          ]
        ]);
      }

      // Ruta de archivo y URL
      $fileExtension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
      $imgID = uniqid();
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
      $filePath = "$uploadDirectory/offering/$imgID.$fileExtension";
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/offering/$imgID.$fileExtension";

      // Mover el archivo al destino
      $uploadedFile->moveTo($filePath);

      // Insertar media en la base de datos
      $this->offering->updateOfferingMedia($id, $fileURL, $filePath, $this->isImage($mimeType) ? 'image' : 'video');

      return $response->withStatus(201)->withJson([
        "message" => "Media file added successfully",
        "URL" => $fileURL
      ]);
    } catch (\Exception $e) {
      if (isset($filePath) && is_file($filePath)) {
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

  // Función para validar si el archivo es imagen
  private function isImage($mimeType)  {
    return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
  }

  public function deleteOfferingMedia(Request $request, Response $response, $args)  {
    $id = $args['id'];
    $mediaID = $args['mediaID'];
    $jwt = $request->getAttribute('jwt');
    $paginator = paginator($request);
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
      $offering = $this->offering->getOfferingById($paginator, $id);

      // Verificar que la oferta se obtuvo correctamente
      if (empty($offering['data'])) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "The specified offering does not exist"
          ]
        ]);
      }

      // Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
      if ($offering['data'][0]['UserID'] != $userId && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
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

      return $response->withStatus(200)->withJson(["message" => "Media file deleted successfully"]);

    } catch (DatabaseException $e) {
      return $response->withStatus(500)->withJson(["error" => $e->getMessage()]);
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
