<?php

namespace Fava\Core;

class View
{
    /** Render a view (optionally inside a layout) and return the HTML. */
    public static function make(string $view, array $data = [], ?string $layout = null): string
    {
        $content = self::partial($view, $data);
        if ($layout === null) {
            return $content;
        }
        $data['content'] = $content;
        return self::partial('layouts/' . $layout, $data);
    }

    /** Render and send. */
    public static function render(string $view, array $data = [], ?string $layout = null, int $status = 200): void
    {
        $html = self::make($view, $data, $layout);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
    }

    public static function partial(string $__view, array $__data = []): string
    {
        $__file = APP_ROOT . '/app/Views/' . $__view . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException('View not found: ' . $__view);
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
