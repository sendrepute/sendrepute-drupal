<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * SendRepute customer API console contract: the only operations the console
 * can call. Generated from customer-api-openapi.json. Admin, API-key and
 * credential-management endpoints are intentionally absent.
 *
 * Canonical source: integrations/wordpress/portable-console/src. Platform
 * copies are produced by sync.py with a platform namespace; do not edit copies.
 */
final class Contract
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,200}$/D';
    public const REQUEST_ID = '/^[A-Za-z0-9_-]{16,128}$/D';
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    public const VIP_TEMPLATE = '/^vip-(0[1-9]|1[0-9]|20)$/D';
    public const MAX_DOCUMENT = 523264;
    public const MAX_RESPONSE = 1048576;
    public const MAX_EXPORT = 4194304;

    /**
     * id => [method, path, scope, paid].
     *
     * @return array<string, array{0:string,1:string,2:string,3:bool}>
     */
    public static function operations(): array
    {
        return [
            'account' => ['GET', '/v1/account', 'account:read', false],
            'referrals' => ['GET', '/v1/account/referrals', 'account:read', false],
            'ledger' => ['GET', '/v1/account/ledger', 'billing:read', false],
            'usage' => ['GET', '/v1/usage', '', false],
            'models' => ['GET', '/v1/models', '', false],
            'invoices' => ['GET', '/v1/payments/invoices', 'billing:read', false],
            'payment_methods' => ['GET', '/v1/payments/methods', 'billing:read', false],
            'deposit_offer' => ['GET', '/v1/payments/deposit-offer', 'billing:read', false],
            'invoice_create' => ['POST', '/v1/payments/invoices', 'billing:write', false],
            'invoice_cancel' => ['DELETE', '/v1/payments/invoices/{id}', 'billing:write', false],
            'payments_refresh' => ['POST', '/v1/payments/refresh', 'billing:write', false],
            'pricing' => ['GET', '/v1/pricing', 'catalog:read', false],
            'vip_plans' => ['GET', '/v1/vip/plans', 'catalog:read', false],
            'vip' => ['GET', '/v1/vip', 'vip:read', false],
            'templates' => ['GET', '/v1/email-builder/templates', 'catalog:read', false],
            'template' => ['GET', '/v1/email-builder/templates/{id}', 'catalog:read', false],
            'vip_templates' => ['GET', '/v1/vip/email-builder/templates', 'vip:read', false],
            'vip_template' => ['GET', '/v1/vip/email-builder/templates/{id}', 'vip:read', false],
            'insights_quote' => ['POST', '/v1/campaign-insights/quote', 'ai:generate', false],
            'insights_analyze' => ['POST', '/v1/campaign-insights/analyze', 'ai:generate', true],
            'rewrite_quote' => ['POST', '/v1/rewrite/ai-quote', 'rewrite', false],
            'rewrite' => ['POST', '/v1/rewrite', 'rewrite', true],
            'rewrite_finalize' => ['POST', '/v1/rewrite/finalize', 'rewrite', false],
            'rewrite_result' => ['GET', '/v1/rewrite/result/{id}', 'rewrite', false],
            'ai_template' => ['POST', '/v1/email-builder/ai-template', 'ai:generate', true],
            'vip_ai_template' => ['POST', '/v1/vip/email-template', 'ai:generate', true],
            'vip_purchase' => ['POST', '/v1/vip/purchase', 'vip:purchase', true],
            'classify' => ['POST', '/v1/classify', 'classify', true],
            'classify_edit_quote' => ['POST', '/v1/classify/edit/quote', 'classify', false],
            'classify_edit' => ['POST', '/v1/classify/edit', 'classify', true],
            'hosted_handoff' => ['POST', '/v1/email-builder/hosted-handoffs', 'builder:write', false],
            'paid_identity' => ['GET', '/customer/paid-results/identity', '', false],
            'paid_result' => ['GET', '/customer/paid-results/{id}', '', false],
            'paid_resolve' => ['POST', '/customer/paid-results/{id}/resolve', '', false],
            'builder_access' => ['POST', '/v1/email-builder/access', 'builder:write', false],
            'builder_access_get' => ['GET', '/v1/email-builder/access/{id}', 'builder:read', false],
            'builder_access_close' => ['DELETE', '/v1/email-builder/access/{id}', 'builder:write', false],
            'vip_access' => ['POST', '/v1/vip/email-builder/access', 'vip:builder', true],
            'vip_access_get' => ['GET', '/v1/vip/email-builder/access/{id}', 'builder:read', false],
            'vip_access_close' => ['DELETE', '/v1/vip/email-builder/access/{id}', 'vip:builder', false],
            'mjml_import' => ['POST', '/v1/email-builder/import', 'builder:write', false],
            'mjml_validate' => ['POST', '/v1/email-builder/validate', 'builder:write', false],
            'mjml_compile' => ['POST', '/v1/email-builder/compile', 'builder:write', false],
            'mjml_export' => ['POST', '/v1/email-builder/export', 'builder:write', false],
            'native_import' => ['POST', '/v1/vip/email-builder/import', 'vip:builder', false],
            'native_validate' => ['POST', '/v1/vip/email-builder/validate', 'vip:builder', false],
            'native_compile' => ['POST', '/v1/vip/email-builder/compile', 'vip:builder', false],
            'native_export' => ['POST', '/v1/vip/email-builder/export', 'vip:builder', false],
        ];
    }

    /**
     * Customer contract operations deliberately not offered by the console.
     *
     * @return array<string, string> path => reason
     */
    public static function unsupported(): array
    {
        return [
            'POST /v1/rewrite/quote' => 'Deprecated by the server (Deprecation: true, successor /v1/classify/edit/quote). The console uses the successor; this is the one intentional exception.',
        ];
    }

    public const MODELS = ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];
    public const RATE_FIELDS = ['classificationBaseMillicents', 'includedUniqueTerms', 'additionalTermMillicents', 'maximumClassificationMillicents'];

    /**
     * Server rate schedule (GET /v1/pricing) reduced to the four classification
     * fields used by priceAuthorization.expectedPricing.
     *
     * @param mixed $pricing
     * @return array<string, int>|string
     */
    public static function rateSchedule($pricing)
    {
        if (!is_array($pricing)) {
            return 'The API returned no pricing.';
        }
        $out = [];
        foreach (self::RATE_FIELDS as $f) {
            if (!isset($pricing[$f]) || !is_numeric($pricing[$f]) || (float) $pricing[$f] < 0 || (float) $pricing[$f] != (int) $pricing[$f]) {
                return 'The API returned an incomplete classification rate schedule.';
            }
            $out[$f] = (int) $pricing[$f];
        }
        return $out;
    }

    private static function header(string $v, int $max): ?string
    {
        $v = trim((string) preg_replace('/[\r\n\t]+/', ' ', $v));
        return '' === $v || strlen($v) > $max ? null : $v;
    }

    /**
     * POST /v1/classify body without priceAuthorization.
     *
     * @return array<string, mixed>|string
     */
    public static function classifyInput(string $sender, string $subject, string $body, string $plain = '', string $html = '', string $model = '')
    {
        $sender = self::header($sender, 320);
        $subject = self::header($subject, 998);
        if (null === $sender || null === $subject) {
            return 'Sender (display name, up to 320) and subject (up to 998) are required.';
        }
        if ('' === $body) {
            return 'Body is required.';
        }
        $out = ['sender' => $sender, 'subject' => $subject, 'body' => $body];
        $alts = [];
        if ('' !== $plain) {
            $alts[] = ['contentType' => 'text/plain', 'body' => $plain];
        }
        if ('' !== $html) {
            $alts[] = ['contentType' => 'text/html', 'body' => $html];
        }
        if (strlen($body) + strlen($plain) + strlen($html) > 524288) {
            return 'Body and displayed alternatives together must not exceed 524288 bytes.';
        }
        if ($alts) {
            $out['displayedAlternatives'] = $alts;
        }
        if ('' !== $model) {
            if (!in_array($model, self::MODELS, true)) {
                return 'Unknown model family.';
            }
            $out['model'] = $model;
        }
        return $out;
    }

    /** @return list<string>|string Terms as entered (server normalizes). */
    public static function termList(string $terms, bool $allowEmpty)
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $terms) ?: []), 'strlen'));
        if ((!$allowEmpty && !$list) || count($list) > 10000) {
            return 'Enter up to 10000 terms, one per line.';
        }
        $total = 0;
        foreach ($list as $t) {
            if (strlen($t) > 500) {
                return 'Each term must be 500 characters or fewer.';
            }
            $total += strlen($t);
        }
        return $total > 524288 ? 'Terms exceed 524288 characters in total.' : $list;
    }

    /** @return array<string, mixed>|string POST /v1/classify/edit/quote body. */
    public static function editQuoteInput(string $parent, string $mode, string $terms)
    {
        if (!preg_match(self::REQUEST_ID, $parent)) {
            return 'Enter the parent classification request ID (16 to 128 characters).';
        }
        if (!in_array($mode, ['manual', 'remove_all'], true)) {
            return 'Choose manual or remove all.';
        }
        $list = self::termList($terms, true);
        return is_string($list) ? $list : ['parentRequestId' => $parent, 'editMode' => $mode, 'terms' => $list];
    }

    /** @return array<string, mixed>|string POST /v1/classify/edit body (identity derived by server). */
    public static function editInput(string $parent, string $mode, string $terms, string $sender, string $subject, string $body)
    {
        $q = self::editQuoteInput($parent, $mode, $terms);
        if (is_string($q)) {
            return $q;
        }
        $sender = self::header($sender, 320);
        $subject = self::header($subject, 998);
        if (null === $sender || null === $subject || '' === $body || strlen($body) > 524288) {
            return 'The complete changed sender, subject and body (up to 524288 bytes) are required.';
        }
        return ['sender' => $sender, 'subject' => $subject, 'body' => $body, 'parentRequestId' => $parent, 'editMode' => $mode, 'editedTerms' => $q['terms']];
    }

    /** Server-equivalent normalization used for duplicate-intent keys. @param list<string> $terms @return list<string> */
    public static function normalizedTerms(array $terms): array
    {
        $n = array_values(array_unique(array_map(static fn ($t) => mb_strtolower(trim((string) $t)), $terms)));
        sort($n, SORT_STRING);
        return $n;
    }

    /**
     * Operator-configured hosted builder return origin. Mirrors the server:
     * canonical origin, no credentials/path, HTTPS (http only for loopback).
     */
    public static function returnOrigin(string $origin): ?string
    {
        $p = parse_url($origin);
        if ('' === $origin || false === $p || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['path']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        $loop = in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ('https' !== $scheme && !($loop && 'http' === $scheme)) {
            return null;
        }
        $canon = $scheme . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        return $canon === $origin && strlen($origin) <= 2048 ? $canon : null;
    }

    /** @return array<string, mixed>|string */
    public static function hostedInput(string $origin, string $state, string $mode, string $mjml, string $access, string $document)
    {
        if (null === self::returnOrigin($origin)) {
            return 'Hosted builder handoff is disabled until an operator configures a canonical HTTPS return origin.';
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            return 'The handoff nonce is missing. Reload the page.';
        }
        $body = ['state' => $state, 'returnOrigin' => $origin, 'mode' => 'vip' === $mode ? 'vip' : 'standard'];
        if ('vip' === $body['mode']) {
            $doc = json_decode($document, true);
            if (!is_array($doc) || strlen($document) > self::MAX_DOCUMENT) {
                return 'VIP handoff needs a valid native document JSON.';
            }
            if (!preg_match(self::ID_PATTERN, $access)) {
                return 'VIP handoff needs an owned open VIP access ID.';
            }
            $body['vipAccessId'] = $access;
            $body['initialDocument'] = $doc;
        } elseif ('' !== $mjml) {
            if (strlen($mjml) > 524288) {
                return 'Initial MJML must be at most 512 KiB.';
            }
            $body['initialMjml'] = $mjml;
        }
        return $body;
    }

    /** Only a launch URL on the fixed SendRepute origin is shown as a link. */
    public static function launchUrl($url): ?string
    {
        return is_string($url) && strlen($url) <= 2048 && 1 === preg_match('#^https://www\.sendrepute\.com/hosted-email-builder\?code=[A-Za-z0-9%_.~-]+$#D', $url) ? $url : null;
    }

    /**
     * @param array<string, string> $query
     * @return array{0:string,1:string,2:bool}|string
     */
    public static function resolve(string $op, string $id = '', array $query = [])
    {
        $ops = self::operations();
        if (!isset($ops[$op])) {
            return 'Operation is not in the customer API allowlist.';
        }
        [$method, $path, , $paid] = $ops[$op];
        if (false !== strpos($path, '{id}')) {
            if (!preg_match(self::ID_PATTERN, $id)) {
                return 'Enter a valid identifier (letters, digits, dash, underscore).';
            }
            if ('rewrite_result' === $op && !preg_match(self::REQUEST_ID, $id)) {
                return 'A rewrite request ID has 16 to 128 characters.';
            }
            if ('vip_template' === $op && !preg_match(self::VIP_TEMPLATE, $id)) {
                return 'VIP template IDs are vip-01 to vip-20.';
            }
            if (in_array($op, ['paid_result', 'paid_resolve'], true) && !preg_match(self::UUID_PATTERN, $id)) {
                return 'A recovery ID is a lowercase UUID.';
            }
            $path = str_replace('{id}', $id, $path);
        }
        if ('ledger' === $op) {
            $kind = ($query['kind'] ?? '') === 'payments' ? 'payments' : 'usage';
            $limit = max(1, min(50, (int) ($query['limit'] ?? 20)));
            $offset = max(0, min(1000000, (int) ($query['offset'] ?? 0)));
            $path .= '?kind=' . $kind . '&limit=' . $limit . '&offset=' . $offset;
        }
        return [$method, $path, $paid];
    }

    /** Path guard used by every transport. */
    public static function safePath(string $path): bool
    {
        return 1 === preg_match('#^/(v1/[A-Za-z0-9/_-]*|customer/paid-results/[A-Za-z0-9/_-]+)(\?[A-Za-z0-9=&_-]{1,200})?$#D', $path)
            && false === strpos($path, '..');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|float>|string
     */
    public static function insightsMetrics(array $input)
    {
        $counts = ['sent', 'delivered', 'bounced', 'hardBounced', 'softBounced', 'deferred', 'failed', 'opened', 'uniqueOpened', 'clicked', 'uniqueClicked', 'unsubscribed', 'complaints', 'accepted', 'pending'];
        $rates = ['deliveryRate', 'openRate', 'clickRate', 'bounceRate', 'unsubscribeRate', 'complaintRate'];
        $metrics = [];
        foreach ($counts as $key) {
            $v = trim((string) ($input[$key] ?? ''));
            if ('' === $v) {
                continue;
            }
            if (!ctype_digit($v) || strlen($v) > 10 || (int) $v > 1000000000) {
                return 'Counts must be whole numbers between 0 and 1,000,000,000.';
            }
            $metrics[$key] = (int) $v;
        }
        foreach ($rates as $key) {
            $v = trim((string) ($input[$key] ?? ''));
            if ('' === $v) {
                continue;
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                return 'Rates are percentages between 0 and 100.';
            }
            $metrics[$key] = (float) $v;
        }
        return $metrics ?: 'Enter at least one aggregate metric.';
    }

    public static function locale(string $locale): string
    {
        return in_array($locale, ['en', 'ru', 'uk', 'hi', 'de', 'fr', 'es', 'it', 'pt', 'ar', 'id', 'tr', 'zh', 'vi'], true) ? $locale : 'en';
    }

    /** @return array<string, mixed>|string */
    public static function rewriteInput(string $parent, string $mode, string $terms)
    {
        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $terms) ?: []), 'strlen')));
        if (!preg_match(self::REQUEST_ID, $parent)) {
            return 'Enter a valid parent classification request ID.';
        }
        if (!$list || count($list) > 1000) {
            return 'Enter 1 to 1000 terms, one per line.';
        }
        foreach ($list as $term) {
            if (strlen($term) > 500) {
                return 'Each term must be 500 characters or fewer.';
            }
        }
        return ['parentRequestId' => $parent, 'mode' => 'all' === $mode ? 'all' : 'single', 'terms' => $list];
    }

    /** @return array<string, string>|string */
    public static function finalizeInput(string $requestId, string $sender, string $subject, string $body)
    {
        if (!preg_match(self::REQUEST_ID, $requestId)) {
            return 'Enter the completed Rewrite All request ID.';
        }
        if ('' === $sender || strlen($sender) > 320 || preg_match('/[\r\n]/', $sender)) {
            return 'Sender must be 1 to 320 characters on one line.';
        }
        if ('' === $subject || strlen($subject) > 998 || preg_match('/[\r\n]/', $subject)) {
            return 'Subject must be 1 to 998 characters on one line.';
        }
        if ('' === $body || strlen($body) > 524288) {
            return 'Body must be 1 byte to 512 KiB.';
        }
        return ['requestId' => $requestId, 'sender' => $sender, 'subject' => $subject, 'body' => $body];
    }

    /** @return array<string, string>|string */
    public static function resolveInput(string $reason, string $typed)
    {
        if ('RESOLVE' !== $typed) {
            return 'Type RESOLVE to confirm an explicit financial resolution.';
        }
        $reason = trim($reason);
        if ('' === $reason || strlen($reason) > 500) {
            return 'Give a reason of 1 to 500 characters.';
        }
        return ['action' => 'resolve', 'reason' => $reason];
    }

    /** @return array<string, string>|string */
    public static function mjmlInput(string $mjml, string $filename = '', string $format = '')
    {
        if ('' === trim($mjml) || strlen($mjml) > self::MAX_DOCUMENT) {
            return 'Paste a complete MJML document under 511 KiB.';
        }
        $body = ['mjml' => $mjml];
        $name = self::filename($filename);
        if (null === $name) {
            return 'Filenames cannot contain slashes or control characters.';
        }
        if ('' !== $name) {
            $body['filename'] = $name;
        }
        if (in_array($format, ['html', 'mjml', 'zip'], true)) {
            $body['format'] = $format;
        }
        return $body;
    }

    /** @return array<string, mixed>|string */
    public static function nativeInput(string $document, string $access, string $cssMode = '', string $filename = '', string $format = '')
    {
        if ('' === trim($document) || strlen($document) > self::MAX_DOCUMENT) {
            return 'Paste a native document JSON under 511 KiB.';
        }
        if (!is_array(json_decode($document, true))) {
            return 'The native document must be valid JSON.';
        }
        if (!preg_match(self::ID_PATTERN, $access)) {
            return 'Enter the open VIP builder access ID.';
        }
        $body = ['document' => $document, 'accessId' => $access];
        if (in_array($cssMode, ['inline', 'head', 'hybrid'], true)) {
            $body['options'] = ['cssMode' => $cssMode];
        }
        $name = self::filename($filename);
        if (null === $name) {
            return 'Filenames cannot contain slashes or control characters.';
        }
        if ('' !== $name) {
            $body['filename'] = $name;
        }
        if (in_array($format, ['html', 'zip'], true)) {
            $body['format'] = $format;
        }
        return $body;
    }

    public static function filename(string $name): ?string
    {
        $name = trim($name);
        return strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) ? null : $name;
    }

    /**
     * @param mixed $result
     * @return array{0:string,1:string,2:string}|string filename, content type, bytes
     */
    public static function exportDownload($result)
    {
        if (!is_array($result) || empty($result['valid']) || !isset($result['filename']) || !is_string($result['filename'])) {
            return 'The export response was not a valid stateless result.';
        }
        $base = trim(substr((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $result['filename']), 0, 120), '.-');
        $base = '' === $base ? 'sendrepute-export' : $base;
        if (isset($result['archiveBase64']) && is_string($result['archiveBase64'])) {
            $bytes = base64_decode($result['archiveBase64'], true);
            if (false === $bytes || 0 !== strpos($bytes, "PK\x03\x04")) {
                return 'The archive was not a valid ZIP file.';
            }
            return [self::ext($base, 'zip'), 'application/zip', $bytes];
        }
        if (isset($result['html']) && is_string($result['html'])) {
            return [self::ext($base, 'html'), 'application/octet-stream', $result['html']];
        }
        if (isset($result['mjml']) && is_string($result['mjml'])) {
            return [self::ext($base, 'mjml'), 'text/plain; charset=utf-8', $result['mjml']];
        }
        return 'The export response contained no downloadable content.';
    }

    /** Headers every platform sends with an export attachment. @return array<string,string> */
    public static function downloadHeaders(string $filename, string $type, int $length): array
    {
        return [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
            'Content-Length' => (string) $length,
        ];
    }

    private static function ext(string $base, string $ext): string
    {
        return preg_match('/\.' . $ext . '$/i', $base) ? $base : $base . '.' . $ext;
    }

    /** @param int|float|string $millicents */
    public static function money($millicents): string
    {
        return 'USD ' . number_format(((float) $millicents) / 100000, 5);
    }

    /** RFC 4122 version 4 identifier generated before a paid submission. */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
