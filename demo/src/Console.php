<?php

/**
 * Read models and actions for the support console.
 */
final class Console
{
    public const FOCUS_SIZE = 15;
    public const PILOT_TARGET = 250;
    private const QUIET_DAYS = 10;
    private const SLIPPING_DAYS = 30;
    private const ADMIN_AWAY_DAYS = 30;
    private const AGENCY_SITES = 5;
    private const HORIZON_DAYS = 60;
    private const MIN_SAMPLE = 25;
    private const CHANNELS = ['email' => 'Email', 'chat' => 'Chat', 'call' => 'Call', 'ticket' => 'Ticket reply'];
    private const OUTCOMES = ['sent' => 'Sent', 'replied' => 'Replied', 'call_booked' => 'Call booked', 'no_response' => 'No response', 'not_interested' => 'Not interested'];

    public static function role(): string
    {
        $role = $_SERVER['HTTP_X_DEMO_ROLE'] ?? Http::query('role', 'support');
        return $role === 'pm' ? 'pm' : 'support';
    }

    public static function actor(string $role): string
    {
        return $role === 'pm' ? 'Sam (Product)' : 'Maya (Support)';
    }

    // ---------------------------------------------------------------------
    // Core rows
    // ---------------------------------------------------------------------

    private static array $rowsCache = [];

    public static function rows(string $product): array
    {
        return self::$rowsCache[$product] ??= self::buildRows($product);
    }

    private static function buildRows(string $product): array
    {
        $now = Time::now();
        $thresholds = self::thresholds($product);

        $rows = Db::all(
            "SELECT sp.*, i.tier, i.account_id, i.registered_at,
                    p.site_name, p.site_url, p.php_version, p.wp_version, p.db_version, p.locale, p.multisite, p.environment,
                    p.theme, p.ttb_active, p.thrive_products, p.dashboard_version, p.other_plugins, p.last_admin_seen_at,
                    p.consent_version, p.updated_at AS profile_updated_at,
                    a.name AS account_name, a.email AS account_email, a.plan, a.help_consent
             FROM site_products sp
             JOIN installs i ON i.install_id = sp.install_id
             LEFT JOIN site_profiles p ON p.install_id = sp.install_id
             LEFT JOIN accounts a ON a.account_id = i.account_id
             WHERE sp.product = ?",
            [$product]
        );

        $accountSites = [];
        foreach (Db::all("SELECT account_id, COUNT(*) AS n FROM installs WHERE tier = 'identified' GROUP BY account_id") as $r) {
            $accountSites[$r['account_id']] = (int) $r['n'];
        }
        $accountProducts = [];
        foreach (Db::all(
            "SELECT i.account_id, COUNT(DISTINCT sp.product) AS n FROM site_products sp JOIN installs i ON i.install_id = sp.install_id
             WHERE i.tier = 'identified' AND sp.lifecycle = 'active' GROUP BY i.account_id"
        ) as $r) {
            $accountProducts[$r['account_id']] = (int) $r['n'];
        }
        $assignments = [];
        foreach (Db::all('SELECT * FROM assignments WHERE product = ?', [$product]) as $a) {
            $assignments[$a['account_id']] = $a;
        }
        $lastContact = [];
        foreach (Db::all('SELECT install_id, MAX(created_at) AS at FROM outreach WHERE product = ? GROUP BY install_id', [$product]) as $r) {
            $lastContact[$r['install_id']] = $r['at'];
        }
        $lastSeen = [];
        foreach (Db::all('SELECT install_id, MAX(received_at) AS at FROM events WHERE product = ? GROUP BY install_id', [$product]) as $r) {
            $lastSeen[$r['install_id']] = $r['at'];
        }

        $out = [];
        foreach ($rows as $row) {
            $row['last_received_at'] = $lastSeen[$row['install_id']] ?? null;
            $out[] = self::enrich($row, $product, $thresholds, $accountSites, $accountProducts, $assignments, $lastContact, $now);
        }
        return $out;
    }

    private static function enrich(array $r, string $product, array $thresholds, array $accountSites, array $accountProducts, array $assignments, array $lastContact, int $now): array
    {
        $config = Products::get($product);
        $stage = (int) $r['stage'];
        $r['stage'] = $stage;
        $r['stage_label'] = $stage > 0 ? $config['labels'][$stage] : 'Not activated';
        $r['metrics'] = json_decode($r['metrics'] ?? '{}', true) ?: [];
        $r['value_weeks'] = json_decode($r['value_weeks'] ?? '{}', true) ?: [];
        $r['thrive_products'] = json_decode($r['thrive_products'] ?? '{}', true) ?: [];
        $r['other_plugins'] = json_decode($r['other_plugins'] ?? '[]', true) ?: [];
        $r['environment'] = $r['environment'] ?: 'production';
        $r['health'] = self::health($r, $now);

        $since = $r['stage_timing_ok'] ? Time::ts($r['stage_reached_at']) : Time::ts($r['stage_received_at']);
        $r['days_in_stage'] = $since ? (int) floor(Time::days($since, $now)) : null;
        $r['days_is_lower_bound'] = !$r['stage_timing_ok'];

        $r['threshold'] = ($stage >= 1 && $stage <= 4) ? $thresholds[$stage]['stuck_after'] : null;
        $r['stuck'] = $r['threshold'] !== null && $r['days_in_stage'] !== null && $r['days_in_stage'] > $r['threshold'];
        $r['overdue'] = $r['threshold'] ? round(($r['days_in_stage'] ?? 0) / $r['threshold'], 1) : null;

        $r['contactable'] = $r['tier'] === 'identified' && (int) $r['help_consent'] === 1 && $r['environment'] === 'production';
        $r['eligible'] = $r['contactable'] && $r['health'] === 'active' && $r['stuck'];

        $r['account_sites'] = $accountSites[$r['account_id']] ?? 0;
        $r['account_products'] = $accountProducts[$r['account_id']] ?? 0;
        $r['agency'] = $r['account_sites'] >= self::AGENCY_SITES;
        $r['admin_days'] = $r['last_admin_seen_at'] ? (int) floor(Time::days(Time::ts($r['last_admin_seen_at']), $now)) : null;
        $r['last_contact_at'] = $lastContact[$r['install_id']] ?? null;
        $r['pilot_transition'] = $product === Products::PILOT['product'] && $stage === Products::PILOT['from_stage'];

        $a = $r['account_id'] ? ($assignments[$r['account_id']] ?? null) : null;
        $r['pilot'] = ($a && $a['install_id'] === $r['install_id']) ? ['arm' => $a['arm'], 'assigned_at' => $a['assigned_at']] : null;

        [$r['priority'], $r['reasons']] = self::priority($r);
        return $r;
    }

    public static function health(array $r, int $now): string
    {
        if ($r['lifecycle'] === 'uninstalled') {
            return 'uninstalled';
        }
        if ($r['lifecycle'] === 'deactivated') {
            return 'deactivated';
        }
        $seen = Time::ts($r['last_heartbeat_at']) ?? Time::ts($r['activated_at']) ?? Time::ts($r['registered_at']);
        if ($seen === null || Time::days($seen, $now) > self::QUIET_DAYS) {
            return 'quiet';
        }
        if ((int) $r['stage'] >= 4) {
            $value = Time::ts($r['last_value_at']);
            if ($value === null || Time::days($value, $now) > self::SLIPPING_DAYS) {
                return 'slipping';
            }
        }
        return 'active';
    }

    /** Favors customers likely to respond, not just customers at risk. */
    private static function priority(array $r): array
    {
        $reasons = [];
        $score = 0;

        if ($r['overdue'] !== null) {
            // Peaks around twice the usual time, then fades. Someone who stalled last
            // week is likelier to respond than someone who stalled months ago.
            $o = $r['overdue'];
            $fit = $o <= 1 ? 0 : ($o <= 2 ? $o - 1 : max(0.3, 1 - ($o - 2) * 0.15));
            $score += 45 * $fit;
            if ($r['stuck']) {
                $reasons[] = $o > 4
                    ? ['label' => "Stalled a long time ({$o}×)", 'tone' => 'down']
                    : ['label' => "{$o}× the usual time", 'tone' => 'up'];
            }
        }
        if ($r['admin_days'] === null || $r['admin_days'] > self::ADMIN_AWAY_DAYS) {
            $reasons[] = ['label' => $r['admin_days'] === null ? 'No admin seen' : "No admin for {$r['admin_days']}d", 'tone' => 'down'];
        } elseif ($r['admin_days'] <= 7) {
            $score += 30;
            $reasons[] = ['label' => 'Admin active ' . self::ago($r['admin_days']), 'tone' => 'up'];
        } else {
            $score += 15;
            $reasons[] = ['label' => 'Admin seen ' . self::ago($r['admin_days']), 'tone' => 'up'];
        }
        if ($r['agency']) {
            $score += 15;
            $reasons[] = ['label' => "Agency, {$r['account_sites']} sites", 'tone' => 'up'];
        } elseif ($r['account_products'] >= 2) {
            $score += 10;
            $reasons[] = ['label' => "Uses {$r['account_products']} Thrive products", 'tone' => 'up'];
        }
        if ($r['pilot_transition']) {
            $score += 10;
            $reasons[] = ['label' => 'Pilot transition', 'tone' => 'up'];
        }
        return [(int) round($score), $reasons];
    }

    private static function ago(int $days): string
    {
        return $days === 0 ? 'today' : ($days === 1 ? 'yesterday' : "{$days}d ago");
    }

    // ---------------------------------------------------------------------
    // Stuck thresholds, from the data
    // ---------------------------------------------------------------------

    /**
     * For each stage: the day by which 80% of the sites that moved on (within 60
     * days) had moved on. After that point, waiting rarely helps. Falls back to
     * the default until there are enough timed transitions.
     */
    public static function thresholds(string $product): array
    {
        $now = Time::now();
        $config = Products::get($product);
        $byInstall = [];
        foreach (Db::all('SELECT install_id, stage, occurred_at, timing_ok FROM milestones WHERE product = ?', [$product]) as $m) {
            $byInstall[$m['install_id']][(int) $m['stage']] = ['at' => Time::ts($m['occurred_at']), 'ok' => (int) $m['timing_ok']];
        }

        $out = [];
        for ($s = 1; $s <= 4; $s++) {
            $entered = 0;
            $times = [];
            foreach ($byInstall as $stages) {
                $here = $stages[$s] ?? null;
                if (!$here || !$here['ok'] || Time::days($here['at'], $now) < self::HORIZON_DAYS) {
                    continue;
                }
                $next = null;
                $nextOk = true;
                foreach ($stages as $st => $m) {
                    if ($st > $s && ($next === null || $m['at'] < $next)) {
                        $next = $m['at'];
                        $nextOk = (bool) $m['ok'];
                    }
                }
                if ($next !== null && !$nextOk) {
                    continue;
                }
                $entered++;
                if ($next !== null && Time::days($here['at'], $next) <= self::HORIZON_DAYS) {
                    $times[] = Time::days($here['at'], $next);
                }
            }
            sort($times);
            if (count($times) >= self::MIN_SAMPLE) {
                $out[$s] = [
                    'stuck_after' => max(1, (int) ceil(self::percentile($times, 0.8))),
                    'typical' => round(self::percentile($times, 0.5), 1),
                    'source' => 'data',
                    'n' => count($times),
                    'advanced_share' => $entered ? round(count($times) / $entered, 3) : null,
                ];
            } else {
                $out[$s] = ['stuck_after' => $config['thresholds'][$s], 'typical' => null, 'source' => 'default', 'n' => count($times), 'advanced_share' => null];
            }
        }
        return $out;
    }

    private static function percentile(array $sorted, float $p): float
    {
        $idx = ($p * (count($sorted) - 1));
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo);
    }

    // ---------------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------------

    public static function summary(string $product): array
    {
        $now = Time::now();
        $config = Products::get($product);
        $rows = array_values(array_filter(self::rows($product), fn($r) => $r['environment'] === 'production'));
        $thresholds = self::thresholds($product);

        $funnel = [];
        $reachedPrev = null;
        for ($s = 1; $s <= 5; $s++) {
            $reached = count(array_filter($rows, fn($r) => $r['stage'] >= $s));
            $funnel[] = [
                'stage' => $s,
                'name' => Products::STAGES[$s],
                'label' => $config['labels'][$s],
                'reached' => $reached,
                'here' => count(array_filter($rows, fn($r) => $r['stage'] === $s && in_array($r['health'], ['active', 'slipping'], true))),
                'from_previous' => $reachedPrev ? round($reached / $reachedPrev, 3) : null,
                // Accounts, not sites, so it matches the worklist's one row per account.
                'stuck' => $s <= 4 ? count(array_unique(array_column(array_filter($rows, fn($r) => $r['stage'] === $s && $r['eligible']), 'account_id'))) : null,
                'threshold' => $thresholds[$s] ?? null,
            ];
            $reachedPrev = $reached;
        }

        $health = array_fill_keys(['active', 'slipping', 'quiet', 'deactivated', 'uninstalled'], 0);
        foreach ($rows as $r) {
            $health[$r['health']]++;
        }

        $identified = array_filter($rows, fn($r) => $r['tier'] === 'identified');
        $contactableAccounts = count(array_unique(array_column(array_filter($rows, fn($r) => $r['contactable']), 'account_id')));

        // Main measure: new activations (after launch, timed) reaching First value within 30 days.
        $timed = Db::all(
            "SELECT a.install_id, a.occurred_at AS activated,
                    (SELECT MIN(v.occurred_at) FROM milestones v WHERE v.install_id = a.install_id AND v.product = a.product AND v.stage >= 4) AS valued
             FROM milestones a WHERE a.product = ? AND a.milestone = 'activated' AND a.timing_ok = 1
               AND a.occurred_at <= ?",
            [$product, Time::iso($now - 30 * 86400)]
        );
        $hit = 0;
        foreach ($timed as $t) {
            if ($t['valued'] && Time::days(Time::ts($t['activated']), Time::ts($t['valued'])) <= 30) {
                $hit++;
            }
        }

        return [
            'product' => ['code' => $product, 'name' => $config['name'], 'wave' => $config['wave'], 'value_label' => $config['value_label']],
            'funnel' => $funnel,
            'health' => $health,
            'withdrawn' => (int) Db::one("SELECT COUNT(*) AS n FROM withdrawals WHERE tier = 'identified'")['n'],
            'coverage' => [
                'sites' => count($rows),
                'anonymous' => count($rows) - count($identified),
                'identified' => count($identified),
                'contactable_accounts' => $contactableAccounts,
                'excluded_non_production' => (int) Db::one(
                    "SELECT COUNT(*) AS n FROM site_products sp JOIN site_profiles p ON p.install_id = sp.install_id
                     WHERE sp.product = ? AND p.environment IN ('staging', 'local')",
                    [$product]
                )['n'],
            ],
            'main_measure' => [
                'label' => 'New activations reaching First value within 30 days',
                'rate' => $timed ? round($hit / count($timed), 3) : null,
                'n' => count($timed),
            ],
            'pilot' => $product === Products::PILOT['product'] ? self::pilot() : null,
            'as_of' => Time::iso($now),
        ];
    }

    /** Intent to treat: both groups measured from the moment of assignment. */
    public static function pilot(): array
    {
        $now = Time::now();
        $p = Products::PILOT;
        $arms = ['contact' => self::emptyArm(), 'holdout' => self::emptyArm()];

        $assignments = Db::all('SELECT * FROM assignments WHERE product = ? AND from_stage = ?', [$p['product'], $p['from_stage']]);
        foreach ($assignments as $a) {
            $arm = &$arms[$a['arm']];
            $arm['assigned']++;
            $at = Time::ts($a['assigned_at']);
            $reachedAt = null;
            $movedAt = null;
            if ($a['install_id']) {
                $m = Db::one(
                    'SELECT MIN(occurred_at) AS at FROM milestones WHERE install_id = ? AND product = ? AND stage >= 4 AND occurred_at > ?',
                    [$a['install_id'], $p['product'], $a['assigned_at']]
                );
                $reachedAt = Time::ts($m['at'] ?? null);
                $movedAt = $reachedAt; // from stage 3, moving up a stage means reaching First value
            }
            if (Time::days($at, $now) >= 30) {
                $arm['primary_n']++;
                if ($reachedAt && Time::days($at, $reachedAt) <= 30) {
                    $arm['primary_hits']++;
                }
            }
            if (Time::days($at, $now) >= 14) {
                $arm['secondary_n']++;
                if ($movedAt && Time::days($at, $movedAt) <= 14) {
                    $arm['secondary_hits']++;
                }
            }
            if ($a['arm'] === 'contact' && $a['install_id']) {
                $contacted = Db::one('SELECT 1 FROM outreach WHERE install_id = ? AND product = ? AND created_at >= ? LIMIT 1', [$a['install_id'], $p['product'], $a['assigned_at']]);
                $arm['actually_contacted'] += $contacted ? 1 : 0;
            }
            unset($arm);
        }

        foreach ($arms as &$arm) {
            $arm['primary_rate'] = $arm['primary_n'] ? round($arm['primary_hits'] / $arm['primary_n'], 3) : null;
            $arm['secondary_rate'] = $arm['secondary_n'] ? round($arm['secondary_hits'] / $arm['secondary_n'], 3) : null;
        }
        unset($arm);

        // Pre-registered: read the result only once both groups reach the planned size.
        $matured = min($arms['contact']['primary_n'], $arms['holdout']['primary_n']);
        $recent = (int) Db::one('SELECT COUNT(*) AS n FROM assignments WHERE product = ? AND from_stage = ? AND assigned_at >= ?', [$p['product'], $p['from_stage'], Time::iso($now - 28 * 86400)])['n'];
        $perWeek = $recent / 4;
        $remaining = max(0, 2 * self::PILOT_TARGET - count($assignments));
        $weeksLeft = $perWeek > 0 ? (int) ceil($remaining / $perWeek) : null;

        return [
            'transition' => 'Live to First value',
            'from_stage' => $p['from_stage'],
            'target_per_group' => self::PILOT_TARGET,
            'assigned' => count($assignments),
            'ready' => $matured >= self::PILOT_TARGET,
            'assignments_per_week' => round($perWeek, 1),
            'weeks_to_target' => $weeksLeft,
            'projected_finish' => $weeksLeft !== null ? Time::iso($now + ($weeksLeft * 7 + 30) * 86400) : null,
            'arms' => $arms,
            'primary' => self::difference($arms, 'primary'),
            'secondary' => self::difference($arms, 'secondary'),
        ];
    }

    private static function emptyArm(): array
    {
        return ['assigned' => 0, 'primary_n' => 0, 'primary_hits' => 0, 'secondary_n' => 0, 'secondary_hits' => 0, 'actually_contacted' => 0];
    }

    private static function difference(array $arms, string $key): ?array
    {
        $c = $arms['contact'];
        $h = $arms['holdout'];
        if (!$c[$key . '_n'] || !$h[$key . '_n']) {
            return null;
        }
        $p1 = $c[$key . '_hits'] / $c[$key . '_n'];
        $p2 = $h[$key . '_hits'] / $h[$key . '_n'];
        $se = sqrt($p1 * (1 - $p1) / $c[$key . '_n'] + $p2 * (1 - $p2) / $h[$key . '_n']);
        return ['diff' => round($p1 - $p2, 3), 'low' => round($p1 - $p2 - 1.96 * $se, 3), 'high' => round($p1 - $p2 + 1.96 * $se, 3)];
    }

    // ---------------------------------------------------------------------
    // Worklist
    // ---------------------------------------------------------------------

    public static function worklist(string $product, string $view, array $filters, string $role): array
    {
        $rows = self::rows($product);
        $match = function ($r) use ($filters, $role) {
            if (!empty($filters['stage']) && (int) $filters['stage'] !== $r['stage']) {
                return false;
            }
            if (!empty($filters['health']) && $filters['health'] !== $r['health']) {
                return false;
            }
            if (!empty($filters['q']) && $role === 'support') {
                $hay = strtolower(implode(' ', [$r['site_name'], $r['site_url'], $r['account_name'], $r['account_email']]));
                if (!str_contains($hay, strtolower($filters['q']))) {
                    return false;
                }
            }
            return true;
        };

        if ($view === 'all') {
            $pool = self::sorted(array_values(array_filter($rows, fn($r) => $r['contactable'] && $match($r))), $filters);
        } elseif ($view === 'stuck') {
            $pool = self::sorted(self::byAccount(array_filter($rows, fn($r) => $r['eligible'] && $match($r))), $filters);
        } else {
            // Today's list is fixed for everyone; filters only narrow what's shown.
            // That keeps pilot randomization tied to the real daily list.
            $pool = array_slice(self::sorted(self::byAccount(array_filter($rows, fn($r) => $r['eligible'])), []), 0, self::FOCUS_SIZE);
            $pool = self::assignPilot($product, $pool);
            $pool = self::sorted(array_values(array_filter($pool, $match)), $filters);
        }

        return [
            'view' => $view,
            'total' => count($pool),
            'rows' => array_map(fn($r) => self::listRow($r, $product, $role), array_slice($pool, 0, 200)),
        ];
    }

    /** One row per account: an agency is one conversation, not ten. */
    private static function byAccount(array $rows): array
    {
        $byAccount = [];
        foreach ($rows as $r) {
            $key = $r['account_id'];
            if (!isset($byAccount[$key])) {
                $r['other_stuck_sites'] = 0;
                $byAccount[$key] = $r;
            } elseif ($r['priority'] > $byAccount[$key]['priority']) {
                $r['other_stuck_sites'] = $byAccount[$key]['other_stuck_sites'] + 1;
                $byAccount[$key] = $r;
            } else {
                $byAccount[$key]['other_stuck_sites']++;
            }
        }
        return array_values($byAccount);
    }

    private static function sorted(array $pool, array $filters): array
    {
        if (($filters['sort'] ?? '') === 'recent') {
            usort($pool, fn($a, $b) => strcmp((string) $b['last_received_at'], (string) $a['last_received_at']));
        } else {
            // Ties go to whoever was in the dashboard most recently.
            usort($pool, fn($a, $b) => [$b['priority'], $a['admin_days'] ?? 999] <=> [$a['priority'], $b['admin_days'] ?? 999]);
        }
        return $pool;
    }

    /** Randomize by account the first time it reaches the day's list at the pilot transition. */
    private static function assignPilot(string $product, array $pool): array
    {
        foreach ($pool as &$r) {
            if (!$r['pilot_transition'] || !$r['account_id']) {
                continue;
            }
            $existing = Db::one('SELECT arm, assigned_at, install_id FROM assignments WHERE account_id = ? AND product = ?', [$r['account_id'], $product]);
            if ($existing === null) {
                $arm = random_int(0, 1) ? 'contact' : 'holdout';
                $at = Time::iso();
                Db::run(
                    'INSERT INTO assignments (account_id, product, install_id, from_stage, arm, assigned_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$r['account_id'], $product, $r['install_id'], $r['stage'], $arm, $at]
                );
                $existing = ['arm' => $arm, 'assigned_at' => $at, 'install_id' => $r['install_id']];
            }
            $r['pilot'] = ['arm' => $existing['arm'], 'assigned_at' => $existing['assigned_at']];
        }
        unset($r);
        return $pool;
    }

    private static function listRow(array $r, string $product, string $role): array
    {
        $play = Products::playbook($product, max(1, $r['stage']), $r['health'], ['first_name' => '', 'site_name' => '', 'metrics' => $r['metrics']]);
        return self::mask([
            'install_id' => $r['install_id'],
            'account_id' => $r['account_id'],
            'site_name' => $r['site_name'],
            'site_url' => $r['site_url'],
            'account_name' => $r['account_name'],
            'account_email' => $r['account_email'],
            'plan' => $r['plan'],
            'stage' => $r['stage'],
            'stage_label' => $r['stage_label'],
            'days_in_stage' => $r['days_in_stage'],
            'days_is_lower_bound' => $r['days_is_lower_bound'],
            'threshold' => $r['threshold'],
            'overdue' => $r['overdue'],
            'stuck' => $r['stuck'],
            'health' => $r['health'],
            'php_version' => $r['php_version'],
            'wp_version' => $r['wp_version'],
            'environment' => $r['environment'],
            'priority' => $r['priority'],
            'reasons' => $r['reasons'],
            'pilot' => $r['pilot'],
            'agency' => $r['agency'],
            'account_sites' => $r['account_sites'],
            'other_stuck_sites' => $r['other_stuck_sites'] ?? 0,
            'last_contact_at' => $r['last_contact_at'],
            'last_received_at' => $r['last_received_at'],
            'next_step' => $play['title'],
        ], $role);
    }

    private static function mask(array $row, string $role): array
    {
        if ($role === 'pm') {
            $row['site_name'] = 'Site ' . substr($row['install_id'], 0, 6);
            $row['site_url'] = null;
            $row['account_name'] = null;
            $row['account_email'] = null;
        }
        return $row;
    }

    // ---------------------------------------------------------------------
    // Site detail
    // ---------------------------------------------------------------------

    public static function site(string $installId, string $product, string $role): array
    {
        $rows = array_values(array_filter(self::rows($product), fn($r) => $r['install_id'] === $installId));
        if (!$rows) {
            throw new DomainException('Site not found for this product.', 404);
        }
        $r = $rows[0];
        $config = Products::get($product);

        if ($role === 'support') {
            // A refresh isn't a new look at someone's data: one entry per person, per site, per 30 minutes.
            $target = "$installId ($product)";
            $recent = Db::one(
                "SELECT 1 FROM access_log WHERE actor = ? AND action = 'viewed_site' AND target = ? AND created_at >= ? LIMIT 1",
                [self::actor($role), $target, Time::iso(Time::now() - 1800)]
            );
            if (!$recent) {
                self::audit(self::actor($role), 'viewed_site', $target);
            }
        }

        $milestones = Db::all('SELECT * FROM milestones WHERE install_id = ? AND product = ? ORDER BY stage', [$installId, $product]);
        $ladder = [];
        for ($s = 1; $s <= 5; $s++) {
            $m = current(array_filter($milestones, fn($x) => (int) $x['stage'] === $s)) ?: null;
            $ladder[] = [
                'stage' => $s,
                'name' => Products::STAGES[$s],
                'label' => $config['labels'][$s],
                'reached_at' => $m['occurred_at'] ?? null,
                'source' => $m['source'] ?? null,
                'timing_ok' => isset($m['timing_ok']) ? (bool) $m['timing_ok'] : null,
            ];
        }

        // Lifecycle from the event log, milestones from the first-only record, so a
        // resent milestone never shows up twice.
        $timeline = [];
        foreach (Db::all(
            "SELECT name, occurred_at, properties FROM events WHERE install_id = ? AND product = ?
             AND name IN ('plugin_activated', 'plugin_deactivated', 'plugin_uninstalled', 'plugin_updated', 'backfill') ORDER BY occurred_at DESC LIMIT 40",
            [$installId, $product]
        ) as $e) {
            $timeline[] = ['at' => $e['occurred_at'], 'kind' => 'event', 'text' => self::describeEvent($product, $e['name'], json_decode($e['properties'], true) ?: [])];
        }
        foreach ($milestones as $m) {
            if ((int) $m['stage'] < 2) {
                continue;
            }
            $note = ['backfill' => ' (reached before opting in)', 'reconciled' => ' (recovered from a weekly check-in)'][$m['source']] ?? '';
            $timeline[] = ['at' => $m['occurred_at'], 'kind' => 'event', 'text' => 'Reached ' . Products::STAGES[(int) $m['stage']] . ': ' . $config['labels'][(int) $m['stage']] . $note];
        }
        $outreach = Db::all('SELECT * FROM outreach WHERE install_id = ? AND product = ? ORDER BY created_at DESC', [$installId, $product]);
        foreach ($outreach as $o) {
            $timeline[] = ['at' => $o['created_at'], 'kind' => 'outreach', 'text' => self::CHANNELS[$o['channel']] . ': ' . self::OUTCOMES[$o['outcome']] . ($o['note'] ? " ({$o['note']})" : '') . " by {$o['author']}"];
        }
        if ($r['pilot']) {
            $timeline[] = ['at' => $r['pilot']['assigned_at'], 'kind' => 'pilot', 'text' => 'Entered the pilot, assigned to ' . ($r['pilot']['arm'] === 'holdout' ? 'holdout (no proactive contact)' : 'contact')];
        }
        usort($timeline, fn($a, $b) => strcmp($b['at'], $a['at']));

        $products = [];
        foreach (Db::all('SELECT product FROM site_products WHERE install_id = ?', [$installId]) as $sp) {
            $pr = current(array_filter(self::rows($sp['product']), fn($x) => $x['install_id'] === $installId));
            if ($pr) {
                $products[] = ['code' => $sp['product'], 'name' => Products::get($sp['product'])['name'], 'stage' => $pr['stage'], 'stage_label' => $pr['stage_label'], 'health' => $pr['health']];
            }
        }

        $accountSites = [];
        if ($r['account_id']) {
            foreach (self::rows($product) as $other) {
                if ($other['account_id'] === $r['account_id'] && $other['install_id'] !== $installId) {
                    $accountSites[] = self::mask(['install_id' => $other['install_id'], 'site_name' => $other['site_name'], 'site_url' => $other['site_url'], 'stage' => $other['stage'], 'stage_label' => $other['stage_label'], 'health' => $other['health'], 'environment' => $other['environment']], $role);
                }
            }
        }

        // The message is written to a person, so only Support gets it with their name and site.
        $personal = $role === 'support';
        $firstName = $personal && $r['account_name'] ? explode(' ', $r['account_name'])[0] : '';
        $play = Products::playbook($product, max(1, $r['stage']), $r['health'], ['first_name' => $firstName, 'site_name' => $personal ? $r['site_name'] : '', 'metrics' => $r['metrics']]);

        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $wk = Time::week(Time::now() - $i * 7 * 86400);
            $weeks[] = ['week' => $wk, 'value' => $r['value_weeks'][$wk] ?? null];
        }

        $site = self::mask([
            'install_id' => $installId,
            'account_id' => $r['account_id'],
            'site_name' => $r['site_name'],
            'site_url' => $r['site_url'],
            'account_name' => $r['account_name'],
            'account_email' => $r['account_email'],
        ], $role);

        return [
            'site' => $site + [
                'plan' => $r['plan'],
                'tier' => $r['tier'],
                'agency' => $r['agency'],
                'account_sites' => $r['account_sites'],
            ],
            'product' => ['code' => $product, 'name' => $config['name'], 'value_label' => $config['value_label']],
            'stage' => $r['stage'],
            'stage_label' => $r['stage_label'],
            'health' => $r['health'],
            'days_in_stage' => $r['days_in_stage'],
            'days_is_lower_bound' => $r['days_is_lower_bound'],
            'threshold' => $r['threshold'],
            'stuck' => $r['stuck'],
            'eligible' => $r['eligible'],
            'priority' => $r['priority'],
            'reasons' => $r['reasons'],
            'pilot' => $r['pilot'],
            'ladder' => $ladder,
            'weeks' => $weeks,
            'metrics' => $r['metrics'],
            'blockers' => self::blockers($r, $product),
            'next_step' => $play,
            'timeline' => $timeline,
            'products' => $products,
            'account_other_sites' => $accountSites,
            'profile' => [
                'php_version' => $r['php_version'],
                'wp_version' => $r['wp_version'],
                'db_version' => $r['db_version'],
                'locale' => $r['locale'],
                'multisite' => (bool) $r['multisite'],
                'environment' => $r['environment'],
                'theme' => $r['theme'],
                'thrive_products' => $r['thrive_products'],
                'dashboard_version' => $r['dashboard_version'],
                'other_plugins' => $r['other_plugins'],
                'last_admin_seen_at' => $r['last_admin_seen_at'],
                'consent_version' => $r['consent_version'],
                'updated_at' => $r['profile_updated_at'],
            ],
            'channels' => self::CHANNELS,
            'outcomes' => self::OUTCOMES,
        ];
    }

    private static function describeEvent(string $product, string $name, array $props): string
    {
        $labels = Products::get($product)['labels'];
        return match ($name) {
            'plugin_activated' => 'Plugin activated' . (!empty($props['version']) ? " ({$props['version']})" : ''),
            'plugin_deactivated' => 'Plugin deactivated' . (!empty($props['reason']) ? ": {$props['reason']}" : ''),
            'plugin_uninstalled' => 'Plugin uninstalled',
            'plugin_updated' => 'Updated to ' . ($props['version'] ?? '?'),
            'backfill' => 'Joined tracking with existing progress (backfilled)',
            default => 'Milestone: ' . ($labels[Products::stageFor($product, $name) ?? 0] ?? $name),
        };
    }

    private static function blockers(array $r, string $product): array
    {
        $out = [];
        $m = $r['metrics'];
        if ($product === 'tl') {
            if (array_key_exists('email_services_connected', $m) && (int) $m['email_services_connected'] === 0) {
                $out[] = ['level' => 'high', 'text' => 'No email service connected. Only the built-in email and WordPress options are set up.'];
            }
            if ($r['stage'] === 2 && (int) ($m['campaigns_displaying'] ?? 0) > 0 && ($r['days_in_stage'] ?? 0) >= 3) {
                $out[] = ['level' => 'high', 'text' => "A campaign is set to display, but no real visitor has seen it in {$r['days_in_stage']} days. The display rules are probably too narrow."];
            }
        }
        $php = (string) $r['php_version'];
        if ($php !== '' && version_compare($php, '8.1', '<')) {
            $out[] = ['level' => 'high', 'text' => "PHP $php is below Thrive's minimum (8.1)."];
        } elseif (str_starts_with($php, '8.1')) {
            $out[] = ['level' => 'medium', 'text' => 'PHP 8.1 stopped getting security updates at the end of 2025.'];
        }
        $wpBehind = self::versionsBehind('wp_version', (string) $r['wp_version'], null);
        if ($wpBehind['behind'] >= 2) {
            $out[] = ['level' => 'medium', 'text' => "WordPress {$r['wp_version']} is {$wpBehind['behind']} versions behind {$wpBehind['latest']}."];
        }
        $pluginBehind = self::versionsBehind('plugin_version', (string) $r['plugin_version'], $product);
        if ($pluginBehind['behind'] >= 2) {
            $out[] = ['level' => 'medium', 'text' => Products::get($product)['name'] . " {$r['plugin_version']} is {$pluginBehind['behind']} releases behind {$pluginBehind['latest']}."];
        }
        if ($r['admin_days'] !== null && $r['admin_days'] > self::ADMIN_AWAY_DAYS) {
            $out[] = ['level' => 'low', 'text' => "No admin has logged in for {$r['admin_days']} days, so an email may not be read."];
        }
        return $out;
    }

    /** "Behind" is measured against the newest version we actually see, not an outside list. */
    private static function versionsBehind(string $field, string $version, ?string $product): array
    {
        if ($version === '') {
            return ['behind' => 0, 'latest' => null];
        }
        static $cache = [];
        $key = $field . ($product ?? '');
        if (!isset($cache[$key])) {
            $sql = $field === 'wp_version'
                ? 'SELECT DISTINCT wp_version AS v FROM site_profiles WHERE wp_version IS NOT NULL'
                : 'SELECT DISTINCT plugin_version AS v FROM site_products WHERE plugin_version IS NOT NULL AND product = ?';
            $list = array_column(Db::all($sql, $product ? [$product] : []), 'v');
            usort($list, 'version_compare');
            $cache[$key] = $list;
        }
        $list = $cache[$key];
        $idx = array_search($version, $list, true);
        return ['behind' => $idx === false ? 0 : count($list) - 1 - $idx, 'latest' => end($list) ?: null];
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public static function logOutreach(string $installId, string $product, array $body, string $role): array
    {
        if ($role !== 'support') {
            throw new DomainException('Only the support role can log outreach.', 403);
        }
        $r = current(array_filter(self::rows($product), fn($x) => $x['install_id'] === $installId));
        if (!$r) {
            throw new DomainException('Site not found for this product.', 404);
        }
        if (!$r['contactable']) {
            throw new DomainException("The account holder hasn't agreed to be contacted.", 403);
        }
        if (($r['pilot']['arm'] ?? null) === 'holdout') {
            throw new DomainException("This account is in the pilot holdout. Help them if they reach out, but don't contact them first.", 409);
        }
        $channel = (string) ($body['channel'] ?? '');
        $outcome = (string) ($body['outcome'] ?? '');
        $note = trim((string) ($body['note'] ?? ''));
        if (!isset(self::CHANNELS[$channel]) || !isset(self::OUTCOMES[$outcome])) {
            throw new InvalidArgumentException('Pick a channel and an outcome.');
        }
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Keep the note under 2,000 characters.');
        }
        $at = Time::iso();
        Db::run(
            'INSERT INTO outreach (account_id, install_id, product, stage_at_contact, channel, outcome, note, author, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$r['account_id'], $installId, $product, $r['stage'], $channel, $outcome, $note ?: null, self::actor($role), $at]
        );
        return ['logged' => true, 'at' => $at];
    }

    /** Exports carry IDs and stages, never contact details. */
    public static function exportCsv(string $product, string $view, array $filters, string $role): string
    {
        $list = self::worklist($product, $view, $filters, $role)['rows'];
        self::audit(self::actor($role), 'exported', count($list) . " rows ($product, $view)");
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['account_id', 'site_ref', 'product', 'stage', 'stage_label', 'days_in_stage', 'health', 'priority', 'pilot_arm', 'next_step'], ',', '"', '');
        foreach ($list as $r) {
            fputcsv($fh, [
                $r['account_id'], substr($r['install_id'], 0, 8), $product, $r['stage'], $r['stage_label'], $r['days_in_stage'],
                $r['health'], $r['priority'], $r['pilot']['arm'] ?? '', $r['next_step'],
            ], ',', '"', '');
        }
        rewind($fh);
        return stream_get_contents($fh);
    }

    public static function audit(string $actor, string $action, ?string $target): void
    {
        Db::run('INSERT INTO access_log (actor, action, target, created_at) VALUES (?, ?, ?, ?)', [$actor, $action, $target, Time::iso()]);
    }

    public static function accessLog(): array
    {
        return Db::all('SELECT actor, action, target, created_at FROM access_log ORDER BY id DESC LIMIT 50');
    }

    public static function products(): array
    {
        return array_values(array_map(fn($p) => ['code' => $p['code'], 'name' => $p['name'], 'wave' => $p['wave']], Products::all()));
    }
}
