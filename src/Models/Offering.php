<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Offering {
    protected $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getOfferings($paginator) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingById($paginator, $id) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.OfferingID = :id
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByCategoryId($paginator, $categoryId) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.CategoryID = :categoryId
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByUserId($paginator, $userId) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.UserID = :userId
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function createOffering($data) {
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];

        if (empty($data)) {
            return (object)[
                "http_code" => 400,
                "error" => [
                    "code" => "INVALID_PARAMETERS",
                    "desc" => "Parameters are missing or invalid"
                ]
            ];
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO Offerings (Title, Description, CategoryID, UserID, Status, CreationDate, 
            TotalReviews, IsActive, Tags, SKU, Stock, ServiceType) VALUES (:Title, :Description, :CategoryID, :UserID, :Status,
             :CreationDate, :TotalReviews, :IsActive, :Tags, :SKU, :Stock, :ServiceType)");
            $stmt->execute([
                ":Title" => $data['Title'],
                ":Description" => $data['Description'],
                ":CategoryID" => $data['CategoryID'],
                ":UserID" => $data['UserID'],
                ":Status" => $data['Status'],
                ":CreationDate" => $data['CreationDate'],
                ":TotalReviews" => $data['TotalReviews'],
                ":IsActive" => $data['IsActive'],
                ":Tags" => json_encode($data['Tags']),
                ":SKU" => $data['SKU'] ?? null,
                ":Stock" => $data['Stock'] ?? null,
                ":ServiceType" => $data['ServiceType'],
            ]);
            $offeringID = $this->db->lastInsertId();
            return $this->getOfferingById($paginator, $offeringID);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function approveOfferingById($id) {
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];

        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Active', IsActive = 1, Approved = 1 WHERE OfferingID = :id AND Status != 'Deleted'");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateOffering($id, $data) {
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];

        if (empty($data)) {
            return (object)[
                "http_code" => 400,
                "error" => [
                    "code" => "INVALID_PARAMETERS",
                    "desc" => "Parameters are missing or invalid"
                ]
            ];
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM Offerings WHERE OfferingID = :id AND Status != 'Deleted'");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                throw new NotFoundException("The specified offering does not exist");
            }

            $status = ($data['Status'] === 'Active' && (!empty($data['Approved']) && $data['Approved'] == 1)) ? 'Active' : 'Pending';
            $modificationDate = date("YmdHis");

            // Actualización del offering
            $stmt = $this->db->prepare("UPDATE Offerings SET Title = :Title, Description = :Description, CategoryID = :CategoryID, 
            Status = :Status, ModificationDate = :ModificationDate, Tags = :Tags, SKU = :SKU, Stock = :Stock, 
            ServiceType = :ServiceType WHERE OfferingID = :id");
            $stmt->execute([
                ":id" => $id,
                ":Title" => $data['Title'],
                ":Description" => $data['Description'],
                ":CategoryID" => $data['CategoryID'],
                ":Status" => $status,
                ":ModificationDate" => $modificationDate,
                ":Tags" => json_encode($data['Tags']),
                ":SKU" => $data['SKU'] ?? null,
                ":Stock" => $data['Stock'] ?? null,
                ":ServiceType" => $data['ServiceType']
            ]);

            return $this->getOfferingById($paginator, $id);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteOffering($id) {
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];
        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Deleted', IsActive = 0 WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    // public function updateOfferingMedia($id, $uploadedFile)  {
    //     $paginator = (object) [
    //         'limit' => 1,   // Limita a un solo registro
    //         'offset' => 0   // No usa ningún desplazamiento
    //     ];
        
    //     $fileWritten = false; # Indica que se grabo el archivo en el FS
    //     try {
    //         # Busco al usuario y si tenia imagen antes
    //         $stmt = $this->db->prepare("SELECT o.OfferingID,m.MediaID,m.Path FROM Offerings as o
    //         LEFT JOIN Media as m ON o.OfferingID = m.OfferingID WHERE o.OfferingID = :id");
    //         $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    //         $stmt->execute();
    //         $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    //         if(empty($rs)){
    //             return (object)["http_code" => 404,
    //                 "error" => [
    //                     "code" => "OFFERING_NOT_FOUND",
    //                     "desc" => "No offering was found with the specified ID"
    //                 ]
    //             ];
    //         }

    //         $fileSize = $uploadedFile->getSize();
    //         $fileContent = $uploadedFile->getStream()->getContents();
        
    //         // Validar tamaño
    //         if (($fileSize > 5 * 1024 * 1024 && $this->isImage($fileContent)) || $fileSize > 50 * 1024 * 1024) {
    //             return ['success' => false, 'error' => [
    //                 "code" => "MEDIA_TOO_BIG",
    //                 "desc" => "Maximum size is 5MB for photos and 50MB for videos"
    //             ]];
    //         }

    //         // Validar formato
    //         $tempFilePath = tempnam(sys_get_temp_dir(), 'uploaded');
    //         file_put_contents($tempFilePath, $fileContent);
    //         $mimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tempFilePath);
    //         unlink($tempFilePath);  

    //         $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/x-matroska'];
    //         if (!in_array($mimeType, $allowedTypes)) {
    //             return ['success' => false, 'error' => [
    //                 "code" => "MEDIA_FORMAT_INVALID",
    //                 "desc" => "Allowed formats are JPEG, PNG, GIF, WEBP, MP4, MKV"
    //             ]];
    //         }
            
    //         // Validar cantidad de archivos existentes
    //         $stmt = $this->db->prepare("SELECT COUNT(*) AS count, MediaType FROM Media WHERE OfferingID = :id GROUP BY MediaType");
    //         $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    //         $stmt->execute();
    //         $mediaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    //         foreach ($mediaCounts as $mediaCount) {
    //             if ($this->isImage($mimeType) && $mediaCount['MediaType'] == 'image' && $mediaCount['count'] >= 2 ||
    //                 !$this->isImage($mimeType) && $mediaCount['MediaType'] == 'video' && $mediaCount['count'] >= 2) {
    //                 return ['success' => false, 'error' => [
    //                     "code" => "MEDIA_TOO_MANY",
    //                     "desc" => "Cannot add more photos or videos to this offering"
    //                 ]];
    //             }
    //         }

    //         $fileExtension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
    //         $imgID = uniqid();
    //         # Directorio destino
    //         $uploadDirectory = $GLOBALS['config']['media_folder']['path'];

    //         # El archivo destino se guarda con ID unico
    //         $filePath = $uploadDirectory."/offering/".$imgID.".".$fileExtension;

    //         #Grabo el archivo en el FS
    //         $uploadedFile->moveTo($filePath);
    //         $fileWritten = true;

    //         # Genero la URL del archivo
    //         $fileURL = $GLOBALS['config']['media_folder']['url']."/offering/".$imgID.".".$fileExtension;

    //         # Borro las imagenes que tuviera antes (si son locales)
    //         foreach($rs as $r){
    //             if(!is_null($r['Path']) && is_file($r['Path'])){
    //                 unlink($r['Path']);
    //             }
    //         }

    //         if(!is_null($rs[0]['MediaID'])){
    //             $stmt = $this->db->prepare("UPDATE Media SET `URL` = :fileURL, `Path` = :filePath, `MediaType` = :mediaType
    //             WHERE `OfferingID` = :id");
    //             $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    //             $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
    //             $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
    //             $mediaType = $this->isImage($mimeType) ? 'image' : 'video';
    //             $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
    //             $stmt->execute();

    //             # Borro la imagen anterior si existe en el sistema de archivos
    //             if(file_exists($rs[0]['Path'])){
    //                 unlink($rs[0]['Path']);
    //             }
    //         }else{
    //             $stmt = $this->db->prepare("INSERT INTO Media (`URL`,`OfferingID`,`Path`,`MediaType`)
    //             VALUES (:fileURL,:id,:filePath,:mediaType)");
    //             $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
    //             $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
    //             $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    //             $mediaType = $this->isImage($mimeType) ? 'image' : 'video';
    //             $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
    //             $stmt->execute();
    //         }

    //         // Devolver los datos actualizados del usuario
    //         return $this->getOfferingById($paginator, $id);
    //     } catch (\PDOException $e) {
    //         # Si hubo algun error de DB y se llego a grabar el archivo en el FS borrarlo
    //         if($fileWritten && file_exists($rs[0]['Path'])){
    //             unlink($filePath);
    //         }
    //         throw new DatabaseException($e->getMessage());
    //     } catch (Exception $e) {
    //         throw new Exception($e->getMessage());
    //     }
    // }

    public function getMediaByOfferingId($id) {
        try {
            $stmt = $this->db->prepare("SELECT MediaID, Path FROM Media WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
    
    public function getMediaCountByType($id) {
        try {
            $stmt = $this->db->prepare("SELECT MediaType, COUNT(*) as count FROM Media WHERE OfferingID = :id GROUP BY MediaType");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $mediaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            // Organizar resultados en un arreglo asociativo
            $counts = ['image' => 0, 'video' => 0];
            foreach ($mediaCounts as $mediaCount) {
                if ($mediaCount['MediaType'] == 'image') {
                    $counts['image'] = $mediaCount['count'];
                } elseif ($mediaCount['MediaType'] == 'video') {
                    $counts['video'] = $mediaCount['count'];
                }
            }
            return $counts;
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }    
    
    public function updateOfferingMedia($id, $fileURL, $filePath, $mediaType, $mediaID = null) {
        try {
            if ($mediaID) {
                // Actualizar el registro existente
                $stmt = $this->db->prepare("UPDATE Media SET URL = :fileURL, Path = :filePath, MediaType = :mediaType WHERE MediaID = :mediaID AND OfferingID = :id");
                $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
            } else {
                // Insertar un nuevo registro si no existe mediaId
                $stmt = $this->db->prepare("INSERT INTO Media (URL, OfferingID, Path, MediaType) VALUES (:fileURL, :id, :filePath, :mediaType)");
            }
    
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
            $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
            $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
    
    public function getMediaById($id, $mediaID) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM Media WHERE MediaID = :mediaID AND OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteOfferingMedia($mediaID) {
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];
        try {
            $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :mediaID");
            $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    // Función para verificar suscripción de usuario a la categoría
    public function checkUserCategorySubscription($userID, $categoryID) {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM UsersCategories WHERE UserID = :userID AND CategoryID = :categoryID");
            $stmt->execute([':userID' => $userID, ':categoryID' => $categoryID]);
            return $stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    // private function isImage($mimeType) {
    //     return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    // }
}
