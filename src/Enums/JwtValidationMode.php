<?php

namespace App\Enums;

enum JwtValidationMode: string {
  case REQUIRED = 'required';
  case OPTIONAL   = 'optional';
  case NO_EXPIRE  = 'no_expire';
}