<?php

use Slim\App;
use App\Controllers\BookingController;
use App\Models\Booking;
use App\Models\Offering;
use App\Models\User;
use App\Models\Donation;
use App\Models\Notification;
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
  $booking = new Booking($pdo);
  $offering = new Offering($pdo);
  $user = new User($pdo);
  $notification = new Notification($pdo);
  $donation = new Donation($pdo);
  $bookingController = new BookingController($booking, $offering, $user, $notification, $donation);

  // Bookings protegidos
  $app->get('/bookings/{bookingID}', [$bookingController, 'getBookingByID'])->add($requiredJwt);
  $app->get('/bookings/public/{publicID}', [$bookingController, 'getBookingByPublicID'])->add($requiredJwt);
  $app->get('/bookings/guide/{userID}', [$bookingController, 'getBookingsByGuide'])->add($requiredJwt);
  $app->get('/bookings/seeker/{userID}', [$bookingController, 'getBookingsBySeeker'])->add($requiredJwt);
  $app->post('/bookings', [$bookingController, 'createBooking'])->add($requiredJwt);
  $app->patch('/bookings/{bookingID}', [$bookingController, 'updateBooking'])->add($requiredJwt);
  $app->post('/bookings/{bookingID}/cancel', [$bookingController, 'cancelBooking'])->add($requiredJwt);
  $app->post('/bookings/{bookingID}/confirm', [$bookingController, 'confirmBooking'])->add($requiredJwt);
  $app->post('/bookings/{bookingID}/complete', [$bookingController, 'completeBooking'])->add($requiredJwt);
  $app->post('/bookings/{bookingID}/rate', [$bookingController, 'rateBooking'])->add($requiredJwt);

  // Reviews:
  $app->get('/reviews', [$bookingController, 'getReviews']);
  $app->get('/reviews/{reviewID}', [$bookingController, 'getReviewsByID']);
  $app->get('/reviews/guide/{userID}', [$bookingController, 'getReviewsByGuide']);
  $app->get('/reviews/seeker/{userID}', [$bookingController, 'getReviewsBySeeker']);
  $app->get('/reviews/user/{userID}', [$bookingController, 'getReviewsByUser']);
  $app->get('/reviews/offering/{offeringID}', [$bookingController, 'getReviewsByOffering']);
};