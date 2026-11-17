<?php
/**
 * Minimal stand-ins for the WordPress functions the tracking client uses, so the
 * simulator can run the real client code from the command line. Each simulated
 * site keeps its options in its own JSON file. Not used inside WordPress.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

final class WP_Shim
{
    public static array $site = [];
    public static array $options = [];
    public static array $hooks = [];
    public static string $options_file = '';

    public static function boot(array $site, string $options_file): void
    {
        self::$site = $site;
        self::$hooks = [];
        self::$options_file = $options_file;
        self::$options = is_file($options_file)
            ? (json_decode((string) file_get_contents($options_file), true) ?: [])
            : [];
    }

    public static function save(): void
    {
        file_put_contents(self::$options_file, json_encode(self::$options, JSON_PRETTY_PRINT));
    }
}

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '') {}
    public function get_error_message(): string { return $this->message; }
}

function is_wp_error($thing): bool { return $thing instanceof WP_Error; }

function get_option(string $name, $default = false) { return WP_Shim::$options[$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { WP_Shim::$options[$name] = $value; WP_Shim::save(); return true; }
function delete_option(string $name): bool { unset(WP_Shim::$options[$name]); WP_Shim::save(); return true; }

function get_bloginfo(string $show = '')
{
    return match ($show) {
        'name' => WP_Shim::$site['name'],
        'version' => WP_Shim::$site['wp_version'],
        'language' => str_replace('_', '-', WP_Shim::$site['locale']),
        default => '',
    };
}
function get_site_url(): string { return WP_Shim::$site['url']; }
function home_url(): string { return WP_Shim::$site['url']; }
function get_locale(): string { return WP_Shim::$site['locale']; }
function is_multisite(): bool { return (bool) WP_Shim::$site['multisite']; }
function get_current_blog_id(): int { return 1; }
function wp_get_environment_type(): string { return WP_Shim::$site['environment']; }
function wp_get_theme(): object
{
    return new class {
        public function get(string $field): string { return $field === 'Name' ? WP_Shim::$site['theme'] : ''; }
    };
}
function current_user_can(string $cap): bool { return !empty(WP_Shim::$site['logged_in_admin']); }
function is_admin(): bool { return true; }

function wp_generate_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function wp_json_encode($data) { return json_encode($data, JSON_UNESCAPED_SLASHES); }

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): void { WP_Shim::$hooks[$hook][] = [$cb, $args]; }
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): void { add_action($hook, $cb, $priority, $args); }
function do_action(string $hook, ...$args): void
{
    foreach (WP_Shim::$hooks[$hook] ?? [] as [$cb, $n]) {
        $cb(...array_slice($args, 0, $n));
    }
}
function apply_filters(string $hook, $value, ...$args)
{
    foreach (WP_Shim::$hooks[$hook] ?? [] as [$cb, $n]) {
        $value = $cb(...array_slice(array_merge([$value], $args), 0, $n));
    }
    return $value;
}

function wp_next_scheduled(string $hook) { return WP_Shim::$options['_cron'][$hook] ?? false; }
function wp_schedule_event(int $ts, string $recurrence, string $hook): bool
{
    $cron = WP_Shim::$options['_cron'] ?? [];
    $cron[$hook] = $ts;
    update_option('_cron', $cron);
    return true;
}
function wp_schedule_single_event(int $ts, string $hook): bool { return wp_schedule_event($ts, 'once', $hook); }
function wp_clear_scheduled_hook(string $hook): void
{
    $cron = WP_Shim::$options['_cron'] ?? [];
    unset($cron[$hook]);
    update_option('_cron', $cron);
}

/**
 * Real HTTP POST over curl. Honors 'blocking' => false the way WordPress does in
 * spirit: the caller doesn't wait for or inspect the response.
 */
function wp_remote_post(string $url, array $args = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $args['body'] ?? '',
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($args['headers'] ?? []), $args['headers'] ?? []),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($args['timeout'] ?? 5),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code === 0) {
        return new WP_Error('http_request_failed', $err ?: 'Request failed');
    }
    return ['response' => ['code' => $code], 'body' => $body];
}
function wp_remote_retrieve_response_code($response): int { return is_array($response) ? (int) $response['response']['code'] : 0; }
