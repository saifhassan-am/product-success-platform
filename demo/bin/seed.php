<?php
/**
 * Fills the demo with clearly fake data: people, sites and outcomes are all
 * invented, and every domain is under the reserved .example TLD. Every event goes
 * through the real ingestion and stage engine, so what the console shows is
 * computed the same way production would compute it.
 *
 *   php bin/seed.php [--accounts=420] [--seed=7]
 */

$opts = getopt('', ['accounts::', 'seed::']);
$dbPath = getenv('PSP_DB') ?: dirname(__DIR__) . '/data/demo.sqlite';
foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
require dirname(__DIR__) . '/src/bootstrap.php';
Db::pdo()->exec('PRAGMA synchronous = OFF');

final class Seed
{
    public static int $now;
    private static int $uuidCounter = 0;

    public const FIRST = ['Amara', 'Ben', 'Carmen', 'Dmitri', 'Elena', 'Farah', 'Gabe', 'Hana', 'Ibrahim', 'Jess', 'Kofi', 'Lena', 'Mateo', 'Nadia', 'Owen', 'Priya', 'Quinn', 'Rosa', 'Sven', 'Tara', 'Uma', 'Victor', 'Wren', 'Xavier', 'Yuki', 'Zoe', 'Aiden', 'Bianca', 'Chidi', 'Dana', 'Emeka', 'Freya', 'Gio', 'Holly', 'Ines', 'Jonah', 'Kira', 'Liam', 'Maya', 'Noor', 'Oscar', 'Pia', 'Rafael', 'Sana', 'Theo', 'Valeria', 'Will', 'Yara', 'Zain', 'Ada'];
    public const LAST = ['Okafor', 'Lindqvist', 'Moreau', 'Haddad', 'Novak', 'Castillo', 'Brennan', 'Sato', 'Mensah', 'Kowalski', 'Ferreira', 'Ahmed', 'Rossi', 'Patel', 'Becker', 'Duarte', 'Nguyen', 'OConnell', 'Ivanova', 'Tanaka', 'Silva', 'Fischer', 'Kaur', 'Larsen', 'Mbeki', 'Romero', 'Walsh', 'Yilmaz', 'Abebe', 'Costa', 'Dubois', 'Eriksen', 'Grant', 'Hughes', 'Jensen', 'Klein', 'Lopez', 'Murphy', 'Nakamura', 'Olsen'];
    public const NICHE = ['Yoga Studio', 'Coaching', 'Bakery', 'Fitness', 'Photography', 'Marketing Lab', 'Design Co', 'Academy', 'Law Office', 'Dental Care', 'Realty', 'Travel Notes', 'Pet Care', 'Garden Supply', 'Wellness', 'Money Habits', 'Kitchen', 'Podcast', 'Music School', 'Life Design', 'Running Club', 'Ceramics', 'Language Lab', 'Bike Shop', 'Skin Studio', 'Career Coach', 'Home Repair', 'Nutrition', 'Calligraphy', 'Woodworks'];
    public const ADJ = ['Bright', 'North', 'Maple', 'Quiet', 'Golden', 'Blue', 'Wild', 'Little', 'Urban', 'Clear', 'True', 'Open', 'Brave', 'Simple', 'Second', 'Hidden', 'Rooted', 'Kind'];

    public static function rnd(): float
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }

    public static function chance(float $p): bool
    {
        return self::rnd() < $p;
    }

    public static function pick(array $items)
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** $weights: value => weight */
    public static function weighted(array $weights)
    {
        $r = self::rnd() * array_sum($weights);
        foreach ($weights as $value => $w) {
            if (($r -= $w) <= 0) {
                return $value;
            }
        }
        return array_key_last($weights);
    }

    public static function gauss(): float
    {
        return sqrt(-2 * log(max(1e-12, self::rnd()))) * cos(2 * M_PI * self::rnd());
    }

    public static function lognormalDays(float $median, float $sigma): float
    {
        return exp(log($median) + $sigma * self::gauss());
    }

    public static function uuid(): string
    {
        $h = md5('seed-' . (++self::$uuidCounter) . '-' . mt_rand());
        return sprintf('%s-%s-4%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 16, 4), substr($h, 20, 12));
    }

    public static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '', strtolower($s)), '-');
    }

    public static function siteName(): string
    {
        return self::chance(0.5)
            ? self::pick(self::LAST) . ' ' . self::pick(self::NICHE)
            : self::pick(self::ADJ) . ' ' . self::pick(self::NICHE);
    }
}

Seed::$now = time();
mt_srand((int) ($opts['seed'] ?? 7));
$accountsToMake = (int) ($opts['accounts'] ?? 420);
$day = 86400;
$now = Seed::$now;

// How each product's journey tends to go. p = chance of reaching the next stage,
// median/sigma = days it takes (lognormal). Invented, for the demo.
$journeys = [
    'tl' => ['p' => [1 => 0.74, 2 => 0.72, 3 => 0.52], 'median' => [1 => 1.2, 2 => 2.5, 3 => 6], 'sigma' => 1.0, 'habit' => 0.55],
    'ta' => ['p' => [1 => 0.66, 2 => 0.62, 3 => 0.55], 'median' => [1 => 3, 2 => 7, 3 => 9], 'sigma' => 0.9, 'habit' => 0.5],
    'tqb' => ['p' => [1 => 0.70, 2 => 0.66, 3 => 0.60], 'median' => [1 => 1.5, 2 => 3, 3 => 4], 'sigma' => 0.9, 'habit' => 0.5],
];
$milestoneNames = [];
foreach (Products::all() as $code => $cfg) {
    $milestoneNames[$code] = array_flip(array_diff_key($cfg['milestones'], ['activated' => 1, 'adopted' => 1]));
}
$versions = ['11.0' => 1, '11.1' => 2, '11.1.1' => 2, '11.2' => 5, '11.2.0.1' => 4];
$phpVersions = ['7.4.33' => 1.5, '8.0.30' => 2.5, '8.1.31' => 16, '8.2.29' => 28, '8.3.27' => 33, '8.4.14' => 16, '8.5.0' => 3];
$wpVersions = ['6.7.2' => 5, '6.8.3' => 12, '6.9.1' => 25, '7.0.2' => 33, '7.1' => 25];
$assigned = [];
$pending = [];
$stats = ['accounts' => 0, 'sites' => 0, 'events' => 0, 'assignments' => 0, 'outreach' => 0];

/** Send one event or profile through the real ingestion path, received shortly after it happened. */
function deliver(array $install, int $occurred, ?array $event, ?array $profile = null): void
{
    global $stats;
    $body = [];
    if ($event) {
        $event['event_id'] = Seed::uuid();
        $event['occurred_at'] = Time::iso($occurred);
        $body['events'] = [$event];
        $stats['events']++;
    }
    if ($profile) {
        $body['profile'] = $profile;
    }
    $received = min(Seed::$now, $occurred + mt_rand(30, 1200));
    Ingest::collect($install, $body, $received);
}

function makeProfile(array $site, string $php, string $wp, array $products): array
{
    return [
        'site_name' => $site['name'],
        'site_url' => $site['url'],
        'php_version' => $php,
        'wp_version' => $wp,
        'db_version' => $site['db'],
        'locale' => $site['locale'],
        'multisite' => $site['multisite'],
        'environment' => $site['environment'],
        'theme' => $site['theme'],
        'ttb_active' => $site['theme'] === 'Thrive Theme Builder',
        'thrive_products' => $products,
        'dashboard_version' => '11.2',
        'other_plugins' => $site['plugins'],
        'consent_version' => '2026-11-help-me-succeed-v1',
    ];
}

function metricsFor(string $product, int $stage, array $s, bool $hasValueWeek, int $value): array
{
    switch ($product) {
        case 'tl':
            return [
                'campaigns' => $stage >= 2 ? 1 + ($stage >= 4 ? mt_rand(0, 3) : mt_rand(0, 1)) : 0,
                'campaigns_displaying' => $stage >= 3 ? mt_rand(1, 2) : ($stage === 2 && $s['display_set'] ? 1 : 0),
                'impressions_7d' => $stage >= 3 ? (int) Seed::lognormalDays(280, 1.1) : 0,
                'leads_7d' => $value,
                'email_services_connected' => $s['esp'],
            ];
        case 'ta':
            return ['courses' => $stage >= 2 ? mt_rand(1, 3) : 0, 'published_courses' => $stage >= 3 ? mt_rand(1, 2) : 0, 'enrollments_7d' => $value];
        default:
            return ['quizzes' => $stage >= 2 ? mt_rand(1, 4) : 0, 'quiz_impressions_7d' => $stage >= 3 ? (int) Seed::lognormalDays(150, 1.0) : 0, 'completions_7d' => $value];
    }
}

/** Times (unix) each stage was reached; null if not (yet). */
function journey(array $j, int $start, float $boost = 1.0): array
{
    $t = [1 => $start, 2 => null, 3 => null, 4 => null];
    for ($s = 1; $s <= 3; $s++) {
        if ($t[$s] === null || !Seed::chance(min(0.97, $j['p'][$s] * $boost))) {
            break;
        }
        $next = $t[$s] + (int) (Seed::lognormalDays($j['median'][$s], $j['sigma']) * 86400);
        $t[$s + 1] = $next <= Seed::$now ? $next : null;
    }
    return $t;
}

for ($a = 0; $a < $accountsToMake; $a++) {
    $first = Seed::pick(Seed::FIRST);
    $last = Seed::pick(Seed::LAST);
    $kind = Seed::weighted(['single' => 86, 'multi' => 10, 'agency' => 4]);
    $siteCount = match ($kind) { 'agency' => mt_rand(5, 11), 'multi' => mt_rand(2, 3), default => 1 };
    $consent = Seed::chance($kind === 'agency' ? 0.85 : 0.6);
    $agencyDomain = Seed::slug(Seed::pick(Seed::ADJ) . Seed::pick(['digital', 'studio', 'web', 'growth'])) . '.example';
    $accountId = 'acct_' . substr(md5("acct-$a-" . mt_rand()), 0, 10);

    $sites = [];
    for ($k = 0; $k < $siteCount; $k++) {
        $name = Seed::siteName();
        $domain = Seed::slug($name) . mt_rand(1, 99) . '.example';
        $sites[] = ['name' => $name, 'url' => "https://$domain", 'domain' => $domain, 'environment' => 'production'];
    }
    if ($siteCount >= 2 && Seed::chance(0.3)) {
        $sites[] = ['name' => $sites[0]['name'] . ' (staging)', 'url' => 'https://staging.' . $sites[0]['domain'], 'domain' => 'staging.' . $sites[0]['domain'], 'environment' => 'staging'];
    }

    $emailDomain = $kind === 'agency' ? $agencyDomain : $sites[0]['domain'];
    $accountCreated = $now - mt_rand(20, 700) * $day;
    Db::run(
        'INSERT INTO accounts (account_id, name, email, plan, help_consent, help_consent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $accountId, "$first $last", strtolower($first) . "@$emailDomain",
            Seed::weighted(['Thrive Suite (annual)' => 65, 'Thrive Suite (quarterly)' => 25, 'Thrive Leads (single product)' => 10]),
            $consent ? 1 : 0, $consent ? Time::iso($now - mt_rand(1, 140) * $day) : null, Time::iso($accountCreated),
        ]
    );
    $stats['accounts']++;

    foreach ($sites as $site) {
        $tier = $consent ? 'identified' : (Seed::chance(0.75) ? 'anonymous' : null);
        if ($tier === null) {
            continue; // never opted in: invisible to the platform
        }

        $products = array_keys(array_filter(['tl' => Seed::chance(0.78), 'ta' => Seed::chance(0.3), 'tqb' => Seed::chance(0.25)]));
        if (!$products) {
            $products = ['tl'];
        }
        $existing = Seed::chance(0.4); // an existing customer who opted in after launch
        $consentAt = $existing ? $now - mt_rand(15, 130) * $day : null;

        $site += [
            'db' => Seed::weighted(['MySQL 8.0.39' => 45, 'MySQL 8.4.3' => 15, 'MariaDB 10.6.19' => 15, 'MariaDB 10.11.9' => 20, 'MariaDB 11.4.3' => 5]),
            'locale' => Seed::weighted(['en_US' => 60, 'en_GB' => 10, 'de_DE' => 6, 'fr_FR' => 5, 'es_ES' => 5, 'pt_BR' => 4, 'en_AU' => 4, 'nl_NL' => 3, 'it_IT' => 3]),
            'multisite' => Seed::chance(0.04),
            'theme' => Seed::weighted(['Thrive Theme Builder' => 45, 'Astra' => 15, 'GeneratePress' => 10, 'Kadence' => 10, 'Divi' => 8, 'Twenty Twenty-Five' => 7, 'Hello Elementor' => 5]),
            'plugins' => array_values(array_filter(['woocommerce', 'wp-rocket', 'elementor', 'litespeed-cache', 'wpforms-lite', 'fluent-crm', 'learndash'], fn() => Seed::chance(0.18))),
        ];
        $php = Seed::weighted($phpVersions);
        $wp = Seed::weighted($wpVersions);
        $productVersions = [];
        foreach ($products as $p) {
            $productVersions[$p] = Seed::weighted($versions);
        }

        $installId = Seed::uuid();
        $earliest = null;
        $timelines = [];
        foreach ($products as $p) {
            $j = $journeys[$p];
            if ($existing) {
                $start = $consentAt - mt_rand(60, 900) * $day;
                $t = journey($j, $start, 1.25);
            } else {
                $start = $now - mt_rand(2, 140) * $day - mt_rand(0, $day);
                $t = journey($j, $start);
            }
            $timelines[$p] = $t;
            $earliest = $earliest === null ? $start : min($earliest, $start);
        }
        $registerAt = $existing ? $consentAt : $earliest + mt_rand(300, 6 * 3600);
        $registerAt = min($registerAt, $now - 3600);

        Ingest::register(['install_id' => $installId, 'tier' => $tier, 'account_id' => $tier === 'identified' ? $accountId : null], $registerAt);
        $install = Db::one('SELECT * FROM installs WHERE install_id = ?', [$installId]);
        $stats['sites']++;

        // Profile on registration. 15% upgrade PHP and 30% upgrade WordPress later; the record is overwritten in place.
        deliver($install, $registerAt, null, makeProfile($site, $php, $wp, $productVersions));
        $profileChanges = [];
        if (Seed::chance(0.15)) {
            $newer = array_filter(array_keys($phpVersions), fn($v) => version_compare($v, $php, '>'));
            if ($newer) {
                $profileChanges[] = [$registerAt + mt_rand(5, 120) * $day, 'php', Seed::pick(array_values($newer))];
            }
        }
        if (Seed::chance(0.3)) {
            $newer = array_filter(array_keys($wpVersions), fn($v) => version_compare($v, $wp, '>'));
            if ($newer) {
                $profileChanges[] = [$registerAt + mt_rand(3, 120) * $day, 'wp', Seed::pick(array_values($newer))];
            }
        }

        $isPilotSite = $tier === 'identified' && $site['environment'] === 'production';
        $queue = []; // [time, product, event]

        foreach ($products as $p) {
            $j = $journeys[$p];
            $t = $timelines[$p];
            $s = [
                'esp' => Seed::chance(0.82) ? mt_rand(1, 2) : 0,
                'display_set' => Seed::chance(0.6),
                'drift_at' => Seed::chance(0.25) ? $registerAt + mt_rand(5, 90) * $day : null,
            ];

            // Past pilot: accounts that sat at Live long enough entered the daily list and were randomized.
            if ($p === 'tl' && $isPilotSite && !$existing && $t[3] !== null) {
                $stuckAt = $t[3] + 14 * $day;
                $stillThere = $t[4] === null || $t[4] > $stuckAt;
                if ($stillThere && $stuckAt > $now - 75 * $day && $stuckAt < $now - $day && !isset($assigned[$accountId]) && Seed::chance(0.6)) {
                    $listedAt = min($now - 3600, $stuckAt + mt_rand(0, 4) * $day);
                    $arm = Seed::chance(0.5) ? 'contact' : 'holdout';
                    $assigned[$accountId] = true;
                    Db::run(
                        'INSERT INTO assignments (account_id, product, install_id, from_stage, arm, assigned_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [$accountId, 'tl', $installId, 3, $arm, Time::iso($listedAt)]
                    );
                    $stats['assignments']++;
                    // Invented effect: contacted accounts reach First value a bit more often.
                    $t[4] = null;
                    if (Seed::chance($arm === 'contact' ? 0.27 : 0.15)) {
                        $t[4] = $listedAt + mt_rand(1, 30) * $day + mt_rand(0, $day);
                    } elseif (Seed::chance(0.1)) {
                        $t[4] = $listedAt + mt_rand(31, 60) * $day;
                    }
                    $t[4] = ($t[4] !== null && $t[4] <= $now) ? $t[4] : null;
                    if ($t[4] === null) {
                        $s['esp'] = Seed::chance(0.35) ? 0 : $s['esp'];
                    }
                    if ($arm === 'contact' && Seed::chance(0.85)) {
                        $s['outreach'] = [$listedAt + mt_rand(0, 2 * $day), $t[3]];
                    }
                }
            }

            $habit = $t[4] !== null && Seed::chance($j['habit']);
            $slipFrom = ($t[4] !== null && Seed::chance(0.12)) ? $now - mt_rand(35, 60) * $day : null;
            $maxStage = max(array_keys(array_filter($t)));

            // Endings: people who stall early are the ones who leave.
            $end = $now;
            $deactAt = null;
            $leaveChance = [1 => 0.3, 2 => 0.25, 3 => 0.15, 4 => 0.05][$maxStage];
            if (Seed::chance($leaveChance)) {
                $deactAt = $t[$maxStage] + mt_rand(5, 70) * $day;
                if ($deactAt >= $now || $deactAt <= $registerAt) {
                    $deactAt = null;
                }
            }
            $quietAt = (!$deactAt && Seed::chance(0.05)) ? $now - mt_rand(16, 60) * $day : null;
            if ($deactAt) {
                $end = $deactAt;
            } elseif ($quietAt && $quietAt > $registerAt) {
                $end = $quietAt;
            }

            // Opt-in: existing customers backfill; new ones report their activation (they opted in during onboarding).
            if ($existing) {
                $known = ['activated' => Time::iso($t[1])];
                for ($st = 2; $st <= 4; $st++) {
                    if ($t[$st] !== null && $t[$st] <= $registerAt) {
                        $known[$milestoneNames[$p][$st]] = Time::iso($t[$st]);
                    }
                }
                $queue[] = [$registerAt, $p, ['product' => $p, 'name' => 'backfill', 'properties' => ['milestones' => $known]]];
            } else {
                $queue[] = [$t[1], $p, ['product' => $p, 'name' => 'plugin_activated', 'properties' => ['version' => $productVersions[$p]]]];
            }

            for ($st = 2; $st <= 4; $st++) {
                if ($t[$st] !== null && $t[$st] > $registerAt && $t[$st] < $end && !Seed::chance(0.02)) {
                    $queue[] = [$t[$st], $p, ['product' => $p, 'name' => $milestoneNames[$p][$st]]];
                }
            }

            // Weekly heartbeats from opt-in until the site leaves or goes quiet.
            for ($hb = $registerAt; $hb <= $end; $hb += 7 * $day + mt_rand(-3600, 3600)) {
                $stage = 1;
                for ($st = 2; $st <= 4; $st++) {
                    if ($t[$st] !== null && $t[$st] <= $hb) {
                        $stage = $st;
                    }
                }
                $value = 0;
                if ($stage >= 4 && !($slipFrom && $hb >= $slipFrom)) {
                    $value = $habit ? (Seed::chance(0.92) ? max(1, (int) Seed::lognormalDays(9, 0.9)) : 0) : (Seed::chance(0.3) ? mt_rand(1, 3) : 0);
                }
                $reached = [];
                for ($st = 2; $st <= 4; $st++) {
                    if ($t[$st] !== null && $t[$st] <= $hb) {
                        $reached[$milestoneNames[$p][$st]] = Time::iso($t[$st]);
                    }
                }
                $adminSeen = ($s['drift_at'] && $hb > $s['drift_at']) ? $s['drift_at'] : $hb - mt_rand(0, 5 * $day);
                $queue[] = [$hb, $p, ['product' => $p, 'name' => 'heartbeat', 'properties' => [
                    'week' => Time::week($hb),
                    'value' => $value,
                    'metrics' => metricsFor($p, $stage, $s, $value > 0, $value),
                    'milestones' => $reached,
                    'last_admin_seen_at' => Time::iso(max($registerAt, $adminSeen)),
                ]]];
            }

            if ($deactAt) {
                $reason = Seed::weighted(["I couldn't get the plugin to work" => 3, "It's a temporary deactivation" => 3, 'I no longer need the plugin' => 2, 'I found a better plugin' => 1]);
                $queue[] = [$deactAt, $p, ['product' => $p, 'name' => 'plugin_deactivated', 'properties' => ['reason' => $reason]]];
                if (Seed::chance(0.4) && $deactAt + 3 * $day < $now) {
                    $queue[] = [$deactAt + mt_rand(1, 20) * $day, $p, ['product' => $p, 'name' => 'plugin_uninstalled']];
                }
            }

            if (!empty($s['outreach']) && $tier === 'identified') {
                $stats['outreach']++;
                $pending[] = [$installId, $accountId, $p, $s['outreach'][0]];
            }

            // Occasional help outside the pilot, earlier in the journey.
            if ($tier === 'identified' && $maxStage <= 2 && Seed::chance(0.08)) {
                $pending[] = [$installId, $accountId, $p, min($now - 3600, $t[1] + mt_rand(4, 20) * $day)];
                $stats['outreach']++;
            }
        }

        foreach ($profileChanges as [$at, $field, $value]) {
            if ($at < $now) {
                $queue[] = [$at, null, ['profile_change' => [$field, $value]]];
            }
        }

        usort($queue, fn($x, $y) => $x[0] <=> $y[0]);
        foreach ($queue as [$at, $p, $event]) {
            if ($at > $now) {
                continue;
            }
            if (isset($event['profile_change'])) {
                [$field, $value] = $event['profile_change'];
                if ($field === 'php') {
                    $php = $value;
                } else {
                    $wp = $value;
                }
                deliver($install, $at, null, makeProfile($site, $php, $wp, $productVersions));
                continue;
            }
            deliver($install, $at, $event);
        }
    }
}

// Outreach log entries, written after the events so their stages are known.
$authors = ['Maya (Support)', 'Jordan (Support)'];
foreach ($pending as [$installId, $accountId, $p, $at]) {
    if ($at >= $now || !Db::one('SELECT 1 FROM installs WHERE install_id = ?', [$installId])) {
        continue;
    }
    $stage = (int) (Db::one(
        'SELECT MAX(stage) AS s FROM milestones WHERE install_id = ? AND product = ? AND occurred_at <= ?',
        [$installId, $p, Time::iso($at)]
    )['s'] ?? 1);
    Db::run(
        'INSERT INTO outreach (account_id, install_id, product, stage_at_contact, channel, outcome, note, author, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $accountId, $installId, $p, max(1, $stage),
            Seed::weighted(['email' => 80, 'chat' => 10, 'call' => 10]),
            Seed::weighted(['sent' => 50, 'replied' => 25, 'call_booked' => 8, 'no_response' => 12, 'not_interested' => 5]),
            Seed::pick([null, null, 'Sent the display settings checklist', 'Suggested a shorter form', 'Offered a quick call', 'Shared the template guide']),
            Seed::pick($authors), Time::iso($at),
        ]
    );
}

for ($i = 0; $i < 6; $i++) {
    Db::run('INSERT INTO withdrawals (tier, created_at) VALUES (?, ?)', ['identified', Time::iso($now - mt_rand(1, 90) * $day)]);
}
// A few earlier looks at sites support contacted, so the access log isn't empty.
$viewed = Db::all('SELECT o.install_id, o.product FROM outreach o JOIN installs i ON i.install_id = o.install_id ORDER BY o.created_at DESC LIMIT 3');
foreach ([['Jordan (Support)', 'viewed_site'], ['Maya (Support)', 'viewed_site'], ['Maya (Support)', 'exported'], ['Jordan (Support)', 'viewed_site']] as $k => [$actor, $action]) {
    $site = $viewed[$k % max(1, count($viewed))] ?? null;
    $target = $action === 'exported' ? '38 rows (tl, stuck)' : ($site ? "{$site['install_id']} ({$site['product']})" : 'a seeded site');
    Db::run('INSERT INTO access_log (actor, action, target, created_at) VALUES (?, ?, ?, ?)', [$actor, $action, $target, Time::iso($now - (6 - $k) * $day)]);
}

printf(
    "Seeded %d accounts, %d sites, %d events, %d past pilot assignments, %d outreach entries.\nDatabase: %s\n",
    $stats['accounts'], $stats['sites'], $stats['events'], $stats['assignments'], $stats['outreach'], Db::path()
);
