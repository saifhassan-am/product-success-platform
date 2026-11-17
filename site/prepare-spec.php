<?php
/**
 * Adjusts SPEC.md for the web page: the page header shows the title and date
 * from the front matter, the demo link points at the browser copy, and each
 * screenshot becomes its own captioned figure.
 *
 *   php site/prepare-spec.php SPEC.md > spec.md
 */

$md = file_get_contents($argv[1] ?? '') ?: exit("Usage: php site/prepare-spec.php <SPEC.md>\n");

// Front matter: no "related" list (the site nav covers it), a readable date, a description.
$md = preg_replace('/^related:\n(?:  - .*\n)+/m', '', $md, 1);
$md = preg_replace_callback('/^date: (\d{4}-\d{2}-\d{2})$/m', fn($m) => 'date: ' . date('F j, Y', strtotime($m[1])), $md, 1);
$md = preg_replace('/^(title: .*)$/m', "$1\ndescription: \"What the Product Success Platform tracks, how consent works, and how the support console and pilot behave.\"", $md, 1);

// The template prints the title, so drop the duplicate heading.
$md = preg_replace('/^# .*\n\n/m', '', $md, 1);

$md = str_replace('](demo/README.md)', '](../demo/)', $md);

// Consecutive image lines become separate paragraphs, so each gets a caption.
$md = preg_replace('/^(!\[[^\]]*\]\([^)]+\))\n(?=!\[)/m', "$1\n\n", $md);

echo $md;
