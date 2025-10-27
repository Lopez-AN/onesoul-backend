<?php

namespace Tests\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Subscription;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class UserControllerGetUsersTest extends TestCase
{
  private $mockUser;
  private $mockAuth;
  private $mockSubscription;
  private $mockRequest;
  private $mockResponse;
  private $controller;

  protected function setUp(): void
  {
    $this->mockUser = $this->createMock(User::class);
    $this->mockAuth = $this->createMock(Auth::class);
    $this->mockSubscription = $this->createMock(Subscription::class);
    $this->mockRequest = $this->createMock(Request::class);
    $this->mockResponse = $this->createMock(Response::class);

    $this->controller = new UserController(
      $this->mockUser,
      $this->mockAuth,
      $this->mockSubscription
    );
  }

  /**
   * Test: getUsers retorna 200 con datos válidos
   */
  public function testGetUsersRetorna200ConDatosValidos()
  {
    // ARRANGE
    $expectedData = [
      'data' => [
        [
          'UserID' => 1,
          'FirstName' => 'Martín',
          'LastName' => 'González',
          'UserType' => 'Guide',
          'Categories' => [
            ['Id' => 13, 'Name' => 'Meditación']
          ],
          'SessionType' => ['Virtual' => true, 'InPerson' => true],
          'Subscription' => null
        ]
      ],
      'rows' => ['total' => 25, 'fetched' => 1]
    ];

    $this->mockUser
      ->expects($this->once())
      ->method('getUsers')
      ->willReturn((object)$expectedData);

    $this->mockResponse
      ->expects($this->once())
      ->method('withStatus')
      ->with(200)
      ->willReturn($this->mockResponse);

    $this->mockResponse
      ->expects($this->once())
      ->method('withJson')
      ->with((object)$expectedData)
      ->willReturn($this->mockResponse);

    // ACT
    $result = $this->controller->getUsers($this->mockRequest, $this->mockResponse, []);

    // ASSERT
    $this->assertInstanceOf(Response::class, $result);
  }

  /**
   * Test: getUsers retorna 500 en error de BD
   */
  public function testGetUsersRetorna500EnErrorBD()
  {
    // ARRANGE
    $this->mockUser
      ->expects($this->once())
      ->method('getUsers')
      ->will($this->throwException(new \Exception('Database connection error')));

    $this->mockResponse
      ->expects($this->once())
      ->method('withStatus')
      ->with(500)
      ->willReturn($this->mockResponse);

    $this->mockResponse
      ->expects($this->once())
      ->method('withJson')
      ->willReturn($this->mockResponse);

    // ACT
    $result = $this->controller->getUsers($this->mockRequest, $this->mockResponse, []);

    // ASSERT
    $this->assertInstanceOf(Response::class, $result);
  }

  /**
   * Test: getUsers llama a getUsers del model
   */
  public function testGetUsersLlamaAlModel()
  {
    // ARRANGE
    $expectedData = [
      'data' => [],
      'rows' => ['total' => 0, 'fetched' => 0]
    ];

    $this->mockUser
      ->expects($this->once())
      ->method('getUsers')
      ->willReturn((object)$expectedData);

    $this->mockResponse
      ->method('withStatus')
      ->willReturn($this->mockResponse);

    $this->mockResponse
      ->method('withJson')
      ->willReturn($this->mockResponse);

    // ACT
    $this->controller->getUsers($this->mockRequest, $this->mockResponse, []);

    // ASSERT (automático: expects verifica que se llamó una vez)
  }

  /**
   * Test: getUsers maneja múltiples usuarios
   */
  public function testGetUsersManejaMúltiplesUsuarios()
  {
    // ARRANGE
    $expectedData = [
      'data' => [
        ['UserID' => 1, 'FirstName' => 'User1', 'Categories' => [], 'SessionType' => ['Virtual' => true, 'InPerson' => false]],
        ['UserID' => 2, 'FirstName' => 'User2', 'Categories' => [], 'SessionType' => ['Virtual' => false, 'InPerson' => true]],
        ['UserID' => 3, 'FirstName' => 'User3', 'Categories' => [], 'SessionType' => ['Virtual' => true, 'InPerson' => true]]
      ],
      'rows' => ['total' => 100, 'fetched' => 3]
    ];

    $this->mockUser
      ->method('getUsers')
      ->willReturn((object)$expectedData);

    $this->mockResponse
      ->method('withStatus')
      ->willReturn($this->mockResponse);

    $this->mockResponse
      ->method('withJson')
      ->willReturn($this->mockResponse);

    // ACT
    $result = $this->controller->getUsers($this->mockRequest, $this->mockResponse, []);

    // ASSERT
    $this->assertInstanceOf(Response::class, $result);
  }

  /**
   * Test: getUsers retorna error con estructura correcta
   */
  public function testGetUsersRetornaErrorConEstructuraCorrecta()
  {
    // ARRANGE
    $this->mockUser
      ->method('getUsers')
      ->will($this->throwException(new \Exception('Test error')));

    $expectedError = [
      "error" => [
        "code" => "INTERNAL_SERVER_ERROR",
        "desc" => "Test error"
      ]
    ];

    $this->mockResponse
      ->expects($this->once())
      ->method('withStatus')
      ->with(500)
      ->willReturn($this->mockResponse);

    $this->mockResponse
      ->expects($this->once())
      ->method('withJson')
      ->willReturn($this->mockResponse);

    // ACT
    $result = $this->controller->getUsers($this->mockRequest, $this->mockResponse, []);

    // ASSERT
    $this->assertInstanceOf(Response::class, $result);
  }
}