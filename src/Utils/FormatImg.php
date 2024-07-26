<?php

# Identifica el formato de una imagen basado en su contenido binario
function imgFormat($bin) {
  if (!$bin || strlen($bin) < 12) {
    return false;
  }

  $strToHex = function($string) {
    $hex = '';
    for ($i = 0; $i < strlen($string); $i++) {
      $hex .= sprintf("%02x", ord($string[$i]));
    }
    return $hex;
  };

  $m_number = $strToHex(substr($bin, 0, 4));
  $extended_check = $strToHex(substr($bin, 4, 8));

  if (substr($m_number, 0, 4) == "424d") {
    return "bmp";
  } else {
    switch ($m_number) {
      case "89504e47":
        return "png";
      case "ffd8ffe0":
        return "jpg";
      case "47494638":
        return "gif";
      case "52494646":
        if (substr($extended_check, 0, 8) == "57454250") {
          return "webp";
        }
        break;
      case "00000018":
        if (substr($extended_check, 0, 8) == "66747970" && substr($extended_check, 8, 8) == "68656963") {
          return "heic";
        }
        break;
    }
  }

  # Si no es de ninguno de los tipos conocidos, devolver false
  return false;
}
