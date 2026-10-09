<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * Escaped HTML for the console. All dynamic values pass through e().
 * No scripts, external fonts or remote assets. Results render once and are
 * never stored; HTML previews use a fully sandboxed srcdoc frame.
 */
final class Renderer
{
    /** @var array<string, mixed> */
    private array $ctx;
    /** @var array<string, array<string, mixed>|string> */
    private array $priceCache = [];

    /**
     * @param array<string, mixed> $ctx action (string), hidden (array name=>value),
     *   tabUrl (callable string=>string), price (callable string=>array|string),
     *   platform (string), primary/secondary (button classes), uuid (callable): string
     */
    public function __construct(array $ctx)
    {
        $this->ctx = $ctx + [
            'platform' => 'this site',
            'primary' => 'sr-btn sr-btn-primary',
            'secondary' => 'sr-btn',
            'uuid' => [Contract::class, 'uuid4'],
            'hostedOrigin' => '',
        ];
    }

    /** @param mixed $value */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $state */
    public function page(string $tab, array $state = []): string
    {
        $tabs = Service::tabs();
        $tab = isset($tabs[$tab]) ? $tab : 'account';
        $h = '<div class="sr-console">' . self::styles();
        $h .= '<header class="sr-head"><div><p class="sr-eyebrow">SendRepute customer API</p><h1>Service console</h1></div>'
            . '<p class="sr-head-note">Free reads load on request. Paid operations show the current server price and run once, only after you confirm. Nothing here sends email or changes ' . self::e($this->ctx['platform']) . ' content.</p></header>';
        $h .= '<nav class="sr-tabs" aria-label="Console sections">';
        foreach ($tabs as $key => $label) {
            $h .= '<a class="sr-tab' . ($key === $tab ? ' is-active" aria-current="page' : '') . '" href="' . self::e(($this->ctx['tabUrl'])($key)) . '">' . self::e($label) . '</a>';
        }
        $h .= '</nav>';
        if (!empty($state['error'])) {
            $h .= '<div class="sr-alert is-error" role="alert"><strong>Not completed.</strong> ' . self::e($state['error']) . '</div>';
        } elseif (!empty($state['notice'])) {
            $h .= '<div class="sr-alert is-ok" role="status">' . self::e($state['notice']) . '</div>';
        }
        if (!empty($state['recovery_id'])) {
            $h .= '<div class="sr-alert is-info">Recovery ID for this paid attempt: <code>' . self::e($state['recovery_id']) . '</code>. Keep it; it is the only way to look up the purchase evidence later.</div>';
        }
        $h .= '<div class="sr-body">';
        $method = 'tab' . ucfirst($tab);
        $h .= $this->$method($state);
        $h .= '</div>' . $this->result($state) . '</div>';
        return $h;
    }

    /** @param array<string, mixed> $s */
    private function tabAccount(array $s): string
    {
        $h = $this->card('Account overview', 'Profile, wallet balance and key scopes. Requires account:read.')
            . $this->reads(['account' => 'Load account', 'usage' => 'Usage and limits', 'models' => 'Classifier models', 'referrals' => 'Referrals']) . '</section>';
        $h .= $this->card('Ledger', 'Wallet usage or payment entries. Requires billing:read.') . $this->form('ledger')
            . '<div class="sr-grid">' . $this->select('kind', 'Entries', ['usage' => 'Usage', 'payments' => 'Payments'])
            . '<label><span>Limit</span><input type="number" name="limit" min="1" max="50" value="20"></label>'
            . '<label><span>Offset</span><input type="number" name="offset" min="0" value="0"></label></div>'
            . $this->submit('Load ledger') . '</form></section>';
        return $h;
    }

    /** @param array<string, mixed> $s */
    private function tabBilling(array $s): string
    {
        $h = $this->card('Payments', 'Invoices, methods and deposit offers. Requires billing:read.')
            . $this->reads(['invoices' => 'Invoices', 'payment_methods' => 'Payment methods', 'deposit_offer' => 'Deposit offer']) . '</section>';
        $h .= $this->card('Create a deposit invoice', 'Creates a payable invoice. Creating it does not charge the wallet; payment happens on SendRepute. Requires billing:write.')
            . $this->form('invoice_create') . $this->hidden('purpose', 'deposit') . '<div class="sr-grid"><label><span>Amount (USD cents, 1000 to 5000000)</span><input type="number" name="amount_cents" min="1000" max="5000000" step="1" required></label>'
            . '<label><span>Deposit offer ID (optional)</span><input name="offer_id" maxlength="128"></label></div>'
            . $this->check('confirm', 'Create this invoice on my SendRepute account.') . $this->submit('Create invoice') . '</form></section>';
        $h .= $this->fragment('vip_invoice');
        $h .= $this->card('Cancel an invoice', 'Cancels an unpaid invoice owned by this account.') . $this->form('invoice_cancel')
            . '<div class="sr-grid"><label><span>Invoice ID</span><input name="id" maxlength="200" pattern="[A-Za-z0-9_\-]+" required></label></div>'
            . $this->check('confirm', 'Cancel this invoice.') . $this->submit('Cancel invoice', false) . '</form></section>';
        $h .= $this->card('Refresh payment status', 'Asks the server to re-check pending payments. No charge.') . $this->form('payments_refresh') . $this->submit('Refresh payments', false) . '</form></section>';
        return $h;
    }

    /** @param array<string, mixed> $s */
    private function tabCatalog(array $s): string
    {
        $h = $this->card('Prices and membership', 'Current tariffs and VIP state. Requires catalog:read and vip:read.')
            . $this->reads(['pricing' => 'Pricing', 'vip_plans' => 'VIP plans', 'vip' => 'VIP status']) . '</section>';
        $h .= $this->card('Template gallery', 'Standard recipes and native VIP templates.')
            . $this->reads(['templates' => 'Standard templates', 'vip_templates' => 'VIP templates'])
            . '<div class="sr-split">' . $this->form('template') . '<label><span>Standard template ID</span><input name="id" maxlength="200" required></label>' . $this->submit('Open', false) . '</form>'
            . $this->form('vip_template') . '<label><span>VIP template ID (vip-01 to vip-20)</span><input name="id" pattern="vip-(0[1-9]|1[0-9]|20)" required></label>' . $this->submit('Open', false) . '</form></div></section>';
        $plans = $this->price('vip_plans');
        $h .= $this->card('Purchase VIP membership', 'Debits one month of membership from the wallet. Manual renewal only. Requires vip:purchase.', true);
        if (is_string($plans)) {
            $h .= $this->unavailable($plans);
        } else {
            $p = (int) $plans['monthlyPriceMillicents'];
            $h .= $this->chip($p, 'per month') . $this->form('vip_purchase') . $this->hidden('expected_price', (string) $p)
                . $this->consent('I authorize a wallet debit of exactly ' . Contract::money($p) . ' for one VIP month. A different server price will be refused.')
                . $this->submit('Purchase once') . '</form>';
        }
        return $h . '</section>';
    }

    /** @param array<string, mixed> $s */
    private function tabClassify(array $s): string
    {
        $in = is_array($s['input'] ?? null) ? $s['input'] : [];
        $v = static fn (string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
        $rates = $this->price('pricing');
        $h = $this->card('Classify one email', 'Manual paid classification of sender display name, subject and body, with optional displayed text/plain and text/html alternatives. Separate from the automatic mail hooks, which keep their own saved consent. An identical request within 24 hours replays the receipt without a new debit. Requires classify.', true);
        if (is_string($rates)) {
            $h .= $this->unavailable($rates);
        } else {
            $h .= '<dl class="sr-facts">' . $this->fact('Base per classification', Contract::money($rates['classificationBaseMillicents']))
                . $this->fact('Unique flagged terms included', (string) $rates['includedUniqueTerms'])
                . $this->fact('Each additional term', Contract::money($rates['additionalTermMillicents']))
                . $this->fact('Maximum per classification', Contract::money($rates['maximumClassificationMillicents'])) . '</dl>';
            $h .= $this->form('classify');
            foreach ($rates as $f => $n) {
                $h .= $this->hidden('rate_' . $f, (string) $n);
            }
            $models = ['' => 'Account default'];
            foreach (Contract::MODELS as $m) {
                $models[$m] = ucfirst($m);
            }
            $h .= '<div class="sr-grid"><label><span>Sender display name</span><input name="sender" maxlength="320" required value="' . self::e($v('sender')) . '"></label>'
                . '<label><span>Subject</span><input name="subject" maxlength="998" required value="' . self::e($v('subject')) . '"></label>'
                . $this->select('model', 'Model', $models, $v('model')) . '</div>'
                . '<label class="sr-block"><span>Body (compatibility text)</span><textarea name="body" rows="6" required>' . self::e($v('body')) . '</textarea></label>'
                . '<div class="sr-grid"><label class="sr-block"><span>Displayed text/plain alternative (optional)</span><textarea name="plain" rows="3">' . self::e($v('plain')) . '</textarea></label>'
                . '<label class="sr-block"><span>Displayed text/html alternative (optional, never rendered here)</span><textarea name="html" rows="3" class="code">' . self::e($v('html')) . '</textarea></label></div>'
                . '<label class="sr-inline"><span>Per-request ceiling (millicents)</span><input type="number" name="max_charge" min="0" step="1" required value="' . (int) $rates['maximumClassificationMillicents'] . '"></label>'
                . $this->consent('I have permission to send this content and authorize a charge computed from the rate schedule above, capped at the ceiling I entered. Changed rates or a higher actual charge are refused before any debit.')
                . $this->submit('Classify once') . '</form>';
        }
        $h .= '</section>';
        $q = is_array($s['edit_quote'] ?? null) ? $s['edit_quote'] : null;
        $h .= $this->card('Manual or remove-all edit', 'Quote the authoritative edit charge for changed flagged terms of an owned classification, then reclassify the complete changed email. Not an AI rewrite. The returned requestId can be the parent of the next edit. Requires classify.', true)
            . $this->form($q ? 'classify_edit' : 'classify_edit_quote')
            . '<div class="sr-grid"><label><span>Parent classification request ID</span><input name="parent_request_id" maxlength="128" pattern="[A-Za-z0-9_\-]{16,128}" required value="' . self::e($v('parent_request_id')) . '"></label>'
            . $this->select('edit_mode', 'Edit mode', ['manual' => 'Manual (per edited term)', 'remove_all' => 'Remove all flagged terms'], $v('edit_mode')) . '</div>'
            . '<label class="sr-block"><span>Edited terms, one per line (up to 10000)</span><textarea name="edited_terms" rows="4">' . self::e($v('edited_terms')) . '</textarea></label>';
        if ($q) {
            $c = (int) $q['editChargeMillicents'];
            $h .= '<dl class="sr-facts">' . $this->fact('Validated terms', (string) (int) $q['validatedTermCount'])
                . $this->fact('Edit charge', Contract::money($c)) . $this->fact('Original analysis paid', Contract::money($q['originalAnalysisPaidMillicents'] ?? 0))
                . $this->fact('Balance after', Contract::money($q['balanceAfterMillicents'])) . '</dl>'
                . $this->hidden('expected_price', (string) $c)
                . '<div class="sr-grid"><label><span>Changed sender</span><input name="sender" maxlength="320" required value="' . self::e($v('sender')) . '"></label>'
                . '<label><span>Changed subject</span><input name="subject" maxlength="998" required value="' . self::e($v('subject')) . '"></label></div>'
                . '<label class="sr-block"><span>Complete changed body</span><textarea name="body" rows="6" required>' . self::e($v('body')) . '</textarea></label>'
                . $this->consent('I authorize this paid edit at the server rate in effect when it is processed. The current estimate is ' . Contract::money($c) . '. The console checks the quote again before sending, but this API does not support a guaranteed price ceiling.')
                . $this->submit('Reclassify once');
        } else {
            $h .= '<p class="sr-muted">Step 1 of 2: get the authoritative edit quote. No charge.</p>' . $this->submit('Get edit quote', false);
        }
        return $h . '</form></section>' . $this->unsupportedCard();
    }

    /** @param array<string, mixed> $s */
    private function tabAi(array $s): string
    {
        $in = is_array($s['input'] ?? null) ? $s['input'] : [];
        $m = is_array($in['m'] ?? null) ? $in['m'] : [];
        $q = is_array($s['insights_quote'] ?? null) ? $s['insights_quote'] : null;
        $h = $this->card('Campaign insights', 'AI guidance from aggregate delivery metrics. Send counts and rates only: never campaign IDs, addresses or message content. Retained by SendRepute for 30 days. Requires ai:generate.', true);
        $h .= $this->form($q ? 'insights_analyze' : 'insights_quote') . '<div class="sr-grid sr-grid-4">';
        foreach (['sent', 'delivered', 'bounced', 'hardBounced', 'softBounced', 'deferred', 'failed', 'opened', 'uniqueOpened', 'clicked', 'uniqueClicked', 'unsubscribed', 'complaints', 'accepted', 'pending'] as $k) {
            $h .= '<label><span>' . self::e(self::humanize($k)) . '</span><input type="number" min="0" max="1000000000" step="1" name="m[' . $k . ']" value="' . self::e($m[$k] ?? '') . '"></label>';
        }
        foreach (['deliveryRate', 'openRate', 'clickRate', 'bounceRate', 'unsubscribeRate', 'complaintRate'] as $k) {
            $h .= '<label><span>' . self::e(self::humanize($k)) . ' (%)</span><input type="number" min="0" max="100" step="0.01" name="m[' . $k . ']" value="' . self::e($m[$k] ?? '') . '"></label>';
        }
        $h .= '</div>' . $this->select('locale', 'Output language', ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'hi' => 'Hindi', 'ar' => 'Arabic', 'id' => 'Indonesian', 'tr' => 'Turkish', 'zh' => 'Chinese', 'vi' => 'Vietnamese'], (string) ($in['locale'] ?? 'en'), 'sr-inline');
        if ($q) {
            $p = (int) $q['priceMillicents'];
            $h .= $this->chip($p, 'per analysis') . $this->hidden('expected_price', (string) $p)
                . $this->consent('I have permission to send these aggregate metrics and authorize exactly ' . Contract::money($p) . '. Resubmitting identical metrics replays the same analysis without a second charge.')
                . $this->submit('Analyze once');
        } else {
            $h .= '<p class="sr-muted">Step 1 of 2: get the current price. No charge.</p>' . $this->submit('Get price', false);
        }
        $h .= '</form></section>';

        $rq = is_array($s['rewrite_quote'] ?? null) ? $s['rewrite_quote'] : null;
        $h .= $this->card('AI rewrite of a classified message', 'Rewrites flagged terms from an existing classification receipt. Variable price: quote first, then authorize a maximum. Requires rewrite.', true);
        $h .= $this->form($rq ? 'rewrite' : 'rewrite_quote') . '<div class="sr-grid">'
            . '<label><span>Parent classification request ID</span><input name="parent_request_id" maxlength="128" pattern="[A-Za-z0-9_\-]{16,128}" required value="' . self::e($in['parent_request_id'] ?? '') . '"></label>'
            . $this->select('rewrite_mode', 'Mode', ['single' => 'Selected terms', 'all' => 'All supplied terms (Rewrite All)'], (string) ($in['rewrite_mode'] ?? 'single')) . '</div>'
            . '<label class="sr-block"><span>Terms, one per line (1 to 1000)</span><textarea name="terms" rows="4" required>' . self::e($in['terms'] ?? '') . '</textarea></label>';
        if ($rq) {
            $max = (int) $rq['maximumChargeMillicents'];
            $h .= '<dl class="sr-facts">' . $this->fact('Unique terms', (int) $rq['uniqueTermCount']) . $this->fact('Minimum per term', Contract::money($rq['minimumPerUniqueTermMillicents']))
                . $this->fact('Minimum charge', Contract::money($rq['minimumChargeMillicents'])) . $this->fact('Quoted maximum', Contract::money($max))
                . $this->fact('Balance after maximum', Contract::money($rq['balanceAfterMaximumMillicents'])) . '</dl>'
                . $this->hidden('expected_minimum', (string) (int) $rq['minimumPerUniqueTermMillicents'])
                . '<label class="sr-inline"><span>Authorized maximum charge (millicents)</span><input type="number" name="maximum_charge" min="0" max="' . $max . '" step="1" value="' . $max . '" required></label>'
                . $this->consent('I have permission to send this classified content and authorize a variable charge up to the maximum entered above. A changed minimum price will be refused before any debit.')
                . $this->submit('Rewrite once');
        } else {
            $h .= '<p class="sr-muted">Step 1 of 2: get an authoritative quote. No charge.</p>' . $this->submit('Get quote', false);
        }
        $h .= '</form><div class="sr-divider"></div>' . $this->form('rewrite_result')
            . '<div class="sr-split"><label><span>Retrieve a rewrite result by request ID</span><input name="id" pattern="[A-Za-z0-9_\-]{16,128}" required></label>' . $this->submit('Retrieve', false) . '</div></form></section>';

        $h .= $this->card('Finalize a Rewrite All draft', 'One free final classification per completed, charged Rewrite All receipt. The sender, subject and visible copy must match the paid rewrite. The first final draft is immutable; identical retries replay it. Requires rewrite.')
            . $this->form('rewrite_finalize') . '<div class="sr-grid">'
            . '<label><span>Rewrite All request ID</span><input name="final_request_id" maxlength="128" pattern="[A-Za-z0-9_\-]{16,128}" required></label>'
            . '<label><span>Sender</span><input name="final_sender" maxlength="320" required></label></div>'
            . '<label class="sr-block"><span>Subject</span><input name="final_subject" maxlength="998" required></label>'
            . '<label class="sr-block"><span>Final visible body</span><textarea name="final_body" rows="6" required></textarea></label>'
            . $this->submit('Finalize draft', false) . '</form></section>';

        $vip = $this->price('vip');
        $h .= $this->card('AI email template', 'Generates a template from your instructions. The result is returned for copying; nothing is saved or sent. Requires ai:generate.', true);
        if (is_string($vip)) {
            $h .= $this->unavailable($vip);
        } else {
            $p = (int) $vip['regularAiTemplatePriceMillicents'];
            $cats = [];
            foreach (['custom', 'newsletter', 'transactional', 'personal', 'business'] as $c) {
                $cats[$c] = ucfirst($c);
            }
            $subs = [];
            foreach (Service::subtypes() as $st) {
                $subs[$st] = self::humanize($st);
            }
            $h .= $this->chip($p, 'per generation') . $this->form('ai_template') . $this->hidden('expected_price', (string) $p) . $this->recovery()
                . '<div class="sr-grid">' . $this->select('category', 'Category', $cats) . $this->select('subtype', 'Subtype', $subs) . '</div>'
                . '<label class="sr-block"><span>Instructions and content</span><textarea name="content" rows="5" required maxlength="20000"></textarea></label>'
                . $this->consent('I have permission to send these instructions and authorize exactly ' . Contract::money($p) . '.') . $this->submit('Generate once') . '</form>';
        }
        $h .= '</section>' . $this->card('Native VIP email template', 'VIP-only AI generation. Image URLs are passed to SendRepute as references and are not fetched by this site.', true);
        if (is_string($vip)) {
            $h .= $this->unavailable($vip);
        } elseif (empty($vip['active'])) {
            $h .= $this->unavailable('This account does not have an active VIP membership.');
        } else {
            $p = (int) $vip['aiTemplatePriceMillicents'];
            $h .= $this->chip($p, 'per generation') . $this->form('vip_ai_template') . $this->hidden('expected_price', (string) $p)
                . '<label class="sr-block"><span>Prompt (maximum 4000 characters)</span><textarea name="prompt" rows="5" maxlength="4000" required></textarea></label>'
                . '<label class="sr-block"><span>HTTPS image URLs, one per line (maximum 8, optional)</span><textarea name="image_urls" rows="3"></textarea></label>'
                . $this->consent('I have permission to send this prompt and these image references and authorize exactly ' . Contract::money($p) . '.') . $this->submit('Generate once') . '</form>';
        }
        return $h . '</section>';
    }

    /** @param array<string, mixed> $s */
    private function tabBuilder(array $s): string
    {
        $in = is_array($s['input'] ?? null) ? $s['input'] : [];
        $h = $this->card('Standard MJML builder', 'Free, stateless import, validation, compilation and export. Nothing is saved; no remote assets are fetched. Exports download as attachments. Requires builder:write.')
            . $this->form('', 'sr-builder')
            . '<label class="sr-block"><span>MJML document</span><textarea name="mjml" rows="12" class="code" required spellcheck="false">' . self::e($in['mjml'] ?? '') . '</textarea></label>'
            . '<div class="sr-grid"><label><span>Filename (optional)</span><input name="filename" maxlength="160" value="' . self::e($in['filename'] ?? '') . '"></label>'
            . $this->select('format', 'Export format', ['html' => 'HTML', 'mjml' => 'MJML', 'zip' => 'ZIP (MJML and HTML)']) . '</div>'
            . $this->opButtons(['mjml_import' => 'Import', 'mjml_validate' => 'Validate', 'mjml_compile' => 'Compile'], 'mjml_export') . '</form></section>';
        $h .= $this->card('Open builder access', 'Standard builder opening is free. Requires builder:write.') . $this->form('builder_access')
            . '<div class="sr-grid"><label><span>Design ID</span><input name="design_id" maxlength="200" required></label>'
            . $this->select('source_kind', 'Source', ['blank' => 'Blank', 'curated' => 'Curated template', 'saved' => 'Saved design'])
            . '<label><span>Template ID (curated only)</span><input name="template_id" maxlength="200"></label></div>'
            . $this->submit('Open access', false) . '</form>' . $this->accessTools('builder_access_get', 'builder_access_close') . '</section>';
        $vip = $this->price('vip');
        $h .= $this->card('Native VIP builder access', 'Paid VIP entitlement for native templates. Requires vip:builder and an active membership.', true);
        if (is_string($vip)) {
            $h .= $this->unavailable($vip);
        } elseif (empty($vip['active'])) {
            $h .= $this->unavailable('This account does not have an active VIP membership.');
        } else {
            $p = (int) $vip['templateAccessPriceMillicents'];
            $h .= $this->chip($p, 'per access') . $this->form('vip_access') . $this->hidden('expected_price', (string) $p) . $this->recovery()
                . '<div class="sr-grid"><label><span>Design ID</span><input name="design_id" maxlength="128" required></label>'
                . $this->select('source_kind', 'Source', ['template' => 'VIP template', 'blank' => 'Blank'])
                . '<label><span>VIP template ID</span><input name="template_id" pattern="vip-(0[1-9]|1[0-9]|20)" placeholder="vip-01"></label></div>'
                . $this->consent('I authorize exactly ' . Contract::money($p) . ' for this VIP builder access.') . $this->submit('Open once') . '</form>';
        }
        $h .= $this->accessTools('vip_access_get', 'vip_access_close') . '</section>';
        $h .= $this->card('Native VIP document processing', 'Stateless processing against an open VIP access. Free; nothing is saved.') . $this->form('', 'sr-builder')
            . '<label class="sr-block"><span>Native document JSON</span><textarea name="document" rows="10" class="code" required spellcheck="false">' . self::e($in['document'] ?? '') . '</textarea></label>'
            . '<div class="sr-grid sr-grid-4"><label><span>VIP access ID</span><input name="access_id" maxlength="200" required value="' . self::e($in['access_id'] ?? '') . '"></label>'
            . $this->select('css_mode', 'CSS mode', ['hybrid' => 'Hybrid', 'inline' => 'Inline', 'head' => 'Head'])
            . '<label><span>Filename (optional)</span><input name="filename" maxlength="160"></label>'
            . $this->select('format', 'Export format', ['html' => 'HTML', 'zip' => 'ZIP (three variants)']) . '</div>'
            . $this->opButtons(['native_import' => 'Import', 'native_validate' => 'Validate', 'native_compile' => 'Compile'], 'native_export') . '</form></section>';
        $h .= $this->hostedCard($s);
        return $h;
    }

    /** @param array<string, mixed> $s */
    private function hostedCard(array $s): string
    {
        $origin = (string) $this->ctx['hostedOrigin'];
        $h = $this->card('Hosted editor handoff', 'Creates a short-lived one-time SendRepute editor launch bound to this credential, a fresh nonce and the operator-configured return origin. Never purchases access. Requires builder:write (VIP mode also vip:builder and an open VIP access).');
        if ('' === $origin) {
            $h .= $this->unavailable('Disabled. An operator must set SENDREPUTE_HOSTED_RETURN_ORIGIN to the canonical HTTPS origin that receives the editor return before handoffs can be created.');
        } else {
            $h .= '<p class="sr-muted">Return origin: <code>' . self::e($origin) . '</code></p>' . $this->form('hosted_handoff')
                . '<div class="sr-grid">' . $this->select('handoff_mode', 'Mode', ['standard' => 'Standard MJML', 'vip' => 'VIP native'])
                . '<label><span>VIP access ID (VIP only)</span><input name="access_id" maxlength="200"></label></div>'
                . '<label class="sr-block"><span>Initial MJML (standard, optional) or native document JSON (VIP)</span><textarea name="mjml" rows="5" class="code"></textarea></label>'
                . $this->submit('Create launch link', false) . '</form>';
        }
        if (is_string($s['launch_url'] ?? null)) {
            $h .= '<p class="sr-submit"><a class="' . self::e($this->ctx['primary']) . '" href="' . self::e($s['launch_url']) . '" target="_blank" rel="noopener noreferrer">Open SendRepute editor</a></p>';
        }
        return $h . '</section>';
    }

    /**
     * Embeddable fragments for hosts with their own page chrome (WordPress console).
     *
     * @param array<string, mixed> $s
     */
    public function fragment(string $name, array $s = []): string
    {
        switch ($name) {
            case 'classify':
                return $this->tabClassify($s);
            case 'hosted':
                return $this->hostedCard($s);
            case 'vip_invoice':
                $plans = $this->price('vip_plans');
                $h = $this->card('Create a VIP membership invoice', 'Invoice for one VIP month at the current server price, paid outside the wallet. Creating it charges nothing. Requires billing:write.');
                if (is_string($plans)) {
                    return $h . $this->unavailable($plans) . '</section>';
                }
                $p = (int) $plans['monthlyPriceMillicents'];
                return $h . $this->chip($p, 'per VIP month') . $this->form('invoice_create') . $this->hidden('purpose', 'vip') . $this->hidden('expected_price', (string) $p)
                    . $this->check('confirm', 'Create a VIP invoice for exactly ' . Contract::money($p) . '. A different server price is refused.') . $this->submit('Create VIP invoice', false) . '</form></section>';
        }
        return '';
    }

    /** @param array<string, mixed> $s */
    private function tabRecovery(array $s): string
    {
        $h = $this->card('Purchase identity', 'The account and credential that paid attempts are bound to. Recovery must use the same credential.')
            . $this->reads(['paid_identity' => 'Show identity']) . '</section>';
        $h .= $this->card('Look up a paid result', 'Permanent purchase evidence for a recovery ID, available after the ordinary 24-hour receipt window. A failed status, timeout or 404 does not prove that nothing was charged; only settlement.kind is financial evidence.')
            . $this->form('paid_result') . '<div class="sr-split"><label><span>Recovery ID</span><input name="id" pattern="[0-9a-f\-]{36}" required value="' . self::e($s['recovery_id'] ?? '') . '"></label>' . $this->submit('Look up', false) . '</div></form></section>';
        $h .= $this->card('Resolve a paid attempt', 'Explicit, audited financial resolution with the original credential. It fences an uncharged attempt or refunds a proven pending VIP reservation; existing paid output is recovered, not refunded. Never use it because of a timeout or a generic failure alone.', true)
            . $this->form('paid_resolve') . '<div class="sr-grid"><label><span>Recovery ID</span><input name="id" pattern="[0-9a-f\-]{36}" required></label>'
            . '<label><span>Type RESOLVE to confirm</span><input name="typed" pattern="RESOLVE" required autocomplete="off"></label></div>'
            . '<label class="sr-block"><span>Reason (1 to 500 characters, recorded in the audit log)</span><textarea name="reason" rows="3" maxlength="500" required></textarea></label>'
            . $this->submit('Resolve once') . '</form></section>';
        return $h;
    }

    private function unsupportedCard(): string
    {
        $h = $this->card('Deprecated, not offered', 'Every other customer contract operation is available in this console. Nothing outside the allowlist can be called.') . '<dl class="sr-facts sr-facts-wide">';
        foreach (Contract::unsupported() as $op => $why) {
            $h .= $this->fact($op, $why);
        }
        return $h . '</dl></section>';
    }

    /** @param array<string, mixed> $state */
    private function result(array $state): string
    {
        if (!is_array($state['result'] ?? null)) {
            return '';
        }
        $r = $state['result'];
        $h = '<section class="sr-result" aria-label="Result"><header><h2>Result</h2><span class="sr-muted">Not stored. Leaving this page discards it.</span></header>';
        $facts = '';
        foreach ($r as $k => $v) {
            if ((is_scalar($v) || null === $v) && 'html' !== $k && (!is_string($v) || strlen($v) < 300)) {
                $d = is_bool($v) ? ($v ? 'yes' : 'no') : (null === $v ? '-' : (string) $v);
                if (is_numeric($v) && preg_match('/Millicents$/', (string) $k)) {
                    $d = Contract::money($v);
                }
                $facts .= $this->fact(self::humanize((string) $k), $d);
            }
        }
        if ('' !== $facts) {
            $h .= '<dl class="sr-facts">' . $facts . '</dl>';
        }
        if (isset($r['html']) && is_string($r['html'])) {
            $h .= '<h3>Preview (sandboxed, scripts disabled)</h3><iframe class="sr-preview" sandbox="" referrerpolicy="no-referrer" srcdoc="' . self::e($r['html']) . '" title="Email preview"></iframe>';
        }
        $json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $h . '<h3>Full response</h3><textarea class="sr-json code" rows="18" readonly>' . self::e(false === $json ? '' : $json) . '</textarea></section>';
    }

    /** @return array<string, mixed>|string */
    private function price(string $op)
    {
        if (!isset($this->priceCache[$op])) {
            $r = ($this->ctx['price'])($op);
            $this->priceCache[$op] = is_array($r) || is_string($r) ? $r : 'Current price could not be verified.';
        }
        return $this->priceCache[$op];
    }

    private function card(string $title, string $desc, bool $paid = false): string
    {
        return '<section class="sr-card' . ($paid ? ' is-paid' : '') . '"><header><h2>' . self::e($title) . '</h2><span class="sr-badge' . ($paid ? ' is-paid">Paid' : '">No charge') . '</span></header><p class="sr-desc">' . self::e($desc) . '</p>';
    }

    private function form(string $op, string $class = ''): string
    {
        $h = '<form method="post" class="sr-form ' . self::e($class) . '" action="' . self::e($this->ctx['action']) . '">';
        foreach ((array) $this->ctx['hidden'] as $n => $v) {
            $h .= $this->hidden((string) $n, (string) $v);
        }
        return $h . ('' !== $op ? $this->hidden('op', $op) : '');
    }

    /** @param array<string, string> $ops */
    private function reads(array $ops): string
    {
        $h = $this->form('') . '<div class="sr-actions">';
        foreach ($ops as $op => $label) {
            $h .= '<button type="submit" class="' . self::e($this->ctx['secondary']) . '" name="op" value="' . self::e($op) . '">' . self::e($label) . '</button>';
        }
        return $h . '</div></form>';
    }

    /** @param array<string, string> $ops */
    private function opButtons(array $ops, string $export): string
    {
        $h = '<div class="sr-actions">';
        foreach ($ops as $op => $label) {
            $h .= '<button type="submit" class="' . self::e($this->ctx['secondary']) . '" name="op" value="' . self::e($op) . '">' . self::e($label) . '</button>';
        }
        return $h . '<button type="submit" class="' . self::e($this->ctx['primary']) . '" name="op" value="' . self::e($export) . '">Export and download</button></div>';
    }

    private function accessTools(string $get, string $close): string
    {
        return '<div class="sr-divider"></div>' . $this->form('') . '<div class="sr-split"><label><span>Existing access ID</span><input name="id" maxlength="200" pattern="[A-Za-z0-9_\-]+" required></label>'
            . '<div class="sr-actions"><button type="submit" class="' . self::e($this->ctx['secondary']) . '" name="op" value="' . self::e($get) . '">Check status</button>'
            . '<button type="submit" class="' . self::e($this->ctx['secondary']) . '" name="op" value="' . self::e($close) . '">Close access</button></div></div>'
            . $this->check('confirm', 'Confirm closing when using Close access.', false) . '</form>';
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private function recovery(): string
    {
        $id = (string) ($this->ctx['uuid'])();
        return $this->hidden('recovery_id', $id) . '<p class="sr-muted">Recovery ID for this attempt: <code>' . self::e($id) . '</code></p>';
    }

    private function submit(string $label, bool $primary = true): string
    {
        return '<p class="sr-submit"><button type="submit" class="' . self::e($this->ctx[$primary ? 'primary' : 'secondary']) . '">' . self::e($label) . '</button></p>';
    }

    private function consent(string $label): string
    {
        return '<label class="sr-consent"><input type="checkbox" name="paid_consent" value="1" required> <span>' . self::e($label) . '</span></label>';
    }

    private function check(string $name, string $label, bool $required = true): string
    {
        return '<label class="sr-check"><input type="checkbox" name="' . self::e($name) . '" value="1"' . ($required ? ' required' : '') . '> ' . self::e($label) . '</label>';
    }

    private function chip(int $millicents, string $unit): string
    {
        return '<p class="sr-price"><span class="sr-price-label">Current server price</span><strong>' . self::e(Contract::money($millicents)) . '</strong><span class="sr-muted">' . self::e($unit . ' (' . $millicents . ' millicents)') . '</span></p>';
    }

    private function unavailable(string $message): string
    {
        return '<p class="sr-unavailable">' . self::e($message) . '</p>';
    }

    /** @param int|string $v */
    private function fact(string $k, $v): string
    {
        return '<div><dt>' . self::e($k) . '</dt><dd>' . self::e((string) $v) . '</dd></div>';
    }

    /** @param array<string, string> $options */
    private function select(string $name, string $label, array $options, string $current = '', string $class = ''): string
    {
        $h = '<label' . ('' !== $class ? ' class="' . self::e($class) . '"' : '') . '><span>' . self::e($label) . '</span><select name="' . self::e($name) . '">';
        foreach ($options as $v => $text) {
            $h .= '<option value="' . self::e($v) . '"' . ((string) $v === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
        }
        return $h . '</select></label>';
    }

    public static function humanize(string $key): string
    {
        $key = (string) preg_replace('/Millicents$/', '', $key);
        $key = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace(['-', '_'], ' ', $key));
        return ucfirst(strtolower($key));
    }

    public static function styles(): string
    {
        return '<style>
.sr-console{--sr-ink:#14313a;--sr-ink-2:#3d5a62;--sr-line:#d9dfdc;--sr-surface:#fbfaf6;--sr-card:#fffefb;--sr-accent:#b8642a;--sr-accent-soft:#f6e9dc;--sr-ok:#2f6b4f;--sr-err:#9a2f2f;max-width:1180px;color:var(--sr-ink);font-size:14px;line-height:1.5}
.sr-console *{box-sizing:border-box}
.sr-head{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;padding:22px 0 18px;border-bottom:2px solid var(--sr-ink)}
.sr-head h1{font-size:30px;line-height:1.1;margin:0;padding:0;font-weight:600;letter-spacing:-.01em;color:var(--sr-ink)}
.sr-eyebrow{margin:0 0 6px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--sr-accent);font-weight:600}
.sr-head-note{max-width:460px;margin:0;color:var(--sr-ink-2);font-size:13px}
.sr-tabs{display:flex;gap:2px;margin:18px 0 20px;border-bottom:1px solid var(--sr-line);overflow-x:auto}
.sr-tab{padding:10px 16px;text-decoration:none;color:var(--sr-ink-2);font-weight:500;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.sr-tab:hover,.sr-tab:focus{color:var(--sr-ink);text-decoration:none}
.sr-tab.is-active{color:var(--sr-ink);border-bottom-color:var(--sr-accent)}
.sr-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));gap:18px;align-items:start}
.sr-card{background:var(--sr-card);border:1px solid var(--sr-line);border-radius:6px;padding:18px 20px 8px}
.sr-card.is-paid{border-top:3px solid var(--sr-accent)}
.sr-card header,.sr-result header{display:flex;justify-content:space-between;align-items:center;gap:12px}
.sr-card h2{font-size:15px;margin:0;color:var(--sr-ink)}
.sr-badge{font-size:11px;padding:2px 8px;border-radius:999px;background:#e8efec;color:var(--sr-ok);font-weight:600;white-space:nowrap}
.sr-badge.is-paid{background:var(--sr-accent-soft);color:var(--sr-accent)}
.sr-desc{color:var(--sr-ink-2);margin:6px 0 14px}
.sr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px 14px;margin-bottom:12px}
.sr-grid-4{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
.sr-form label>span{display:block;font-size:12px;font-weight:600;color:var(--sr-ink-2);margin-bottom:4px}
.sr-form input:not([type=checkbox]),.sr-form select,.sr-form textarea{width:100%;max-width:100%;border:1px solid var(--sr-line);border-radius:4px;padding:6px 8px;background:#fff;color:var(--sr-ink)}
.sr-form textarea.code,.sr-json{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
.sr-block{display:block;margin-bottom:12px}
.sr-inline{display:block;max-width:320px;margin-bottom:12px}
.sr-split{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-bottom:10px}
.sr-split .sr-submit{margin:0}
.sr-actions{display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 14px}
.sr-btn{display:inline-block;padding:7px 14px;border:1px solid var(--sr-ink);border-radius:4px;background:var(--sr-card);color:var(--sr-ink);font-weight:600;cursor:pointer}
.sr-btn-primary{background:var(--sr-ink);color:#fbfaf6}
.sr-btn:hover{border-color:var(--sr-accent)}
.sr-divider{height:1px;background:var(--sr-line);margin:12px 0 14px}
.sr-price{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;background:var(--sr-accent-soft);border-radius:4px;padding:10px 12px;margin:0 0 14px}
.sr-price strong{font-size:18px;color:var(--sr-ink);font-variant-numeric:tabular-nums}
.sr-price-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--sr-accent);font-weight:600}
.sr-consent{display:flex;gap:10px;align-items:flex-start;border-left:3px solid var(--sr-accent);background:var(--sr-surface);padding:10px 12px;margin:6px 0 4px;font-weight:500}
.sr-consent input{margin-top:3px}
.sr-check{display:block;margin:4px 0}
.sr-muted{color:var(--sr-ink-2);font-size:12px}
.sr-unavailable{background:var(--sr-surface);border:1px dashed var(--sr-line);padding:12px;border-radius:4px;color:var(--sr-ink-2)}
.sr-alert{padding:12px 14px;border-radius:4px;margin:0 0 18px;border-left:4px solid}
.sr-alert.is-error{background:#f8eaea;border-color:var(--sr-err)}
.sr-alert.is-ok{background:#e8f1ec;border-color:var(--sr-ok)}
.sr-alert.is-info{background:var(--sr-accent-soft);border-color:var(--sr-accent)}
.sr-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1px;background:var(--sr-line);border:1px solid var(--sr-line);border-radius:4px;overflow:hidden;margin:0 0 14px}
.sr-facts-wide{grid-template-columns:1fr}
.sr-facts>div{background:var(--sr-card);padding:8px 10px}
.sr-facts dt{font-size:11px;color:var(--sr-ink-2);text-transform:uppercase;letter-spacing:.06em}
.sr-facts dd{margin:2px 0 0;font-variant-numeric:tabular-nums;word-break:break-word;font-weight:500}
.sr-result{margin-top:24px;background:var(--sr-card);border:1px solid var(--sr-ink);border-radius:6px;padding:18px 20px}
.sr-result h2{margin:0;font-size:16px}
.sr-result h3{font-size:13px;margin:16px 0 8px}
.sr-preview{width:100%;height:460px;border:1px solid var(--sr-line);background:#fdfdfb;border-radius:4px}
.sr-json{width:100%}
@media (max-width:782px){.sr-head{flex-direction:column;align-items:flex-start}.sr-split{grid-template-columns:1fr}}
</style>';
    }
}
