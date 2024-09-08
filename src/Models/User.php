<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

#require_once('/../Utils/FormatImg.php');


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
            LEFT JOIN Categories as c ON uc.categoryID = c.categoryID
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
            LEFT JOIN Categories as c ON uc.categoryID = c.categoryID
            LEFT JOIN Media as m ON u.UserID = m.UserID
            LEFT JOIN Reviews as r ON u.UserID = r.GUserID
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
    
            return $user;

        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getUsersByType($paginator, $type) {
        try {
            if($type == 'both'){
                $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
                u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
                u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
                u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
                u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
                GROUP_CONCAT(DISTINCT c.Name ORDER BY c.Name ASC SEPARATOR ', ') AS Categories,
                u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
                FROM Users as u
                LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
                LEFT JOIN Categories as c ON uc.categoryID = c.categoryID
                LEFT JOIN Media as m ON u.UserID = m.UserID
                LEFT JOIN Reviews as
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
                LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
                LEFT JOIN Categories as c ON uc.categoryID = c.categoryID
                LEFT JOIN Media as m ON u.UserID = m.UserID
                LEFT JOIN Reviews as r ON u.UserID = r.SUserID
                WHERE lower(u.UserType) = 'both' OR u.UserType = :type
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

    public function updateUser($id, $data) {
        try {
            // Verificar si el usuario existe
            $resp = $this->getUserById($id);
            if (empty($resp)) {
                return (object)[
                    "http_code" => 404,
                    "error" => [
                        "code" => "USER_NOT_FOUND",
                        "desc" => "No user was found with the specified ID"
                    ]
                ];
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
                            "desc" => "Key $key present in the JSON is not supported"
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
            $stmt->bindValue(':UserID', $id, PDO::PARAM_INT);

            // Ejecutar la consulta
            $stmt->execute();

            // Devolver los datos actualizados del usuario
            return $this->getUserById($id);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteUser($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM Users WHERE UserID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    private function validateUser($data) {
        if (empty($data['UserName'])) {
            throw new ValidationException('Username is required');
        }
        if (empty($data['Email'])) {
            throw new ValidationException('Email is required');
        }
    }
}
