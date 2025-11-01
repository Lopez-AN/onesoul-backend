<?php

namespace App\Enums;

enum UserAccessScope: string {
  case PUBLIC = 'public';
  case USER   = 'user';
  case ADMIN  = 'admin';
}