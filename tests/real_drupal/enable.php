<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

if ($argc !== 2) {
  throw new RuntimeException('Usage: enable.php PROJECT');
}
$project = $argv[1];
chdir($project . '/web');
$loader = require $project . '/vendor/autoload.php';
$request = Request::create('http://127.0.0.1/');
$kernel = DrupalKernel::createFromRequest($request, $loader, 'prod');
$kernel->boot();
$kernel->preHandle($request);
$kernel->getContainer()->get('module_installer')->install(['sendrepute', 'sendrepute_harness'], TRUE);
$kernel->getContainer()->get('cache_tags.invalidator')->invalidateTags(['config:core.extension']);
$user = \Drupal\user\Entity\User::create([
  'name' => 'restricted',
  'mail' => 'restricted@example.invalid',
  'status' => 1,
]);
$user->setPassword('harness-restricted-pass');
$user->save();
fwrite(STDOUT, "Enabled ZIP-installed sendrepute module\n");