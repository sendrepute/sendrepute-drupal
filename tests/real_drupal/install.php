<?php

declare(strict_types=1);

if ($argc !== 4) {
  throw new RuntimeException('Usage: install.php PROJECT DATABASE SOCKET');
}
[$script, $project, $database, $socket] = $argv;
$web = $project . '/web';
chdir($web);
copy('sites/default/default.settings.php', 'sites/default/settings.php');
chmod('sites/default/settings.php', 0666);
mkdir('sites/default/files', 0777, TRUE);

$class_loader = require $project . '/vendor/autoload.php';
require_once 'core/includes/install.core.inc';
$driver = 'Drupal\\mysql\\Driver\\Database\\mysql';
install_drupal($class_loader, [
  'interactive' => FALSE,
  'site_path' => 'sites/default',
  'parameters' => ['profile' => 'minimal', 'langcode' => 'en'],
  'forms' => [
    'install_settings_form' => [
      'driver' => $driver,
      $driver => [
        'database' => $database,
        'username' => 'root',
        'password' => '',
        'host' => 'localhost',
        'port' => '',
        'prefix' => '',
        'isolation_level' => 'READ COMMITTED',
      ],
    ],
    'install_configure_form' => [
      'site_name' => 'SendRepute real harness',
      'site_mail' => 'site@example.invalid',
      'account' => [
        'name' => 'admin',
        'mail' => 'admin@example.invalid',
        'pass' => ['pass1' => 'harness-admin-pass', 'pass2' => 'harness-admin-pass'],
      ],
      'enable_update_status_module' => NULL,
      'enable_update_status_emails' => NULL,
    ],
  ],
]);
chmod('sites/default/settings.php', 0666);
if (file_put_contents('sites/default/settings.php', "\n\$databases['default']['default']['unix_socket'] = " . var_export($socket, TRUE) . ";\n", FILE_APPEND) === FALSE) {
  throw new RuntimeException('Could not persist the owned MariaDB socket.');
}
chmod('sites/default/settings.php', 0444);
fwrite(STDOUT, "Installed Drupal in $project\n");