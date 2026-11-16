<?php

declare(strict_types=1);

use Nolviko\Tools\I18n\CatalogParity;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/src/I18n/CatalogParity.php';

$locale = $argv[1] ?? 'tr';

if (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $locale) !== 1) {
    fwrite(STDERR, "catalog_locale_invalid\n");
    exit(2);
}

$root = dirname(__DIR__) . '/translations';
$reference = [];
$translated = [];

foreach (glob($root . '/en/*.php') ?: [] as $file) {
    $reference = array_replace($reference, require $file);
}

foreach (glob($root . '/' . $locale . '/*.php') ?: [] as $file) {
    $translated = array_replace($translated, require $file);
}

if ($reference === []) {
    fwrite(STDERR, "catalog_reference_missing\n");
    exit(2);
}

$findings = (new CatalogParity())->compare($reference, $translated, $locale);

foreach ($findings as $id => $diagnostic) {
    fwrite(STDERR, $diagnostic . ':' . $id . "\n");
}

echo 'catalog_keys=' . count($reference) . ' findings=' . count($findings) . "\n";
exit($findings === [] ? 0 : 1);
