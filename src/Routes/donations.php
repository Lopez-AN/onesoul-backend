<?php

use Slim\App;
use App\Controllers\DonationController;
use App\Models\Donation;
use App\Models\Offering;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $donation = new Donation($pdo);
  $offering = new Offering($pdo);
  $donationController = new DonationController($donation, $offering);

  $app->get('/donations/guide/{userID}', [$donationController, 'getDonations'])->add($jwtMiddleware);
  $app->get('/donations/{voucherID}', [$donationController, 'getDonationById'])->add($jwtMiddleware);
  $app->get('/donations/validate/{redeemCode}', [$donationController, 'validateCoupon']);
  $app->get('/donations/montly/{userID}', [$donationController, 'getMontlyDonations'])->add($jwtMiddleware);
  $app->post('/donations', [$donationController, 'createDonation'])->add($jwtMiddleware);
  $app->post('/donations/assign', [$donationController, 'assignDonation'])->add($jwtMiddleware);
  $app->delete('/donations/{voucherID}', [$donationController, 'cancelDonation'])->add($jwtMiddleware);
  $app->get('/coupon/raffle/{quantity}', [$donationController, 'raffleCoupons'])->add($jwtMiddleware);

  $app->get('/coupon/assign/{quantity}', [$donationController, 'raffleCoupons'])->add($jwtMiddleware);

};
