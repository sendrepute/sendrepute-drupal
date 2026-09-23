<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sendrepute\Exception\ClassificationException;
use Psr\Log\LoggerInterface;

/**
 * Applies scoped policy to a delegate-formatted message.
 */
final class MailPolicy {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SendReputeClient $client,
    private readonly LoggerInterface $logger,
  ) {}

  /** @return array<string, mixed>|null */
  public function routeFor(array $message): ?array {
    $module = $message['module'] ?? NULL;
    $key = $message['key'] ?? NULL;
    if (!is_string($module) || !is_string($key)) {
      return NULL;
    }
    foreach ($this->configFactory->get('sendrepute.settings')->get('routes') ?? [] as $route) {
      if (is_array($route) && ($route['module'] ?? NULL) === $module && ($route['key'] ?? NULL) === $key) {
        return $route;
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE when delivery may proceed.
   */
  public function allow(array $message, array $route): bool {
    $config = $this->configFactory->get('sendrepute.settings');
    if (!$config->get('paid_consent')) {
      return TRUE;
    }
    $protected = $this->isCritical($message)
      && empty($route['allow_critical']);
    try {
      [$subject, $body] = $this->canonicalInput($message);
      $sender = $config->get('sender_display_name');
      if (!is_string($sender)) {
        throw new ClassificationException('input', 'The configured sender display name is invalid.');
      }
      $result = $this->client->classify(trim($sender), $subject, $body);
      $threshold = (float) ($route['threshold'] ?? 0.9);
      $blocked = ($route['mode'] ?? 'advisory') === 'block'
        && !$protected
        && $result['spamProbability'] >= $threshold;
      if ($blocked) {
        $this->logger->warning('SendRepute blocked mail route {module}/{key} at configured threshold.', [
          'module' => (string) ($message['module'] ?? ''),
          'key' => (string) ($message['key'] ?? ''),
        ]);
      }
      return !$blocked;
    }
    catch (ClassificationException $exception) {
      $this->logger->error('SendRepute classification failed for route {module}/{key}: {category}.', [
        'module' => (string) ($message['module'] ?? ''),
        'key' => (string) ($message['key'] ?? ''),
        'category' => $exception->category(),
      ]);
      return $protected || ($route['failure_policy'] ?? 'preserve') !== 'block';
    }
  }

  /**
   * @return array{string, string}
   */
  private function canonicalInput(array $message): array {
    $subject = $message['subject'] ?? '';
    $body = $message['body'] ?? NULL;
    if (!is_string($subject) || $subject === '' || strlen($subject) > 998) {
      throw new ClassificationException('input', 'The final subject is unsupported.');
    }
    if (is_object($body) && $body instanceof \Stringable) {
      $body = (string) $body;
    }
    if (!is_string($body) || strlen($body) > 262144) {
      throw new ClassificationException('input', 'The delegate did not produce one bounded final body.');
    }

    $headers = $message['headers'] ?? [];
    if (!is_array($headers)) {
      throw new ClassificationException('input', 'The final headers are unsupported.');
    }
    foreach ($headers as $name => $value) {
      if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
        throw new ClassificationException('input', 'The final headers are unsupported.');
      }
      $line = strtolower($name . ': ' . (string) $value);
      if (str_contains($line, 'content-disposition: attachment')
        || str_contains($line, 'content-type: multipart/')) {
        throw new ClassificationException('input', 'Multipart or attachment-bearing output is not classified.');
      }
    }

    // A length frame prevents one fragment from changing another's boundary.
    $canonical = "SENDREPUTE-FINAL-BODY/1\nbytes=" . strlen($body) . "\n\n" . $body;
    return [$subject, $canonical];
  }

  private function isCritical(array $message): bool {
    $module = strtolower((string) ($message['module'] ?? ''));
    $key = strtolower((string) ($message['key'] ?? ''));
    if ($module === 'user') {
      return TRUE;
    }
    return (bool) preg_match('/(?:password|reset|account|verify|verification|security|login|one.?time|cancel|confirm|activation)/', $module . ':' . $key);
  }

}
