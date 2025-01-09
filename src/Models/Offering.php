<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Offering
{
    protected $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getOfferings($paginator){
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID, 
            u.FirstName as author_FirstName, u.LastName as author_LastName, m.URL as author_imgURL 
            FROM Offerings AS o 
            INNER JOIN Users AS u ON u.UserID = o.UserID 
            LEFT JOIN Media AS m ON u.UserID = m.UserID
            ORDER BY o.OfferingID 
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($offerings as &$offering) {
                $mediaStmt = $this->db->prepare("SELECT MediaID, MediaType, URL, Title, Description, position FROM Media WHERE OfferingID = :offeringID");
                $mediaStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $mediaStmt->execute();

                $mediaResults = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
                $media = ["images" => [], "videos" => []];

                foreach ($mediaResults as $mediaItem) {
                    $mediaData = [
                        "id" => $mediaItem['MediaID'],
                        "url" => $mediaItem['URL'],
                        "Title" => $mediaItem['Title'],
                        "Description" => $mediaItem['Description'],
                        "position" => $mediaItem['position']
                    ];

                    if ($mediaItem['MediaType'] === 'image') {
                        $media['images'][] = $mediaData;
                    } elseif ($mediaItem['MediaType'] === 'video') {
                        $media['videos'][] = $mediaData;
                    }
                }

                $offering['media'] = $media;

                // Obtener FAQs
                $faqStmt = $this->db->prepare("SELECT position, question, answer 
                FROM OfferingsFaqs 
                WHERE OfferingID = :offeringID
                ORDER BY position ASC");


                $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $faqStmt->execute();

                $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);
                $offering['faqs'] = $faqs;

                // Obtener Packages
                $packagesStmt = $this->db->prepare("SELECT package, price, description, conditions, sessionType 
                FROM OfferingsPackages 
                WHERE OfferingID = :offeringID");
                
                $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $packagesStmt->execute();

                $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

                $offering['packages'] = $packages;

                // Obtener el UserID del Offering
                $offering['author'] = [
                    "UserID" => $offering['author_UserID'],
                    "FirstName" => $offering['author_FirstName'],
                    "LastName" => $offering['author_LastName'],
                    "imgURL" => $offering['author_imgURL']
                ];

                unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'], $offering['author_imgURL']);
            }

            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                "data" => $offerings,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($offerings)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }


    public function getOfferingById($id){
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, 
                   u.UserID as author_UserID, u.FirstName as author_FirstName, u.LastName as author_LastName, 
                   m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.OfferingID = :id");
    
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
    
            $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            if (empty($offerings)) {
                return (object) [
                    "http_code" => 404,
                    "error" => [
                        "code" => "OFFERING_NOT_FOUND",
                        "desc" => "No offering was found with the specified ID"
                    ]
                ];
            }
  
        // Consulta para FAQs
        $stmtFaqs = $this->db->prepare("
            SELECT Position, Question, Answer 
            FROM OfferingsFaqs 
            WHERE OfferingID = :id
            ORDER BY Position ASC
        ");
        $stmtFaqs->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtFaqs->execute();
        $faqs = $stmtFaqs->fetchAll(PDO::FETCH_ASSOC);

        // Consulta para Packages
        $stmtPackages = $this->db->prepare("
            SELECT Package, Price, Description, Conditions, SessionType 
            FROM OfferingsPackages 
            WHERE OfferingID = :id
        ");
        $stmtPackages->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtPackages->execute();
        $packages = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);

        // Proceso de organización de medios
        $stmtMedia = $this->db->prepare("
            SELECT MediaID, URL, Title, Description, MediaType, Position
            FROM Media
            WHERE OfferingID = :id
            ORDER BY Position ASC
        ");
        $stmtMedia->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtMedia->execute();
        $mediaData = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);
        
        $media = ["images" => [], "videos" => []];
        foreach ($mediaData as $item) {
            $mediaItem = [
                "position" => $item["Position"],
                "id" => $item['MediaID'],
                "url" => $item['URL'],
                "Title" => $item['Title'],
                "Description" => $item['Description']
                
            ];
            if ($item['MediaType'] === 'image') {
                $media["images"][] = $mediaItem;
            } elseif ($item['MediaType'] === 'video') {
                $media["videos"][] = $mediaItem;
            }
        }

            // Construcción del objeto principal
            $offering = $offerings[0];    
            $offering['media'] = $media;
            $offering['faqs'] = $faqs;
            $offering['packages'] = $packages;
            $offering['author'] = [
                "UserID" => $offering['author_UserID'],
                "FirstName" => $offering['author_FirstName'],
                "LastName" => $offering['author_LastName'],
                "imgURL" => $offering['author_imgURL']
            ];
    
            unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'], $offering['author_imgURL']);
    
            return (object) [
                "http_code" => 200,
                "data" => $offering
            ];
            
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByCategoryId($paginator, $categoryId){
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
            u.FirstName as author_FirstName, u.LastName as author_LastName, m.URL as author_imgURL 
            FROM Offerings AS o 
            INNER JOIN Users AS u ON u.UserID = o.UserID 
            LEFT JOIN Media AS m ON u.UserID = m.UserID 
            WHERE o.CategoryID = :categoryId 
            ORDER BY o.OfferingID 
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($offerings as &$offering) {
                $mediaStmt = $this->db->prepare("SELECT MediaID, MediaType, URL, Title, Description, position FROM Media WHERE OfferingID = :offeringID");
                $mediaStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $mediaStmt->execute();

                $mediaResults = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
                $media = ["images" => [], "videos" => []];

                foreach ($mediaResults as $mediaItem) {
                    $mediaData = [
                        "id" => $mediaItem['MediaID'],
                        "url" => $mediaItem['URL'],
                        "Title" => $mediaItem['Title'],
                        "Description" => $mediaItem['Description'],
                        "position" => $mediaItem['position']
                    ];

                    if ($mediaItem['MediaType'] === 'image') {
                        $media['images'][] = $mediaData;
                    } elseif ($mediaItem['MediaType'] === 'video') {
                        $media['videos'][] = $mediaData;
                    }
                }

                $offering['media'] = $media;

                // Obtener FAQs
                $faqStmt = $this->db->prepare("SELECT position, question, answer 
                FROM OfferingsFaqs 
                WHERE OfferingID = :offeringID
                ORDER BY position ASC");


                $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $faqStmt->execute();

                $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);
                $offering['faqs'] = $faqs;

                // Obtener Packages
                $packagesStmt = $this->db->prepare("SELECT package, price, description, conditions, sessionType 
                FROM OfferingsPackages 
                WHERE OfferingID = :offeringID");
                
                $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $packagesStmt->execute();

                $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

                $offering['packages'] = $packages;

                // Obtener el UserID del Offering
                $offering['author'] = [
                    "UserID" => $offering['author_UserID'],
                    "FirstName" => $offering['author_FirstName'],
                    "LastName" => $offering['author_LastName'],
                    "imgURL" => $offering['author_imgURL']
                ];

                unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'], $offering['author_imgURL']);
            }

            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                "data" => $offerings,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($offerings)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }


    public function getOfferingsByUserId($paginator, $userId){
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
            u.FirstName as author_FirstName, u.LastName as author_LastName, m.URL as author_imgURL 
            FROM Offerings AS o 
            INNER JOIN Users AS u ON u.UserID = o.UserID 
            LEFT JOIN Media AS m ON u.UserID = m.UserID 
            WHERE o.UserID = :userId 
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($offerings as &$offering) {
                $mediaStmt = $this->db->prepare("SELECT MediaID, MediaType, URL, Title, Description, position FROM Media WHERE OfferingID = :offeringID");
                $mediaStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $mediaStmt->execute();

                $mediaResults = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
                $media = ["images" => [], "videos" => []];

                foreach ($mediaResults as $mediaItem) {
                    $mediaData = [
                        "id" => $mediaItem['MediaID'],
                        "url" => $mediaItem['URL'],
                        "Title" => $mediaItem['Title'],
                        "Description" => $mediaItem['Description'],
                        "position" => $mediaItem['position']
                    ];

                    if ($mediaItem['MediaType'] === 'image') {
                        $media['images'][] = $mediaData;
                    } elseif ($mediaItem['MediaType'] === 'video') {
                        $media['videos'][] = $mediaData;
                    }
                }

                $offering['media'] = $media;

                // Obtener FAQs
                $faqStmt = $this->db->prepare("SELECT position, question, answer 
                FROM OfferingsFaqs 
                WHERE OfferingID = :offeringID
                ORDER BY position ASC");


                $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $faqStmt->execute();

                $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);
                $offering['faqs'] = $faqs;

                // Obtener Packages
                $packagesStmt = $this->db->prepare("SELECT package, price, description, conditions, sessionType 
                FROM OfferingsPackages 
                WHERE OfferingID = :offeringID");
                
                $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
                $packagesStmt->execute();

                $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

                $offering['packages'] = $packages;

                // Obtener el UserID del Offering
                $offering['author'] = [
                    "UserID" => $offering['author_UserID'],
                    "FirstName" => $offering['author_FirstName'],
                    "LastName" => $offering['author_LastName'],
                    "imgURL" => $offering['author_imgURL']
                ];

                unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'], $offering['author_imgURL']);
            }

            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                "data" => $offerings,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($offerings)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function createOffering($data){
        if (empty($data)) {
            return (object) [
                "http_code" => 400,
                "error" => [
                    "code" => "INVALID_PARAMETERS",
                    "desc" => "Parameters are missing or invalid"
                ]
            ];
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO Offerings (Title, ShortDescription, Description, CategoryID, UserID,
            Status, CreationDate, IsActive, Tags, SKU, Stock, ServiceType)
            VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status, :CreationDate, 0, :Tags, :SKU, :Stock, :ServiceType)");

            $stmt->bindParam(':Title', $data['Title'], PDO::PARAM_STR);
            $stmt->bindParam(':ShortDescription', $data['ShortDescription'], PDO::PARAM_STR);
            $stmt->bindParam(':Description', $data['Description'], PDO::PARAM_STR);
            $stmt->bindParam(':CategoryID', $data['CategoryID'], PDO::PARAM_INT);
            $stmt->bindParam(':UserID', $data['UserID'], PDO::PARAM_INT);
            $stmt->bindValue(':Status', 'Pending', PDO::PARAM_STR);
            $stmt->bindValue(':CreationDate', date('YmdHis'), PDO::PARAM_STR);
            $stmt->bindValue(':Tags', implode(",", $data['Tags']), PDO::PARAM_STR);
            $stmt->bindValue(':SKU', $data['SKU'] ?? null, ($data['SKU'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':Stock', $data['Stock'] ?? null, ($data['Stock'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':ServiceType', $data['ServiceType'], PDO::PARAM_STR);

            $stmt->execute();
            $offeringID = $this->db->lastInsertId();

            if (isset($data['FAQS'])) {
                foreach ($data['FAQS'] as $s) {
                    $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, position, question, answer)
            VALUES (:OfferingID, :position, :question, :answer)");

                    $stmt->bindParam(':OfferingID', $offeringID, PDO::PARAM_INT);
                    $stmt->bindParam(':position', $s['position'], PDO::PARAM_INT);
                    $stmt->bindParam(':question', $s['question'], PDO::PARAM_STR);
                    $stmt->bindParam(':answer', $s['answer'], PDO::PARAM_STR);
                    $stmt->execute();
                }
            }

            return $this->getOfferingById($offeringID);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function approveOfferingById($id){
        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Active', IsActive = 1, Approved = 1
            WHERE OfferingID = :id AND Status != 'Deleted'");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateOffering($id, $data) {
        if (empty($data)) {
            return (object) [
                "http_code" => 400,
                "error" => [
                    "code" => "INVALID_PARAMETERS",
                    "desc" => "Parameters are missing or invalid"
                ]
            ];
        }
    
        try {
            // Verificar si la oferta existe
            $stmt = $this->db->prepare("SELECT * FROM Offerings WHERE OfferingID = :id AND Status != 'Deleted'");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $offering = $stmt->fetch();
            if (!$offering) {
                throw new NotFoundException("The specified offering does not exist");
            }
    
            // Lista de campos permitidos para actualizar
            $allowedFields = [
                'Title',
                'ShortDescription',
                'Description',
                'CategoryID',
                'Status',
                'Tags',
                'SKU',
                'Stock',
                'ServiceType'
            ];
    
            // Construcción dinámica de la consulta
            $fields = [];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                } else {
                    return (object) [
                        "http_code" => 400,
                        "error" => [
                            "code" => "INVALID_UPDATE_KEY",
                            "desc" => "Key '$field' is not allowed to be updated"
                        ]
                    ];
		        }
            }    
    
            if (empty($fields)) {
                return (object) [
                    "http_code" => 400,
                    "error" => [
                        "code" => "NO_FIELDS_TO_UPDATE",
                        "desc" => "No valid fields to update"
                    ]
                ];
            }
    
            // Modificación de la fecha de modificación
            $modificationDate = date("YmdHis");
    
            // Construir la consulta SQL de actualización
            $sql = "UPDATE Offerings SET " . implode(", ", $fields) . ", ModificationDate = :ModificationDate WHERE OfferingID = :id";
            $stmt = $this->db->prepare($sql);
    
            // Vincular los parámetros
            foreach ($data as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $stmt->bindValue(":$key", $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                }
            }
    
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':ModificationDate', $modificationDate, PDO::PARAM_STR);
            $stmt->execute();
    
            // Gestionar los FAQs, si están presentes en los datos
            if (isset($data['FAQS']) && is_array($data['FAQS'])) {
                // Eliminar los FAQs existentes para esta oferta
                $stmt = $this->db->prepare("DELETE FROM OfferingsFaqs WHERE OfferingID = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
    
                // Insertar los nuevos FAQs
                $stmt = $this->db->prepare(
                    "INSERT INTO OfferingsFaqs (OfferingID, position, question, answer) 
                    VALUES (:OfferingID, :position, :question, :answer)"
                );
    
                foreach ($data['FAQS'] as $faq) {
                    $stmt->bindParam(':OfferingID', $id, PDO::PARAM_INT);
                    $stmt->bindParam(':position', $faq['position'], PDO::PARAM_INT);
                    $stmt->bindParam(':question', $faq['question'], PDO::PARAM_STR);
                    $stmt->bindParam(':answer', $faq['answer'], PDO::PARAM_STR);
                    $stmt->execute();
                }
            }

            // Gestionar los paquetes, si están presentes en los datos
            if (isset($data['packages']) && is_array($data['packages'])) {
            
            // Eliminar los paquetes existentes para esta oferta
            $stmt = $this->db->prepare("DELETE FROM OfferingsPackages WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            // Insertar los nuevos paquetes
            $stmt = $this->db->prepare(
                "INSERT INTO OfferingsPackages (OfferingID, package, price, description, conditions, sessionType) 
                VALUES (:OfferingID, :package, :price, :description, :conditions, :sessionType)"
            );

            foreach ($data['packages'] as $package) {
                $stmt->bindParam(':OfferingID', $id, PDO::PARAM_INT);
                $stmt->bindParam(':package', $package['package'], PDO::PARAM_STR);
                $stmt->bindParam(':price', $package['price'], PDO::PARAM_STR);
                $stmt->bindParam(':description', $package['description'], PDO::PARAM_STR);
                $stmt->bindParam(':conditions', $package['conditions'], PDO::PARAM_STR);
                $stmt->bindParam(':sessionType', $package['sessionType'], PDO::PARAM_STR);
                $stmt->execute();
            }
        }
    
            return $this->getOfferingById($id);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteOffering($id){
        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Deleted', IsActive = 0
            WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getMediaById($id, $media_id){
        try {
            $stmt = $this->db->prepare("SELECT * FROM Media
            WHERE MediaID = :media_id AND OfferingID = :id");
            $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function createOfferingMedia($id, $position, $fileURL, $filePath, $mediaType){
        try {
            // Verificar si ya existe una posición 0 asociada al OfferingID
            $stmt = $this->db->prepare("SELECT COUNT(*) AS count FROM Media WHERE OfferingID = :id AND Position = 0 AND MediaType = 'image'");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
            // Asignar posición 0 si no existe ninguna imagen en esa posición
            $position = ($result['count'] == 0 && $mediaType === 'image') ? 0 : null;
        
            // Calcular la próxima posición si no es posición 0
            if ($position === null) {
                $stmt = $this->db->prepare("SELECT MAX(Position) AS max_position FROM Media WHERE OfferingID = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                $maxPosition = $stmt->fetch(PDO::FETCH_ASSOC)['max_position'];
                $position = $maxPosition !== null ? $maxPosition + 1 : 1;
            }

            // Insertar en la tabla Media
            $stmt = $this->db->prepare("INSERT INTO Media (`OfferingID`, `URL`, `Path`, `MediaType`, `Position`)
            VALUES (:id, :fileURL, :filePath, :mediaType, :position)");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':position', $position, PDO::PARAM_INT);
            $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
            $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
            $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateOfferingMedia($id, $position, $media_id, $fileURL = false, $filePath = false, $mediaType = false){
        try {
            // Diferente update segun se adjunto un archivo o no
            if ($fileURL) {
                $stmt = $this->db->prepare("UPDATE Media
                SET URL = :fileURL, Path = :filePath, MediaType = :mediaType, Position = :position
                WHERE MediaID = :media_id AND OfferingID = :id");
            } else {
                $stmt = $this->db->prepare("UPDATE Media SET Position = :position
                WHERE MediaID = :media_id AND OfferingID = :id");
            }


            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':position', $position, PDO::PARAM_INT);
            $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);

            if ($fileURL) {
                $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
                $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
                $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
            }

            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteOfferingMedia($media_id){
        try {
            $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :media_id");
            $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }


    public function getMediaByOfferingId($id){
        try {
            $stmt = $this->db->prepare("SELECT MediaID, Path FROM Media
            WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getMediaCountByType($id){
        try {
            $stmt = $this->db->prepare("SELECT MediaType, COUNT(*) as count FROM Media
            WHERE OfferingID = :id GROUP BY MediaType");
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

    // Función para verificar suscripción de usuario a la categoría
    public function checkUserCategorySubscription($userID, $categoryID){
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM UsersCategories
            WHERE UserID = :userID AND CategoryID = :categoryID");
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
}