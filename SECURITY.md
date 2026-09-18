# Security Policy

## Reporting a vulnerability

Please **do not open public issues for security problems**.
Email `info@queryproxy.com` (or use GitHub private vulnerability reporting)
with a description, reproduction steps and impact assessment. You will get an
acknowledgement within 72 hours.

## Scope highlights

QueryProxy is a security tool; these areas are especially sensitive:

- SQL guard bypasses (`SqlInspector`): submitting an `UPDATE`/`DELETE` without
  `WHERE` that passes, LIMIT-clamp evasion, forbidden-statement evasion.
  The forbidden-statement denylist is a defence-in-depth layer, not the
  authorization boundary: it enumerates dangerous syntax known today and a
  dialect spelling it does not name yet will pass it. Every write still
  requires a DBA approval, and that approval — not the denylist — is what
  authorizes a statement. A new denylist gap is still a valid report.
- Authorization: team isolation, connection grants, self-approval prevention.
- Webhook authentication: Slack signature / Teams HMAC verification, replay handling.
- Masking: any path where unmasked PII reaches the result store or the browser.
- Credential storage: connection secrets must never appear in logs, audit metadata
  or error messages.

## Supported versions

Only the latest minor release receives security fixes.
