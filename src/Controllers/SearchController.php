<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Search;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;

require_once(ROOT . '/src/Utils/Paginator.php');

class SearchController {
    protected $search;

    public function __construct(Search $search) {
        $this->search = $search;
    }

    public function searchCategories(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        $queryParams = $request->getQueryParams();
        $query = $queryParams['query'];
        try {
            $results = $this->search->searchCategories($paginator, $query);
            $response->getBody()->write(json_encode($results));
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchOfferings(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        $queryParams = $request->getQueryParams();
        $query = $queryParams['query'];
        try {
            $results = $this->search->searchOfferings($paginator, $query);
            $response->getBody()->write(json_encode($results));
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchUsers(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        $queryParams = $request->getQueryParams();
        $query = $queryParams['query'];
        try {
            $results = $this->search->searchUsers($paginator, $query);
            $response->getBody()->write(json_encode($results));
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
        return $response->withHeader('Content-Type', 'application/json');
    }
}
