<?php
// Prueba puntual del endpoint de acceso; conserva la cookie temporal durante la petición y luego la elimina.
$cookieFile = __DIR__ . '/test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$ch = curl_init('http://localhost/proyecto/api/users.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'usuario' => 'admin', 'password' => 'admin123']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);

echo "Login admin: $response\n";
if (file_exists($cookieFile)) unlink($cookieFile);
