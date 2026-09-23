# Security policy

Report private vulnerabilities to `support@sendrepute.com`. Include the module
version, Drupal/PHP versions, impact, and a redacted reproduction. Never send
API tokens, recipient data, message content, exported production configuration,
or unredacted logs.

Keep Drupal, PHP, cURL, the selected mail delegate, and this module supported
and updated. Store `SENDREPUTE_API_TOKEN` only in protected server/process
configuration and grant `administer sendrepute` narrowly.

Classification sends the configured non-address sender display name, message
subject, and final displayed body to SendRepute only after scoped paid consent
and exact route opt-in. Review your privacy,
retention, contractual, and authorization obligations before enabling it.
Recipients, headers, sender addresses, and attachments must not be sent.

Use advisory/preserve first. Blocking mail can disrupt business and security
flows. Account and security mail is preserved unless an administrator
deliberately opts that exact route into critical enforcement.

The fixed HTTPS endpoint refuses redirects and automatic retries and validates
TLS. Do not patch it to use HTTP, a configurable destination, or disabled
certificate checks. Do not include secrets or content in exception messages.
