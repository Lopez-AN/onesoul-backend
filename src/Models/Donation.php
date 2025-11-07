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

  /**
   * Obtiene todas las donaciones de un guía con paginación
   * @param int $userID: ID del guía
   * @param object $paginator: objeto con propiedades 'limit' y 'offset'
   * @return object: {data: array, rows: {total: int, fetched: int}}
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Obtiene las donaciones mensuales activas (no canceladas) de un guía
   * Solo cuenta cupones del mes actual
   * @param int $userID: ID del guía
   * @param object $paginator: objeto con propiedades 'limit' y 'offset'
   * @return object: {data: array, rows: {total: int, fetched: int}}
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Obtiene una donación específica por ID de voucher
   * @param int $voucherID: ID único del voucher
   * @return array|bool: array con datos de donación o false si no existe
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Obtiene una donación por su RedeemCode (código de canje secreto)
   * @param string $redeemCode: código de canje (formato: XXXX-XXXX-XXXX)
   * @return array|bool: array con datos de donación o false si no existe
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Crea una o varias donaciones (cupones) nuevas en estado 'draft'
   * Genera RaffleCode y RedeemCode únicos para cada cupón
   * @param int $userID: ID del guía propietario
   * @param int $offeringID: ID del servicio asociado
   * @param int $quantity: cantidad de cupones a crear
   * @return void
   * @throws DatabaseException: si hay error en inserción o duplicidad de códigos
   **/
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

  /**
   * Asigna una donación a una agencia (cambia estado de 'draft' a 'assigned')
   * @param int $agencyID: ID de la agencia a asignar
   * @param int $voucherID: ID del voucher a asignar
   * @return void
   * @throws DatabaseException: si hay error en la actualización
   **/
  public function assignDonation($agencyID, $voucherID){
    $stmt = $this->db->prepare("UPDATE DonationVouchers
      SET AgencyID = ?, Status = 'assigned'
      WHERE VoucherID = ?");

    $stmt->execute([$agencyID, $voucherID]);
  }

  /**
   * Cancela una donación existente (cambia estado a 'canceled')
   * @param int $voucherID: ID del voucher a cancelar
   * @return void
   * @throws DatabaseException: si hay error en la actualización
   **/
  public function cancelDonation($voucherID){
    $stmt = $this->db->prepare("UPDATE DonationVouchers
      SET Status = 'canceled' WHERE VoucherID = ?");

    $stmt->execute([$voucherID]);
  }

  /**
   * Sortea N cupones aleatorios en estado 'assigned'
   * Selecciona cupones de forma aleatoria del pool disponible
   * @param int $quantity: cantidad de cupones a sortear (1-1000)
   * @return array: array de cupones sorteados con sus datos
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Obtiene lista paginada de todas las agencias
   * @param object $paginator: objeto con propiedades 'limit' y 'offset'
   * @return object: {data: array, rows: {total: int, fetched: int}}
   * @throws DatabaseException: si hay error en la consulta
   **/
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

  /**
   * Obtiene una agencia específica por ID
   * @param int $agencyID: ID de la agencia
   * @return array|bool: array con datos de agencia o false si no existe
   * @throws DatabaseException: si hay error en la consulta
   **/
  public function getAgencyById($agencyID){
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS
      a.AgencyID, a.Name, a.ContactEmail
    FROM Agencies as a
    WHERE a.AgencyID = ?");

    $stmt->execute([$agencyID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Crea una nueva agencia
   * @param string $name: nombre de la agencia
   * @param string $contactEmail: email de contacto de la agencia
   * @return void
   * @throws DatabaseException: si hay error en inserción
   **/
  public function createAgency($name, $contactEmail){
    $stmt = $this->db->prepare("INSERT INTO Agencies
      (Name, ContactEmail) VALUES (?, ?)");

    $stmt->execute([$name, $contactEmail]);
  }

  /**
   * Elimina una agencia existente
   * @param int $agencyID: ID de la agencia a eliminar
   * @return void
   * @throws DatabaseException: si hay error en la eliminación
   **/
  public function deleteAgency($agencyID){
    $stmt = $this->db->prepare("DELETE FROM Agencies
      WHERE AgencyID = ?");

    $stmt->execute([$agencyID]);
  }

  /**
   * Genera un RaffleCode - Código público de sorteo
   * Formato: 10 caracteres alfanuméricos (ej: XYZ9876578)
   * @return string: código generado único
   * @private
   **/
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
   * Formato: 3 bloques de 4 caracteres con guion (ej: XXXX-XXXX-XXXX)
   * @return string: código generado único
   * @private
   **/
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
