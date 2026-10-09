<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\sendrepute\Service\SendReputeClient;
use Symfony\Component\HttpFoundation\Request;

if ($argc !== 4) {
  throw new RuntimeException('Usage: verify.php PROJECT EXPECTED_VERSION SINK');
}
[$script, $project, $expected, $sink] = $argv;
chdir($project . '/web');
$loader = require $project . '/vendor/autoload.php';
$request = Request::create('http://127.0.0.1/');
$kernel = DrupalKernel::createFromRequest($request, $loader, 'prod');
$kernel->boot();
$kernel->preHandle($request);
$container = $kernel->getContainer();

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$assert(\Drupal::VERSION === $expected, 'Unexpected exact Drupal version: ' . \Drupal::VERSION);
$assert($container->get('module_handler')->moduleExists('sendrepute'), 'ZIP module is not enabled.');
$definitions = $container->get('plugin.manager.mail')->getDefinitions();
$assert(isset($definitions['sendrepute_mail'], $definitions['php_mail']), 'Real mail plugin discovery failed.');

$moduleConfig = $container->get('config.factory')->get('sendrepute.settings');
$assert($moduleConfig->get('paid_consent') === TRUE, 'Authenticated config save did not persist consent.');
$assert($moduleConfig->get('sender_display_name') === 'Harness Store', 'Tokenless CSRF submission changed config or valid save failed.');
$routes = $moduleConfig->get('routes');
$assert(is_array($routes) && count($routes) === 1 && $routes[0]['module'] === 'sendrepute_harness', 'Selected route did not persist.');
$assert($container->get('config.factory')->get('system.mail')->get('interface.sendrepute_harness_selected') === 'sendrepute_mail', 'Selected route was not opted into the backend.');

putenv('SENDREPUTE_API_TOKEN=synthetic-local-token');
$requests = 0;
$client = new SendReputeClient(static function (string $body, string $token) use (&$requests): array {
  $requests++;
  if ($requests > 3) {
    throw new RuntimeException('Synthetic classifier call budget exceeded.');
  }
  if ($token !== 'synthetic-local-token') {
    throw new RuntimeException('Synthetic classifier received wrong token.');
  }
  return ['status' => 200, 'body' => json_encode([
    'requestId' => 'req_harness_local',
    'model' => 'thor',
    'result' => [
      'label' => 'spam', 'spamProbability' => 0.99, 'confidence' => 'high',
      'reasons' => [], 'flaggedTerms' => [], 'analyzedFields' => ['sender', 'subject', 'body'],
      'modelVersion' => 'synthetic-1', 'analyzedAt' => '2026-01-01T00:00:00Z',
    ],
    'billing' => ['chargedMillicents' => 0, 'replayed' => FALSE],
  ], JSON_THROW_ON_ERROR)];
});
$container->set('sendrepute.client', $client);

$configFactory = $container->get('config.factory');
$mailConfig = $configFactory->getEditable('sendrepute.settings');
$baseRoute = $routes[0];
$criticalRoute = $baseRoute;
$criticalRoute['module'] = 'sendrepute_harness';
$criticalRoute['key'] = 'critical_reset';
$criticalRoute['mode'] = 'block';
$criticalRoute['failure_policy'] = 'block';
$criticalRoute['allow_critical'] = FALSE;
$mailConfig->set('routes', [$baseRoute, $criticalRoute])->save();
$systemMail = $configFactory->getEditable('system.mail');
$interfaces = $systemMail->get('interface');
$interfaces['sendrepute_harness_critical_reset'] = 'sendrepute_mail';
$systemMail->set('interface', $interfaces)->save();

$manager = $container->get('plugin.manager.mail');
$countSink = static fn(): int => is_file($sink) ? substr_count(file_get_contents($sink), '--- sendmail') : 0;
$selected = $manager->mail('sendrepute_harness', 'selected', 'sink@example.invalid', 'en', [
  'subject' => 'Synthetic selected route',
  'body' => 'real MailManager selected body',
]);
$assert($selected['result'] === TRUE && $requests === 1 && $countSink() === 1, 'Selected real MailManager route did not classify and deliver once.');

$unselected = $manager->mail('sendrepute_harness', 'unselected', 'sink@example.invalid', 'en', [
  'body' => 'real MailManager unselected body',
]);
$assert($unselected['result'] === TRUE && $requests === 1 && $countSink() === 2, 'Unselected real MailManager route did not bypass classification and deliver.');

$critical = $manager->mail('sendrepute_harness', 'critical_reset', 'sink@example.invalid', 'en', [
  'subject' => 'Critical reset',
  'body' => 'critical real MailManager body',
]);
$assert($critical['result'] === TRUE && $requests === 2 && $countSink() === 3, 'Critical selected mail was not preserved through real MailManager routing.');

$blocking = $baseRoute;
$blocking['key'] = 'blocked';
$blocking['mode'] = 'block';
$blocking['threshold'] = 0.90;
$mailConfig->set('routes', [$baseRoute, $criticalRoute, $blocking])->save();
$interfaces = $systemMail->get('interface');
$interfaces['sendrepute_harness_blocked'] = 'sendrepute_mail';
$systemMail->set('interface', $interfaces)->save();
$blocked = $manager->mail('sendrepute_harness', 'blocked', 'sink@example.invalid', 'en', [
  'subject' => 'Synthetic blocked route',
  'body' => 'must not leave real MailManager',
]);
$assert($blocked['result'] === FALSE && $requests === 3 && $countSink() === 3, 'Real MailManager failed to cancel a selected high-risk send before transport.');

$mailConfig->set('paid_consent', FALSE)->save();
$consentOff = $manager->mail('sendrepute_harness', 'selected', 'sink@example.invalid', 'en', [
  'body' => 'consent disabled body',
]);
$assert($consentOff['result'] === TRUE && $requests === 3 && $countSink() === 4, 'Disabled consent classified or lost real MailManager mail.');
$assert(str_contains(file_get_contents($sink), 'real MailManager selected body'), 'Local sendmail sink did not capture core php_mail output.');

// Each paid request is single-attempt. A 409 in-progress/429 response is not
// retried by the adapter, including if a caller invokes it again independently.
$attempts = 0;
$limited = new SendReputeClient(static function (string $body, string $token) use (&$attempts): array {
  $attempts++;
  return ['status' => $attempts === 1 ? 409 : 429, 'body' => '{}'];
});
foreach ([409, 429] as $status) {
  try {
    $limited->classify('Harness Store', 'Retry probe', 'safe body');
    throw new RuntimeException("Drupal accepted synthetic HTTP $status as a classification.");
  }
  catch (\Drupal\sendrepute\Exception\ClassificationException $exception) {
    $assert($attempts === ($status === 409 ? 1 : 2), 'Drupal retried a failed paid classification.');
  }
}
fwrite(STDOUT, "Drupal $expected real MailManager routing, high-risk cancellation, restricted permissions, consent, CSRF, synthetic 409/429 single attempts, and local mail sink passed\n");