<?php

use Slim\App;
use App\Controllers\BookingController;
use App\Models\Booking;
use App\Models\Offering;

return function (App $app) {

  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => [
          "/bookings",
          "/bookings/{userID}",
          "/bookings/{bookingID}",
          "/reviews",
          "/reviews/{userID}"
        ]
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt"
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $booking = new Booking($pdo);
  $offering = new Offering($pdo);
  $bookingController = new BookingController($booking, $offering);

  $app->post('/bookings', [$bookingController, 'createBooking']);
  $app->get('/bookings/{bookingID}', [$bookingController, 'getBookingByID']);
  $app->get('/bookings/guide/{userID}', [$bookingController, 'getBookingsByGuide']);
  $app->patch('/bookings/{bookingID}', [$bookingController, 'updateBooking']);
  $app->delete('/bookings/{bookingID}', [$bookingController, 'cancelBooking']);
  $app->post('/reviews', [$bookingController, 'createReview']);
  $app->get('/reviews/{userID}', [$bookingController, 'getReviewsByGuide']);

};