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
- For a confirmed critical vulnerability, the target is to release a fix within
  14 days of confirmation. Other severities are fixed on a best-effort basis
  and scheduled with the reporter. If a fix needs longer, you are told why and
  given a new date.
- After a fix is released, a GitHub Security Advisory is published.
- If you wish, you are credited for the report in the advisory.

## Verifying Release Images

Every release image published to Docker Hub (`queryproxy/queryproxy`) and GitHub
Container Registry (`ghcr.io/queryproxy/queryproxy`) is signed keyless with
[cosign](https://github.com/sigstore/cosign) from the release workflow, and
carries an SBOM and a SLSA provenance attestation. Replace `0.3.0` with the
version you are checking.

Verify the signature with cosign v3 or later: signatures are stored in the
Sigstore bundle format as OCI 1.1 referrers, and cosign v2 does not find them
(`no signatures found`). The identity must be the release workflow of this
repository at a version tag (pre-release tags such as `v0.3.0-rc.1` included),
issued by GitHub Actions:

```sh
cosign verify \
  --certificate-identity-regexp '^https://github\.com/QueryProxy/QueryProxy/\.github/workflows/release\.yml@refs/tags/v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/queryproxy/queryproxy:0.3.0
```

Inspect the SBOM and the provenance attached to the image:

```sh
docker buildx imagetools inspect ghcr.io/queryproxy/queryproxy:0.3.0 \
  --format '{{ json .SBOM }}'
docker buildx imagetools inspect ghcr.io/queryproxy/queryproxy:0.3.0 \
  --format '{{ json .Provenance }}'
```

An image whose signature does not verify, or that was not built by the release
workflow, must not be deployed; report it as described above. The project's
[OpenSSF Scorecard](https://scorecard.dev/viewer/?uri=github.com/QueryProxy/QueryProxy)
results are published weekly.

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
