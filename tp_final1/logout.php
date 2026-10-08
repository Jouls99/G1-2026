<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Si existe una sesión, registra su cierre y finaliza la jornada cuando corresponde.
if (isLoggedIn()) {
	$user = getCurrentUser();
	$errorCierreJornada = false;
	require_once __DIR__ . '/api/helpers.php';
	require_once __DIR__ . '/includes/jornada.php';
	// Guarda el evento de salida antes de limpiar los datos de autenticación.
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
	// El cierre de jornada y el archivado se delegan al servicio común; un fallo se informa al login.
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
	// Destruye la sesión incluso si falló el cierre de jornada y redirige con el aviso correspondiente.
	logoutUser();
	header('Location: registroinicio.php' . ($errorCierreJornada ? '?error=cierre_jornada' : ''));
	exit;
}

// Ruta de salida idempotente para solicitudes que ya no tienen usuario autenticado.
logoutUser();
header('Location: registroinicio.php');
exit;
