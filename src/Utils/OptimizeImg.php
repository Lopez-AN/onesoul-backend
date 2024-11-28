<?php

function optimizeImage($file, $maxWidth = 1024, $maxHeight = 1024, $webp = true) {
    // Obtener la información de la imagen
    list($width, $height, $type) = getimagesize($file);

    // Verificar el tipo de imagen
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP])) {
        throw new Exception('El archivo no es un formato de imagen válido.');
    }

    // Calcular el nuevo tamaño manteniendo la relación de aspecto
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    // Crear la imagen redimensionada (por defecto WEBP)
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Crear imagen dependiendo del tipo de archivo
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($file);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($file);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($file);
            break;
        case IMAGETYPE_WEBP:
            $source = imagecreatefromwebp($file);
            break;
    }

    // Copiar y redimensionar
    imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Guardar la imagen como JPEG o WebP con calidad de compresión
    $optimizedPath = $file; 
    if ($webp) { // Modo webp (parametro de la funcion)
        $optimizedPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $file); // Cambiar extensión a .webp
        imagewebp($newImage, $optimizedPath, 80); // Guardar como WEBP Calidad 80
    }else{
        imagejpeg($newImage, $optimizedPath, 80); // Guardar como JPEG calidad 80
    }
    // Liberar la memoria
    imagedestroy($source);
    imagedestroy($newImage);

    return $optimizedPath;
}
