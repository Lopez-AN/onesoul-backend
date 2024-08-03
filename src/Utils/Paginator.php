<?php

function paginator($request){
  $queryParams = $request->getQueryParams();
  $limit = $queryParams['limit'] ?? $GLOBALS['config']['default_rows'];
  $limit = $limit > $GLOBALS['config']['max_rows'] ? $GLOBALS['config']['max_rows'] : $limit;
  $offset = $queryParams['offset'] ?? 0;

  return (object)["limit" => $limit, "offset" => $offset];
}
