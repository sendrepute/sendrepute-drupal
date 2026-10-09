<?php

declare(strict_types=1);

namespace Drupal\Core\Config {
  interface ConfigFactoryInterface {
    public function get($name);
  }
}

namespace Drupal\Component\Plugin {
  interface PluginManagerInterface {
    public function createInstance($plugin_id, array $configuration = []);
  }
}

namespace Drupal\Core\Mail {
  interface MailInterface {
    public function format(array $message);
    public function mail(array $message);
  }
}

namespace Drupal\Core\Plugin {
  interface ContainerFactoryPluginInterface {}
  class PluginBase {
    public function __construct(array $configuration, $plugin_id, $plugin_definition) {}
  }
}

namespace Symfony\Component\DependencyInjection {
  interface ContainerInterface {
    public function get(string $id);
  }
}

namespace Psr\Log {
  interface LoggerInterface {
    public function emergency($message, array $context = []);
    public function alert($message, array $context = []);
    public function critical($message, array $context = []);
    public function error($message, array $context = []);
    public function warning($message, array $context = []);
    public function notice($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
    public function log($level, $message, array $context = []);
  }
}

namespace {
  require __DIR__ . '/../sendrepute/src/Exception/ClassificationException.php';
  require __DIR__ . '/../sendrepute/src/Service/SendReputeClient.php';
  require __DIR__ . '/../sendrepute/src/Service/MailPolicy.php';
  require __DIR__ . '/../sendrepute/src/Plugin/Mail/SendReputeMail.php';

  use Drupal\Component\Plugin\PluginManagerInterface;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Mail\MailInterface;
  use Drupal\sendrepute\Exception\ClassificationException;
  use Drupal\sendrepute\Plugin\Mail\SendReputeMail;
  use Drupal\sendrepute\Service\MailPolicy;
  use Drupal\sendrepute\Service\SendReputeClient;
  use Psr\Log\LoggerInterface;

  final class FixtureConfig {
    public function __construct(private array $values) {}
    public function get(string $name): mixed {
      return $this->values[$name] ?? NULL;
    }
  }

  final class FixtureFactory implements ConfigFactoryInterface {
    public function __construct(public array $values) {}
    public function get($name): FixtureConfig {
      return new FixtureConfig($this->values);
    }
  }

  final class FixtureLogger implements LoggerInterface {
    public array $entries = [];
    public function emergency($m, array $c = []) {$this->log('emergency', $m, $c);}
    public function alert($m, array $c = []) {$this->log('alert', $m, $c);}
    public function critical($m, array $c = []) {$this->log('critical', $m, $c);}
    public function error($m, array $c = []) {$this->log('error', $m, $c);}
    public function warning($m, array $c = []) {$this->log('warning', $m, $c);}
    public function notice($m, array $c = []) {$this->log('notice', $m, $c);}
    public function info($m, array $c = []) {$this->log('info', $m, $c);}
    public function debug($m, array $c = []) {$this->log('debug', $m, $c);}
    public function log($level, $message, array $context = []) {$this->entries[] = [$level, $message, $context];}
  }

  final class FixtureDelegate implements MailInterface {
    public int $deliveries = 0;
    public function format(array $message): array {
      $message['body'] = implode("\n\n", $message['body']);
      return $message;
    }
    public function mail(array $message): bool {
      $this->deliveries++;
      return TRUE;
    }
  }

  final class FixturePluginManager implements PluginManagerInterface {
    public int $creations = 0;
    public function __construct(public FixtureDelegate $delegate) {}
    public function createInstance($plugin_id, array $configuration = []): MailInterface {
      check($plugin_id === 'php_mail', 'Unexpected delegate plugin.');
      $this->creations++;
      return $this->delegate;
    }
  }

  function check(bool $condition, string $message): void {
    if (!$condition) {
      throw new \RuntimeException($message);
    }
  }

  function validResponse(float $probability = 0.95): string {
    return json_encode([
      'requestId' => 'req_offline_00000001',
      'model' => 'thor',
      'result' => [
        'label' => $probability >= 0.5 ? 'spam' : 'inbox',
        'spamProbability' => $probability,
        'confidence' => 'high',
        'reasons' => [['signal' => 'offline', 'detail' => 'fixture', 'weight' => 1.0]],
        'flaggedTerms' => [],
        'analyzedFields' => ['sender', 'subject', 'body'],
        'modelVersion' => 'fixture-1',
        'analyzedAt' => '2026-01-01T00:00:00Z',
      ],
      'billing' => ['chargedMillicents' => 1000, 'replayed' => FALSE],
    ], JSON_THROW_ON_ERROR);
  }

  putenv('SENDREPUTE_API_TOKEN=offline-token');
  $requests = [];
  $client = new SendReputeClient(function (string $body, string $token) use (&$requests): array {
    $requests[] = json_decode($body, TRUE, 64, JSON_THROW_ON_ERROR);
    check($token === 'offline-token', 'Token was not passed only to transport.');
    return ['status' => 200, 'body' => validResponse()];
  });
  $route = [
    'module' => 'contact', 'key' => 'page_mail', 'delegate' => 'php_mail',
    'mode' => 'block', 'failure_policy' => 'preserve', 'threshold' => 0.9,
    'allow_critical' => FALSE,
  ];
  $factory = new FixtureFactory([
    'paid_consent' => FALSE,
    'sender_display_name' => 'Example Store',
    'routes' => [$route],
  ]);
  $logger = new FixtureLogger();
  $policy = new MailPolicy($factory, $client, $logger);
  $message = [
    'module' => 'contact', 'key' => 'page_mail', 'subject' => 'Hello',
    'body' => "plain final body", 'headers' => ['Content-Type' => 'text/plain'],
    'to' => 'recipient@example.invalid', 'params' => ['attachments' => ['secret']],
  ];
  check($policy->allow($message, $route), 'Consent-off mail must be preserved.');
  check(count($requests) === 0, 'Consent-off policy made a paid request.');

  $factory->values['paid_consent'] = TRUE;
  check(!$policy->allow($message, $route), 'Above-threshold block mode did not block.');
  check(count($requests) === 1, 'Expected exactly one classification.');
  check(array_keys($requests[0]) === ['sender', 'subject', 'body'], 'Payload does not match the exact /v1/classify API contract.');
  check($requests[0]['sender'] === 'Example Store', 'Configured display name was not sent.');
  check(!str_contains(json_encode($requests[0]), 'recipient@example'), 'Recipient leaked.');
  check(!str_contains(json_encode($requests[0]), 'secret'), 'Attachment leaked.');
  check(str_contains($requests[0]['body'], 'bytes=16'), 'Canonical body was not length framed.');

  $delegate = new FixtureDelegate();
  $pluginManager = new FixturePluginManager($delegate);
  $plugin = new SendReputeMail([], 'sendrepute_mail', [], $pluginManager, $policy);
  $unformatted = $message;
  $unformatted['body'] = ['plain', 'HTML converted by delegate'];
  $formatted = $plugin->format($unformatted);
  check(is_string($formatted['body']) && str_contains($formatted['body'], 'HTML converted'), 'Delegate did not format before policy.');
  check(!$plugin->mail($formatted), 'Backend plugin did not expose blocking outcome.');
  check($delegate->deliveries === 0, 'Blocked mail reached the delegate.');
  $route['mode'] = 'advisory';
  $factory->values['routes'] = [$route];
  check($plugin->mail($formatted), 'Advisory backend did not preserve delivery.');
  check($delegate->deliveries === 1, 'Advisory backend did not invoke delegate once.');
  check($pluginManager->creations === 1, 'Format and mail did not share one delegate instance.');
  $factory->values['routes'] = [];
  check($plugin->mail($formatted), 'A removed route was not safely delegated.');
  check($delegate->deliveries === 2, 'A removed route caused mail loss.');
  $route['mode'] = 'block';
  $factory->values['routes'] = [$route];

  $critical = $message;
  $critical['module'] = 'user';
  $critical['key'] = 'password_reset';
  check($policy->allow($critical, $route), 'Critical mail was blocked without deliberate selection.');
  $route['allow_critical'] = TRUE;
  check(!$policy->allow($critical, $route), 'Deliberately selected critical enforcement did not block.');

  $errors = [
    401 => 'auth',
    302 => 'redirect',
    429 => 'rate_limit',
  ];
  foreach ($errors as $status => $category) {
    $failing = new SendReputeClient(fn(): array => ['status' => $status, 'body' => '{}']);
    try {
      $failing->classify('Example Store', 'subject', 'body');
      throw new \RuntimeException("Status $status was accepted.");
    }
    catch (ClassificationException $exception) {
      check($exception->category() === $category, "Status $status had the wrong category.");
    }
  }

  $malformedBodies = [
    '{',
    json_encode(['spamProbability' => 0.9]),
    substr(validResponse(), 0, -1) . ',"unexpected":true}',
    str_replace('0.95', '1.5', validResponse()),
  ];
  foreach ($malformedBodies as $raw) {
    $bad = new SendReputeClient(fn(): array => ['status' => 200, 'body' => $raw]);
    try {
      $bad->classify('Example Store', 'subject', 'body');
      throw new \RuntimeException('Malformed response was accepted.');
    }
    catch (ClassificationException $exception) {
      check($exception->category() === 'response', 'Malformed response had wrong category.');
    }
  }

  $outage = new SendReputeClient(function (): array {
    throw new \RuntimeException('contains secret and payload');
  });
  $outagePolicy = new MailPolicy($factory, $outage, $logger);
  $route['allow_critical'] = FALSE;
  $route['failure_policy'] = 'preserve';
  check($outagePolicy->allow($message, $route), 'Preserve failure policy blocked.');
  $route['failure_policy'] = 'block';
  check(!$outagePolicy->allow($message, $route), 'Block failure policy preserved noncritical mail.');

  $multipart = $message;
  $multipart['headers']['Content-Type'] = 'multipart/alternative; boundary=x';
  check(!$outagePolicy->allow($multipart, $route), 'Unsupported multipart bypassed fail-block.');
  check($policy->allow(array_merge($critical, ['body' => str_repeat('x', 262145)]), $route), 'Critical oversized mail was not preserved.');

  try {
    $client->classify('Example Store', str_repeat('x', 999), 'body');
    throw new \RuntimeException('Oversized subject was accepted.');
  }
  catch (ClassificationException $exception) {
    check($exception->category() === 'input', 'Oversized input had wrong category.');
  }

  foreach (['', 'sender@example.invalid', "Display\nBcc: victim"] as $invalidSender) {
    try {
      $client->classify($invalidSender, 'subject', 'body');
      throw new \RuntimeException('Invalid sender display name was accepted.');
    }
    catch (ClassificationException $exception) {
      check($exception->category() === 'input', 'Invalid sender had wrong category.');
    }
  }

  $pluginSource = file_get_contents(__DIR__ . '/../sendrepute/src/Plugin/Mail/SendReputeMail.php');
  check(!str_contains($pluginSource, 'hook_mail_alter'), 'Module falsely implements mail alter enforcement.');
  check(strpos($pluginSource, '->format($message)') < strpos($pluginSource, 'public function mail'), 'Delegate formatting is not before mail policy.');
  check(strpos($pluginSource, '$this->policy->allow') < strrpos($pluginSource, '->mail($message)'), 'Policy is not before delegate send.');

  foreach ($logger->entries as [, , $context]) {
    $serialized = json_encode($context);
    check(!str_contains($serialized, 'recipient@example') && !str_contains($serialized, 'plain final body'), 'Log leaked mail data.');
  }
  echo "PHP fixtures passed\n";
}
