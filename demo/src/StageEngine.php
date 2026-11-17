<?php

/**
 * Turns events into per-site, per-product state. Stage is the highest milestone
 * ever reached, so it never goes backwards.
 */
final class StageEngine
{
    private const VALUE_STAGE = 4;
    private const ADOPTED_STAGE = 5;
    private const WEEKS_KEPT = 8;

    public static function apply(string $installId, string $product, string $name, int $occurred, int $received, array $props): void
    {
        self::ensureRow($installId, $product);

        switch ($name) {
            case 'plugin_activated':
                Db::run(
                    'UPDATE site_products SET activated_at = COALESCE(activated_at, :at), plugin_version = COALESCE(:v, plugin_version)
                     WHERE install_id = :i AND product = :p',
                    [':at' => Time::iso($occurred), ':v' => $props['version'] ?? null, ':i' => $installId, ':p' => $product]
                );
                self::setLifecycle($installId, $product, 'active', $occurred);
                self::milestone($installId, $product, 'activated', $occurred, $received, 'event', true);
                break;

            case 'plugin_deactivated':
                self::setLifecycle($installId, $product, 'deactivated', $occurred);
                break;

            case 'plugin_uninstalled':
                self::setLifecycle($installId, $product, 'uninstalled', $occurred);
                break;

            case 'plugin_updated':
                Db::run(
                    'UPDATE site_products SET plugin_version = :v WHERE install_id = :i AND product = :p',
                    [':v' => (string) ($props['version'] ?? ''), ':i' => $installId, ':p' => $product]
                );
                break;

            case 'heartbeat':
                self::heartbeat($installId, $product, $occurred, $received, $props);
                break;

            case 'backfill':
                // Only a running product can report its state, so it is active.
                self::setLifecycle($installId, $product, 'active', $occurred);
                foreach ((array) ($props['milestones'] ?? []) as $milestone => $date) {
                    $at = Time::ts(is_string($date) ? $date : null);
                    self::milestone($installId, $product, (string) $milestone, $at ?? $occurred, $received, 'backfill', false);
                    if ($milestone === 'activated') {
                        Db::run(
                            'UPDATE site_products SET activated_at = COALESCE(activated_at, ?) WHERE install_id = ? AND product = ?',
                            [Time::iso($at ?? $occurred), $installId, $product]
                        );
                    }
                }
                break;

            default:
                if (Products::stageFor($product, $name) === null) {
                    throw new InvalidArgumentException("Unknown event '$name' for product '$product'.");
                }
                self::milestone($installId, $product, $name, $occurred, $received, 'event', true);
        }

        self::recompute($installId, $product);
    }

    private static function heartbeat(string $installId, string $product, int $occurred, int $received, array $props): void
    {
        $row = Db::one('SELECT value_weeks, last_heartbeat_at FROM site_products WHERE install_id = ? AND product = ?', [$installId, $product]);
        $weeks = json_decode($row['value_weeks'], true) ?: [];
        $value = max(0, (int) ($props['value'] ?? 0));
        $weeks[Time::week($occurred)] = $value;
        ksort($weeks);
        $weeks = array_slice($weeks, -self::WEEKS_KEPT, null, true);

        // A heartbeat proves the product is running, unless it predates the last lifecycle change.
        self::setLifecycle($installId, $product, 'active', $occurred);

        $lastHeartbeat = max(Time::ts($row['last_heartbeat_at']) ?? 0, $occurred);
        Db::run(
            'UPDATE site_products SET last_heartbeat_at = :hb, value_weeks = :w, metrics = :m WHERE install_id = :i AND product = :p',
            [':hb' => Time::iso($lastHeartbeat), ':w' => json_encode($weeks), ':m' => json_encode((object) ($props['metrics'] ?? [])), ':i' => $installId, ':p' => $product]
        );
        // Decided in PHP: SQLite compares a bound '0' as text, which is always "greater" than 0.
        if ($value > 0) {
            Db::run(
                'UPDATE site_products SET last_value_at = :at WHERE install_id = :i AND product = :p AND (last_value_at IS NULL OR last_value_at < :at)',
                [':at' => Time::iso($occurred), ':i' => $installId, ':p' => $product]
            );
        }

        if (!empty($props['last_admin_seen_at']) && Time::ts($props['last_admin_seen_at'])) {
            Db::run(
                'UPDATE site_profiles SET last_admin_seen_at = :s WHERE install_id = :i AND (last_admin_seen_at IS NULL OR last_admin_seen_at < :s)',
                [':s' => Time::iso(Time::ts($props['last_admin_seen_at'])), ':i' => $installId]
            );
        }

        // Fill in any milestone whose event never arrived.
        foreach ((array) ($props['milestones'] ?? []) as $milestone => $date) {
            $at = Time::ts(is_string($date) ? $date : null);
            self::milestone($installId, $product, (string) $milestone, $at ?? $occurred, $received, 'reconciled', $at !== null);
        }

        self::recompute($installId, $product);
        $stage = (int) Db::one('SELECT stage FROM site_products WHERE install_id = ? AND product = ?', [$installId, $product])['stage'];
        if ($stage >= self::VALUE_STAGE && self::isHabit($weeks, $occurred)) {
            self::milestone($installId, $product, 'adopted', $occurred, $received, 'event', true);
        }
    }

    /** Value in at least 3 of the 4 most recent weeks, counting the heartbeat's own week. */
    public static function isHabit(array $weeks, int $asOf): bool
    {
        $hits = 0;
        for ($i = 0; $i < 4; $i++) {
            if (($weeks[Time::week($asOf - $i * 7 * 86400)] ?? 0) > 0) {
                $hits++;
            }
        }
        return $hits >= 3;
    }

    private static function milestone(string $installId, string $product, string $milestone, int $occurred, int $received, string $source, bool $timingOk): void
    {
        $stage = Products::stageFor($product, $milestone);
        if ($stage === null) {
            throw new InvalidArgumentException("Unknown milestone '$milestone' for product '$product'.");
        }
        Db::run(
            'INSERT OR IGNORE INTO milestones (install_id, product, milestone, stage, occurred_at, received_at, source, timing_ok)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$installId, $product, $milestone, $stage, Time::iso($occurred), Time::iso($received), $source, $timingOk ? 1 : 0]
        );
        if ($stage === self::VALUE_STAGE) {
            Db::run(
                'UPDATE site_products SET last_value_at = :at WHERE install_id = :i AND product = :p AND (last_value_at IS NULL OR last_value_at < :at)',
                [':at' => Time::iso($occurred), ':i' => $installId, ':p' => $product]
            );
        }
    }

    private static function recompute(string $installId, string $product): void
    {
        $top = Db::one(
            'SELECT stage, occurred_at, received_at, timing_ok FROM milestones WHERE install_id = ? AND product = ?
             ORDER BY stage DESC, occurred_at ASC LIMIT 1',
            [$installId, $product]
        );
        if ($top === null) {
            return;
        }
        Db::run(
            'UPDATE site_products SET stage = :s, stage_reached_at = :at, stage_received_at = :rec, stage_timing_ok = :ok
             WHERE install_id = :i AND product = :p',
            [':s' => $top['stage'], ':at' => $top['occurred_at'], ':rec' => $top['received_at'], ':ok' => $top['timing_ok'], ':i' => $installId, ':p' => $product]
        );
    }

    private static function ensureRow(string $installId, string $product): void
    {
        Db::run('INSERT OR IGNORE INTO site_products (install_id, product) VALUES (?, ?)', [$installId, $product]);
    }

    private static function setLifecycle(string $installId, string $product, string $lifecycle, int $at): void
    {
        Db::run(
            'UPDATE site_products SET lifecycle = :l, lifecycle_at = :at
             WHERE install_id = :i AND product = :p AND (lifecycle_at IS NULL OR lifecycle_at <= :at)',
            [':l' => $lifecycle, ':at' => Time::iso($at), ':i' => $installId, ':p' => $product]
        );
    }
}
