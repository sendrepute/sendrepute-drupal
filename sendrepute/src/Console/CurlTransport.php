<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * Fixed-origin, single-attempt HTTPS transport. No redirects, bounded body,
 * contract path guard. The token comes from server configuration only.
 */
final class CurlTransport
{
    private string $base;
    private string $token;
    /** @var list<string> */
    private array $resolve;

    /**
     * @param list<string> $resolve Optional pinned host:port:address entries (CURLOPT_RESOLVE).
     */
    public function __construct(string $base, string $token, array $resolve = [])
    {
        $this->base = rtrim($base, '/');
        $this->token = trim($token);
        $this->resolve = array_values(array_filter($resolve, 'is_string'));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status:int, data:?array, error:string}
     */
    public function __invoke(string $method, string $path, ?array $body, int $maxBytes): array
    {
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true) || !Contract::safePath($path)) {
            return ['status' => 0, 'data' => null, 'error' => 'The request is outside the customer API allowlist.'];
        }
        $parts = parse_url($this->base);
        if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])) {
            return ['status' => 0, 'data' => null, 'error' => 'The SendRepute API origin must be HTTPS.'];
        }
        if ('' === $this->token || strlen($this->token) > 4096 || preg_match('/[\r\n]/', $this->token)) {
            return ['status' => 0, 'data' => null, 'error' => 'The server-side SendRepute API token is not configured.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'The PHP cURL extension is required.'];
        }
        $maxBytes = max(1024, min(Contract::MAX_EXPORT, $maxBytes));
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        $json = null;
        if (null !== $body) {
            $json = [] === $body ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                return ['status' => 0, 'data' => null, 'error' => 'The request could not be encoded.'];
            }
            $headers[] = 'Content-Type: application/json';
        }
        $raw = '';
        $tooLarge = false;
        $handle = curl_init($this->base . $path);
        if (false === $handle) {
            return ['status' => 0, 'data' => null, 'error' => 'SendRepute could not be reached securely.'];
        }
        if ($this->resolve) {
            curl_setopt($handle, CURLOPT_RESOLVE, $this->resolve);
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$raw, &$tooLarge, $maxBytes): int {
                if (strlen($raw) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($tooLarge) {
            return ['status' => $status, 'data' => null, 'error' => 'The SendRepute response exceeded the safe size limit.'];
        }
        if (false === $ok) {
            return ['status' => 0, 'data' => null, 'error' => 'SendRepute could not be reached securely.'];
        }
        return self::decode($status, $raw);
    }

    /** @return array{status:int, data:?array, error:string} */
    public static function decode(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = 'The SendRepute API rejected the request.';
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = substr(strip_tags($decoded['error']['message']), 0, 300);
            }
            return ['status' => $status, 'data' => null, 'error' => $message];
        }
        if (!is_array($decoded)) {
            return ['status' => $status, 'data' => null, 'error' => 'The SendRepute API returned an invalid response.'];
        }
        return ['status' => $status, 'data' => $decoded, 'error' => ''];
    }
}
