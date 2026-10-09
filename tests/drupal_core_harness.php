<?php

declare(strict_types=1);

/**
 * Source harness for an immutable official Drupal core checkout.
 *
 * DRUPAL_CORE_PATH points to the core directory (for example /tmp/.../core).
 * The harness loads the real MailInterface and inspects the real PhpMail and
 * MailManager source without installing Drupal or invoking mail().
 */

$core = getenv('DRUPAL_CORE_PATH');
if (!is_string($core) || $core === '') {
  throw new RuntimeException('Set DRUPAL_CORE_PATH to an official Drupal core directory.');
}
$interfaceFile = $core . '/lib/Drupal/Core/Mail/MailInterface.php';
$phpMailFile = $core . '/lib/Drupal/Core/Mail/Plugin/Mail/PhpMail.php';
$managerFile = $core . '/lib/Drupal/Core/Mail/MailManager.php';
foreach ([$interfaceFile, $phpMailFile, $managerFile] as $file) {
  if (!is_file($file)) {
    throw new RuntimeException("Missing Drupal core mail source: $file");
  }
}

require_once $interfaceFile;
$mailInterface = new ReflectionClass(\Drupal\Core\Mail\MailInterface::class);
foreach (['format', 'mail'] as $method) {
  if (!$mailInterface->hasMethod($method)) {
    throw new RuntimeException("Real Drupal MailInterface is missing $method().");
  }
  $parameters = $mailInterface->getMethod($method)->getParameters();
  if (count($parameters) !== 1 || (string) $parameters[0]->getType() !== 'array') {
    throw new RuntimeException("Real Drupal MailInterface::$method signature changed.");
  }
}

$phpMail = file_get_contents($phpMailFile);
$manager = file_get_contents($managerFile);
if (!is_string($phpMail) || !is_string($manager)) {
  throw new RuntimeException('Could not read real Drupal core mail sources.');
}
foreach ([
  "class PhpMail implements MailInterface",
  "id: 'php_mail'",
  "public function format(array \$message)",
  "\$message['body'] = implode(\"\\n\\n\", \$message['body']);",
  "public function mail(array \$message)",
] as $required) {
  if (!str_contains($phpMail, $required)) {
    throw new RuntimeException("Real Drupal PhpMail contract changed: $required");
  }
}

$alter = strpos($manager, "alter('mail', \$message)");
$format = strpos($manager, '->format($message)', $alter === FALSE ? 0 : $alter);
$mail = strpos($manager, '->mail($message)', $format === FALSE ? 0 : $format);
if ($alter === FALSE || $format === FALSE || $mail === FALSE || !($alter < $format && $format < $mail)) {
  throw new RuntimeException('Real Drupal MailManager no longer orders mail alter, backend format, then backend mail as required.');
}

fwrite(STDOUT, "Real Drupal core mail source harness passed: $core\n");
