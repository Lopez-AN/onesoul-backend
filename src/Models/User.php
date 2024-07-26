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

    public function getUsers() {
        try {
            $stmt = $this->db->query("SELECT u.*, m.URL FROM Users as u
            LEFT JOIN Media as m ON u.UserID = m.UserID");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getUserById($id) {
        try {
            $stmt = $this->db->prepare("SELECT u.*, m.URL FROM Users as u
            LEFT JOIN Media as m ON u.UserID = m.UserID WHERE u.UserID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getUsersByType($type) {
        try {
            $stmt = $this->db->prepare("SELECT u.*, m.URL FROM Users as u
            LEFT JOIN Media as m ON u.UserID = m.UserID WHERE u.UserType = :type");
            $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
