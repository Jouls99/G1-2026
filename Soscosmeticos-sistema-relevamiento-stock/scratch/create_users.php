<?php
$users = [
    [
        'usuario' => 'admin',
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'administrador',
        'creado_en' => date('c')
    ],
    [
        'usuario' => 'vendedor',
        'password' => password_hash('123456', PASSWORD_DEFAULT),
        'role' => 'vendedor',
        'creado_en' => date('c')
    ],
    [
        'usuario' => 'vendedora_maria',
        'password' => password_hash('clave123', PASSWORD_DEFAULT),
        'role' => 'vendedora',
        'creado_en' => date('c')
    ]
];

file_put_contents(__DIR__ . '/../users.json', json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "users_updated\n";
