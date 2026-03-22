<?php

use Slim\App;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Notification;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Secret;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication(
    new Options(),
    new FirebaseDecoder(new Secret($GLOBALS['config']['jwt']['secret'], 'HS256'))
  );

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);
  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM
  $user = new User($pdo);
  $category = new Category($pdo);
  $auth = new Auth($pdo, $redis);
  $subscription = new Subscription($pdo);
  $notification = new Notification($pdo);
  $userController = new UserController($user, $auth, $category, $subscription, $notification);

  $app->get('/users', [$userController, 'getUsers'])->add($optionalJwt);
  $app->get('/users/type/{UserType}', [$userController, 'getUsersByType'])->add($optionalJwt);
  $app->get('/users/category/{CategoryID}', [$userController, 'getUsersByCategory'])->add($optionalJwt);
  $app->get('/search/guides', [$userController, 'searchGuides'])->add($optionalJwt);
  $app->get('/users/{UserID}', [$userController, 'getUserById'])->add($optionalJwt);
  $app->get('/users/email/{Email}', [$userController, 'getUserByEmail'])->add($optionalJwt);
  $app->get('/users/username/{UserName}', [$userController, 'getUserByUserName'])->add($optionalJwt);
  $app->get('/users/referred/{ReferralCode}', [$userController, 'getUserByRefCode'])->add($optionalJwt);
  $app->get('/users/consent/{id}', [$userController, 'latestConsentByUser'])->add($requiredJwt);
  $app->get('/users/{id}/referrals', [$userController, 'referralsByUser'])->add($requiredJwt);
  $app->get('/users/{id}/rewards', [$userController, 'rewardsByUser'])->add($requiredJwt);
  $app->post('/users/invite/mail', [$userController, 'inviteByEmail'])->add($requiredJwt);
  $app->patch('/users/{UserID}', [$userController, 'updateUser'])->add($requiredJwt);
  $app->get('/users/{UserID}/settings', [$userController, 'getSettings'])->add($requiredJwt);
  $app->patch('/users/{UserID}/settings', [$userController, 'updateSettings'])->add($requiredJwt);
  $app->delete('/users/{id}', [$userController, 'disableUser'])->add($requiredJwt);
  $app->post('/users/categories/{id}', [$userController, 'updateUserCategories'])->add($requiredJwt);
  $app->post('/users/social/{id}', [$userController, 'updateUserSocialAccounts'])->add($requiredJwt);
  $app->get('/users/social/{id}', [$userController, 'getUserSocialAccounts']);
  $app->post('/users/profile_photo/{id}', [$userController, 'updateProfilePhoto'])->add($requiredJwt);
  $app->delete('/users/profile_photo/{id}', [$userController, 'deleteProfilePhoto'])->add($requiredJwt);
};
