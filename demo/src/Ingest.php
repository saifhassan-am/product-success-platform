<?php

/**
 * What the WordPress plugins talk to: register, collect, withdraw.
 */
final class Ingest
{
    private const MAX_EVENTS = 200;
    private const LIFECYCLE = ['plugin_activated', 'plugin_deactivated', 'plugin_uninstalled', 'plugin_updated', 'heartbeat', 'backfill'];
    private const PROFILE_FIELDS = [
        'php_version', 'wp_version', 'db_version', 'locale', 'multisite', 'environment', 'theme', 'ttb_active',
        'thrive_products', 'dashboard_version', 'other_plugins', 'consent_version',
    ];
    private const IDENTIFIED_FIELDS = ['site_name', 'site_url'];

    /**
     * A site registers once per consent tier and gets its own token back.
     * In production the identified tier also proves its account connection with
     * the existing signed account request; the demo trusts the account ID.
     */
    public static function register(array $body, ?int $now = null): array
    {
        $now ??= Time::now();
        $installId = (string) ($body['install_id'] ?? '');
        $tier = (string) ($body['tier'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/', $installId)) {
            throw new InvalidArgumentException('install_id must be a random UUID.');
        }
        if (!in_array($tier, ['anonymous', 'identified'], true)) {
            throw new InvalidArgumentException("tier must be 'anonymous' or 'identified'.");
        }

        $accountId = null;
        if ($tier === 'identified') {
            $accountId = (string) ($body['account_id'] ?? '');
            $account = Db::one('SELECT help_consent FROM accounts WHERE account_id = ?', [$accountId]);
            if ($account === null) {
                throw new InvalidArgumentException('Unknown account.');
            }
            if ((int) $account['help_consent'] !== 1) {
                throw new DomainException("The account holder hasn't agreed to 'Help me succeed'.", 403);
            }
        }

        if (Db::one('SELECT 1 FROM installs WHERE install_id = ?', [$installId]) !== null) {
            throw new DomainException('This install ID is already registered. Generate a new one.', 409);
        }

        $token = bin2hex(random_bytes(24));
        Db::run(
            'INSERT INTO installs (install_id, token, tier, account_id, registered_at) VALUES (?, ?, ?, ?, ?)',
            [$installId, hash('sha256', $token), $tier, $accountId, Time::iso($now)]
        );
        return ['install_id' => $installId, 'token' => $token];
    }

    public static function authenticate(?string $header): array
    {
        if (!$header || !preg_match('/^Bearer\s+([0-9a-f]{48})$/', $header, $m)) {
            throw new DomainException('Missing or malformed site token.', 401);
        }
        $install = Db::one('SELECT * FROM installs WHERE token = ?', [hash('sha256', $m[1])]);
        if ($install === null) {
            throw new DomainException('Unknown site token.', 401);
        }
        return $install;
    }

    public static function collect(array $install, array $body, ?int $now = null): array
    {
        $now ??= Time::now();
        $events = $body['events'] ?? [];
        if (!is_array($events) || count($events) > self::MAX_EVENTS) {
            throw new InvalidArgumentException('events must be an array of at most ' . self::MAX_EVENTS . '.');
        }

        return Db::transaction(function () use ($install, $body, $events, $now) {
            $result = ['accepted' => 0, 'duplicates' => 0, 'rejected' => [], 'profile' => false];

            if (isset($body['profile']) && is_array($body['profile'])) {
                self::upsertProfile($install, $body['profile'], $now);
                $result['profile'] = true;
            }

            foreach ($events as $i => $event) {
                try {
                    $fresh = self::store($install, is_array($event) ? $event : [], $now);
                    $fresh ? $result['accepted']++ : $result['duplicates']++;
                } catch (InvalidArgumentException $e) {
                    $result['rejected'][] = ['index' => $i, 'error' => $e->getMessage()];
                }
            }
            return $result;
        });
    }

    /** Returns false when the event was already received (a retry). */
    private static function store(array $install, array $event, int $now): bool
    {
        $id = (string) ($event['event_id'] ?? '');
        $product = (string) ($event['product'] ?? '');
        $name = (string) ($event['name'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw new InvalidArgumentException('event_id must be a UUID.');
        }
        if (!Products::exists($product)) {
            throw new InvalidArgumentException("Unknown product '$product'.");
        }
        if (!in_array($name, self::LIFECYCLE, true) && Products::stageFor($product, $name) === null) {
            throw new InvalidArgumentException("Unknown event '$name' for product '$product'.");
        }
        $occurred = Time::parseOrFail((string) ($event['occurred_at'] ?? ''));
        $props = is_array($event['properties'] ?? null) ? $event['properties'] : [];

        $inserted = Db::run(
            'INSERT OR IGNORE INTO events (event_id, install_id, product, name, occurred_at, received_at, properties) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $install['install_id'], $product, $name, Time::iso($occurred), Time::iso($now), json_encode((object) $props)]
        );
        if ($inserted === 0) {
            return false;
        }
        StageEngine::apply($install['install_id'], $product, $name, $occurred, $now, $props);
        return true;
    }

    /** Latest values win. The previous record is overwritten, not kept. */
    private static function upsertProfile(array $install, array $profile, int $now): void
    {
        $fields = self::PROFILE_FIELDS;
        if ($install['tier'] === 'identified') {
            $fields = array_merge($fields, self::IDENTIFIED_FIELDS);
        }

        $row = ['install_id' => $install['install_id'], 'updated_at' => Time::iso($now)];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $profile)) {
                continue;
            }
            $value = $profile[$field];
            $row[$field] = is_array($value) ? json_encode($value) : (is_bool($value) ? (int) $value : $value);
        }

        $columns = array_keys($row);
        $updates = implode(', ', array_map(fn($c) => "$c = excluded.$c", array_diff($columns, ['install_id'])));
        Db::run(
            'INSERT INTO site_profiles (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ")
             ON CONFLICT(install_id) DO UPDATE SET $updates",
            array_values($row)
        );
    }

    /**
     * Consent withdrawn (or the site moved to another tier). Everything tied to
     * this install is deleted. A real withdrawal is counted, with nothing identifying.
     */
    public static function withdraw(array $install, array $body, ?int $now = null): array
    {
        $now ??= Time::now();
        $reason = ($body['reason'] ?? 'withdrawn') === 'tier_changed' ? 'tier_changed' : 'withdrawn';

        Db::transaction(function () use ($install, $reason, $now) {
            $accountId = $install['account_id'];
            Db::run('DELETE FROM installs WHERE install_id = ?', [$install['install_id']]);

            if ($accountId !== null) {
                // Keep the pilot record so the analysis stays intent-to-treat, but drop the site link.
                Db::run('UPDATE assignments SET install_id = NULL, withdrawn = 1 WHERE install_id = ?', [$install['install_id']]);
            }
            if ($reason === 'withdrawn') {
                Db::run('INSERT INTO withdrawals (tier, created_at) VALUES (?, ?)', [$install['tier'], Time::iso($now)]);
            }
        });

        return ['deleted' => true];
    }
}
