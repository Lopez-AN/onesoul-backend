<?php

function paginator($request){
  $queryParams = $request->getQueryParams();

  $limit = $queryParams['limit'] ?? $GLOBALS['config']['default_rows'];
  $limit = !filter_var($limit, FILTER_VALIDATE_INT) || $limit > $GLOBALS['config']['max_rows'] ?
    $GLOBALS['config']['max_rows'] : $limit;

  $offset = $queryParams['offset'] ?? 0;
  $offset = !filter_var($offset, FILTER_VALIDATE_INT) ?
    0 : $offset;

  return (object)["limit" => $limit, "offset" => $offset];
}
