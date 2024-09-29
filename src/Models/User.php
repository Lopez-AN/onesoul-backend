<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class User {
    protected $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getUsers($paginator) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
            u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
            u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
            u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
            u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
            GROUP_CONCAT(DISTINCT c.Name ORDER BY c.Name ASC SEPARATOR ', ') AS Categories,
            u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
            FROM Users as u
            LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
            LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
            LEFT JOIN Media as m ON u.UserID = m.UserID
            LEFT JOIN Reviews as r ON u.UserID = r.SUserID
            GROUP BY u.UserID
            ORDER BY u.UserID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['Categories'] = is_null($e['Categories']) ? [] : array_map('trim', explode(",", $e['Categories']));
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

    public function getUserById($id) {
        try {
            $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName, u.UserName,
            u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
            u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
            u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
            u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
            GROUP_CONCAT(DISTINCT c.Name ORDER BY c.Name ASC SEPARATOR ', ') AS Categories,
            u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
            FROM Users as u
            LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
            LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
            LEFT JOIN Media as m ON u.UserID = m.UserID
            LEFT JOIN Reviews as r ON u.UserID = r.GUserID OR u.UserID = r.SUserID
            WHERE u.UserID = :id
            GROUP BY u.UserID
            ORDER BY u.UserID");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rs)) {
                return (object)[
                    "http_code" => 404,
                    "error" => [
                        "code" => "USER_NOT_FOUND",
                        "desc" => "No user was found with the specified ID"
                    ]
                ];
            }

            $user = $rs[0];

            // Verificar si la cuenta está desactivada
            if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
                return (object)[
                    "http_code" => 401,
                    "error" => [
                        "code" => "USER_DISABLED",
                        "desc" => "The specified user is disabled"
                    ]
                ];
            }

            return (object)[
                "http_code" => 200,
                "data" => $user
            ];

        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getUsersByType($paginator, $type) {
        try {
            if($type == 'Guide'){
                $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
                u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
                u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
                u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
                u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
                GROUP_CONCAT(DISTINCT c.Name ORDER BY c.Name ASC SEPARATOR ', ') AS Categories,
                u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
                FROM Users as u
                LEFT JOIN UsersCategories as uc ON uc.UserID = u.UserID
                LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
                LEFT JOIN Media as m ON u.UserID = m.UserID
                LEFT JOIN Reviews as r ON u.UserID = r.GUserID
                ORDER BY u.UserID
                LIMIT :_limit OFFSET :_offset");
            }else{
                $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
                u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
                u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
                u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
                u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
                GROUP_CONCAT(DISTINCT c.Name ORDER BY c.Name ASC SEPARATOR ', ') AS Categories,
                u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
                FROM Users as u
                LEFT JOIN UsersCategories as uc ON uc.UserID = u.UserID
                LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
                LEFT JOIN Media as m ON u.UserID = m.UserID
                LEFT JOIN Reviews as r ON u.UserID = r.SUserID
                WHERE u.UserType = :type
                GROUP BY u.UserID
                ORDER BY u.UserID
                LIMIT :_limit OFFSET :_offset");
                $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            }

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            return (object)[
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

    public function updateUser($userId, $data) {
        try {
            // Verificar si el usuario existe
            $resp = $this->getUserById($userId);
            if($resp -> http_code != 200){
                return $resp;
            }

            // Verificar si hay campos para actualizar
            if (empty($data)) {
                return (object)[
                    "http_code" => 400,
                    "error" => [
                        "code" => "INVALID_PARAMETERS",
                        "desc" => "Parameters are missing or invalid"
                    ]
                ];
            }

            // Lista de campos permitidos para actualizar
            $allowedFields = [
                'FirstName', 'LastName', 'Email', 'Phone',
                'AddressName', 'AddressNumber', 'Floor', 'Department',
                'Cp', 'City', 'State', 'CountryCode', 'DateOfBirth',
                'Gender', 'Biography', 'UserType', 'TermsAndConditions',
                'SignedContract', 'LegalDocuments', 'shortDescription'
            ];

            // Filtrar y preparar los campos a actualizar
            $fields = [];
            foreach ($data as $key => $value) {
                if (!in_array($key, $allowedFields)) {
                    return (object)[
                        "http_code" => 400,
                        "error" => [
                            "code" => "INVALID_UPDATE_KEY",
                            "desc" => "Key '$key' present in the JSON is not supported"
                        ]
                    ];
                }
                $fields[] = "$key = :$key";
            }

            // Construir la consulta SQL para la actualización
            $sql = "UPDATE Users SET " . implode(", ", $fields) . " WHERE UserID = :UserID";
            $stmt = $this->db->prepare($sql);

            // Vincular parámetros y manejar valores NULL
            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value === null ? null : $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }

            // Vincular el ID del usuario
            $stmt->bindValue(':UserID', $userId, PDO::PARAM_INT);

            // Ejecutar la consulta
            $stmt->execute();

            // Devolver los datos actualizados del usuario
            return $this->getUserById($userId);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteUser($id) {
        try {
            $stmt = $this->db->prepare("SELECT UserID FROM Users WHERE UserID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rs)) {
                return (object)[
                    "http_code" => 404,
                    "error" => [
                        "code" => "USER_NOT_FOUND",
                        "desc" => "No user was found with the specified ID"
                    ]
                ];
            }
            $stmt = $this->db->prepare("DELETE FROM Users WHERE UserID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            return (object)[
                "http_code" => 200,
                "data" => [
                    "message" => "User deleted"
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateProfilePhoto($userId, $uploadedFile)  {
        $fileWritten = false; # Indica que se grabo el archivo en el FS
        try {
            # Busco al usuario y si tenia imagen antes
            $stmt = $this->db->prepare("SELECT u.UserID,m.MediaID,m.Path FROM Users as u
            LEFT JOIN Media as m ON u.UserID = m.UserID WHERE u.UserID = :id");
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if(empty($rs)){
                return (object)["http_code" => 404,
                    "error" => [
                        "code" => "USER_NOT_FOUND",
                        "desc" => "No user was found with the specified ID"
                    ]
                ];
            }

            # Extraigo el nombre del archivo y su extension
            $fileName = $uploadedFile->getClientFilename();
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            $imgID = uniqid(); #Le doy un ID unico a la imagen

            # Directorio destino
            $uploadDirectory = $GLOBALS['config']['media_folder']['path'];

            # El archivo destino se guarda con ID unico
            $filePath = $uploadDirectory."/user/".$imgID.".".$fileExtension;

            #Grabo el archivo en el FS
            $uploadedFile->moveTo($filePath);
            $fileWritten = true;

            # Genero la URL del archivo
            $fileURL = $GLOBALS['config']['media_folder']['url']."/user/".$imgID.".".$fileExtension;

            # Borro las imagenes que tuviera antes (si son locales)
            foreach($rs as $r){
                if(!is_null($r['Path']) && is_file($r['Path'])){
                    unlink($r['Path']);
                }
            }

            if(!is_null($rs[0]['MediaID'])){
                $stmt = $this->db->prepare("UPDATE Media SET `URL` = :fileURL, `Path` = :filePath
                WHERE `UserID` = :userID");
                $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
                $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
                $stmt->bindParam(':userID', $userId, PDO::PARAM_INT);
                $stmt->execute();

                # Borro la imagen anterior si existe en el sistema de archivos
                if(file_exists($rs[0]['Path'])){
                    unlink($rs[0]['Path']);
                }
            }else{
                $stmt = $this->db->prepare("INSERT INTO Media (`URL`,`UserID`,`Path`)
                VALUES (:fileURL,:userID,:filePath)");
                $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
                $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
                $stmt->bindParam(':userID', $userId, PDO::PARAM_INT);
                $stmt->execute();
            }

            // Devolver los datos actualizados del usuario
            return $this->getUserById($userId);
        } catch (\PDOException $e) {
            # Si hubo algun error de DB y se llego a grabar el archivo en el FS borrarlo
            if($fileWritten && file_exists($rs[0]['Path'])){
                unlink($filePath);
            }
            throw new DatabaseException($e->getMessage());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function deleteProfilePhoto($userId) {
        try {
            # Seleccionar el MediaID para eliminar la entrada
            $stmt = $this->db->prepare("SELECT m.MediaID, m.URL, m.Path FROM Media as m WHERE m.UserID = :id");
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rs[0]['MediaID'])) {
                return (object)[
                    "http_code" => 404,
                    "error" => [
                        "code" => "PHOTO_NOT_FOUND",
                        "desc" => "No profile photo found for this user"
                    ]
                ];
            }
            # Eliminar la entrada en la tabla Media
            $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :mediaID");
            $stmt->bindParam(':mediaID', $rs[0]['MediaID'], PDO::PARAM_INT);
            $stmt->execute();

            # Si existe el archivo local lo borro
            if (!is_null($rs[0]['Path']) && file_exists($rs[0]['Path'])) {
                unlink($rs[0]['Path']); // Eliminar el archivo del sistema
            }

            return (object)[
                "http_code" => 200,
                "data" => [
                    "message" => "Profile photo deleted"
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getUserCategories($userId) {
        $query = "SELECT CategoryID FROM UsersCategories WHERE UserID = :userId";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':userId', $userId);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addUserCategory($userId, $categoryId) {
        $query = "INSERT INTO UsersCategories (UserID, CategoryID) VALUES (:userId, :categoryId)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':userId', $userId);
        $stmt->bindParam(':categoryId', $categoryId);
        $stmt->execute();
    }

    public function deleteUserCategory($userId, $categoryId) {
        $query = "DELETE FROM UsersCategories WHERE UserID = :userId AND CategoryID = :categoryId";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':userId', $userId);
        $stmt->bindParam(':categoryId', $categoryId);
        $stmt->execute();
    }
}
