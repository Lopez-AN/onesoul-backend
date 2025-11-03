<?php

use Slim\App;
use App\Controllers\DonationController;
use App\Models\Donation;
use App\Models\Offering;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $donation = new Donation($pdo);
  $offering = new Offering($pdo);
  $donationController = new DonationController($donation, $offering);

  $app->get('/donations/guide/{userID}', [$donationController, 'getDonations'])->add($requiredJwt);
  $app->get('/donations/{voucherID}', [$donationController, 'getDonationById'])->add($requiredJwt);
  $app->get('/donations/validate/{redeemCode}', [$donationController, 'validateCoupon']);
  $app->get('/donations/montly/{userID}', [$donationController, 'getMontlyDonations'])->add($requiredJwt);
  $app->post('/donations', [$donationController, 'createDonation'])->add($requiredJwt);
  $app->post('/donations/assign', [$donationController, 'assignDonation'])->add($requiredJwt);
  $app->delete('/donations/{voucherID}', [$donationController, 'cancelDonation'])->add($requiredJwt);
  $app->get('/coupon/raffle/{quantity}', [$donationController, 'raffleCoupons'])->add($requiredJwt);

  $app->get('/coupon/assign/{quantity}', [$donationController, 'raffleCoupons'])->add($requiredJwt);

};
