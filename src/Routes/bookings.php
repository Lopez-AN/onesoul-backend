<?php

use Slim\App;
use App\Controllers\BookingController;
use App\Models\Booking;
use App\Models\Offering;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $booking = new Booking($pdo);
  $offering = new Offering($pdo);
  $bookingController = new BookingController($booking, $offering);

  // Bookings protegidos
  $app->get('/bookings/{bookingID}', [$bookingController, 'getBookingByID'])->add($jwtMiddleware);
  $app->get('/bookings/guide/{userID}', [$bookingController, 'getBookingsByGuide'])->add($jwtMiddleware);
  $app->get('/bookings/seeker/{userID}', [$bookingController, 'getBookingsBySeeker'])->add($jwtMiddleware);
  $app->post('/bookings', [$bookingController, 'createBooking'])->add($jwtMiddleware);
  $app->patch('/bookings/{bookingID}', [$bookingController, 'updateBooking'])->add($jwtMiddleware);
  $app->delete('/bookings/{bookingID}', [$bookingController, 'cancelBooking'])->add($jwtMiddleware);

  // Reviews: solo POST protegido
  $app->post('/reviews', [$bookingController, 'createReview'])->add($jwtMiddleware);
  $app->get('/reviews', [$bookingController, 'getReviews']);
  $app->get('/reviews/{reviewID}', [$bookingController, 'getReviewsByID']);
  $app->get('/reviews/guide/{userID}', [$bookingController, 'getReviewsByGuide']);
  $app->get('/reviews/offering/{offeringID}', [$bookingController, 'getReviewsByOffering']);
};