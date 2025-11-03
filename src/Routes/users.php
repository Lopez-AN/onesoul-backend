<?php

use Slim\App;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Category;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);
  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM
  $user = new User($pdo);
  $category = new Category($pdo);
  $auth = new Auth($pdo, $redis);
  $subscription = new Subscription($pdo);
  $userController = new UserController($user, $auth, $category, $subscription);

  $app->get('/users', [$userController, 'getUsers'])->add($optionalJwt);
  $app->get('/users/type/{type}', [$userController, 'getUsersByType'])->add($optionalJwt);
  $app->get('/users/category/{id}', [$userController, 'getUsersByCategory'])->add($optionalJwt);
  $app->get('/search/guides', [$userController, 'searchGuides'])->add($optionalJwt);
  $app->get('/users/{id}', [$userController, 'getUserById'])->add($optionalJwt);
  $app->get('/users/email/{email}', [$userController, 'getUserByEmail'])->add($optionalJwt);
  $app->get('/users/username/{userName}', [$userController, 'getUserByUserName'])->add($optionalJwt);
  $app->get('/users/referred/{referralCode}', [$userController, 'getUserByRefCode'])->add($optionalJwt);
  $app->get('/users/consent/{id}', [$userController, 'latestConsentByUser'])->add($requiredJwt);
  $app->get('/users/{id}/referrals', [$userController, 'referralsByUser'])->add($requiredJwt);
  $app->get('/users/{id}/rewards', [$userController, 'rewardsByUser'])->add($requiredJwt);
  $app->post('/users/invite/mail', [$userController, 'inviteByEmail'])->add($requiredJwt);
  $app->patch('/users/{id}', [$userController, 'updateUser'])->add($requiredJwt);
  $app->delete('/users/{id}', [$userController, 'disableUser'])->add($requiredJwt);
  $app->post('/users/categories/{id}', [$userController, 'updateUserCategories'])->add($requiredJwt);
  $app->post('/users/social/{id}', [$userController, 'updateUserSocialAccounts'])->add($requiredJwt);
  $app->get('/users/social/{id}', [$userController, 'getUserSocialAccounts']);
  $app->post('/users/profile_photo/{id}', [$userController, 'updateProfilePhoto'])->add($requiredJwt);
  $app->delete('/users/profile_photo/{id}', [$userController, 'deleteProfilePhoto'])->add($requiredJwt);
};
