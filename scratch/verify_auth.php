<?php
// Test de verificación de seguridad y control de acceso

$cookieFile = __DIR__ . '/test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function testUrl($url, $cookieFile = null, $postData = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $httpCode, 'redirect' => $redirectUrl, 'body' => $response];
}

echo "=== 1. VERIFICACIÓN DE ACCESO ANÓNIMO (DEBE ESTAR BLOQUEADO) ===\n";
$views = ['index.php', 'control_stock.php', 'informe.php'];
foreach ($views as $view) {
    $res = testUrl("http://localhost/proyecto/$view");
    echo "$view -> Código HTTP: {$res['code']} | Redirección: {$res['redirect']}\n";
}

$apis = ['api/inventario.php', 'api/ventas.php', 'api/users.php'];
foreach ($apis as $api) {
    $res = testUrl("http://localhost/proyecto/$api");
    echo "$api -> Código HTTP: {$res['code']} | Respuesta: {$res['body']}\n";
}

echo "\n=== 2. REGISTRO E INICIO DE SESIÓN DE USUARIO ===\n";
$registerRes = testUrl("http://localhost/proyecto/api/users.php", $cookieFile, [
    'usuario' => 'vendedora_maria',
    'password' => 'clave123',
    'role' => 'vendedora'
]);
echo "Registro -> Código HTTP: {$registerRes['code']} | Respuesta: {$registerRes['body']}\n";

echo "\n=== 3. VERIFICACIÓN CON SESIÓN ACTIVA (DEBE PERMITIR ACCESO) ===\n";
foreach ($views as $view) {
    $res = testUrl("http://localhost/proyecto/$view", $cookieFile);
    echo "$view (Autenticado) -> Código HTTP: {$res['code']}\n";
}

$invRes = testUrl("http://localhost/proyecto/api/inventario.php", $cookieFile);
$invData = json_decode($invRes['body'], true);
$count = is_array($invData) ? count($invData) : 0;
echo "api/inventario.php (Autenticado) -> Código HTTP: {$invRes['code']} | Items devueltos: $count\n";

$ventasRes = testUrl("http://localhost/proyecto/api/ventas.php", $cookieFile);
$ventasData = json_decode($ventasRes['body'], true);
$vCount = is_array($ventasData) ? count($ventasData) : 0;
echo "api/ventas.php (Autenticado) -> Código HTTP: {$ventasRes['code']} | Ventas devueltas: $vCount\n";

if (file_exists($cookieFile)) unlink($cookieFile);
