<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Service;

use Drupal\sendrepute\Exception\ClassificationException;

/**
 * Bounded, single-attempt client for the paid classification endpoint.
 */
final class SendReputeClient {

  public const API_URL = 'https://www.sendrepute.com/api/v1/classify';
  public const MAX_RESPONSE_BYTES = 1048576;

  /** @var null|callable(string, string): array{status: int, body: string} */
  private $transport;

  /**
   * @param null|callable(string, string): array{status: int, body: string} $transport
   *   An offline-test transport. Production uses the private cURL transport.
   */
  public function __construct(?callable $transport = NULL) {
    $this->transport = $transport;
  }

  /**
   * @return array{spamProbability: float, label: string, confidence: string}
   */
  public function classify(string $sender, string $subject, string $canonicalBody): array {
    $token = getenv('SENDREPUTE_API_TOKEN');
    if (!is_string($token) || trim($token) === '' || strlen($token) > 4096 || preg_match('/[\r\n]/', $token)) {
      throw new ClassificationException('auth', 'The server-side SendRepute token is missing or invalid.');
    }
    if (!$this->validSender($sender)
      || $subject === '' || strlen($subject) > 998
      || $canonicalBody === '' || strlen($canonicalBody) > 524288) {
      throw new ClassificationException('input', 'The final message exceeds the supported analysis limit.');
    }

    $payload = json_encode([
      'sender' => $sender,
      'subject' => $subject,
      'body' => $canonicalBody,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
      throw new ClassificationException('input', 'The final message cannot be safely encoded.');
    }

    try {
      $response = $this->transport !== NULL
        ? ($this->transport)($payload, trim($token))
        : self::curlTransport($payload, trim($token));
    }
    catch (ClassificationException $exception) {
      throw $exception;
    }
    catch (\Throwable) {
      throw new ClassificationException('transport', 'SendRepute could not be reached securely.');
    }

    $status = $response['status'] ?? NULL;
    $raw = $response['body'] ?? NULL;
    if (!is_int($status) || !is_string($raw) || strlen($raw) > self::MAX_RESPONSE_BYTES) {
      throw new ClassificationException('response', 'SendRepute returned an invalid or oversized response.');
    }
    if ($status < 200 || $status >= 300) {
      throw match ($status) {
        401, 403 => new ClassificationException('auth', 'SendRepute authentication or classification permission failed.'),
        402 => new ClassificationException('balance', 'The SendRepute balance or spend limit is insufficient.'),
        429 => new ClassificationException('rate_limit', 'The SendRepute rate limit was reached; no retry was attempted.'),
        300, 301, 302, 303, 305, 307, 308 => new ClassificationException('redirect', 'SendRepute returned a redirect, which was refused.'),
        default => new ClassificationException('transport', 'SendRepute did not complete the analysis; no retry was attempted.'),
      };
    }

    $decoded = json_decode($raw, TRUE, 64);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
      throw new ClassificationException('response', 'SendRepute returned malformed JSON.');
    }
    $this->validateEntireResponse($decoded);

    return [
      'spamProbability' => (float) $decoded['result']['spamProbability'],
      'label' => $decoded['result']['label'],
      'confidence' => $decoded['result']['confidence'],
    ];
  }

  private function validSender(string $sender): bool {
    return $sender !== ''
      && strlen($sender) <= 320
      && preg_match('//u', $sender) === 1
      && preg_match('/[\x00-\x1F\x7F@<>]/u', $sender) !== 1;
  }

  /**
   * Validates every response branch before policy can use any result.
   *
   * @param array<string, mixed> $value
   */
  private function validateEntireResponse(array $value): void {
    if (!$this->exactAllowedKeys($value, ['requestId', 'model', 'result', 'billing'])
      || !is_string($value['requestId'] ?? NULL)
      || $value['requestId'] === ''
      || strlen($value['requestId']) > 128
      || !in_array($value['model'] ?? NULL, ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'], TRUE)
      || !is_array($value['result'] ?? NULL)
      || !is_array($value['billing'] ?? NULL)) {
      throw new ClassificationException('response', 'SendRepute returned an invalid classification result.');
    }

    $result = $value['result'];
    $billing = $value['billing'];
    $required = ['label', 'spamProbability', 'confidence', 'reasons', 'flaggedTerms', 'analyzedFields', 'modelVersion', 'analyzedAt'];
    $allowed = array_merge($required, ['flaggedTermCount', 'contentAudit']);
    $probability = $result['spamProbability'] ?? NULL;
    if (!$this->exactAllowedKeys($result, $allowed)
      || array_diff($required, array_keys($result))
      || !in_array($result['label'] ?? NULL, ['inbox', 'spam'], TRUE)
      || (!is_int($probability) && !is_float($probability))
      || !is_finite((float) $probability)
      || (float) $probability < 0.0 || (float) $probability > 1.0
      || !in_array($result['confidence'] ?? NULL, ['low', 'medium', 'high'], TRUE)
      || !$this->validReasons($result['reasons'] ?? NULL)
      || !$this->stringList($result['flaggedTerms'] ?? NULL, 1000)
      || !$this->stringList($result['analyzedFields'] ?? NULL, 20)
      || !is_string($result['modelVersion'] ?? NULL) || $result['modelVersion'] === ''
      || strlen($result['modelVersion']) > 128
      || !is_string($result['analyzedAt'] ?? NULL)
      || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $result['analyzedAt'])
      || (isset($result['flaggedTermCount']) && (!is_int($result['flaggedTermCount']) || $result['flaggedTermCount'] < 0))
      || (isset($result['contentAudit']) && !$this->validAudit($result['contentAudit']))
      || !$this->exactAllowedKeys($billing, ['chargedMillicents', 'replayed'])
      || !is_int($billing['chargedMillicents'] ?? NULL) || $billing['chargedMillicents'] < 0
      || !is_bool($billing['replayed'] ?? NULL)) {
      throw new ClassificationException('response', 'SendRepute returned an invalid classification result.');
    }
  }

  /** @param array<string, mixed> $value */
  private function exactAllowedKeys(array $value, array $allowed): bool {
    return array_diff(array_keys($value), $allowed) === [];
  }

  private function stringList(mixed $value, int $maximum): bool {
    if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!is_string($item) || strlen($item) > 4096) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function validReasons(mixed $value): bool {
    if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
      return FALSE;
    }
    foreach ($value as $reason) {
      if (!is_array($reason) || !$this->exactAllowedKeys($reason, ['signal', 'detail', 'weight'])
        || count($reason) !== 3
        || !is_string($reason['signal'] ?? NULL) || strlen($reason['signal']) > 256
        || !is_string($reason['detail'] ?? NULL) || strlen($reason['detail']) > 4096
        || (!is_int($reason['weight'] ?? NULL) && !is_float($reason['weight'] ?? NULL))
        || !is_finite((float) $reason['weight'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function validAudit(mixed $value): bool {
    if (!is_array($value)) {
      return FALSE;
    }
    $required = ['score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount', 'warningCount', 'suggestionCount', 'issues', 'goodPractices', 'inputTruncated'];
    if (!$this->exactAllowedKeys($value, array_merge($required, ['homoglyphTerms']))
      || array_diff($required, array_keys($value))
      || !is_int($value['score']) || $value['score'] < 0 || $value['score'] > 100
      || !in_array($value['grade'], ['A', 'B', 'C', 'D', 'F'], TRUE)
      || !in_array($value['summary'], ['fix_critical', 'fix_warnings', 'review_suggestions', 'looks_good'], TRUE)
      || !is_array($value['counts']) || !$this->exactAllowedKeys($value['counts'], ['words', 'links', 'images', 'triggerPhrases'])
      || count($value['counts']) !== 4 || !$this->nonNegativeIntegers($value['counts'])
      || !$this->nonNegativeIntegers(array_intersect_key($value, array_flip(['totalIssues', 'criticalCount', 'warningCount', 'suggestionCount'])))
      || !is_bool($value['inputTruncated'])
      || (isset($value['homoglyphTerms']) && !$this->boundedStringList($value['homoglyphTerms'], 20, 120))
      || !is_array($value['issues']) || !array_is_list($value['issues']) || count($value['issues']) > 50
      || !is_array($value['goodPractices']) || !array_is_list($value['goodPractices']) || count($value['goodPractices']) > 20) {
      return FALSE;
    }
    foreach ($value['issues'] as $issue) {
      if (!is_array($issue) || !$this->exactAllowedKeys($issue, ['code', 'category', 'severity', 'deduction', 'evidence']) || count($issue) !== 5
        || !is_string($issue['code'] ?? NULL) || strlen($issue['code']) > 128
        || !in_array($issue['category'] ?? NULL, ['subject', 'content', 'links', 'structure', 'compliance'], TRUE)
        || !in_array($issue['severity'] ?? NULL, ['critical', 'warning', 'suggestion'], TRUE)
        || !is_int($issue['deduction'] ?? NULL) || $issue['deduction'] < 0 || $issue['deduction'] > 100
        || !is_string($issue['evidence'] ?? NULL) || strlen($issue['evidence']) > 200) {
        return FALSE;
      }
    }
    foreach ($value['goodPractices'] as $practice) {
      if (!is_array($practice) || !$this->exactAllowedKeys($practice, ['code', 'category']) || count($practice) !== 2
        || !is_string($practice['code'] ?? NULL) || strlen($practice['code']) > 128
        || !in_array($practice['category'] ?? NULL, ['subject', 'content', 'links', 'structure', 'compliance'], TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /** @param array<mixed> $values */
  private function nonNegativeIntegers(array $values): bool {
    foreach ($values as $item) {
      if (!is_int($item) || $item < 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function boundedStringList(mixed $value, int $maximumItems, int $maximumBytes): bool {
    if (!is_array($value) || !array_is_list($value) || count($value) > $maximumItems) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!is_string($item) || strlen($item) > $maximumBytes) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * One TLS-verified request with redirects and retries disabled.
   *
   * @return array{status: int, body: string}
   */
  private static function curlTransport(string $body, string $token): array {
    if (!function_exists('curl_init')) {
      throw new ClassificationException('transport', 'The PHP cURL extension is required.');
    }
    $received = '';
    $tooLarge = FALSE;
    $handle = curl_init(self::API_URL);
    if ($handle === FALSE) {
      throw new ClassificationException('transport', 'SendRepute could not be reached securely.');
    }
    curl_setopt_array($handle, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
      ],
      CURLOPT_FOLLOWLOCATION => FALSE,
      CURLOPT_MAXREDIRS => 0,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_SSL_VERIFYPEER => TRUE,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, &$tooLarge): int {
        if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
          $tooLarge = TRUE;
          return 0;
        }
        $received .= $chunk;
        return strlen($chunk);
      },
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($ok === FALSE || $tooLarge) {
      throw new ClassificationException($tooLarge ? 'response' : 'transport', $tooLarge
        ? 'SendRepute returned an oversized response.'
        : 'SendRepute could not be reached securely.');
    }
    return ['status' => $status, 'body' => $received];
  }

}
