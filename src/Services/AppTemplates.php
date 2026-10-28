<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Crypto;
use Cosmo\Core\Store;

/**
 * Plantilla de configuración de cada aplicación para las instalaciones nuevas (storage/data/app-templates.json):
 * lo que se repite en todos los clientes (correo, verificación en dos pasos, tablas de informes…).
 * La contraseña del SMTP se guarda cifrada. Por ahora solo Quasar.
 */
final class AppTemplates
{
    private const FILE = 'app-templates.json';
    /** Aplicaciones que se pueden instalar desde Cosmo */
    public const PROVISIONABLE = ['quasar'];
    /** Primera versión de cada aplicación con bin/cosmo-setup.php */
    public const PROVISION_MIN = ['quasar' => '0.10.0'];

    public const QUASAR_DEFAULTS = [
        'locale' => 'ca',
        'login_field' => 'username',
        'two_factor' => 'off',
        'mail_host' => '',
        'mail_port' => 587,
        'mail_encryption' => 'tls',
        'mail_username' => '',
        'mail_password' => '',
        'mail_from' => '',
        'mail_from_name' => '',
        'reports_tables' => '',
        'ai_limit' => 5,
    ];

    public function __construct(private readonly Store $store, private readonly Crypto $crypto)
    {
    }

    public function get(string $app): array
    {
        return ((array) ($this->store->read(self::FILE)[$app] ?? [])) + self::QUASAR_DEFAULTS;
    }

    /**
     * @return array<string,string> errores por campo
     */
    public function save(string $app, array $input): array
    {
        $current = $this->get($app);
        $data = [
            'locale' => (string) ($input['locale'] ?? 'ca'),
            'login_field' => (string) ($input['login_field'] ?? 'username'),
            'two_factor' => (string) ($input['two_factor'] ?? 'off'),
            'mail_host' => trim((string) ($input['mail_host'] ?? '')),
            'mail_port' => (int) ($input['mail_port'] ?? 587),
            'mail_encryption' => (string) ($input['mail_encryption'] ?? 'tls'),
            'mail_username' => trim((string) ($input['mail_username'] ?? '')),
            'mail_password' => $current['mail_password'],
            'mail_from' => trim((string) ($input['mail_from'] ?? '')),
            'mail_from_name' => trim((string) ($input['mail_from_name'] ?? '')),
            'reports_tables' => trim(str_replace("\r", '', (string) ($input['reports_tables'] ?? ''))),
            'ai_limit' => max(0, (float) str_replace(',', '.', (string) ($input['ai_limit'] ?? '0'))),
        ];
        $errors = [];
        if (!in_array($data['locale'], ['ca', 'es', 'en'], true)) {
            $errors['locale'] = 'Idioma no válido';
        }
        if (!in_array($data['login_field'], ['username', 'email', 'both'], true)) {
            $errors['login_field'] = 'Opción no válida';
        }
        if (!in_array($data['two_factor'], ['off', 'optional', 'required'], true)) {
            $errors['two_factor'] = 'Opción no válida';
        }
        if (!in_array($data['mail_encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors['mail_encryption'] = 'Opción no válida';
        }
        if ($data['mail_port'] < 1 || $data['mail_port'] > 65535) {
            $errors['mail_port'] = 'Puerto entre 1 y 65535';
        }
        if ($data['mail_from'] !== '' && filter_var($data['mail_from'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['mail_from'] = 'Email no válido';
        }
        if ($data['two_factor'] !== 'off' && $data['mail_host'] === '') {
            $errors['two_factor'] = 'La verificación en dos pasos envía el código por correo: configura el SMTP';
        }
        foreach (self::parseTables($data['reports_tables']) as $table => $description) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table)) {
                $errors['reports_tables'] = "Tabla no válida: $table";
            }
        }
        if ($errors) {
            return $errors;
        }
        $password = (string) ($input['mail_password'] ?? '');
        if ($password !== '') {
            $data['mail_password'] = $this->crypto->encrypt($password);
        } elseif (!empty($input['mail_password_clear'])) {
            $data['mail_password'] = '';
        }
        $this->store->update(self::FILE, static function (array $all) use ($app, $data): array {
            $all[$app] = $data + ['updated_at' => date(DATE_ATOM)];
            return $all;
        });
        return [];
    }

    /**
     * Configuración de Quasar (config.local.php) a partir de la plantilla y de lo propio de la instalación.
     * @param array{ai_key?:string,app_name?:string} $extra
     */
    public function quasarConfig(array $extra): array
    {
        $t = $this->get('quasar');
        $config = [
            'locale' => $t['locale'],
            'login_field' => $t['login_field'],
            'two_factor' => ['mode' => $t['two_factor']],
        ];
        if (($extra['app_name'] ?? '') !== '') {
            $config['app_name'] = $extra['app_name'];
        }
        if ($t['mail_host'] !== '') {
            $config['mail'] = [
                'transport' => 'smtp',
                'host' => $t['mail_host'],
                'port' => (int) $t['mail_port'],
                'encryption' => $t['mail_encryption'],
                'username' => $t['mail_username'],
                'password' => $t['mail_password'] !== '' ? $this->crypto->decrypt((string) $t['mail_password']) : '',
                'from' => ['address' => $t['mail_from'] !== '' ? $t['mail_from'] : $t['mail_username'], 'name' => $t['mail_from_name'] !== '' ? $t['mail_from_name'] : ($extra['app_name'] ?? 'Quasar')],
            ];
        }
        $aiKey = trim((string) ($extra['ai_key'] ?? ''));
        if ($aiKey !== '') {
            $config['ai'] = ['enabled' => true, 'api_key' => $aiKey, 'monthly_limit_eur' => (float) $t['ai_limit']];
        }
        $tables = self::parseTables($t['reports_tables']);
        if ($tables) {
            $config['reports'] = ['enabled' => true, 'tables' => $tables];
        }
        return $config;
    }

    /** "clients = Clientes\nactivities = Visitas" → ['clients' => 'Clientes', …] */
    public static function parseTables(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$table, $description] = array_map('trim', explode('=', $line, 2) + [1 => '']);
            $out[$table] = $description !== '' ? $description : $table;
        }
        return $out;
    }
}
