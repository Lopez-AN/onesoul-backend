<?php

use Slim\App;
use App\Controllers\BookingController;
use App\Models\Booking;
use App\Models\Offering;
use App\Models\User;
use App\Models\Donation;
use App\Models\Notification;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
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
  $app->get('/bookings/{BookingID}', [$bookingController, 'getBookingByID'])->add($requiredJwt);
  $app->get('/bookings/public/{PublicID}', [$bookingController, 'getBookingByPublicID'])->add($requiredJwt);
  $app->get('/bookings/guide/{GuideID}', [$bookingController, 'getBookingsByGuide'])->add($requiredJwt);
  $app->get('/bookings/seeker/{SeekerID}', [$bookingController, 'getBookingsBySeeker'])->add($requiredJwt);
  $app->get('/bookings/seeker/info/{BookingID}', [$bookingController, 'getSeekerInfo'])->add($requiredJwt);
  $app->post('/bookings', [$bookingController, 'createBooking'])->add($requiredJwt);
  $app->patch('/bookings/{BookingID}', [$bookingController, 'updateBooking'])->add($requiredJwt);
  $app->post('/bookings/{BookingID}/cancel', [$bookingController, 'cancelBooking'])->add($requiredJwt);
  $app->post('/bookings/{BookingID}/confirm', [$bookingController, 'confirmBooking'])->add($requiredJwt);
  $app->post('/bookings/{BookingID}/complete', [$bookingController, 'completeBooking'])->add($requiredJwt);
  $app->post('/bookings/{BookingID}/rate', [$bookingController, 'rateBooking'])->add($requiredJwt);

  // Reviews:
  $app->get('/reviews', [$bookingController, 'getReviews']);
  $app->get('/reviews/{ReviewID}', [$bookingController, 'getReviewsByID']);
  $app->get('/reviews/guide/{UserID}', [$bookingController, 'getReviewsByGuide']);
  $app->get('/reviews/seeker/{UserID}', [$bookingController, 'getReviewsBySeeker']);
  $app->get('/reviews/user/{UserID}', [$bookingController, 'getReviewsByUser']);
  $app->get('/reviews/offering/{OfferingID}', [$bookingController, 'getReviewsByOffering']);
};