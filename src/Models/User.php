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

    public function getUserById($paginator, $id) {
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
            LEFT JOIN Media as m ON u.UserID = m.UserID WHERE u.UserID = :id
            LEFT JOIN Reviews as r ON u.UserID = r.SUserID
            GROUP BY u.UserID
            ORDER BY u.UserID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
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

    public function createUser($data) {
#        if(isset($data['imgBase64']) && !empty($data['imgBase64'])){
#            $img = base64_decode($data['imgBase64']);
#            $ext = imgFormat();
#            if($ext != NULL){
#                file_put_contents("/usr/share/img/users/".$data['userid'].".$ext");
#            }
#        }

#        if(isset($data['imgURL']) && !empty($data['imgURL'])){
#            $img = @file_get_contents($data['imgURL']);
#            $ext = imgFormat();
#            if($ext != NULL){
#                file_put_contents("/usr/share/img/users/".$data['userid'].".$ext");
#            }
#        }

        $this->validateUser($data);
        try {
            $stmt = $this->db->prepare("INSERT INTO Users (FirstName, LastName, UserName, PasswordHash, Email, Phone, 
            AddressName, AddressNumber, Floor, Department, Cp, City, State, CountryCode, DateOfBirth, Gender, Biography, 
            ValidatedEmail, TwoFactorAuth, UserType, RegistrationDate, LastLogin, DeactivationDate, UserLevel, 
            TermsAndConditions, SignedContract, LegalDocuments) VALUES (:FirstName, :LastName, :UserName, :PasswordHash, 
            :Email, :Phone, :AddressName, :AddressNumber, :Floor, :Department, :Cp, :City, :State, :CountryCode, :DateOfBirth, 
            :Gender, :Biography, :ValidatedEmail, :TwoFactorAuth, :UserType, :RegistrationDate, :LastLogin, :DeactivationDate, 
            :UserLevel, :TermsAndConditions, :SignedContract, :LegalDocuments)");
            $stmt->execute($data);
            return $this->getUserById($this->db->lastInsertId());
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateUser($id, $data) {
        $this->validateUser($data);
        try {
            $stmt = $this->db->prepare("UPDATE Users SET FirstName = :FirstName, LastName = :LastName, UserName = :UserName, 
            PasswordHash = :PasswordHash, Email = :Email, Phone = :Phone, AddressName = :AddressName, AddressNumber = 
            :AddressNumber, Floor = :Floor, Department = :Department, Cp = :Cp, City = :City, State = :State, CountryCode = 
            :CountryCode, DateOfBirth = :DateOfBirth, Gender = :Gender, Biography = :Biography, ValidatedEmail = :ValidatedEmail, 
            TwoFactorAuth = :TwoFactorAuth, UserType = :UserType, RegistrationDate = :RegistrationDate, LastLogin = :LastLogin, 
            DeactivationDate = :DeactivationDate, UserLevel = :UserLevel, TermsAndConditions = :TermsAndConditions, SignedContract
             = :SignedContract, LegalDocuments = :LegalDocuments WHERE UserID = :UserID");
            $data['UserID'] = $id;
            $stmt->execute($data);
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
