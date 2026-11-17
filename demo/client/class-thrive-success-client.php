<?php
/**
 * Thrive Success client (demo).
 *
 * In production this would live in Thrive Dashboard, which every Thrive product
 * already bundles, so all products share one client, one consent setting and
 * one outbox. Products only call register_product() and track().
 *
 * Rules it follows:
 * - Nothing is sent unless the site is in a consent tier.
 * - Each tier gets its own random install ID, so anonymous history is never
 *   linked to an identity.
 * - Nothing is sent during a visitor's request. Events go into a local outbox
 *   that a background job sends.
 * - The site profile is only sent when it changes. A heartbeat goes every week.
 */
final class Thrive_Success_Client
{
    public const VERSION = '0.1.0-demo';
    public const CONSENT_VERSION = '2026-11-help-me-succeed-v1';

    private const STATE = 'thrive_success_state';
    private const OUTBOX = 'thrive_success_outbox';
    private const FLUSH_HOOK = 'thrive_success_flush';
    private const DAILY_HOOK = 'thrive_success_daily';
    private const BATCH = 50;
    private const MAX_AGE = 7 * 86400;      // milestones also ride on every heartbeat, so a dropped one is recovered
    private const WEEK = 7 * 86400;
    private const ADMIN_THROTTLE = 3600;

    /** A short, curated list. Never the full plugin list. */
    private const INTERESTING_PLUGINS = [
        'elementor', 'divi-builder', 'beaver-builder-lite-version', 'wp-rocket', 'litespeed-cache', 'w3-total-cache',
        'optinmonster', 'wpforms-lite', 'mailchimp-for-wp', 'fluent-crm', 'woocommerce', 'learndash', 'lifterlms',
    ];

    private static string $endpoint = '';
    private static array $products = [];

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    public static function boot(string $endpoint): void
    {
        self::$endpoint = rtrim($endpoint, '/');
        add_action(self::FLUSH_HOOK, [self::class, 'flush']);
        add_action(self::DAILY_HOOK, [self::class, 'daily']);
        add_action('admin_init', [self::class, 'on_admin_load']);
        add_action('_core_updated_successfully', [self::class, 'maybe_send_profile']);
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::DAILY_HOOK);
        }
        // Products register on plugins_loaded, so check for a copied site once they have.
        add_action('init', [self::class, 'guard_url_change']);
    }

    /**
     * @param array{version: string, milestones: string[], state: callable} $args
     *        state() returns ['activated_at' => int|null,
     *                         'milestones' => [name => ISO date|null] (reached ones only),
     *                         'metrics' => [], 'value' => int]
     */
    public static function register_product(string $code, array $args): void
    {
        self::$products[$code] = $args;
    }

    public static function tier(): string
    {
        return self::state()['tier'] ?? 'none';
    }

    // ------------------------------------------------------------------
    // Consent
    // ------------------------------------------------------------------

    /**
     * Called from the consent UI. 'identified' needs the connected account, and
     * only works if the account holder agreed to "Help me succeed".
     */
    public static function set_tier(string $tier, ?string $account_id = null): void
    {
        if (!in_array($tier, ['none', 'anonymous', 'identified'], true)) {
            return;
        }
        $old = self::state();
        if (($old['tier'] ?? 'none') === $tier) {
            return;
        }

        // Retire the old stream first. Its queued events must never leave under the new ID.
        if (!empty($old['token'])) {
            self::post('/api/v1/withdraw', ['reason' => $tier === 'none' ? 'withdrawn' : 'tier_changed'], $old['token']);
        }
        delete_option(self::OUTBOX);

        $state = [
            'tier' => $tier,
            'install_id' => $tier === 'none' ? null : wp_generate_uuid4(),
            'token' => null,
            'account_id' => $tier === 'identified' ? $account_id : null,
            'consent_version' => $tier === 'none' ? null : self::CONSENT_VERSION,
            'consent_at' => time(),
            'url_fp' => self::url_fingerprint(),
            'sent' => [],
            'profile_hash' => null,
            'last_heartbeat' => 0,
            'admin_seen' => $old['admin_seen'] ?? time(),
        ];
        self::save($state);

        if ($tier !== 'none') {
            self::queue_backfill();
            self::maybe_send_profile();
            self::flush();
        }
    }

    // ------------------------------------------------------------------
    // Tracking
    // ------------------------------------------------------------------

    public static function track(string $product, string $name, array $props = [], ?int $when = null): void
    {
        if (self::tier() === 'none' || !isset(self::$products[$product])) {
            return;
        }
        $when ??= time();
        if (in_array($name, self::$products[$product]['milestones'], true)) {
            $state = self::state();
            if (!empty($state['sent'][$product][$name])) {
                return; // already reported; the server would ignore it anyway
            }
            $state['sent'][$product][$name] = gmdate('c', $when);
            self::save($state);
        }
        self::enqueue_event($product, $name, $props, $when);
    }

    /** Weekly, always. Carries counts and the milestones reached so far. */
    public static function maybe_heartbeat(bool $force = false, ?int $now = null): void
    {
        $now ??= time();
        $state = self::state();
        if (self::tier() === 'none' || (!$force && $now - ($state['last_heartbeat'] ?? 0) < self::WEEK)) {
            return;
        }
        foreach (self::$products as $code => $p) {
            $s = ($p['state'])();
            self::enqueue_event($code, 'heartbeat', [
                'week' => gmdate('o-\WW', $now),
                'value' => (int) ($s['value'] ?? 0),
                'metrics' => (object) ($s['metrics'] ?? []),
                'milestones' => (object) array_filter($s['milestones'] ?? [], fn($d) => $d !== false),
                'last_admin_seen_at' => gmdate('c', $state['admin_seen'] ?? $now),
            ], $now);
        }
        $state = self::state();
        $state['last_heartbeat'] = $now;
        self::save($state);
    }

    public static function on_admin_load(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = self::state();
        if (time() - ($state['admin_seen'] ?? 0) > self::ADMIN_THROTTLE) {
            $state['admin_seen'] = time();
            self::save($state);
        }
        self::maybe_send_profile();
        // Low-traffic sites are exactly the ones whose cron misfires. Admin page loads
        // nudge the background sender; WordPress runs it in a separate, non-blocking request.
        if (get_option(self::OUTBOX) && function_exists('wp_schedule_single_event') && !wp_next_scheduled(self::FLUSH_HOOK)) {
            wp_schedule_single_event(time(), self::FLUSH_HOOK);
        }
    }

    public static function daily(): void
    {
        self::maybe_send_profile();
        self::maybe_heartbeat();
        self::flush();
    }

    // ------------------------------------------------------------------
    // Profile: latest values only, sent when something changed
    // ------------------------------------------------------------------

    public static function profile(): array
    {
        $theme = (string) wp_get_theme()->get('Name');
        $profile = [
            'php_version' => (string) apply_filters('thrive_success_php_version', PHP_VERSION),
            'wp_version' => (string) get_bloginfo('version'),
            'db_version' => (string) apply_filters('thrive_success_db_version', isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb']->db_version() : ''),
            'locale' => get_locale(),
            'multisite' => is_multisite(),
            'environment' => self::environment(),
            'theme' => $theme,
            'ttb_active' => $theme === 'Thrive Theme Builder',
            'thrive_products' => array_map(fn($p) => $p['version'], self::$products),
            'dashboard_version' => self::VERSION,
            'other_plugins' => self::interesting_plugins(),
            'consent_version' => self::state()['consent_version'] ?? null,
        ];
        if (self::tier() === 'identified') {
            $profile['site_name'] = (string) get_bloginfo('name');
            $profile['site_url'] = home_url();
        }
        return $profile;
    }

    public static function maybe_send_profile(): void
    {
        if (self::tier() === 'none') {
            return;
        }
        $profile = self::profile();
        $hash = md5((string) wp_json_encode($profile));
        $state = self::state();
        if (($state['profile_hash'] ?? null) === $hash) {
            return;
        }
        $state['profile_hash'] = $hash;
        self::save($state);

        $outbox = (array) get_option(self::OUTBOX, []);
        $outbox['profile'] = ['data' => $profile, 'queued_at' => time()]; // only the latest profile is kept
        update_option(self::OUTBOX, $outbox, false);
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /** Runs in the background (cron). Returns the server's response, for the demo. */
    public static function flush(): ?array
    {
        $state = self::state();
        if (($state['tier'] ?? 'none') === 'none') {
            delete_option(self::OUTBOX);
            return null;
        }
        if (empty($state['token']) && !self::register()) {
            return null;
        }
        $state = self::state();

        $outbox = (array) get_option(self::OUTBOX, []);
        $events = array_values(array_filter($outbox['events'] ?? [], fn($e) => time() - $e['queued_at'] < self::MAX_AGE));
        $batch = array_slice($events, 0, self::BATCH);
        $payload = ['events' => array_map(fn($e) => $e['data'], $batch)];
        if (!empty($outbox['profile'])) {
            $payload['profile'] = $outbox['profile']['data'];
        }
        if (!$payload['events'] && empty($payload['profile'])) {
            return ['accepted' => 0];
        }

        $result = self::post('/api/v1/collect', $payload, $state['token']);
        if ($result === null) {
            return null; // keep everything for the next run
        }
        if (($result['_status'] ?? 0) === 401) {
            $state['token'] = null; // the server no longer knows us; register again next time
            self::save($state);
            return $result;
        }

        $outbox = (array) get_option(self::OUTBOX, []);
        $outbox['events'] = array_slice($events, count($batch));
        unset($outbox['profile']);
        update_option(self::OUTBOX, $outbox, false);
        return $result;
    }

    private static function register(): bool
    {
        $state = self::state();
        $result = self::post('/api/v1/register', [
            'install_id' => $state['install_id'],
            'tier' => $state['tier'],
            'account_id' => $state['account_id'],
        ]);
        if (($result['_status'] ?? 0) === 409) {
            $state['install_id'] = wp_generate_uuid4(); // ID collision: start fresh
            self::save($state);
            return false;
        }
        if (empty($result['token'])) {
            return false;
        }
        $state['token'] = $result['token'];
        self::save($state);
        return true;
    }

    private static function post(string $path, array $body, ?string $token = null): ?array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = wp_remote_post(self::$endpoint . $path, [
            'body' => wp_json_encode($body),
            'headers' => $headers,
            'timeout' => 5,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $decoded = json_decode($response['body'] ?? '', true) ?: [];
        $decoded['_status'] = wp_remote_retrieve_response_code($response);
        return $decoded;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * When a site opts in, it reports where it already is, once, flagged as
     * backfilled. A site that opts in within a day of activating (the normal
     * onboarding case) counts as a new, timed activation instead.
     */
    private static function queue_backfill(): void
    {
        foreach (self::$products as $code => $p) {
            $s = ($p['state'])();
            $milestones = array_filter($s['milestones'] ?? [], fn($d) => $d !== false);
            $activatedAt = isset($s['activated_at']) ? (int) $s['activated_at'] : null;

            if ($activatedAt && time() - $activatedAt < 86400 && !$milestones) {
                self::enqueue_event($code, 'plugin_activated', ['version' => $p['version']], $activatedAt);
            } else {
                $milestones = ['activated' => $activatedAt ? gmdate('c', $activatedAt) : null] + $milestones;
                self::enqueue_event($code, 'backfill', ['milestones' => (object) $milestones]);
            }

            $state = self::state();
            foreach (array_keys($milestones) as $name) {
                $state['sent'][$code][$name] = gmdate('c');
            }
            self::save($state);
        }
        self::maybe_heartbeat(true);
    }

    /** A copied site (staging clone, migration) gets a new ID instead of sharing one. */
    public static function guard_url_change(): void
    {
        $state = self::state();
        if (($state['tier'] ?? 'none') === 'none' || ($state['url_fp'] ?? null) === self::url_fingerprint()) {
            return;
        }
        $state['install_id'] = wp_generate_uuid4();
        $state['token'] = null;
        $state['sent'] = [];
        $state['profile_hash'] = null;
        $state['url_fp'] = self::url_fingerprint();
        self::save($state);
        delete_option(self::OUTBOX);
        self::queue_backfill();
        self::maybe_send_profile();
    }

    private static function enqueue_event(string $product, string $name, array $props, ?int $when = null): void
    {
        $outbox = (array) get_option(self::OUTBOX, []);
        $outbox['events'][] = [
            'queued_at' => time(),
            'data' => [
                'event_id' => wp_generate_uuid4(),
                'product' => $product,
                'name' => $name,
                'occurred_at' => gmdate('c', $when ?? time()),
                'properties' => (object) $props,
            ],
        ];
        update_option(self::OUTBOX, $outbox, false);
    }

    private static function environment(): string
    {
        $type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($type !== 'production') {
            return $type === 'local' || $type === 'development' ? 'local' : 'staging';
        }
        $host = (string) parse_url(home_url(), PHP_URL_HOST);
        if (preg_match('/(^localhost$|\.local$|\.test$|\.localhost$)/', $host)) {
            return 'local';
        }
        if (preg_match('/^(staging|stage|dev)\./', $host)) {
            return 'staging';
        }
        return 'production';
    }

    private static function interesting_plugins(): array
    {
        $active = (array) apply_filters('thrive_success_active_plugins', get_option('active_plugins', []));
        $slugs = array_map(fn($p) => explode('/', (string) $p)[0], $active);
        return array_values(array_intersect(self::INTERESTING_PLUGINS, $slugs));
    }

    private static function url_fingerprint(): string
    {
        return md5(home_url());
    }

    private static function state(): array
    {
        return (array) get_option(self::STATE, []);
    }

    private static function save(array $state): void
    {
        update_option(self::STATE, $state, false);
    }
}
