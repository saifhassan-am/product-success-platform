<?php

final class Http
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $status, array $extra = []): void
    {
        self::json(['error' => $message] + $extra, $status);
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }
        return $data;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return $default;
        }
        $value = trim($_GET[$key]);
        return $value === '' ? $default : $value;
    }
}
