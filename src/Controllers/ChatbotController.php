<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once(ROOT . '/src/Utils/Paginator.php');

class ChatbotController {
  public function __construct(Chatbot $chatbot) {
  }
}