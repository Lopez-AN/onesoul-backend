<?php
// Función para validar el contenido con Perspective API
function validateContentWithPerspective($text)
{
  $apiKey = 'AIzaSyAJn4jjVR9PizqPEAoAYpzemILKTaRIjrU';
  $url = "https://commentanalyzer.googleapis.com/v1alpha1/comments:analyze?key=$apiKey";

  $data = [
    'comment' => ['text' => $text],
    'languages' => ['es', 'en'],
    'requestedAttributes' => ['TOXICITY' => (object) []]
  ];

  $options = [
    'http' => [
      'method' => 'POST',
      'header' => 'Content-Type: application/json',
      'content' => json_encode($data)
    ]
  ];

  $context = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  $response = json_decode($result, true);

  $toxicityScore = $response['attributeScores']['TOXICITY']['summaryScore']['value'];
  return $toxicityScore >= 0.7; // Umbral de toxicidad definido
}
