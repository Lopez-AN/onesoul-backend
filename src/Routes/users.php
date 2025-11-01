<?php

use Slim\App;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Category;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $optionalJwtMiddleware = new OptionalJwtMiddleware($jwtMiddleware);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM
  $user = new User($pdo);
  $category = new Category($pdo);
  $auth = new Auth($pdo, $redis);
  $subscription = new Subscription($pdo);
  $userController = new UserController($user, $auth, $category, $subscription);

  $app->get('/users', [$userController, 'getUsers'])->add($optionalJwtMiddleware);
  $app->get('/users/{id}', [$userController, 'getUserById'])->add($optionalJwtMiddleware);
  $app->get('/users/type/{type}', [$userController, 'getUsersByType'])->add($optionalJwtMiddleware);
  $app->get('/users/email/{email}', [$userController, 'getUserByEmail'])->add($optionalJwtMiddleware);
  $app->get('/users/username/{username}', [$userController, 'getUserByUserName'])->add($optionalJwtMiddleware);
  $app->get('/users/category/{id}', [$userController, 'getUsersByCategory'])->add($optionalJwtMiddleware);
  $app->get('/users/referred/{referralCode}', [$userController, 'getUserByRefCode'])->add($optionalJwtMiddleware);
  $app->get('/users/consent/{id}', [$userController, 'latestConsentByUser']);
  $app->get('/users/{id}/referrals', [$userController, 'referralsByUser'])->add($jwtMiddleware);
  $app->get('/users/{id}/rewards', [$userController, 'rewardsByUser'])->add($jwtMiddleware);
  $app->post('/users/invite/mail', [$userController, 'inviteByEmail'])->add($jwtMiddleware);
  $app->post('/users/profile_photo/{id}', [$userController, 'updateProfilePhoto'])->add($jwtMiddleware);
  $app->delete('/users/profile_photo/{id}', [$userController, 'deleteProfilePhoto'])->add($jwtMiddleware);
  $app->patch('/users/{id}', [$userController, 'updateUser'])->add($jwtMiddleware);
  $app->delete('/users/{id}', [$userController, 'disableUser'])->add($jwtMiddleware);
  $app->post('/users/categories/{id}', [$userController, 'updateUserCategories'])->add($jwtMiddleware);
  $app->post('/users/social/{id}', [$userController, 'updateUserSocialAccounts'])->add($jwtMiddleware);
  $app->get('/users/social/{id}', [$userController, 'getUserSocialAccounts']);
};
