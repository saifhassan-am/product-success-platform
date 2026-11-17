<?php
/**
 * Turns the demo server's console page into the browser-only copy for GitHub
 * Pages: loads static-api.js, drops the Live switch (nothing to refresh without
 * a server) and explains what's different.
 *
 *   php site/demo-index.php demo/public/console/index.html > docs/demo/index.html
 */

$html = file_get_contents($argv[1] ?? '') ?: exit("Usage: php site/demo-index.php <console index.html>\n");

$replace = function (string $pattern, string $with) use (&$html): void {
    $html = preg_replace($pattern, $with, $html, -1, $count);
    if ($count !== 1) {
        fwrite(STDERR, "demo-index.php: expected one match for $pattern, found $count. Did the console markup change?\n");
        exit(1);
    }
};

$head = implode("\n  ", [
    '<meta name="robots" content="noindex, nofollow">',
    '<meta name="description" content="A working model of the Product Success Platform support console, with invented data.">',
    '<link rel="icon" href="../favicon.svg" type="image/svg+xml">',
]);
$replace('#(<meta name="viewport"[^>]*>)#', "$1\n  $head");
$replace('#\s*<label class="switch".*?</label>#s', '');
$replace('#(<main>)#', <<<'HTML'
$1
    <p class="snapshot-note" role="note">
      <span><strong>Browser-only copy</strong> with demo data from <span id="snapshot-date">the last build</span>. Contacts you log stay in this tab until you reload.</span>
      <span class="links">
        <a href="../spec/">Read the spec</a>
        <a href="https://github.com/saifhassan-am/product-success-platform/tree/main/demo#run-it">Run it locally for Live mode</a>
      </span>
    </p>
HTML);
$replace('#(\s*)(<script src="app.js"></script>)#', '$1<script src="static-api.js"></script>$1$2');

echo $html;
