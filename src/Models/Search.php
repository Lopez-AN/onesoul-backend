<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Search {
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function searchCategories($paginator, $query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare("SELECT SQL_CALC_FOUND_ROWS c.*,m.URL as imgURL
            FROM Categories AS c
            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            WHERE `Name` LIKE :search1 OR `Description` LIKE :search2
            ORDER BY c.CategoryID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
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

    public function searchOfferings($paginator, $query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare('SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE Title LIKE :search1 OR Description LIKE :search2 OR Tags LIKE :search3
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset');

            $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
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

    public function searchUsers($paginator, $query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
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
            WHERE FirstName LIKE :search1 OR LastName LIKE :search2 OR Biography LIKE :search3
            GROUP BY u.userID
            ORDER BY u.UserID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
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
}
