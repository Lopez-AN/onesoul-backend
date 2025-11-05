<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use Exception;

class Donation{
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  public function getDonations($userID, $paginator){
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
    WHERE d.GuideID = ?
    ORDER BY d.CreatedAt DESC
    LIMIT ? OFFSET ?");

    $stmt->execute([$userID, $paginator->limit, $paginator->offset]);

    $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    $donations = array_map(function ($e) {
      $e['Winner'] = @json_decode($e['Winner'], true)[0];
      $e['Offering'] = @json_decode($e['Offering'], true)[0];
      return $e;
    }, $donations);

    return (object) [
      "data" => $donations,
      "rows" => [
        "total" => $total['total'],
        "fetched" => count($donations)
      ]
    ];
  }

  public function getMontlyDonations($userID, $paginator){
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
    WHERE d.GuideID = ? AND d.Status <> 'canceled'
    AND d.CreatedAt >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
    AND d.CreatedAt < DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 1 MONTH)
    ORDER BY d.CreatedAt DESC
    LIMIT ? OFFSET ?");

    $stmt->execute([$userID, $paginator->limit, $paginator->offset]);

    $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    $donations = array_map(function ($e) {
      $e['Winner'] = @json_decode($e['Winner'], true)[0];
      $e['Offering'] = @json_decode($e['Offering'], true)[0];
      return $e;
    }, $donations);

    return (object) [
      "data" => $donations,
      "rows" => [
        "total" => $total['total'],
        "fetched" => count($donations)
      ]
    ];
  }

  public function getDonationById($voucherID){
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
    WHERE d.VoucherID = ?");

    $stmt->execute([$voucherID]);

    $donation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($donation)) {
      return false;
    }

    $donation['Winner'] = @json_decode($donation['Winner'], true)[0];
    $donation['Offering'] = @json_decode($donation['Offering'], true)[0];

    return $donation;
  }

  public function getDonationByRedeemCode($redeemCode){
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
    WHERE d.RedeemCode = ?");

    $stmt->execute([$redeemCode]);

    $donation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($donation)) {
      return false;
    }

    $donation['Winner'] = @json_decode($donation['Winner'], true)[0];
    $donation['Offering'] = @json_decode($donation['Offering'], true)[0];

    return $donation;
  }

  public function createDonation($userID, $offeringID, $quantity){
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      for($x = 0; $x < $quantity; $x++){
        # Evita que pueda llegar a repetirse un rafflecode o redeemcode
        $raffleCode = "";
        $redeemCode = "";
        $redeemCodeMasked = "";
        do{
          $raffleCode = $this -> _generateRaffleCode();
          $redeemCode = $this -> _generateRedeemCode();
          $aRedeemCode = explode("-", $redeemCode);
          $redeemCodeMasked = "****-****-".$aRedeemCode[2];

          $stmt = $this->db->prepare("SELECT VoucherID FROM DonationVouchers
            WHERE RaffleCode = ? OR RedeemCode = ?");

          $stmt->execute([$raffleCode, $redeemCode]);

          $donation = $stmt->fetch(PDO::FETCH_ASSOC);
        }while($donation);

        $stmt = $this->db->prepare("INSERT INTO DonationVouchers
          (GuideID, OfferingID, RaffleCode, RedeemCode, RedeemCodeMasked)
          VALUES (?, ?, ?, ?, ?)");

        $stmt->execute([$userID, $offeringID, $raffleCode, $redeemCode, $redeemCodeMasked]);
      }
      $this->db->commit(); # Confirmo transacción
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  public function assignDonation($agencyID, $voucherID){
    $stmt = $this->db->prepare("UPDATE DonationVouchers
      SET AgencyID = ?, Status = 'assigned'
      WHERE VoucherID = ?");

    $stmt->execute([$agencyID, $voucherID]);
  }

  public function cancelDonation($voucherID){
    $stmt = $this->db->prepare("UPDATE DonationVouchers
      SET Status = 'canceled' WHERE VoucherID = ?");

    $stmt->execute([$voucherID]);
  }

  public function raffleCoupons($quantity) {
    # seleccionar IDs aleatorios de los cupones "draft"
    $stmt = $this->db->prepare("SELECT d.VoucherID, d.GuideID,
      d.RaffleCode, d.RedeemCode,
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
      WHERE o.OfferingID = d.OfferingID) AS Offering
      FROM DonationVouchers as d
      WHERE d.Status = 'assigned'
      ORDER BY RAND() LIMIT ?");

    $stmt->execute([$quantity]);
    $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    # Si no hay cupones salir
    if (empty($donations)) {
      return [];
    }

    $donations = array_map(function($e){
      $e['Offering'] = @json_decode($e['Offering'], true);
      return $e;
    }, $donations);

    return $donations;
  }

  public function getAgencies($paginator){
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS
      a.AgencyID, a.Name, a.ContactEmail
    FROM Agencies as a
    LIMIT ? OFFSET ?");

    $stmt->execute([$paginator->limit, $paginator->offset]);

    $agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return (object) [
      "data" => $agencies,
      "rows" => [
        "total" => $total['total'],
        "fetched" => count($agencies)
      ]
    ];
  }

  public function getAgencyById($agencyID){
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS
      a.AgencyID, a.Name, a.ContactEmail
    FROM Agencies as a
    WHERE a.AgencyID = ?");

    $stmt->execute([$agencyID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public function createAgency($name, $contactEmail){
    $stmt = $this->db->prepare("INSERT INTO Agencies
      (Name, ContactEmail) VALUES (?, ?)");

    $stmt->execute([$name, $contactEmail]);
  }

  public function deleteAgency($agencyID){
    $stmt = $this->db->prepare("DELETE FROM Agencies
      WHERE AgencyID = ?");

    $stmt->execute([$agencyID]);
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
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; # Sin caracteres ambiguos
    $code = '';

    for ($i = 0; $i < 12; $i++) {
      $code .= $chars[random_int(0, strlen($chars) - 1)];
      # Agregar guion en el medio
      if ($i === 3 || $i === 7) {
        $code .= '-';
      }
    }
    return $code;
  }
}
