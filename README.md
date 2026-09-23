> **Standalone source distribution:** this repository contains the integration runtime, documentation, and source packager. Upstream workspace/CMS/production-normalizer regression suites are deliberately not distributed here because they depend on private server code or isolated platform fixtures. Testing commands and historical verification evidence below describe upstream maintainer validation, not a self-contained test suite in this source-only checkout. No third-party registry publication is implied.

# SendRepute for Drupal

SendRepute 0.1.0 is a standalone Drupal mail backend for **Drupal 10.3+ and
Drupal 11.x** on PHP 8.1+. It supports exact, administrator-selected
`module/key` mail routes. Classification is a variable-price paid operation and
is disabled by default.

## Install and configure

Build the allowlisted archive:

```sh
python3 package.py
```

Install `dist/sendrepute-drupal-0.1.0.zip` through Drupal's module installer, or
extract its `sendrepute` directory into `modules/contrib/`. Enable the module,
grant `administer sendrepute` only to trusted administrators, then visit
**Configuration → System → SendRepute**.

Set a scoped customer bearer token in the web/PHP process environment:

```text
SENDREPUTE_API_TOKEN=...
```

The token needs `classify` permission. It is never accepted by the form or
stored in Drupal configuration, so configuration export cannot contain it.
Restrict the environment at the process/service manager and keep it out of
logs. The settings form uses Drupal Config Form API access checks and CSRF form
tokens.

Configure a non-empty **sender display name** in the form. This is the
human-readable organization/application name required by the exact endpoint's
`CustomerClassificationInput` contract, not an address. The module rejects `@`,
angle brackets, controls, invalid UTF-8, empty values, and values over the API's
320-byte limit. It never derives this value from Drupal's email-address fields.

Each non-empty route line has:

```text
module|key|delegate|mode|failure-policy|threshold|critical-selection
contact|page_mail|php_mail|advisory|preserve|0.90|no
```

Saving writes only those exact routes to `system.mail.interface` as
`sendrepute_mail`; their named delegate performs actual formatting and
delivery. Start with `advisory|preserve`. `block` mode and API failure policy
are independent. A successful result blocks only at or above the configured
probability threshold. `preserve` on failure (the default) keeps delivery
working. `block` on failure refuses delivery when analysis cannot safely
complete.

All `user` mail and keys resembling password reset, account, verification,
security, login, one-time, cancellation, confirmation, or activation remain
deliverable by default even if block/fail-block is selected. Set the final
field to `yes` on that exact route only after deliberately deciding that
classification may block critical mail.

Paid consent is separately required. Clearing it makes the backend delegate
without an API request. The API is the pricing, entitlement, balance, receipt,
and scope authority; this module does not make a free paid probe or claim inbox
placement.

## Mail lifecycle and content boundary

Drupal core's `MailManager::doMail()` invokes mail hooks and
`hook_mail_alter()` **before** selecting a backend and calling its `format()`,
then calls that backend's `mail()`. Therefore `hook_mail_alter()` cannot see
the final backend-formatted body and this module intentionally does not claim
enforcement there.

Instead, the supported integration is the `sendrepute_mail` `@Mail` plugin. Its
`format()` first invokes the configured delegate's formatter. Its `mail()` sees
that result, classifies it, applies policy, and only then calls the same
delegate's `mail()`. The original message is not rewritten.

The only supported delegate in 0.1.0 is Drupal core's `php_mail`. The settings
form rejects every other plugin ID. The SendRepute backend creates that delegate
once and reuses the same object for `format()` and `mail()`, preserving any
instance state across the core mail lifecycle. Core `php_mail` converts Drupal's
body parts into its actual single-part delivery body before classification.
Custom/Symfony/contrib/provider backends are not supported in this release.

Multipart output, attachment disposition, non-scalar bodies, malformed
headers, subjects over 998 bytes, and bodies over 256 KiB are rejected before
paid classification. The configured failure policy then applies, except
protected critical routes still deliver. This conservative refusal prevents
approving a whole message from only a benign text alternative and prevents
attachment bytes from entering the payload.

Only the configured sender display name, non-empty subject, and a length-framed
final body are transmitted, exactly matching the `CustomerClassificationInput`
referenced by the OpenAPI `POST /v1/classify` operation. Recipient addresses,
sender email addresses, headers, parameters, and attachments are neither copied
into nor serialized in the request.

## Transport and decision safety

The destination is fixed to
`https://www.sendrepute.com/api/v1/classify`. The cURL transport requires HTTPS,
peer and hostname verification, refuses redirects, has bounded connect/overall
timeouts and a 1 MiB response cap, and performs exactly one attempt with no
automatic retry. Authentication, balance, rate-limit, redirect, malformed,
oversized, and transport outcomes are errors—not spam classifications.

The complete nested `CustomerClassificationResponse` referenced by that exact
operation is validated before `result.spamProbability` can affect delivery:
top-level `requestId`, `model`, `result`, and `billing`, all required result
members, optional content audit, and charged/replayed billing fields. The
similarly named flat edit-classification schema is not used for this endpoint.
Errors and logs contain only route identifiers and safe categories, never
credentials, recipients, headers, attachments, subject, or body.

## Compatibility evidence and limits

The implementation targets the documented Drupal 10.3/11 Mail API contracts:
`MailManagerInterface`, `MailInterface`, `@Mail` plugin discovery,
`ContainerFactoryPluginInterface`, Config Form API, permissions, and
`system.mail.interface`. Source design was checked against Drupal core's mail
manager render order and core PHP mail plugin behavior. In addition to offline
fixtures, the isolated installed-site harness was run against exact supported
Drupal releases 10.6.17 and 11.4.7 with PHP 8.4.10, Composer 2.8.5, and MariaDB
10.11.13. It used a local sendmail sink and synthetic classifier; no paid API
call or external email send was performed.

The source harness was run against immutable official Drupal GitLab archives
for tags 10.3.0 and 11.0.0, downloaded only into `/tmp` (not installed or
packaged). Observed archive SHA-256 values were respectively
`48c7049e3e3bf7246fc699a3f03f4cdf330e8ca59631582915d10ce7c421a6ba` and
`1de4329f1b306042b8631562c43e8f81e897d4863f1b8619f98abba78b228714`.
This verifies those baseline tags, not every later patch or contributed mailer.

Drupal API references:

* <https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Mail%21MailManager.php/function/MailManager%3A%3AdoMail/10>
* <https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Mail%21MailInterface.php/interface/MailInterface/10>
* <https://api.drupal.org/api/drupal/core%21core.api.php/function/hook_mail_alter/10>

Run offline tests with `sh tests/run.sh`. They cover paid-consent gating,
authentication responses, redirect refusal, bounds and malformed responses,
payload minimization, mixed policy outcomes, protected critical mail, package
layout, exact endpoint OpenAPI/route parity, one shared delegate instance, and
source-level backend order. Tests make no network call or send. If an existing
Drupal checkout is available, set `DRUPAL_CORE_PATH=/path/to/web/core`; the
harness loads its real `MailInterface`, inspects real `PhpMail`, and verifies
`MailManager` ordering. It reports an explicit skip when no core source is
available and never downloads Drupal.

Run the repeatable installed-site matrix with `sh tests/run-installed.sh`.
It creates only a new uniquely named, mode-0700 temporary root, MariaDB data
directory/database and socket, and guarded local HTTP ports; installs the exact
versions above through Composer;
installs and then replaces the allowlisted ZIP while enabled; and exercises a
real administrator login, Form API CSRF rejection and save, route opt-in, mail
plugin discovery, synthetic classification, critical-mail preservation, and
core `php_mail` through a local sink. The last successful exact-version and
lock hashes are recorded in `tests/real_drupal/evidence.json`. An explicitly requested
`DRUPAL_REAL_WORK_ROOT` must not already exist and is never recursively
deleted. MariaDB has networking disabled and every database command uses the
owned socket. Override the guarded HTTP base port with
`DRUPAL_REAL_WEB_PORT`.
