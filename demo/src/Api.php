<?php

final class Api
{
    public static function handle(string $method, string $path): void
    {
        try {
            self::route($method, $path);
        } catch (InvalidArgumentException $e) {
            Http::error($e->getMessage(), 400);
        } catch (DomainException $e) {
            $code = $e->getCode();
            Http::error($e->getMessage(), $code >= 400 && $code < 500 ? $code : 409);
        } catch (Throwable $e) {
            error_log((string) $e);
            Http::error('Something went wrong on the server.', 500, ['detail' => $e->getMessage()]);
        }
    }

    private static function route(string $method, string $path): void
    {
        $product = Http::query('product', 'tl');
        if (!Products::exists($product)) {
            throw new InvalidArgumentException("Unknown product '$product'.");
        }

        // Plugin-facing endpoints.
        if ($method === 'POST' && $path === '/api/v1/register') {
            Http::json(Ingest::register(Http::body()), 201);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/collect') {
            $install = Ingest::authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
            Http::json(Ingest::collect($install, Http::body()), 202);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/withdraw') {
            $install = Ingest::authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
            Http::json(Ingest::withdraw($install, Http::body()));
            return;
        }

        // Console endpoints.
        $role = Console::role();
        if ($method === 'GET' && $path === '/api/v1/console/products') {
            Http::json(['products' => Console::products(), 'role' => $role, 'actor' => Console::actor($role)]);
            return;
        }
        if ($method === 'GET' && $path === '/api/v1/console/summary') {
            Http::json(Console::summary($product));
            return;
        }
        if ($method === 'GET' && $path === '/api/v1/console/worklist') {
            $view = in_array(Http::query('view'), ['today', 'stuck', 'all'], true) ? Http::query('view') : 'today';
            Http::json(Console::worklist($product, $view, self::filters(), $role));
            return;
        }
        if ($method === 'GET' && $path === '/api/v1/console/export.csv') {
            $view = in_array(Http::query('view'), ['today', 'stuck', 'all'], true) ? Http::query('view') : 'stuck';
            $csv = Console::exportCsv($product, $view, self::filters(), $role);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="product-success-' . $product . '-' . $view . '-' . gmdate('Y-m-d') . '.csv"');
            echo $csv;
            return;
        }
        if ($method === 'GET' && $path === '/api/v1/console/access-log') {
            Http::json(['entries' => Console::accessLog()]);
            return;
        }
        if (preg_match('#^/api/v1/console/sites/([0-9a-f-]{36})$#', $path, $m) && $method === 'GET') {
            Http::json(Console::site($m[1], $product, $role));
            return;
        }
        if (preg_match('#^/api/v1/console/sites/([0-9a-f-]{36})/outreach$#', $path, $m) && $method === 'POST') {
            Http::json(Console::logOutreach($m[1], $product, Http::body(), $role), 201);
            return;
        }
        if ($path === '/api/v1/health') {
            Http::json(['ok' => true, 'time' => Time::iso()]);
            return;
        }

        Http::error('Not found.', 404);
    }

    private static function filters(): array
    {
        return [
            'stage' => Http::query('stage'),
            'health' => Http::query('health'),
            'q' => Http::query('q'),
            'sort' => Http::query('sort'),
        ];
    }
}
