<?php
/**
 * Writes the seeded demo database out as JSON, so the console can run in a
 * browser with no PHP behind it (the GitHub Pages copy).
 *
 *   php bin/export-static.php <output-dir>
 *
 * Run it right after bin/seed.php. Everything the static console shows is
 * computed here by the same Console code the PHP server uses; the browser only
 * filters, groups, sorts and masks, the way the server would.
 */

$out = $argv[1] ?? null;
if (!$out) {
    fwrite(STDERR, "Usage: php bin/export-static.php <output-dir>\n");
    exit(1);
}
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "Can't create $out\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

Time::freeze(time());
$products = array_column(Console::products(), 'code');

// Load today's list once per product, the way the console does on open. That's
// what randomizes accounts into the pilot, so it has to happen before anything
// else is read.
foreach ($products as $p) {
    Console::worklist($p, 'today', [], 'support');
}
(new ReflectionProperty(Console::class, 'rowsCache'))->setValue(null, []);

// Taken before the site details below, which would otherwise log a "viewed"
// entry for every site.
$accessLog = Console::accessLog();

$listRow = new ReflectionMethod(Console::class, 'listRow');
$write = function (string $file, $data) use ($out): void {
    file_put_contents("$out/$file", json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
};

$counts = [];
foreach ($products as $p) {
    $rows = [];
    $sites = [];
    foreach (Console::rows($p) as $r) {
        $row = $listRow->invoke(null, $r, $p, 'support');
        unset($row['other_stuck_sites']); // depends on the view, so the browser works it out
        $row['contactable'] = $r['contactable'];
        $row['eligible'] = $r['eligible'];
        $row['admin_days'] = $r['admin_days'];
        $row['pilot_contacted'] = $r['pilot'] && $r['pilot']['arm'] === 'contact'
            && Db::one('SELECT 1 FROM outreach WHERE install_id = ? AND product = ? AND created_at >= ? LIMIT 1', [$r['install_id'], $p, $r['pilot']['assigned_at']]) !== null;
        $rows[] = $row;
        $site = Console::site($r['install_id'], $p, 'support');
        // The Product role gets the same message without the person's name and site.
        $site['next_step']['pm_message'] = Console::site($r['install_id'], $p, 'pm')['next_step']['message'];
        $sites[$r['install_id']] = $site;
    }
    $write("$p.json", ['summary' => Console::summary($p), 'rows' => $rows]);
    $write("$p-sites.json", $sites);
    $counts[] = count($rows) . " $p";
}

$write('meta.json', [
    'generated_at' => Time::iso(),
    'products' => Console::products(),
    'actors' => ['support' => Console::actor('support'), 'pm' => Console::actor('pm')],
    'access_log' => $accessLog,
]);

echo 'Exported ' . implode(', ', $counts) . " sites to $out\n";
