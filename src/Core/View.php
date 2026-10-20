<?php
declare(strict_types=1);

namespace Cosmo\Core;

/**
 * Plantillas PHP de templates/. Acceden a estos helpers con $this (e, url, icon, ago, bytes…).
 */
final class View
{
    /** @var array<string,string>|null */
    private ?array $icons = null;
    /** @var array<string,string> HTML que una plantilla deja para el layout (p. ej. botones de cabecera) */
    private array $slots = [];

    public function __construct(private readonly App $app)
    {
    }

    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $vars);
        return $layout === null ? $content : $this->renderFile($layout, $vars + ['content' => $content]);
    }

    private function renderFile(string $__template, array $__vars): string
    {
        $__file = CS_ROOT . '/templates/' . $__template . '.php';
        if (!preg_match('~^[a-z0-9_/-]+$~', $__template) || !is_file($__file)) {
            throw new \RuntimeException("Plantilla no encontrada: $__template");
        }
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /** Empieza a capturar HTML para el slot $name (acabar con endSlot()). */
    public function slot(string $name): void
    {
        $this->slots['__current'] = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        $name = $this->slots['__current'] ?? '';
        unset($this->slots['__current']);
        $this->slots[$name] = (string) ob_get_clean();
    }

    public function slotHtml(string $name): string
    {
        return $this->slots[$name] ?? '';
    }

    public function partial(string $template, array $vars = []): string
    {
        return $this->renderFile($template, $vars);
    }

    // ------------------------------------------------------------------ helpers

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function url(string $route, array $params = []): string
    {
        return $this->app->url($route, $params);
    }

    public function asset(string $file): string
    {
        $path = CS_ROOT . '/templates/assets/' . $file;
        return $this->url('asset', ['f' => $file, 'v' => is_file($path) ? substr(md5((string) filemtime($path)), 0, 8) : '0']);
    }

    public function appName(): string
    {
        return (string) ($this->app->config['app_name'] ?? 'Cosmo');
    }

    public function version(): string
    {
        return $this->app->version();
    }

    public function user(): ?User
    {
        return $this->app->auth->user();
    }

    public function can(string $permission): bool
    {
        return $this->user()?->can($permission) ?? false;
    }

    public function csrfToken(): string
    {
        return Session::csrfToken();
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e(Session::csrfToken()) . '">';
    }

    /** @return list<array{0:string,1:string}> */
    public function flashes(): array
    {
        return Session::takeFlashes();
    }

    public function route(): string
    {
        return $this->app->route;
    }

    public function json(mixed $value): string
    {
        return $this->e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function icon(string $name, string $class = ''): string
    {
        $this->icons ??= (array) require CS_ROOT . '/templates/icons.php';
        $inner = $this->icons[$name] ?? null;
        if ($inner === null) {
            return '';
        }
        return '<svg class="cs-icon ' . $this->e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }

    /** Todos los iconos como <symbol>, para app.js (<use href="#cs-i-nombre">). */
    public function iconSprite(array $names): string
    {
        $out = '';
        foreach ($names as $name) {
            $svg = $this->icon($name);
            if ($svg !== '' && preg_match('~<svg[^>]*>(.*)</svg>~s', $svg, $m)) {
                $out .= '<symbol id="cs-i-' . $this->e($name) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                    . ' stroke-linecap="round" stroke-linejoin="round">' . $m[1] . '</symbol>';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" hidden aria-hidden="true">' . $out . '</svg>';
    }

    /** Markdown mínimo para el CHANGELOG: párrafos, listas con -, `código`, **negrita** y enlaces. */
    public function markdown(string $text): string
    {
        $inline = function (string $s): string {
            $s = $this->e($s);
            $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
            $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
            return preg_replace('~\[([^\]]+)\]\((https?://[^)\s]+)\)~', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s) ?? $s;
        };
        $html = '';
        $inList = false;
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                $html .= ($inList ? '' : '<ul>') . '<li>' . $inline($m[1]) . '</li>';
                $inList = true;
                continue;
            }
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            if (trim($line) !== '') {
                $html .= '<p>' . $inline(ltrim($line, '# ')) . '</p>';
            }
        }
        return '<div class="cs-md">' . $html . ($inList ? '</ul>' : '') . '</div>';
    }

    public function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($n, $n >= 100 ? 0 : 1, ',', '.')) . ' ' . $units[$i];
    }

    /** "hace 5 min", "hace 3 días"… */
    public function ago(?string $date): string
    {
        $ts = $date ? strtotime($date) : false;
        if ($ts === false) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            return 'ahora';
        }
        return match (true) {
            $diff < 60 => 'hace ' . $diff . ' s',
            $diff < 3600 => 'hace ' . intdiv($diff, 60) . ' min',
            $diff < 86400 => 'hace ' . intdiv($diff, 3600) . ' h',
            $diff < 86400 * 60 => 'hace ' . intdiv($diff, 86400) . ($diff < 86400 * 2 ? ' día' : ' días'),
            default => 'hace ' . intdiv($diff, 86400 * 30) . ' meses',
        };
    }

    public function datetime(?string $date): string
    {
        $ts = $date ? strtotime($date) : false;
        return $ts === false ? '—' : date('d/m/Y H:i', $ts);
    }

    /** <time> con la fecha completa en el title y el tiempo relativo como texto */
    public function time(?string $date): string
    {
        if (!$date || strtotime($date) === false) {
            return '<span class="cs-muted">—</span>';
        }
        return '<time datetime="' . $this->e(date(DATE_ATOM, strtotime($date))) . '" title="' . $this->e($this->datetime($date)) . '">'
            . $this->e($this->ago($date)) . '</time>';
    }
}
