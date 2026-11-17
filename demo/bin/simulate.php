<?php
/**
 * Walks one fake site through the Thrive Leads journey, live, using the real
 * WordPress-side client and integration against the running demo server.
 * Open the console, switch on Live, and search for the site name to watch it move.
 *
 *   php bin/simulate.php [--endpoint=http://127.0.0.1:8787] [--delay=5] [--fast] [--keep]
 *
 * --fast  no pauses between steps
 * --keep  stop before the final step, so the site stays in the console
 */

$opts = getopt('', ['endpoint::', 'delay::', 'fast']);
$endpoint = rtrim($opts['endpoint'] ?? 'http://127.0.0.1:8787', '/');
$delay = isset($opts['fast']) ? 0 : (int) ($opts['delay'] ?? 5);

// The account lives in the store, not the plugin. Create it the way the store would.
require dirname(__DIR__) . '/src/bootstrap.php';
$accountId = 'acct_demo00001';
Db::run(
    'INSERT OR REPLACE INTO accounts (account_id, name, email, plan, help_consent, help_consent_at, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)',
    [$accountId, 'Riley Park', 'riley@lumenpottery.example', 'Thrive Suite (annual)', Time::iso(), Time::iso()]
);

require dirname(__DIR__) . '/client/wp-shim.php';

/** Stand-in for Thrive Leads' own tables on this fake site. */
class TL_Success_Data
{
    public static ?int $activated = null;
    public static ?string $firstCampaign = null;
    public static ?string $firstImpression = null;
    public static ?string $secondLead = null;
    public static int $campaigns = 0;
    public static int $impressions = 0;
    public static int $leads = 0;

    public static function activated_at(): ?int { return self::$activated; }
    public static function first_campaign_date(): ?string { return self::$firstCampaign; }
    public static function first_impression_date(): ?string { return self::$firstImpression; }
    public static function second_lead_date(): ?string { return self::$secondLead; }
    public static function counts(): array
    {
        return [
            'campaigns' => self::$campaigns,
            'campaigns_displaying' => self::$campaigns,
            'impressions_7d' => self::$impressions,
            'leads_7d' => self::$leads,
            'email_services_connected' => 1,
        ];
    }
}

$optionsFile = dirname(__DIR__) . '/data/sim-site.json';
@unlink($optionsFile);
WP_Shim::boot([
    'name' => 'Lumen Pottery',
    'url' => 'https://lumenpottery.example',
    'wp_version' => '7.0.2',
    'php_version' => '8.1.31',
    'locale' => 'en_US',
    'multisite' => false,
    'environment' => 'production',
    'theme' => 'Thrive Theme Builder',
    'logged_in_admin' => true,
], $optionsFile);
add_filter('thrive_success_php_version', fn() => WP_Shim::$site['php_version']);
add_filter('thrive_success_db_version', fn() => 'MariaDB 10.11.9');
add_filter('thrive_success_active_plugins', fn() => ['woocommerce/woocommerce.php', 'wp-rocket/wp-rocket.php', 'hello-dolly/hello.php']);

require dirname(__DIR__) . '/client/class-thrive-success-client.php';
Thrive_Success_Client::boot($endpoint);
require dirname(__DIR__) . '/client/integrations/thrive-leads.php';
do_action('init');

// ----------------------------------------------------------------- helpers

function say(string $title, string $detail = ''): void
{
    echo "\n\033[1m$title\033[0m\n";
    if ($detail !== '') {
        echo '  ' . wordwrap($detail, 88, "\n  ") . "\n";
    }
}

function cron(): void
{
    if (Thrive_Success_Client::tier() === 'none') {
        echo "  Nothing sent: tracking is off.\n";
        return;
    }
    $r = Thrive_Success_Client::flush();
    if ($r === null) {
        echo "  Nothing sent: the server is unreachable. The outbox keeps it for the next run.\n";
        return;
    }
    $parts = [];
    foreach (['accepted', 'duplicates'] as $k) {
        if (!empty($r[$k])) {
            $parts[] = "$k: {$r[$k]}";
        }
    }
    if (!empty($r['profile'])) {
        $parts[] = 'profile updated';
    }
    echo '  Sent in the background. Server says: ' . ($parts ? implode(', ', $parts) : 'nothing new') . "\n";
}

function consoleView(string $endpoint): ?array
{
    $id = get_option('thrive_success_state')['install_id'] ?? null;
    if (!$id) {
        return null;
    }
    $raw = @file_get_contents("$endpoint/api/v1/console/sites/$id?product=tl&role=pm");
    return $raw ? json_decode($raw, true) : null;
}

function showStage(string $endpoint): void
{
    $d = consoleView($endpoint);
    if ($d) {
        echo "  Console now shows: stage {$d['stage']} ({$d['stage_label']}), PHP {$d['profile']['php_version']}, WordPress {$d['profile']['wp_version']}\n";
    }
}

function pause(int $delay): void
{
    if ($delay > 0) {
        sleep($delay);
    }
}

// ----------------------------------------------------------------- the journey

$health = @file_get_contents("$endpoint/api/v1/health");
if (!$health) {
    fwrite(STDERR, "Can't reach the demo server at $endpoint. Start it first (see README).\n");
    exit(1);
}

say('Lumen Pottery: a new Thrive Leads customer', "Open $endpoint/console/index.html, switch on Live, pick 'All opted-in sites', sort by 'Recently active' (or search 'Lumen').");
pause($delay);

say('1. Riley activates Thrive Leads', 'Tracking is off until someone consents, so nothing leaves the site yet.');
TL_Success_Data::$activated = time();
do_action('activate_thrive-leads/thrive-leads.php');
cron();
pause($delay);

say("2. During onboarding, Riley connects their Thrive account and says yes to 'Help me succeed'", 'The site registers, gets its own token, and reports the activation as a new, timed one (it happened minutes ago).');
Thrive_Success_Client::set_tier('identified', $accountId);
showStage($endpoint);
pause($delay);

say('3. Riley creates a first campaign', 'Stage 2, Built.');
TL_Success_Data::$campaigns = 1;
TL_Success_Data::$firstCampaign = gmdate('c');
do_action('wp_insert_post', 101, (object) ['post_type' => 'tve_lead_group', 'post_title' => 'Spring workshop popup'], false);
cron();
showStage($endpoint);
pause($delay);

say('4. Riley previews the campaign on the live site while logged in', "The impression hook fires, but it's the owner, so it doesn't count as 'live'.");
do_action('tve_leads_form_impression', null, (object) ['post_title' => 'Popup'], ['key' => 1], 0, []);
cron();
showStage($endpoint);
pause($delay);

say('5. A real visitor sees the campaign', 'Stage 3, Live.');
WP_Shim::$site['logged_in_admin'] = false;
TL_Success_Data::$impressions = 1;
TL_Success_Data::$firstImpression = gmdate('c');
do_action('tve_leads_form_impression', null, (object) ['post_title' => 'Popup'], ['key' => 1], 0, []);
cron();
showStage($endpoint);
pause($delay);

say('6. Riley tests the form logged out, with their own email', "One person isn't enough. Owners test their own forms, so this doesn't count as First value yet.");
do_action('tve_leads_form_conversion', null, null, ['key' => 1], 0, ['email' => 'riley@lumenpottery.example'], []);
cron();
showStage($endpoint);
pause($delay);

say('7. A real visitor signs up', 'Two different people now. Stage 4, First value. Their email stays on the site; only the milestone is sent.');
TL_Success_Data::$leads = 2;
TL_Success_Data::$secondLead = gmdate('c');
do_action('tve_leads_form_conversion', null, null, ['key' => 1], 0, ['email' => 'visitor.one@example.org'], []);
cron();
showStage($endpoint);
pause($delay);

say('8. The site is restored from an old backup, and the milestone is sent again', 'The plugin has no memory of sending it. The server already has it, so nothing double counts.');
$state = get_option('thrive_success_state');
unset($state['sent']['tl']['first_leads']);
update_option('thrive_success_state', $state);
Thrive_Success_Client::track('tl', 'first_leads');
cron();
showStage($endpoint);
pause($delay);

say('9. Riley upgrades PHP from 8.1 to 8.3', 'The daily check on an admin page notices it. The profile is overwritten in place; no history is kept.');
WP_Shim::$site['logged_in_admin'] = true;
WP_Shim::$site['php_version'] = '8.3.27';
Thrive_Success_Client::on_admin_load();
cron();
showStage($endpoint);
pause($delay);

say('10. WordPress updates itself to 7.1', 'The core update hook resends the profile right away.');
WP_Shim::$site['wp_version'] = '7.1';
do_action('_core_updated_successfully');
cron();
showStage($endpoint);
pause($delay);

say('11. The weekly heartbeat goes out', 'Sent every week, always. It carries counts and the milestones reached, so a lost milestone gets recovered.');
Thrive_Success_Client::maybe_heartbeat(true);
cron();
pause($delay);

if (in_array('--keep', $argv, true)) {
    say('Done. Lumen Pottery stays in the console. Run again without --keep to see withdrawal.');
    exit(0);
}

say("12. Riley turns off 'Help me succeed'", "Collection stops and the server deletes this site's identified data. It disappears from the console; only a count of withdrawals remains.");
Thrive_Success_Client::set_tier('none');
echo consoleView($endpoint) === null ? "  Console: site not found (deleted)\n" : "  Console: still there (unexpected)\n";

say('Done.');
