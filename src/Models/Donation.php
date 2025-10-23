<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Donation
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getDonations($userID, $paginator){
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS d.VoucherID, d.GuideID,
      d.RaffleCode, d.RedeemCodeMasked, d.Status, d.CreatedAt, d.AssignedAt, d.RedeemedAt,
      d.ExpiredAt, d.CanceledAt, d.CanceledBy, d.AgencyID, d.RaffleChannel, d.BookingID,
      -- Subconsulta offering
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'OfferingID', o.OfferingID,
          'Title', o.Title,
          'ShortDescription', o.ShortDescription,
          'ImgURL', m.URL
        )
      ) FROM Offerings as o
      LEFT JOIN Media AS m ON o.OfferingID = m.OfferingID
      WHERE o.OfferingID = d.OfferingID) AS Offering,
      -- Subconsulta para Ganadores
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL,
          'UserType', u.UserType
        )
      ) FROM Users as u
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      WHERE u.UserID = d.WinnerUserID) AS Winner
      FROM DonationVouchers AS d
      ORDER BY d.CreatedAt DESC
      LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Winner'] = @json_decode($e['Winner'], true);
        $e['Offering'] = @json_decode($e['Offering'], true);
        return $e;
      }, $rs);

      return (object) [
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

  public function getMontlyDonations($userID, $paginator){
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS d.VoucherID, d.GuideID,
      d.RaffleCode, d.RedeemCodeMasked, d.Status, d.CreatedAt, d.AssignedAt, d.RedeemedAt,
      d.ExpiredAt, d.CanceledAt, d.CanceledBy, d.AgencyID, d.RaffleChannel, d.BookingID,
      -- Subconsulta offering
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'OfferingID', o.OfferingID,
          'Title', o.Title,
          'ShortDescription', o.ShortDescription,
          'ImgURL', m.URL
        )
      ) FROM Offerings as o
      LEFT JOIN Media AS m ON o.OfferingID = m.OfferingID
      WHERE o.OfferingID = d.OfferingID) AS Offering,
      -- Subconsulta para Ganadores
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL,
          'UserType', u.UserType
        )
      ) FROM Users as u
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      WHERE u.UserID = d.WinnerUserID) AS Winner
      FROM DonationVouchers AS d
      WHERE d.Status <> 'canceled' AND d.CreatedAt >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
      AND d.CreatedAt < DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 1 MONTH)
      ORDER BY d.CreatedAt DESC
      LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Winner'] = @json_decode($e['Winner'], true);
        $e['Offering'] = @json_decode($e['Offering'], true);
        return $e;
      }, $rs);

      return (object) [
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

  public function getDonationById($voucherID){
    try {
      $stmt = $this->db->prepare("SELECT d.VoucherID, d.GuideID,
      d.RaffleCode, d.RedeemCodeMasked, d.Status, d.CreatedAt, d.AssignedAt, d.RedeemedAt,
      d.ExpiredAt, d.CanceledAt, d.CanceledBy, d.AgencyID, d.RaffleChannel, d.BookingID,
      -- Subconsulta offering
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'OfferingID', o.OfferingID,
          'Title', o.Title,
          'ShortDescription', o.ShortDescription,
          'ImgURL', m.URL
        )
      ) FROM Offerings as o
      LEFT JOIN Media AS m ON o.OfferingID = m.OfferingID
      WHERE o.OfferingID = d.OfferingID) AS Offering,
      -- Subconsulta para Ganadores
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL,
          'UserType', u.UserType
        )
      ) FROM Users as u
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      WHERE u.UserID = d.WinnerUserID) AS Winner
      FROM DonationVouchers AS d
      WHERE d.VoucherID = :voucherID");

      $stmt->bindValue(':voucherID', $voucherID, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return null;
      }

      $offering = $rs[0];

      $offering['Winner'] = @json_decode($offering['Winner'], true);
      $offering['Offering'] = @json_decode($offering['Offering'], true);

      return $offering;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getDonationByRedeemCode($redeemCode){
    try {
      $stmt = $this->db->prepare("SELECT d.VoucherID, d.GuideID,
      d.RaffleCode, d.RedeemCodeMasked, d.Status, d.CreatedAt, d.AssignedAt, d.RedeemedAt,
      d.ExpiredAt, d.CanceledAt, d.CanceledBy, d.AgencyID, d.RaffleChannel, d.BookingID,
      -- Subconsulta offering
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'OfferingID', o.OfferingID,
          'Title', o.Title,
          'ShortDescription', o.ShortDescription,
          'ImgURL', m.URL
        )
      ) FROM Offerings as o
      LEFT JOIN Media AS m ON o.OfferingID = m.OfferingID
      WHERE o.OfferingID = d.OfferingID) AS Offering,
      -- Subconsulta para Ganadores
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'UserID', u.UserID,
          'UserName', u.UserName,
          'DisplayName', u.DisplayName,
          'ImgURL', m.URL,
          'UserType', u.UserType
        )
      ) FROM Users as u
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      WHERE u.UserID = d.WinnerUserID) AS Winner
      FROM DonationVouchers AS d
      WHERE d.RedeemCode = :redeemCode");

      $stmt->bindValue(':redeemCode', $redeemCode, PDO::PARAM_STR);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return null;
      }

      $offering = $rs[0];

      $offering['Winner'] = @json_decode($offering['Winner'], true);
      $offering['Offering'] = @json_decode($offering['Offering'], true);

      return $offering;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createDonation($userID, $offeringID){
    try{
      // Evita que pueda llegar a repetirse un rafflecode o redeemcode
      $raffleCode = "";
      $redeemCode = "";
      $redeemCodeMasked = "";
      do{
        $raffleCode = $this -> _generateRaffleCode();
        $redeemCode = $this -> _generateRedeemCode();
        $aRedeemCode = explode("-", $redeemCode);
        $redeemCodeMasked = "****-****-".$aRedeemCode[2];

        $stmt = $this->db->prepare("SELECT VoucherID FROM DonationVouchers
          WHERE RaffleCode = :raffleCode OR RedeemCode = :redeemCode");
        $stmt->bindValue(':raffleCode', $raffleCode, PDO::PARAM_STR);
        $stmt->bindValue(':redeemCode', $redeemCode, PDO::PARAM_STR);
        $stmt->execute();

        $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }while(!empty($rs));

      $stmt = $this->db->prepare("INSERT INTO DonationVouchers
        (GuideID, OfferingID, RaffleCode, RedeemCode, RedeemCodeMasked) VALUES
        (:guideID, :offeringID, :raffleCode, :redeemCode, :redeemCodeMasked)");

      $stmt->bindParam(':guideID', $userID, PDO::PARAM_INT);
      $stmt->bindParam(':offeringID', $offeringID, PDO::PARAM_INT);
      $stmt->bindParam(':raffleCode', $raffleCode, PDO::PARAM_STR);
      $stmt->bindParam(':redeemCode', $redeemCode, PDO::PARAM_STR);
      $stmt->bindParam(':redeemCodeMasked', $redeemCodeMasked, PDO::PARAM_STR);

      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Genera un RaffleCode - Código público de sorteo
   * Formato: XYZ9876578 (10 caracteres alfanuméricos)
   */
  private function _generateRaffleCode() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
      $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
  }

  /**
   * Genera un RedeemCode - Código secreto de canje
   * Formato: XXXX-XXXX-XXX (3 bloques de 4 caracteres con guion)
   */
  private function _generateRedeemCode() {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // Sin caracteres ambiguos
    $code = '';

    for ($i = 0; $i < 12; $i++) {
      $code .= $chars[random_int(0, strlen($chars) - 1)];
      // Agregar guion en el medio
      if ($i === 3 || $i === 7) {
        $code .= '-';
      }
    }
    return $code;
  }
}
