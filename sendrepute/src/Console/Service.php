<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * Platform-neutral request handling for the customer API console.
 *
 * The platform adapter is responsible for authentication, permission and
 * CSRF verification BEFORE calling handle() or download(). This class then
 * enforces the contract allowlist, input validation, paid consent, a fresh
 * server price check, a credential-bound duplicate guard and exactly one
 * attempt per paid submission.
 */
final class Service
{
    public const LOCK_TTL = 86400;
    private const PAID = ['classify', 'classify_edit', 'insights_analyze', 'rewrite', 'ai_template', 'vip_ai_template', 'vip_purchase', 'vip_access'];
    private const SUBTYPES = ['custom', 'weekly-digest', 'product-update', 'promotion', 'event-invitation', 'order-confirmation', 'shipping-update', 'payment-receipt', 'password-reset', 'account-alert', 'invitation', 'thank-you', 'congratulations', 'personal-update', 'introduction', 'announcement', 'follow-up', 'meeting-invitation'];

    /** @var callable(string, string, ?array, int): array{status:int, data:?array, error:string} */
    private $transport;
    private LockStore $locks;
    private string $secret;
    private string $actor;
    private string $hostedOrigin;
    /** @var array<string, mixed> */
    private array $post = [];

    /**
     * @param callable $transport Fixed-origin transport (see CurlTransport).
     * @param string   $secret    Server-side secret bound to the configured credential.
     * @param string   $actor     Administrator identifier (display/audit only; never part of paid identity).
     * @param array{hostedReturnOrigin?:string} $options Operator configuration. Hosted handoff stays
     *   disabled unless hostedReturnOrigin is a canonical HTTPS origin.
     */
    public function __construct(callable $transport, LockStore $locks, string $secret, string $actor, array $options = [])
    {
        $origin = (string) ($options['hostedReturnOrigin'] ?? '');
        $this->hostedOrigin = null !== Contract::returnOrigin($origin) ? $origin : '';
        $this->transport = $transport;
        $this->locks = $locks;
        $this->secret = $secret;
        $this->actor = $actor;
    }

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return ['account' => 'Account', 'billing' => 'Billing', 'catalog' => 'Catalog', 'classify' => 'Classification', 'ai' => 'AI services', 'builder' => 'Builder', 'recovery' => 'Recovery'];
    }

    /** @return list<string> */
    public static function subtypes(): array
    {
        return self::SUBTYPES;
    }

    public static function tabFor(string $op): string
    {
        $map = [
            'account' => ['account', 'referrals', 'ledger', 'usage', 'models'],
            'billing' => ['invoices', 'payment_methods', 'deposit_offer', 'invoice_create', 'invoice_cancel', 'payments_refresh'],
            'catalog' => ['pricing', 'vip_plans', 'vip', 'templates', 'template', 'vip_templates', 'vip_template', 'vip_purchase'],
            'ai' => ['insights_quote', 'insights_analyze', 'rewrite_quote', 'rewrite', 'rewrite_finalize', 'rewrite_result', 'ai_template', 'vip_ai_template'],
            'recovery' => ['paid_identity', 'paid_result', 'paid_resolve'],
            'classify' => ['classify', 'classify_edit_quote', 'classify_edit'],
        ];
        foreach ($map as $tab => $ops) {
            if (in_array($op, $ops, true)) {
                return $tab;
            }
        }
        return 'builder';
    }

    /**
     * @param array<string, mixed> $post Raw submitted fields (already CSRF/permission checked).
     * @return array{tab:string, state:array<string, mixed>}
     */
    public function handle(array $post): array
    {
        $this->post = $post;
        $op = $this->field('op');
        $ops = Contract::operations();
        if (!isset($ops[$op])) {
            return $this->out('account', ['error' => 'Operation is not in the customer API allowlist.']);
        }
        $tab = self::tabFor($op);
        $input = $this->collectInput();
        $base = ['op' => $op, 'input' => $input];
        if (in_array($op, self::PAID, true)) {
            return $this->paid($op, $tab, $base, $input);
        }

        $body = null;
        switch ($op) {
            case 'invoice_create':
                if ('1' !== $this->field('confirm')) {
                    return $this->out($tab, $base + ['error' => 'Tick the confirmation box first.']);
                }
                if ('vip' === $this->field('purpose')) {
                    $expected = $this->field('expected_price');
                    $plans = $this->send('GET', '/v1/vip/plans', null, Contract::MAX_RESPONSE);
                    if (is_string($plans)) {
                        return $this->out($tab, $base + ['error' => $plans]);
                    }
                    if (!ctype_digit($expected) || (int) $expected < 1 || !isset($plans['monthlyPriceMillicents']) || (string) (int) $plans['monthlyPriceMillicents'] !== $expected) {
                        return $this->out($tab, $base + ['error' => 'The VIP price changed since this form was shown. No invoice was created.']);
                    }
                    $body = ['purpose' => 'vip', 'expectedPriceMillicents' => (int) $expected];
                    break;
                }
                $amount = $this->field('amount_cents');
                if (!ctype_digit($amount) || (int) $amount < 1000 || (int) $amount > 5000000) {
                    return $this->out($tab, $base + ['error' => 'Enter an amount between 1000 and 5000000 cents.']);
                }
                $body = ['amountCents' => (int) $amount, 'purpose' => 'deposit'];
                $offer = $this->field('offer_id');
                if ('' !== $offer) {
                    if (strlen($offer) > 128) {
                        return $this->out($tab, $base + ['error' => 'Offer IDs are at most 128 characters.']);
                    }
                    $body['offerId'] = $offer;
                }
                break;
            case 'classify_edit_quote':
                $body = Contract::editQuoteInput($this->field('parent_request_id'), $this->field('edit_mode'), $this->raw('edited_terms'));
                break;
            case 'hosted_handoff':
                if ('' === $this->hostedOrigin) {
                    return $this->out($tab, $base + ['error' => 'Hosted builder handoff is disabled. An operator must configure SENDREPUTE_HOSTED_RETURN_ORIGIN with a canonical HTTPS origin first.']);
                }
                $body = Contract::hostedInput($this->hostedOrigin, bin2hex(random_bytes(32)), $this->field('handoff_mode'), $this->raw('mjml'), $this->field('access_id'), $this->raw('mjml'));
                break;
            case 'invoice_cancel':
            case 'builder_access_close':
            case 'vip_access_close':
                if ('1' !== $this->field('confirm')) {
                    return $this->out($tab, $base + ['error' => 'Tick the confirmation box first.']);
                }
                break;
            case 'payments_refresh':
                $body = [];
                break;
            case 'insights_quote':
                $metrics = Contract::insightsMetrics($input['m']);
                if (is_string($metrics)) {
                    return $this->out($tab, $base + ['error' => $metrics]);
                }
                $body = [];
                break;
            case 'rewrite_quote':
                $body = Contract::rewriteInput($input['parent_request_id'], $input['rewrite_mode'], $input['terms']);
                break;
            case 'rewrite_finalize':
                $body = Contract::finalizeInput($this->field('final_request_id'), $this->field('final_sender'), $this->field('final_subject'), $this->raw('final_body'));
                break;
            case 'paid_resolve':
                $body = Contract::resolveInput($this->field('reason'), $this->field('typed'));
                break;
            case 'builder_access':
                $kind = $this->field('source_kind');
                $body = ['designId' => $this->field('design_id'), 'sourceKind' => in_array($kind, ['curated', 'saved', 'blank'], true) ? $kind : 'blank'];
                if ('' === $body['designId'] || strlen($body['designId']) > 200) {
                    $body = 'Enter a design ID of at most 200 characters.';
                } elseif ('curated' === $body['sourceKind']) {
                    $tid = $this->field('template_id');
                    if (!preg_match(Contract::ID_PATTERN, $tid)) {
                        $body = 'Curated access needs a template ID.';
                    } else {
                        $body['templateId'] = $tid;
                    }
                }
                break;
            case 'mjml_import':
            case 'mjml_validate':
            case 'mjml_compile':
                $body = Contract::mjmlInput($input['mjml'], $input['filename']);
                break;
            case 'native_import':
            case 'native_validate':
            case 'native_compile':
                $body = Contract::nativeInput($input['document'], $input['access_id'], $this->field('css_mode'), $input['filename']);
                break;
            case 'mjml_export':
            case 'native_export':
                return $this->out($tab, $base + ['error' => 'Exports are delivered as downloads.']);
        }
        if (is_string($body)) {
            return $this->out($tab, $base + ['error' => $body]);
        }
        $resolved = Contract::resolve($op, $this->field('id'), ['kind' => $this->field('kind'), 'limit' => $this->field('limit'), 'offset' => $this->field('offset')]);
        if (is_string($resolved)) {
            return $this->out($tab, $base + ['error' => $resolved]);
        }
        $max = in_array($op, ['mjml_compile', 'native_compile', 'mjml_import', 'native_import'], true) ? Contract::MAX_EXPORT : Contract::MAX_RESPONSE;
        $response = $this->send($resolved[0], $resolved[1], in_array($resolved[0], ['GET', 'DELETE'], true) ? null : $body, $max);
        if (is_string($response)) {
            return $this->out($tab, $base + ['error' => $response]);
        }
        $extra = ['result' => $response, 'notice' => 'Completed. The result below is shown once and is not stored.'];
        if ('insights_quote' === $op) {
            if (!isset($response['priceMillicents']) || !in_array($response['priceMillicents'], [10000, 5000], true)) {
                return $this->out($tab, $base + ['error' => 'The API returned an unrecognized insights price. Nothing was charged.']);
            }
            $extra['insights_quote'] = $response;
            $extra['notice'] = 'Price verified. Review it and confirm to run the analysis.';
        }
        if ('rewrite_quote' === $op) {
            foreach (['uniqueTermCount', 'minimumPerUniqueTermMillicents', 'minimumChargeMillicents', 'maximumChargeMillicents', 'balanceAfterMaximumMillicents'] as $k) {
                if (!isset($response[$k]) || !is_numeric($response[$k])) {
                    return $this->out($tab, $base + ['error' => 'The API returned an incomplete rewrite quote. Nothing was charged.']);
                }
            }
            $extra['rewrite_quote'] = $response;
            $extra['notice'] = 'Quote received. Set your maximum and confirm to run the rewrite.';
        }
        if ('classify_edit_quote' === $op) {
            foreach (['validatedTermCount', 'editChargeMillicents', 'currentBalanceMillicents', 'balanceAfterMillicents'] as $k) {
                if (!isset($response[$k]) || !is_numeric($response[$k])) {
                    return $this->out($tab, $base + ['error' => 'The API returned an incomplete edit quote. Nothing was charged.']);
                }
            }
            $extra['edit_quote'] = $response;
            $extra['notice'] = 'Edit estimate received. Enter the complete changed email and review the rate-at-processing consent before reclassifying.';
        }
        if ('hosted_handoff' === $op) {
            $url = Contract::launchUrl($response['launchUrl'] ?? null);
            if (null === $url) {
                return $this->out($tab, $base + ['error' => 'The API returned a launch URL outside the SendRepute origin. It was not shown.']);
            }
            $extra['launch_url'] = $url;
            $extra['notice'] = 'One-time editor launch created. It expires at ' . (string) ($response['expiresAt'] ?? 'the stated time') . ' and returns only to ' . $this->hostedOrigin . '.';
        }
        if ('paid_result' === $op || 'paid_resolve' === $op) {
            $extra['notice'] = 'Recovery evidence loaded. Only settlement.kind is financial evidence; a failed status does not prove no charge.';
        }
        return $this->out($tab, $base + $extra);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $input
     * @return array{tab:string, state:array<string, mixed>}
     */
    private function paid(string $op, string $tab, array $base, array $input): array
    {
        if ('1' !== $this->field('paid_consent')) {
            return $this->out($tab, $base + ['error' => 'Paid operations require the consent checkbox. Nothing was submitted.']);
        }
        $expected = $this->field('expected_price');
        $changed = 'The server price changed since this form was shown. Nothing was charged; review the new price and confirm again.';
        $body = null;
        switch ($op) {
            case 'classify':
                $body = Contract::classifyInput($this->field('sender'), $this->field('subject'), $this->raw('body'), $this->raw('plain'), $this->raw('html'), $this->field('model'));
                if (is_string($body)) {
                    return $this->out($tab, $base + ['error' => $body]);
                }
                $ceiling = $this->field('max_charge');
                if (!ctype_digit($ceiling) || strlen($ceiling) > 15) {
                    return $this->out($tab, $base + ['error' => 'Enter a whole-number per-request ceiling in millicents.']);
                }
                $fresh = Contract::rateSchedule($this->send('GET', '/v1/pricing', null, Contract::MAX_RESPONSE));
                if (is_string($fresh)) {
                    return $this->out($tab, $base + ['error' => $fresh]);
                }
                foreach (Contract::RATE_FIELDS as $f) {
                    if ($this->field('rate_' . $f) !== (string) $fresh[$f]) {
                        return $this->out($tab, $base + ['error' => 'The classification rate schedule changed since this form was shown. Nothing was charged; review the new rates and confirm again.']);
                    }
                }
                $body['priceAuthorization'] = ['expectedPricing' => $fresh, 'maxChargeMillicents' => (int) $ceiling];
                break;
            case 'classify_edit':
                $body = Contract::editInput($this->field('parent_request_id'), $this->field('edit_mode'), $this->raw('edited_terms'), $this->field('sender'), $this->field('subject'), $this->raw('body'));
                if (is_string($body)) {
                    return $this->out($tab, $base + ['error' => $body]);
                }
                $quote = $this->send('POST', '/v1/classify/edit/quote', ['parentRequestId' => $body['parentRequestId'], 'editMode' => $body['editMode'], 'terms' => $body['editedTerms']], Contract::MAX_RESPONSE);
                if (is_string($quote)) {
                    return $this->out($tab, $base + ['error' => $quote]);
                }
                if (!isset($quote['editChargeMillicents']) || !ctype_digit($expected) || (string) (int) $quote['editChargeMillicents'] !== $expected) {
                    return $this->out($tab, $base + ['error' => $changed, 'edit_quote' => isset($quote['editChargeMillicents']) ? $quote : null]);
                }
                break;
            case 'insights_analyze':
                $metrics = Contract::insightsMetrics($input['m']);
                if (is_string($metrics)) {
                    return $this->out($tab, $base + ['error' => $metrics]);
                }
                $quote = $this->send('POST', '/v1/campaign-insights/quote', [], Contract::MAX_RESPONSE);
                if (is_string($quote)) {
                    return $this->out($tab, $base + ['error' => $quote]);
                }
                if (!isset($quote['priceMillicents']) || (string) (int) $quote['priceMillicents'] !== $expected) {
                    return $this->out($tab, $base + ['error' => $changed, 'insights_quote' => isset($quote['priceMillicents']) ? $quote : null]);
                }
                $locale = Contract::locale($input['locale']);
                $analysis = 'sr' . substr(hash_hmac('sha256', (string) json_encode([$metrics, $locale]), $this->secret), 0, 40);
                $body = ['analysisId' => $analysis, 'expectedPriceMillicents' => (int) $expected, 'consent' => true, 'locale' => $locale, 'metrics' => $metrics];
                break;
            case 'rewrite':
                $body = Contract::rewriteInput($input['parent_request_id'], $input['rewrite_mode'], $input['terms']);
                if (is_string($body)) {
                    return $this->out($tab, $base + ['error' => $body]);
                }
                $minimum = $this->field('expected_minimum');
                $maximum = $this->field('maximum_charge');
                if (!ctype_digit($minimum) || !ctype_digit($maximum)) {
                    return $this->out($tab, $base + ['error' => 'Enter a whole-number maximum charge.']);
                }
                $quote = $this->send('POST', '/v1/rewrite/ai-quote', $body, Contract::MAX_RESPONSE);
                if (is_string($quote)) {
                    return $this->out($tab, $base + ['error' => $quote]);
                }
                if (!isset($quote['minimumPerUniqueTermMillicents'], $quote['maximumChargeMillicents'])
                    || (string) (int) $quote['minimumPerUniqueTermMillicents'] !== $minimum
                    || (int) $maximum > (int) $quote['maximumChargeMillicents']) {
                    return $this->out($tab, $base + ['error' => $changed, 'rewrite_quote' => isset($quote['maximumChargeMillicents']) ? $quote : null]);
                }
                $body['priceAuthorization'] = ['expectedMinimumPerUniqueTermMillicents' => (int) $minimum, 'maximumChargeMillicents' => (int) $maximum];
                break;
            case 'vip_purchase':
                $plans = $this->send('GET', '/v1/vip/plans', null, Contract::MAX_RESPONSE);
                if (is_string($plans)) {
                    return $this->out($tab, $base + ['error' => $plans]);
                }
                if (!isset($plans['monthlyPriceMillicents']) || (string) (int) $plans['monthlyPriceMillicents'] !== $expected) {
                    return $this->out($tab, $base + ['error' => $changed]);
                }
                $body = ['expectedPriceMillicents' => (int) $expected];
                break;
            default:
                $vip = $this->send('GET', '/v1/vip', null, Contract::MAX_RESPONSE);
                if (is_string($vip)) {
                    return $this->out($tab, $base + ['error' => $vip]);
                }
                $field = 'ai_template' === $op ? 'regularAiTemplatePriceMillicents' : ('vip_access' === $op ? 'templateAccessPriceMillicents' : 'aiTemplatePriceMillicents');
                if (!isset($vip[$field]) || (string) (int) $vip[$field] !== $expected) {
                    return $this->out($tab, $base + ['error' => $changed]);
                }
                if ('ai_template' !== $op && empty($vip['active'])) {
                    return $this->out($tab, $base + ['error' => 'An active VIP membership is required.']);
                }
                $body = $this->paidBody($op, (int) $expected);
                if (is_string($body)) {
                    return $this->out($tab, $base + ['error' => $body]);
                }
        }
        $resolved = Contract::resolve($op);
        if (is_string($resolved) || true !== $resolved[2]) {
            return $this->out($tab, $base + ['error' => 'Operation is not in the customer API allowlist.']);
        }
        $key = self::intentKey($this->secret, $op, $resolved[1], $body);
        if (!$this->locks->claim($key, self::LOCK_TTL)) {
            return $this->out($tab, $base + ['error' => 'This identical paid request was already submitted in the last 24 hours and was not sent again. Use Recovery or change the input to start a new paid operation.']);
        }
        // Exactly one attempt. A failure keeps the guard: the server may have completed.
        $response = $this->send('POST', $resolved[1], $body, Contract::MAX_RESPONSE);
        $recovery = isset($body['recoveryId']) ? (string) $body['recoveryId'] : '';
        if (is_string($response)) {
            $msg = $response . ' It was not retried automatically. The duplicate guard stays in place for 24 hours.';
            if ('' !== $recovery) {
                $msg .= ' Keep recovery ID ' . $recovery . ' and check it on the Recovery tab after 24 hours.';
            }
            return $this->out($tab, $base + ['error' => $msg, 'recovery_id' => $recovery]);
        }
        $this->locks->complete($key);
        return $this->out($tab, ['op' => $op, 'result' => $response, 'recovery_id' => $recovery, 'notice' => 'Paid operation completed once. Copy the result now; it is not stored.']);
    }

    /**
     * Durable duplicate-guard key: credential + operation + paid intent.
     * $secret is derived from the configured API credential by every adapter,
     * so two administrators of the same credential share one key. Per-render
     * recovery IDs are excluded and edited terms are normalized like the server,
     * so re-rendered or reordered forms cannot bypass the guard.
     *
     * @param array<string, mixed> $body
     */
    public static function intentKey(string $secret, string $op, string $path, array $body): string
    {
        unset($body['recoveryId']);
        if (isset($body['editedTerms']) && is_array($body['editedTerms'])) {
            $body['editedTerms'] = Contract::normalizedTerms($body['editedTerms']);
        }
        unset($body['priceAuthorization']);
        ksort($body);
        return hash_hmac('sha256', "credential-intent\n" . $op . "\n" . $path . "\n" . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
    }

    public function hostedOrigin(): string
    {
        return $this->hostedOrigin;
    }

    /** @return array<string, mixed>|string */
    private function paidBody(string $op, int $expected)
    {
        $uuid = $this->field('recovery_id');
        if ('ai_template' === $op) {
            $content = $this->raw('content');
            if ('' === trim($content) || strlen($content) > 20000) {
                return 'Instructions must be 1 to 20000 characters.';
            }
            $category = $this->field('category');
            $subtype = $this->field('subtype');
            $body = [
                'content' => $content,
                'category' => in_array($category, ['newsletter', 'transactional', 'personal', 'business', 'custom'], true) ? $category : 'custom',
                'subtype' => in_array($subtype, self::SUBTYPES, true) ? $subtype : 'custom',
                'expectedPriceMillicents' => $expected,
            ];
            if (preg_match(Contract::UUID_PATTERN, $uuid)) {
                $body['recoveryId'] = $uuid;
            }
            return $body;
        }
        if ('vip_ai_template' === $op) {
            $prompt = $this->raw('prompt');
            if ('' === trim($prompt) || strlen($prompt) > 4000) {
                return 'Enter a prompt of at most 4000 characters.';
            }
            $body = ['prompt' => $prompt, 'expectedPriceMillicents' => $expected];
            $urls = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $this->raw('image_urls')) ?: []), 'strlen'));
            if (count($urls) > 8) {
                return 'At most eight image URLs are supported.';
            }
            foreach ($urls as $url) {
                if (strlen($url) > 2048 || 0 !== strpos(strtolower($url), 'https://') || false === filter_var($url, FILTER_VALIDATE_URL)) {
                    return 'Every image URL must be a valid HTTPS URL.';
                }
            }
            if ($urls) {
                $body['imageUrls'] = $urls;
            }
            return $body;
        }
        $design = $this->field('design_id');
        $kind = 'blank' === $this->field('source_kind') ? 'blank' : 'template';
        if ('' === $design || strlen($design) > 128) {
            return 'Enter a design ID of at most 128 characters.';
        }
        if (!preg_match(Contract::UUID_PATTERN, $uuid)) {
            return 'The recovery identifier is missing. Reload the page.';
        }
        $body = ['designId' => $design, 'sourceKind' => $kind, 'expectedPriceMillicents' => $expected, 'recoveryId' => $uuid];
        if ('template' === $kind) {
            $tid = $this->field('template_id');
            if (!preg_match(Contract::VIP_TEMPLATE, $tid)) {
                return 'Template access needs a VIP template ID from vip-01 to vip-20.';
            }
            $body['templateId'] = $tid;
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{file:array{0:string,1:string,2:string}}|array{tab:string, state:array<string, mixed>}
     */
    public function download(array $post): array
    {
        $this->post = $post;
        $op = $this->field('op');
        if ('mjml_export' === $op) {
            $body = Contract::mjmlInput($this->raw('mjml'), $this->field('filename'), $this->field('format'));
        } elseif ('native_export' === $op) {
            $body = Contract::nativeInput($this->raw('document'), $this->field('access_id'), $this->field('css_mode'), $this->field('filename'), $this->field('format'));
        } else {
            $body = 'Only builder exports can be downloaded.';
        }
        $base = ['op' => $op, 'input' => $this->collectInput()];
        if (is_string($body)) {
            return $this->out('builder', $base + ['error' => $body]);
        }
        $resolved = Contract::resolve($op);
        $response = is_array($resolved) ? $this->send('POST', $resolved[1], $body, Contract::MAX_EXPORT) : $resolved;
        if (is_string($response)) {
            return $this->out('builder', $base + ['error' => $response]);
        }
        $file = Contract::exportDownload($response);
        if (is_string($file)) {
            return $this->out('builder', $base + ['error' => $file]);
        }
        return ['file' => $file];
    }

    public static function isExport(string $op): bool
    {
        return in_array($op, ['mjml_export', 'native_export'], true);
    }

    /**
     * Price source for paid cards. Returns the response or an error message.
     *
     * @return array<string, mixed>|string
     */
    public function priceRead(string $op)
    {
        $resolved = Contract::resolve($op);
        if ('pricing' === $op && is_array($resolved)) {
            $r = $this->send('GET', $resolved[1], null, Contract::MAX_RESPONSE);
            return is_string($r) ? 'Current rates could not be verified: ' . $r : Contract::rateSchedule($r);
        }
        if (!in_array($op, ['vip', 'vip_plans'], true) || is_string($resolved)) {
            return 'Unknown price source.';
        }
        $response = $this->send('GET', $resolved[1], null, Contract::MAX_RESPONSE);
        if (is_string($response)) {
            return 'Current price could not be verified: ' . $response;
        }
        $fields = 'vip' === $op ? ['regularAiTemplatePriceMillicents', 'aiTemplatePriceMillicents', 'templateAccessPriceMillicents'] : ['monthlyPriceMillicents'];
        foreach ($fields as $f) {
            if (!isset($response[$f]) || !is_numeric($response[$f]) || $response[$f] < 0) {
                return 'The API returned incomplete pricing.';
            }
        }
        return $response;
    }

    /** @return array<string, mixed>|string */
    private function send(string $method, string $path, ?array $body, int $max)
    {
        if (!Contract::safePath($path)) {
            return 'The request is outside the customer API allowlist.';
        }
        $r = ($this->transport)($method, $path, $body, $max);
        if (!is_array($r) || !empty($r['error']) || !is_array($r['data'] ?? null)) {
            $msg = is_array($r) && !empty($r['error']) ? (string) $r['error'] : 'The SendRepute API returned an invalid response.';
            $status = is_array($r) ? (int) ($r['status'] ?? 0) : 0;
            return $status > 0 ? $msg . ' (HTTP ' . $status . ')' : $msg;
        }
        return $r['data'];
    }

    /** @return array<string, mixed> */
    private function collectInput(): array
    {
        $m = [];
        if (isset($this->post['m']) && is_array($this->post['m'])) {
            foreach ($this->post['m'] as $k => $v) {
                if (is_string($k) && is_string($v) && preg_match('/^[A-Za-z]{1,32}$/D', $k)) {
                    $m[$k] = trim($v);
                }
            }
        }
        return [
            'm' => $m,
            'locale' => $this->field('locale'),
            'parent_request_id' => $this->field('parent_request_id'),
            'rewrite_mode' => $this->field('rewrite_mode'),
            'terms' => $this->raw('terms'),
            'mjml' => $this->raw('mjml'),
            'document' => $this->raw('document'),
            'access_id' => $this->field('access_id'),
            'filename' => $this->field('filename'),
            'sender' => $this->field('sender'),
            'subject' => $this->field('subject'),
            'body' => $this->raw('body'),
            'plain' => $this->raw('plain'),
            'html' => $this->raw('html'),
            'model' => $this->field('model'),
            'edit_mode' => $this->field('edit_mode'),
            'edited_terms' => $this->raw('edited_terms'),
        ];
    }

    private function field(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private function raw(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    /**
     * @param array<string, mixed> $state
     * @return array{tab:string, state:array<string, mixed>}
     */
    private function out(string $tab, array $state): array
    {
        return ['tab' => $tab, 'state' => $state];
    }
}
