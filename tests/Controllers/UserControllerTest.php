<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use App\Models\User;
use App\Models\Auth;
use App\Models\Category;
use App\Models\Subscription;
use App\Controllers\UserController;
use PDO;

class UserControllerTest extends TestCase {

  private PDO $db;
  private User $userModel;
  private Auth $authModel;
  private Category $categoryModel;
  private Subscription $subscriptionModel;
  private UserController $controller;
  private ResponseFactory $responseFactory;

  protected function setUp(): void {
    // Inicializar BD SQLite en memoria
    $this->db = new PDO('sqlite::memory:');
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear tablas
    $this->createTables();

    // Instanciar modelos
    $this->userModel = new User($this->db);
    $this->authModel = new Auth($this->db, null); // Redis mockeable después
    $this->categoryModel = new Category($this->db);
    $this->subscriptionModel = new Subscription($this->db);

    // Instanciar controlador
    $this->controller = new UserController(
      $this->userModel,
      $this->authModel,
      $this->categoryModel,
      $this->subscriptionModel
    );

    // Factory para responses
    $this->responseFactory = new ResponseFactory();

    // Insertar datos de prueba
    $this->seedTestData();
  }

  private function createTables(): void {
    $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    $this->db->exec($sql);
  }

  private function seedTestData(): void {
    // Insertar categorías
    $this->db->exec("
      INSERT INTO Categories (CategoryID, Name, IsActive) VALUES
      (1, 'Travel', 1),
      (2, 'Wellness', 1),
      (3, 'Arts', 1)
    ");

    // Insertar usuarios
    $this->db->exec("
      INSERT INTO Users (UserID, FirstName, LastName, UserName, DisplayName, Email, Phone, 
                       UserType, RegistrationDate, ValidatedEmail, ReferralCode) VALUES
      (1, 'John', 'Doe', 'johndoe', 'John D', 'john@example.com', '+5491234567890', 
       'Guide', '2024-01-15 10:00:00', 1, 'REF001'),
      (2, 'Jane', 'Smith', 'janesmith', 'Jane S', 'jane@example.com', '+5491234567891', 
       'Seeker', '2024-02-20 15:30:00', 1, 'REF002'),
      (3, 'Bob', 'Johnson', 'bobjohnson', 'Bob J', 'bob@example.com', '+5491234567892', 
       'Guide', '2024-01-10 08:00:00', 0, 'REF003')
    ");

    // Asociar categorías a usuarios
    $this->db->exec("
      INSERT INTO UsersCategories (UserID, CategoryID) VALUES
      (1, 1),
      (1, 2),
      (3, 1),
      (3, 3)
    ");

    // Insertar subscripciones
    $this->db->exec("
      INSERT INTO Subscriptions (SubscriptionID, PlanID, UserID, StartDate, Status, 
                               PaymentPlatform, PlatformSubscriptionID, PlatformCustomerID) VALUES
      (1, 1, 1, '2024-01-15', 'ACTIVE', 'STRIPE', 'sub_stripe_001', 'cus_001')
    ");
  }

  /**
   * TEST: getUsers - Obtener todos los usuarios con paginación
   */
  public function testGetUsersSuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users?limit=10&offset=0');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUsers($request, $response, []);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertIsArray($body['data']);
    $this->assertGreaterThan(0, count($body['data']));
    $this->assertArrayHasKey('rows', $body);
  }

  /**
   * TEST: getUserById - Obtener usuario por ID (éxito)
   */
  public function testGetUserByIdSuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/1');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserById($request, $response, ['id' => 1]);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('John', $body['FirstName']);
    $this->assertEquals('johndoe', $body['UserName']);
    $this->assertEquals('john@example.com', $body['Email']);
  }

  /**
   * TEST: getUserById - Usuario no encontrado
   */
  public function testGetUserByIdNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/999');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserById($request, $response, ['id' => 999]);

    $this->assertEquals(404, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('USER_NOT_FOUND', $body['error']['code']);
  }

  /**
   * TEST: getUserByEmail - Obtener usuario por email (éxito)
   */
  public function testGetUserByEmailSuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/email/john@example.com');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByEmail($request, $response, ['email' => 'john@example.com']);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals(1, $body['UserID']);
    $this->assertEquals('John', $body['FirstName']);
  }

  /**
   * TEST: getUserByEmail - Email no encontrado
   */
  public function testGetUserByEmailNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/email/nonexistent@example.com');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByEmail($request, $response, ['email' => 'nonexistent@example.com']);

    $this->assertEquals(404, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('USER_NOT_FOUND', $body['error']['code']);
  }

  /**
   * TEST: getUserByUserName - Obtener usuario por username (éxito)
   */
  public function testGetUserByUserNameSuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/username/johndoe');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByUserName($request, $response, ['username' => 'johndoe']);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('johndoe', $body['UserName']);
    $this->assertEquals('John', $body['FirstName']);
  }

  /**
   * TEST: getUserByUserName - Username no encontrado
   */
  public function testGetUserByUserNameNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/username/invaliduser');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByUserName($request, $response, ['username' => 'invaliduser']);

    $this->assertEquals(404, $result->getStatusCode());
  }

  /**
   * TEST: getUsersByType - Obtener usuarios por tipo (Guides)
   */
  public function testGetUsersByTypeGuides(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/type/Guide?limit=10&offset=0');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUsersByType($request, $response, ['type' => 'Guide']);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    // Debería haber al menos 2 guías (UserID 1 y 3)
    $this->assertGreaterThanOrEqual(2, count($body['data']));
  }

  /**
   * TEST: getUsersByType - Obtener usuarios por tipo (Seekers)
   */
  public function testGetUsersByTypeSeekers(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/type/Seeker?limit=10&offset=0');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUsersByType($request, $response, ['type' => 'Seeker']);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertGreaterThanOrEqual(1, count($body['data']));
  }

  /**
   * TEST: getUsersByCategory - Obtener usuarios por categoría
   */
  public function testGetUsersByCategorySuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/category/1?limit=10&offset=0');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUsersByCategory($request, $response, ['id' => 1]);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    // Debe haber usuarios con categoría 1
    $this->assertGreaterThanOrEqual(2, count($body['data']));
  }

  /**
   * TEST: getUserByRefCode - Obtener usuario por código referral (éxito)
   */
  public function testGetUserByRefCodeSuccess(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/referred/REF001');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByRefCode($request, $response, ['referralCode' => 'REF001']);

    $this->assertEquals(200, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('REF001', $body['ReferralCode']);
  }

  /**
   * TEST: getUserByRefCode - Código referral no encontrado
   */
  public function testGetUserByRefCodeNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/referred/INVALID');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserByRefCode($request, $response, ['referralCode' => 'INVALID']);

    $this->assertEquals(404, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('USER_NOT_FOUND', $body['error']['code']);
  }

  /**
   * TEST: latestConsentByUser - Usuario sin consentimiento
   */
  public function testLatestConsentByUserNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/consent/1');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->latestConsentByUser($request, $response, ['id' => 1]);

    $this->assertEquals(404, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('CONSENT_NOT_FOUND', $body['error']['code']);
  }

  /**
   * TEST: getUserSocialAccounts - Usuario sin cuentas sociales
   */
  public function testGetUserSocialAccountsNotFound(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/social/1');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserSocialAccounts($request, $response, ['id' => 1]);

    $this->assertEquals(404, $result->getStatusCode());
    $body = json_decode((string)$result->getBody(), true);

    $this->assertEquals('SOCIAL_ACCOUNTS_NOT_FOUND', $body['error']['code']);
  }

  /**
   * TEST: Validación de respuestas - Estructura de datos
   */
  public function testGetUserResponseStructure(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/1');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserById($request, $response, ['id' => 1]);
    $body = json_decode((string)$result->getBody(), true);

    // Validar campos presentes
    $this->assertArrayHasKey('UserID', $body);
    $this->assertArrayHasKey('FirstName', $body);
    $this->assertArrayHasKey('Email', $body);
    $this->assertArrayHasKey('UserType', $body);
    $this->assertArrayHasKey('Categories', $body);
    $this->assertArrayHasKey('SessionType', $body);

    // Validar tipos de datos
    $this->assertIsInt($body['UserID']);
    $this->assertIsString($body['FirstName']);
    $this->assertIsArray($body['Categories']);
    $this->assertIsBool($body['SessionType']['Virtual']);
  }

  /**
   * TEST: Validación de tipos de datos booleanos
   */
  public function testGetUserBooleanFields(): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/users/1');
    $response = $this->responseFactory->createResponse();

    $result = $this->controller->getUserById($request, $response, ['id' => 1]);
    $body = json_decode((string)$result->getBody(), true);

    // Campos que deben ser booleanos
    $this->assertIsBool($body['ValidatedEmail']);
    $this->assertIsBool($body['ValidatedPhone']);
    $this->assertIsBool($body['TwoFactorAuth']);
  }
}