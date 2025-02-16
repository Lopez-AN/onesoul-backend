<?php
use Aws\Rekognition\RekognitionClient;
use Aws\Credentials\CredentialProvider;

require_once(ROOT . '/src/Utils/PerspectiveText.php');

// Función para analizar imágenes con Amazon Rekognition
function analyzeImageWithRekognition($filePath)
{
    // Obtener credenciales temporales desde la instancia EC2
    $provider = CredentialProvider::instanceProfile();
    $creds = $provider()->wait();

    $client = new RekognitionClient([
        'region' => 'us-east-1', // Cambia según tu configuración
        'version' => 'latest',
        'credentials' => $creds
    ]);

    try {
        // Leer el archivo de imagen
        $imageData = file_get_contents($filePath);
        
        // Detectar etiquetas de contenido inapropiado
        $result = $client->detectModerationLabels([
            'Image' => ['Bytes' => $imageData],
            'MinConfidence' => 80, // Nivel de confianza
        ]);
        
        if (!empty($result['ModerationLabels'])) {
            return ['error' => true, 'reason' => 'Inappropriate content detected in the image.'];
        }

        // Detección de texto en imágenes
        $textResult = $client->detectText([
            'Image' => ['Bytes' => $imageData],
        ]);

        $detectedText = [];
        foreach ($textResult['TextDetections'] as $textDetection) {
            if ($textDetection['Confidence'] > 80) {
                $detectedText[] = $textDetection['DetectedText'];
            }
        }

        // Validar si el texto detectado contiene contenido inapropiado
        foreach ($detectedText as $text) {
            if (validateContentWithPerspective($text)) {
                return ['error' => true, 'reason' => 'Inappropriate text detected in the image.'];
            }
        }
        
        return ['error' => false, 'text' => $detectedText];
    } catch (\Aws\Exception\AwsException $e) {
        return ['error' => true, 'reason' => 'AWS Rekognition Error: ' . $e->getAwsErrorMessage()];
    } catch (\Exception $e) {
        return ['error' => true, 'reason' => 'General Error: ' . $e->getMessage()];
    }
}
