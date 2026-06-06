<?php

// Strip a leading UTF-8 BOM from all known-affected text files, then verify
// the routing YAML parses with no scalar (junk) route entries.
echo "BOM STRIP START\n";
$base = __DIR__;
$exts = ['yml', 'php', 'module', 'install', 'inc', 'twig', 'css', 'js', 'json', 'md', 'html', 'xml', 'dist'];
$bom = "\xEF\xBB\xBF";

$rii = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
);
$fixed = 0;
foreach ($rii as $file) {
  if ($file->isDir()) {
    continue;
  }
  $path = $file->getPathname();
  if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
    continue;
  }
  if (!in_array(strtolower($file->getExtension()), $exts, true)) {
    continue;
  }
  $raw = file_get_contents($path);
  if (substr($raw, 0, 3) === $bom) {
    file_put_contents($path, substr($raw, 3));
    $rel = str_replace($base . DIRECTORY_SEPARATOR, '', $path);
    echo "STRIPPED: $rel\n";
    $fixed++;
  }
}
echo "BOM STRIP END ($fixed files fixed)\n\n";

// Verify routing files now parse clean.
require 'C:/dev/drupal-code/vendor/composer/autoload_real.php';
ComposerAutoloaderInit83d924f1e38c4d50fac07d7b0732a5eb::getLoader();
$routing = [
  'license_service/license_service.routing.yml',
  'license_service_token_counter/license_service_token_counter.routing.yml',
  'license_service_token_counter/modules/license_service_token_counter_engine/license_service_token_counter_engine.routing.yml',
  'license_service_token_limits/license_service_token_limits.routing.yml',
];
echo "ROUTING VERIFY:\n";
foreach ($routing as $f) {
  $data = \Symfony\Component\Yaml\Yaml::parseFile($base . '/' . $f);
  $bad = 0;
  foreach ($data as $name => $info) {
    if (!is_array($info)) {
      echo "  *** STILL SCALAR: $f [$name]\n";
      $bad++;
    }
  }
  echo "  OK $f (" . count($data) . " routes, $bad scalar)\n";
}
echo "DONE\n";
