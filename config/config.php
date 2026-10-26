<?php
/**
 * Configuración base de Cosmo. Los valores propios (clave, usuarios, token de GitHub…)
 * van en config/config.local.php, que devuelve solo las claves a sobrescribir.
 * php bin/setup.php lo crea con la clave y el primer usuario.
 */
return [
    'app_name' => 'Cosmo',
    'debug' => false,
    'timezone' => 'Europe/Madrid',

    // Clave para cifrar los tokens de las instalaciones (32 bytes en base64). La genera bin/setup.php.
    // Si se pierde o se cambia, hay que volver a introducir los tokens.
    'key' => null,

    // Usuarios del panel. password = hash de password_hash():  php bin/hash-password.php
    // role: admin (todo, incluido dar de alta instalaciones) | operator (copias, actualizaciones,
    //       mantenimiento) | viewer (solo consulta)
    'users' => [
        // 'kim' => ['name' => 'Kim', 'email' => 'kim@example.com', 'password' => '$2y$12$…', 'role' => 'admin'],
    ],

    'session_name' => 'COSMOSID',
    'session_lifetime' => 7200,       // segundos de inactividad (0 = sin límite)
    'login_max_attempts' => 5,        // intentos fallidos por usuario o IP antes de bloquear
    'login_lockout' => 900,           // segundos de bloqueo

    // Aplicaciones que se pueden dar de alta. Todas hablan la misma API remota (?r=remote) y dicen cuál son
    // en su estado ('app'); la clave es ese identificador.
    //   repo         repositorio de GitHub con las etiquetas vX.Y.Z
    //   min_version  primera versión con API remota (Cosmo no actualiza a una anterior)
    //   backups      tipos de copia que acepta la acción backup, con su nombre
    'apps' => [
        'quasar' => [
            'name' => 'Quasar',
            'repo' => 'jprojects/quasar',
            'min_version' => '0.2.0',
            'backups' => ['full' => 'Completa', 'db' => 'Base de datos', 'files' => 'Ficheros'],
        ],
        'neutron' => [
            'name' => 'Neutron',
            'repo' => 'jprojects/neutron',
            'min_version' => '1.0.0',
            'backups' => ['full' => 'Completa', 'db' => 'Contenido (site/)'],
        ],
    ],

    // Acceso a GitHub. Si los repositorios son privados hace falta un token (fine-grained, con acceso a los
    // repositorios de 'apps' y permiso Contents: read-only)
    'github' => [
        'token' => null,
        'cache_ttl' => 600,           // segundos que se guardan etiquetas y CHANGELOG
    ],

    // Peticiones a las instalaciones
    'http' => [
        'timeout' => 20,              // consultas (estado, log…)
        'long_timeout' => 900,        // copias, actualizaciones y descargas
        'concurrency' => 8,           // comprobaciones en paralelo
    ],

    'monitor' => [
        'history_days' => 30,         // historial de comprobaciones por instalación
        'stale_backup_days' => 7,     // avisa si la última copia es más antigua
        'disk_min_free' => 1073741824, // avisa con menos espacio libre (bytes)
    ],

    // Copias descargadas a Cosmo (storage/backups/{instalación}/)
    'backups' => [
        'local_keep' => 10,           // por instalación
    ],

    // Avisos a un webhook cuando una instalación cae o se recupera, o falla una copia o actualización.
    // format: slack (también Mattermost/Rocket.Chat) | discord | ntfy | json
    'notify' => [
        'webhook' => null,
        'format' => 'slack',
    ],

    'storage_dir' => dirname(__DIR__) . '/storage',
];
