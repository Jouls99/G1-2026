<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
	$user = getCurrentUser();
	$errorCierreJornada = false;
	require_once __DIR__ . '/api/helpers.php';
	require_once __DIR__ . '/includes/jornada.php';
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
	try {
		$db = getDBConnection();
		if ($db === null) {
			throw new RuntimeException('No hay conexión con la base de datos.');
		}
		$closeResult = cerrarSesionYJornadaSiCorresponde($db, $user);
		if ($closeResult['closed']) {
			logActivity(
				(string)($user['usuario'] ?? 'Usuario'),
				'cierre_jornada',
				"Cierre de jornada: se archivaron {$closeResult['archived']} registros de ventas y se depuraron {$closeResult['pruned']} registros antiguos.",
				[
					'registros_archivados' => $closeResult['archived'],
					'registros_depurados' => $closeResult['pruned']
				],
				isset($user['id']) ? (int)$user['id'] : null
			);
		}
	} catch (Exception $e) {
		$errorCierreJornada = true;
		error_log('Error al cerrar la jornada y eliminar ventas: ' . $e->getMessage());
	}
	logoutUser();
	header('Location: registroinicio.php' . ($errorCierreJornada ? '?error=cierre_jornada' : ''));
	exit;
}

logoutUser();
header('Location: registroinicio.php');
exit;
