<?php

use Slim\App;
use App\Controllers\BookingController;
use App\Models\Booking;

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
  $bookingController = new BookingController($booking);

  $app->post('/bookings', [$bookingController, 'createBooking']);
  $app->get('/bookings/{userID}', [$bookingController, 'getBookingsByGuide']);
  $app->patch('/bookings/{bookingID}', [$bookingController, 'updateBooking']);
  $app->delete('/bookings/{bookingID}', [$bookingController, 'cancelBooking']);
  $app->post('/reviews', [$bookingController, 'createReview']);
  $app->get('/reviews/{userID}', [$bookingController, 'getReviewsByGuide']);

};