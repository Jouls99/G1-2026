<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
	$user = getCurrentUser();
	require_once __DIR__ . '/api/helpers.php';
	logActivity(
		(string)($user['usuario'] ?? 'Usuario'),
		'logout',
		"Cierre de sesión de '" . (string)($user['usuario'] ?? 'Usuario') . "'",
		[
			'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida'),
			'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
		],
		isset($user['id']) ? (int)$user['id'] : null
	);
}

logoutUser();
header('Location: registroinicio.php');
exit;
