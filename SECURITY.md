# Security Policy

## Supported Versions

Only the latest release receives security fixes while the project is at `0.y.z`.

| Version | Supported |
|---|---|
| Latest release | Yes |
| Older versions | No |

## Reporting a Vulnerability

Do not report security vulnerabilities through public issues, pull requests or discussions.

Report vulnerabilities privately through GitHub Private Vulnerability Reporting:
[open a private security advisory](https://github.com/QueryProxy/QueryProxy/security/advisories/new).

If you cannot use GitHub, send an email to `info@muhammetsafak.com` with the subject line `[SECURITY] QueryProxy`.

Please include:

- the affected version or versions,
- steps to reproduce the issue,
- the impact of the vulnerability,
- a suggested fix, if you have one.

## Response Process

- You will receive a response within 7 days of your report.
- The report is assessed and you are informed of the result.
- After a fix is released, a GitHub Security Advisory is published.
- If you wish, you are credited for the report in the advisory.

## Scope

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

The following are out of scope for this policy:

- Vulnerabilities in third-party dependencies that do not affect QueryProxy (report them to their maintainers). A vulnerable dependency version shipped in a QueryProxy release or image is in scope.
