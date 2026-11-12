<?php

namespace Fava\Core;

class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function exception(\Throwable $e, string $where = ''): void
    {
        // a judge link is a credential: keep only its first characters
        $where = (string) preg_replace('#/f/([A-Za-z0-9_\-]{6})[A-Za-z0-9_\-]+#', '/f/$1…', $where);
        // places and function names only – never the arguments, which may hold passwords
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 12) as $i => $frame) {
            $trace[] = '#' . $i . ' '
                . (isset($frame['file']) ? $frame['file'] . '(' . (int) ($frame['line'] ?? 0) . ')' : '[internal]') . ': '
                . (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '') . '()';
        }
        self::write('ERROR', ($where !== '' ? $where . ': ' : '') . get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $trace,
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = STORAGE_DIR . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            if (can_call('error_log')) {
                error_log('[fava] ' . $level . ' ' . $message);
            }
            return;
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $level . ' ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
