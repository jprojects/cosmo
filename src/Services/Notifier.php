<?php
declare(strict_types=1);

namespace Cosmo\Services;

use Cosmo\Core\Log;

/**
 * Avisos a un webhook (config 'notify'): Slack/Mattermost, Discord, ntfy o JSON genérico.
 * Un fallo al avisar nunca interrumpe la tarea que lo provoca.
 */
final class Notifier
{
    public function __construct(private readonly array $config, private readonly string $appName)
    {
    }

    public function enabled(): bool
    {
        return is_string($this->config['webhook'] ?? null) && str_starts_with($this->config['webhook'], 'https://');
    }

    /** @param 'info'|'warning'|'error'|'success' $level */
    public function send(string $level, string $title, string $text = ''): void
    {
        if (!$this->enabled()) {
            return;
        }
        $emoji = ['info' => 'ℹ️', 'warning' => '⚠️', 'error' => '🔴', 'success' => '✅'][$level] ?? '';
        $message = trim("$emoji *{$this->appName}* · $title" . ($text !== '' ? "\n$text" : ''));

        [$body, $type, $headers] = match ($this->config['format'] ?? 'slack') {
            'discord' => [json_encode(['content' => str_replace('*', '**', $message)]), 'application/json', []],
            'ntfy' => [$text !== '' ? $text : $title, 'text/plain; charset=utf-8', ['Title: ' . $this->appName . ' · ' . $title, 'Tags: ' . $level]],
            'json' => [json_encode(['level' => $level, 'title' => $title, 'text' => $text, 'app' => $this->appName, 'time' => date(DATE_ATOM)]), 'application/json', []],
            default => [json_encode(['text' => $message]), 'application/json', []],
        };

        $ch = curl_init($this->config['webhook']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: ' . $type], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $code >= 300) {
            Log::warning('No se pudo enviar el aviso al webhook', ['code' => $code, 'error' => curl_error($ch)]);
        }
    }
}
