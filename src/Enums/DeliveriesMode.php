<?php

namespace App\Enums;

enum DeliveriesMode: string {
  case PENDING = 'pending';
  case SENT = 'sent';
  case FAILED = 'failed';
  case ALL   = 'all';
}