# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

### Added

- Connection form: choose where a connection's password comes from —
  stored password, HashiCorp Vault, AWS Secrets Manager or Azure Key Vault.
  External sources take a secret reference with a format hint; the
  connection list shows the source and the reference, never the password.
- Admin > Teams: a system administrator sets the secret paths each team may
  reference (audited, with an overlap warning). A team without paths cannot
  choose an external source in the connection form.
- External secret references for connection passwords (ADR-028): a
  connection's password can be `vault://<mount>/<path>#<key>`,
  `aws-sm://<secret-id>[#<json-key>]` or
  `azure-kv://<vault-name>/<secret-name>[/<version>]` instead of a value. It
  is resolved when the connection is opened and never stored. HashiCorp
  Vault (KV v2/v1; token, AppRole, Kubernetes auth), AWS Secrets Manager
  (default credential chain, IRSA included) and Azure Key Vault (client
  secret, workload identity, managed identity) are supported. Resolution is
  fail-closed: the request fails with a generic message and the new
  `secret.resolve_failed` audit event records the reference, never the
  value. Values are cached in process memory only
  (`QUERYPROXY_SECRET_CACHE_TTL`, default 300 s). A system admin sets the
  reference prefixes each team may use (`queryproxy:team:secret-prefixes`);
  a team without prefixes cannot use references. Optional
  `QUERYPROXY_SECRET_ALLOWED_REFERENCES` limits references instance-wide.
  "Test connection" resolves the reference; `queryproxy:doctor` checks the
  store settings.
- `qp`, the command-line client for the REST API (separate repository
  `QueryProxy/qp`, ADR-030): `qp submit --wait` files a request, waits for
  approval and prints the result; `qp status`, `qp results`. Static binaries
  for Linux, macOS and Windows (amd64, arm64), checksums signed keylessly
  with cosign. CI gains an end-to-end job that runs `qp` against a live
  server.
- Logging out of an SSO session now also ends the identity provider session
  (RP-initiated logout) when the provider publishes an
  `end_session_endpoint`; register `<APP_URL>/login` as a post-logout
  redirect URI at the provider.
- Helm chart is now published on every release tag as
  `oci://ghcr.io/queryproxy/charts/queryproxy:<version>`, signed keyless with
  cosign; the release fails before anything is pushed if the tag differs
  from `Chart.yaml` `version`/`appVersion`. Pull requests dry-run
  `helm package`. The chart README documents installing from the registry
  and verifying the signature.
- CockroachDB and YugabyteDB are tested in CI as target databases through
  the PostgreSQL driver; `docs/compatibility.md` lists the status (Aurora
  PostgreSQL, Neon, Supabase: not tested) and the known differences.
- SCIM 2.0 provisioning (ADR-026) under `/scim/v2`: Users and Groups with
  filter (`userName`/`externalId` `eq`), pagination, PATCH and PUT;
  `ServiceProviderConfig`, `Schemas`, `ResourceTypes`. Off by default
  (`QUERYPROXY_SCIM_ENABLED`). Dedicated bearer tokens
  (`php artisan queryproxy:scim-token create|revoke|list`), stored as
  SHA-256 only. `active=false` and `DELETE` suspend the user, never delete;
  `active=true` lifts only a suspension SCIM itself set, never one an
  administrator set. SCIM sees only users it created or SSO-linked users,
  never local accounts. Group membership applies `QUERYPROXY_OIDC_ROLE_MAP`.
  New audit events `scim.*`.
- Schema explorer backend (ADR-029): `GET /connections/{connection}/schema`
  returns the tables, views and columns of a connection for the Studio,
  read through fixed catalog queries in a read-only session with its own
  short timeout (`QUERYPROXY_SCHEMA_TIMEOUT`, default 5 s) and without an
  approval. Only users with access to the connection can read it; on
  MySQL/MariaDB only the connection's own database is listed. Columns
  matched by a column masking rule are flagged `masked`. Large catalogs are
  cut at `QUERYPROXY_SCHEMA_MAX_COLUMNS` (default 20000) with the default
  schema kept first. The result is cached per connection
  (`QUERYPROXY_SCHEMA_CACHE_TTL`, default 3600 s); a rate-limited manual
  refresh skips the cache (`QUERYPROXY_SCHEMA_REFRESH_LIMIT`, default 3 per
  minute). Each live read is recorded as `schema.read` in the audit log;
  reads served from the cache are not.
- Slack app: OAuth v2 install (`/integrations/slack/install`), bot-token
  messages that update in place (approved, rejected, cancelled, running,
  completed, failed), a thread summary with status, row count, duration and
  link (no SQL), opt-in direct messages to approvers, the `/queryproxy`
  slash command with a request modal, Events API handling for
  `app_uninstalled` and `tokens_revoked`, and a weekly e-mail based identity
  suggestion scan whose suggestions an administrator confirms on the web.
  The incoming-webhook mode is unchanged. New audit events `slack.installed`
  and `slack.uninstalled`. New settings: `QUERYPROXY_SLACK_CLIENT_ID`,
  `QUERYPROXY_SLACK_CLIENT_SECRET`, `QUERYPROXY_SLACK_SIGNING_SECRET`,
  `QUERYPROXY_SLACK_RATE_LIMIT`. Slack endpoints are limited to 120
  requests per minute per address before signature verification and to a
  per-workspace budget (default 300 per minute) after it (ADR-004
  amendment); Teams keeps `throttle:60,1`. Two migrations make
  `chat_integrations.webhook_url` and `signing_secret` nullable.
- Settings > SCIM for system admins: shows the SCIM endpoint URL, lists
  SCIM tokens (name, creator, last use, status), creates tokens (value
  shown once, after a fresh password, TOTP or SSO confirmation as for API
  tokens) and revokes them. While SCIM is switched off the page explains
  how to enable it. After an SSO re-confirmation started there, the admin
  comes back to the SCIM page.
- Slack settings: an Add to Slack button, the installed workspace and
  channel, an opt-in switch for direct messages to approvers and a Remove
  app action on the ChatOps page; admins confirm or dismiss e-mail based
  Slack ID suggestions on the Users page. `slack.uninstalled` now also
  records `removed_by_user`.

### Changed

- The profile page hides the password form for users without a local
  password.
- `users` gets `scim_provisioned_at`, `scim_external_id`, `scim_user_name`
  and `suspended_by_scim`; new tables `scim_tokens`, `scim_groups`,
  `scim_group_user` (migrations are reversible). Accounts created by SCIM
  count as SSO users: no local password through reset flows.

### Fixed

- On CockroachDB a read request's read-only transaction did not stop DDL
  (the server commits before DDL); the read session is now also read-only
  session-wide there. "Stop" now works for running statements on
  CockroachDB (`CANCEL QUERIES`).
- Saving a Slack signing secret on a row that has none (an app-only
  install) no longer asks for a current secret, and a webhook URL can no
  longer be saved without a signing secret.

### Security

- An SSO-linked user can no longer be given a local password through the
  admin "Reset password" action or the e-mail reset flow, regardless of
  `QUERYPROXY_LOCAL_LOGIN`.

## [0.4.0] - 2026-11-01

Step-by-step upgrade and rollback instructions, with the migration list, are
on the [upgrading page](https://queryproxy.com/docs/upgrading/). Upgrade from
0.3.3, the tested starting point: the container runs five new migrations on
start (`2026_10_26_000001`, `2026_10_26_000010`, `2026_10_26_000011`,
`2026_10_26_000020` and `2026_10_31_072351`), so back up the data volume and
the application database first. Every new setting is optional and every
feature is off or empty until you use it: `QUERYPROXY_OIDC_*` (single
sign-on), `QUERYPROXY_LOCAL_LOGIN`, `QUERYPROXY_API_TOKEN_MAX_DAYS`,
and `QUERYPROXY_API_RATE_LIMIT`. Keep the scheduler running: it now
removes expired connection grants every minute.
Three changes below are listed as **Breaking**; read them before you
upgrade.

### Breaking (0.4.0)

- **`users.password` can be empty.** SSO-only accounts have no local password,
  and `users` gains `auth_provider`, `external_issuer` and `external_id`. A
  script or integration that reads the `users` table directly must expect an
  empty password. Changing `QUERYPROXY_OIDC_ISSUER` later does not carry
  existing SSO accounts over: they are tied to the old issuer and are refused
  rather than taken over by e-mail. No password can be set for an account
  without one through the reset link. Migration `2026_10_26_000001` adds the
  columns; rolling it back stops while such accounts exist.
- **`connection_user` has new columns:** `expires_at`, `granted_by`, `reason`
  and `access_level`. Existing rows stay permanent and `write`. A script that
  inserts grant rows without them gets the same defaults, and a row whose
  `expires_at` has passed no longer gives access. Migration
  `2026_10_26_000010` adds the columns; rolling it back stops while a timed or
  `read` grant exists.
- **`QUERYPROXY_LOCAL_LOGIN` is new.** The default `all` changes nothing, but
  an unknown value (a typo, for example) is treated as `admins_only`, which
  limits password login to system administrators.

### Added (0.4.0)

- **`php artisan queryproxy:migrate-appdb --to=<dsn>` moves the application
  database to another server.** It is meant for installations that started on
  the default SQLite file and now want PostgreSQL, MySQL, MariaDB or SQL Server
  (`--to=pgsql://user:password@host:5432/queryproxy`; URL-encode special
  characters in the password). It runs the migrations on the target, copies
  every table in foreign key order in chunks (ids, audit hashes and the chain
  head unchanged, PostgreSQL sequences reset), then compares the row count of
  every table and runs `audit:verify` on the target. The audit triggers
  (ADR-020) are never dropped: they are installed by the migrations before the
  copy, which only inserts. The whole copy is one transaction, so a failure
  leaves a migrated, empty target you can retry. The target must be empty (a
  database that was only migrated is fine); `--dry-run` checks everything and
  writes nothing; without `--force` the command asks you to confirm that
  QueryProxy is stopped. The target password is never printed. The `cache`
  tables are not copied. After the move, switch `DB_CONNECTION` and the
  `DB_*` settings and start QueryProxy again.
- **An image with SQL Server support: `queryproxy/queryproxy:<version>-mssql`
  and `:mssql`.** It adds the Microsoft ODBC Driver 18 and the `pdo_sqlsrv`
  extension (build it yourself with `--build-arg INSTALL_SQLSRV=1`). `:mssql`
  moves only with stable releases and never takes `latest`; a pre-release
  publishes its `-mssql` version tag only. The variant is built for `linux/amd64`
  and `linux/arm64` (Microsoft ships the driver for Alpine on both), scanned,
  signed and attested like the default image. The Microsoft driver is under
  Microsoft's own licence (<https://aka.ms/odbc18eula>); using the variant
  means accepting it. The default image is unchanged.
- **SQL Server is now supported as the application database.** The
  application schema could not be created there: SQL Server refuses foreign
  keys that give a table more than one cascade path, and the schema had
  several. On SQL Server only, eight foreign keys are created with
  `ON DELETE NO ACTION` (`query_requests.team_id`, `masking_rules.team_id`,
  `connection_grant_requests.team_id`, `connections.created_by`,
  `connection_user.granted_by`, `connection_grant_requests.decided_by`, and
  the two `query_requests` keys to users that are `RESTRICT` on every driver
  since ADR-018), and the application does what the database would have done
  on a delete: deleting a team removes its requests, masking rules and grant
  requests first, and hard deleting a user who has no requests sets the keys
  that referenced them to NULL. Raw SQL that deletes a team or user is
  refused on SQL Server only while rows still point at it through one of
  those eight keys (for a team: requests, masking rules or grant requests;
  for a user: connections it created, grants it gave, grant requests it
  decided, or requests it filed or reviewed). For the six keys that cascade
  or set NULL elsewhere, the delete is refused there instead of cascading or
  setting NULL; the two request keys to users are refused on every driver.
  Nothing changes on PostgreSQL,
  MySQL, MariaDB or SQLite: no new migration, no change to an existing
  installation, same delete results. Use the `:mssql` image (the default image
  has no `pdo_sqlsrv`); `php artisan queryproxy:migrate-appdb
  --to="sqlsrv://user:password@host:1433/queryproxy?trust_server_certificate=true"`
  moves an installation there.
- **CI tests the upgrade from the previous release.** The new `upgrade` job in
  the Integration workflow creates data on the 0.3.3 image, starts the image
  built from the branch on the same data volume, and checks that the data is
  still there, that `audit:verify` passes across the upgrade, and that `/up`
  and the login page answer.
- **Single sign-on through OpenID Connect, with team roles from IdP groups.**
  Set `QUERYPROXY_OIDC_ISSUER` and `QUERYPROXY_OIDC_CLIENT_ID` (and
  `QUERYPROXY_OIDC_CLIENT_SECRET`, or `QUERYPROXY_OIDC_CLIENT_SECRET_FILE`) and
  the login page offers "Log in with SSO". It works with any provider that
  publishes discovery (Keycloak, Entra ID, Okta, Google, Authentik): authorization
  code flow with PKCE, `state` and `nonce`, and the ID token signature is checked
  against the provider's keys. Register `{APP_URL}/auth/oidc/callback` as the
  redirect URI. The first sign-in creates the user, and an existing local account
  with the same e-mail address is linked, but only when the provider says the
  address is verified (`email_verified`); otherwise the sign-in is refused and
  the refusal is audited (`auth.sso_refused`).
  Entra ID does not send `email_verified`; there the optional `xms_edov` claim
  (add it to the app registration's token configuration) counts instead, but
  only when `email_verified` is absent, and an explicit `email_verified: false`
  always wins. A user is identified by issuer and
  subject together. `QUERYPROXY_OIDC_ROLE_MAP` (`group:team:role`, comma-separated)
  turns the groups in `QUERYPROXY_OIDC_GROUP_CLAIM` (default `groups`) into team
  roles at every sign-in: memberships in the teams the map names are added,
  changed and removed to match the IdP, and each change is audited
  (`user.role_synced`). Memberships in other teams are not touched. While a role
  map is set, a token without the group claim (including a "groups overage"
  reference) is refused and nothing is changed; an empty group list is applied
  as such. Suspended and deleted users cannot sign in through SSO either. `QUERYPROXY_OIDC_SCOPES`
  changes the requested scopes (some providers need `groups`). New audit events:
  `auth.sso_login`, `auth.sso_refused`, `user.sso_provisioned`, `user.sso_linked`,
  `user.role_synced`.
  Role changes at the IdP apply at the next sign-in.
- **`QUERYPROXY_LOCAL_LOGIN` limits password login: `all` (default), `admins_only`
  or `none`.** With `admins_only` only system admins sign in with a password
  (break-glass access); with `none` password login and password reset are off, so
  make sure SSO works first. An unknown value is treated as `admins_only`.
- **CI signs in to a real Keycloak.** The new `oidc` job in the CI workflow
  imports a test realm and checks the sign-in and the group-to-role sync end to
  end.
- **A Helm chart runs QueryProxy on Kubernetes, with an HA reference setup.**
  `deploy/helm/queryproxy` starts the one image as separate `web`, `worker`
  and `scheduler` Deployments (roles switched with `QUERYPROXY_RUN_WORKER` and
  `QUERYPROXY_RUN_SCHEDULER`); the scheduler is always one replica with the
  `Recreate` strategy. Migrations run as a `pre-install,pre-upgrade` hook Job.
  `values-ha.yaml` sets up an external PostgreSQL, S3 result storage, several
  web and worker pods, an Ingress, a PodDisruptionBudget and a NetworkPolicy.
  The worker can scale on the `queryproxy_queue_depth` metric (HorizontalPodAutoscaler,
  off by default; a prometheus-adapter example is in `values.yaml`). `APP_KEY`
  and the database password come from a Secret (your own, for example from the
  External Secrets Operator, or one the chart creates and keeps), optionally
  mounted as files (`*_FILE`). With SQLite the chart renders one all-in-one
  Deployment (web, worker and scheduler, one replica) and refuses more than one
  web replica, because a SQLite file cannot be shared between pods; the role
  split applies with an external database. The chart adds the Service's
  in-cluster names to `TRUSTED_HOSTS`, so Prometheus can scrape `/metrics`
  through the Service. The pods run as a non-root user with all capabilities
  dropped; the root filesystem is still writable because the image's
  entrypoint writes to it. The architecture and the options are in
  `deploy/helm/queryproxy/README.md`. A new `Helm` workflow lints the chart on
  Helm 3 and 4 and installs it into a kind cluster, where a sample request is
  approved and run by the worker pod, and an upgrade and a rollback are
  checked.
- **The image can store result files on S3 and use Redis.** It now includes the
  Flysystem S3 adapter (`QUERYPROXY_RESULT_DISK=s3` with the usual `AWS_*`
  settings, including S3-compatible endpoints) and the `predis` Redis client
  (`REDIS_CLIENT=predis`, for cache, queue and sessions). Both are optional;
  the default setup is unchanged.
- **Time-bound connection grants.** A DBA can give a developer access to a
  connection for a period ("4 hours, read-only") instead of until someone
  remembers to remove it, and a developer can ask for such access with a
  reason; a DBA of the team approves or rejects the request. The grant starts
  when it is approved. Nobody decides their own request, not even a DBA the
  requester became while it was open. `connection_user` gains `expires_at`,
  `granted_by`, `reason` and `access_level` (`read` or `write`); existing
  grants stay permanent with full access. Access ends at `expires_at` (an
  expired grant is ignored at once), and the new `connections:expire-grants`
  command, scheduled every minute, removes the rows and records
  `grant.expired`. A `read` grant cannot file a request the SQL guard
  classifies as a write or DDL: the studio answers 403. Requests that were
  already pending or approved when a grant ends are not cancelled.
  A request must match the grant the developer already holds: a request for
  another access level than the grant in force, or for any level while a
  permanent grant of that level exists, is refused when it is filed and when
  it is approved, so a time-bound approval never replaces or widens standing
  access. An approval for the same level only extends a time-bound grant; it
  never shortens it or makes it permanent. To change an existing grant, a DBA
  edits it directly. DBAs are notified in the app and in the team's
  Slack/Teams channels (without the free-text reason); the requester is told the decision and the grant that is
  in force. New audit events: `grant.requested`, `grant.approved`,
  `grant.rejected` and `grant.expired` (ADR-025). Removing a grant by hand
  still records `connection.grant_revoked`, and `connection.grant_added` now
  carries `access_level`, `expires_at` and `reason` in its metadata.
- **Screens for time-bound access.** On the dashboard a developer can ask for
  access to a connection of their team: pick the level (read or write), a
  period (1 hour, 4 hours, 1 day, 7 days or a custom amount up to 30 days) and
  give the reason, which is required. A developer who holds a timed grant sees
  its level and the time left, and can ask to extend it at the same level; the
  open requests and the decisions of the last seven days, with the DBA's
  rejection reason, are listed on the same page. The Approvals page has a new
  "Access requests" tab where a DBA of the team approves a request or rejects
  it with a reason; nobody can decide their own request, and a request for
  another level than the grant in force cannot be approved (change the
  existing grant on the Connections page). On the Connections page the grants
  panel now sets the level, the period (permanent by default) and an optional
  reason for each grant, shows each developer's level and remaining time, and
  replaces or revokes an existing grant. The dashboard now also shows the
  confirmation after a DBA approves a request from it.
- REST API under `/api/v1` (ADR-024): submit a query request, read its status
  and its masked result (NDJSON, or CSV with `Accept: text/csv`), approve,
  reject or cancel it, and list connections. Authentication uses Laravel
  Sanctum personal access tokens (prefixed `qp_`) with the scopes
  `requests:submit`, `requests:read`, `results:read` and `approvals:write`;
  every token expires (`QUERYPROXY_API_TOKEN_MAX_DAYS`, default 90) and is
  revoked when its owner is suspended or deleted. Requests still wait for
  human approval, an approval that would be an override is refused with `403`
  and a link to the web form, and a token can never approve its owner's own
  request. Column names of a result are result data: an auditor does not get
  them. A user the `QUERYPROXY_REQUIRE_2FA` policy covers cannot create a
  token until they have enrolled, and an existing token of such a user is
  answered with `401` until then; the token is kept and works again afterwards.
  Each token is limited to `QUERYPROXY_API_RATE_LIMIT` calls per minute
  (default 60), and failed authentications are limited per address to the same
  number. Token creation and revocation are audited as `token.created` and
  `token.revoked`; API submissions, approvals, rejections, cancellations and
  result reads carry `channel = api` in the audit log.
- OpenAPI 3.1 description of the API in `docs/openapi.yaml`, linted by
  Spectral in CI and checked against the routes by a test.
- **Users can get request and access-request events by e-mail.** On the
  Profile page each user chooses which events also arrive by e-mail:
  a request waiting for review, a decision on their request, a finished,
  failed or stopped request, the pending-request reminder, an access request
  waiting for a DBA, and the decision on an access request. Everything is
  off by default, so nothing changes until a user turns it on. An e-mail
  never carries query results, a reviewer's reason, a decision note or an
  error message. The submission and reminder e-mails show the statement with
  its values hidden (the same redaction as chat, ADR-017; none with
  `QUERYPROXY_CHAT_INCLUDE_SQL=none`, and never the raw SQL, even with
  `full`). Links open the request in QueryProxy and need a signed-in
  session; nothing is approved from an e-mail. The e-mail is sent from the
  queue (the `default` queue the worker already listens to); the in-app
  notification is still written at once. A user who is suspended or deleted,
  or who turned the event off, before the worker sends the e-mail gets none.
  A mail failure never blocks the request: it is logged, and on a real queue
  the job also lands in `failed_jobs` so `queue:retry` can resend it. With
  `MAIL_MAILER=log` the Profile page says that nothing is delivered. The
  migration adds `users.notification_preferences`.
- **Create and revoke API tokens on the Profile page.** A new "API tokens"
  section lets you name a personal access token, choose its scopes and set
  how many days it lasts (required, up to `QUERYPROXY_API_TOKEN_MAX_DAYS`,
  90 by default), and lists your tokens with their scopes, expiry and last
  use. Creating a token asks you to confirm first: enter your password (or
  a code from your authenticator app when two-factor authentication is on),
  and wrong tries are rate limited. An account without a local password
  must have signed in through SSO in the last 15 minutes; otherwise the page
  sends you through SSO again (the provider is asked to sign you in anew)
  and brings you back. The token value is shown once, right after you
  create it, with a Copy button; it is never kept in the page state, so a
  reload or any later action hides it for good, and only its hash is stored.
  Revoking a token stops it at once. Only the scopes your roles can use are
  offered: `approvals:write` needs the DBA role in a team, `requests:submit`
  a DBA or developer role, and `results:read` is not offered to auditors.
  Creating and revoking are audited as before.

### Changed (0.4.0)

- **A pre-release no longer moves `latest`.** A pre-release tag such as
  `v0.4.0-rc.1` now publishes only its version tag; `latest` stays on the
  most recently published stable release.
- **SSO users are outside `QUERYPROXY_REQUIRE_2FA`.** Their second factor is the
  identity provider's, so enforce MFA there. A local account that was linked to
  the provider and still signs in with its password stays covered.

### Security (0.4.0)

- **The release workflow refuses tags users could not verify.** A tag that
  matches the `v*.*.*` trigger but not the certificate identity in
  `SECURITY.md` (for example `v1.2.3.4` or `v1.2.3+build`) now stops the
  workflow before anything is built or pushed. Use `vX.Y.Z` or
  `vX.Y.Z-<pre-release>`.
- **Updated `symfony/mime` to 7.4.22 (and `symfony/polyfill-mbstring` to
  1.43.3).** It fixes CVE-2026-45070 (e-mail header injection through
  non-token characters in MIME parameter names). QueryProxy does not build
  e-mail headers or MIME parameter names from user input, so it was not
  exploitable here; the update clears `composer audit`.

## [0.3.3] - 2026-10-25

### Fixed (0.3.3)

- **The Teams webhook field suggests a Workflows URL.** Microsoft has
  retired Office 365 connectors (see Deprecated in 0.3.0), but the empty
  Microsoft Teams field on the ChatOps page still showed a
  `webhook.office.com` example, which pointed new setups at a retired
  connector. The placeholder now shows a Power Automate Workflows URL, which
  receives an Adaptive Card, and the hint under it notes that Office 365
  connectors are retired. A legacy `webhook.office.com` URL you already
  configured is kept, and QueryProxy still sends it a MessageCard.

### Security (0.3.3)

- **The release workflow verifies the image signature before releasing.**
  After signing, the workflow runs `cosign verify` against the image in both
  registries with the certificate identity and issuer documented in
  `SECURITY.md`. If the check fails, no GitHub Release is created; the image
  is already pushed by then, so only the release is withheld.

## [0.3.2] - 2026-10-25

### Fixed (0.3.2)

- **Changes that write an audit entry no longer fail on MariaDB 11.8 or
  later.** MariaDB 11.8 turns on `innodb_snapshot_isolation` by default: a
  transaction that reads a row, and then touches a row another transaction
  changed since its snapshot, fails with error 1020 instead of waiting. When
  the application database was MariaDB 11.8 or later, deleting or suspending
  a user, creating or deleting a team and submitting a query could fail this
  way, because they read first and then append to the audit chain. These
  transactions are now tried up to three times, and each attempt starts from
  fresh data. On other databases the first attempt normally succeeds; a
  deadlock or lock timeout in these transactions is now retried as well.
- **Deleting a team keeps its TLS certificate files until the delete
  commits.** The files used to be removed before the delete finished, so a
  delete that was rolled back would have lost them. They are now removed after
  the commit; a team delete that is retried or rolled back keeps them.

### Changed (0.3.2)

- **Integration tests run on MariaDB 11.4 and 11.8.** The CI matrix has a
  separate job for each line, and the audit chain tests run against both as
  the application database.

## [0.3.1] - 2026-10-25

No image was published for 0.3.0: the release scan stopped it on a
vulnerable package in the base image. 0.3.1 is the same release with that
package updated; upgrade from 0.2.7 straight to 0.3.1 and follow the 0.3.0
upgrade notes below.

### Security (0.3.1)

- **The image installs Alpine security updates on build.** The PHP base image
  still shipped `brotli-libs` 1.2.0-r1 (CVE-2026-111497, an out-of-bounds
  write in the Brotli decoder); the build now runs `apk upgrade`, so packages
  fixed in Alpine are picked up before the base image is rebuilt.

### Changed (0.3.1)

- Dependency updates: `laravel/framework` 13.38.0, `vite` 8.3.5,
  `@codemirror/view` 6.43.15 and `github/codeql-action/upload-sarif` 4.39.1.

## [0.3.0] - 2026-10-25

Step-by-step upgrade and rollback instructions, with the migration list, are
on the [upgrading page](https://queryproxy.com/docs/upgrading/). Six changes
below are marked **Breaking** under Changed: the redacted chat SQL preview,
soft-deleted users with required override reasons, required TLS on new
connections, the Teams Workflows card format, and two that need action
before you upgrade: the database privileges the audit migration needs, and
the pinned time zone of MySQL and MariaDB application connections. If the
migration user lacks those privileges the upgrade stops and the container
does not start.

### Security (0.3.0)

- **Reads run in a read-only session on the target database.** PostgreSQL,
  MySQL, MariaDB and SQLite enforce it in the engine. SQL Server has no such
  session: use the optional read-only identity (below) with `SELECT`
  privileges only. The connection form warns about this.
- **MySQL and MariaDB reads could leave their read-only transaction.** MySQL
  commits implicitly before DDL, which ended the transaction opened with
  `SET TRANSACTION READ ONLY` and let the statement run outside it with write
  access. The SQL guard classes DDL as a write, so reaching this needed a
  statement the guard had misclassified as a read and a DBA who approved it
  as one. It was found by the integration tests against real databases
  before this release and was never in a released version: read-only
  sessions are new in 0.3.0. Reads on MySQL and MariaDB now set the session
  itself read only, so a statement that ends the transaction is still
  refused, and if the transaction is gone when a read commits the run fails
  instead of reporting success. The read path also classifies the statement
  again and refuses a write sent as a read, recording `refused_by: guard` in
  the audit log. The SQL guard and the DBA approval are unchanged; this
  closes a gap in the database-side layer.
- **SQL literals are redacted in chat, audit and listings.** String and
  numeric literals become placeholders in Slack and Teams previews, audit
  metadata and its export, the dashboard and the request lists. The raw SQL
  stays on the request page for the requester, the team's `dba` users and
  the admin. An Auditor sees redacted SQL only. When a statement cannot be
  redacted safely the preview is hidden.
- **New connections require TLS** (see Changed). Existing connections keep
  their behaviour and are flagged until you enforce TLS on them.
- **Release images are signed** with cosign (keyless) and carry an SBOM and a
  SLSA provenance attestation; an OpenSSF Scorecard workflow runs weekly.
  How to verify is in [SECURITY.md](SECURITY.md#verifying-release-images).
- **livewire/livewire 4.4.8** fixes an authorization bypass in `#[Authorize]`
  ([GHSA-mhf7-xp6r-6qrc](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc)).
  QueryProxy does not use `#[Authorize]`, so it was not affected directly.

### Added (0.3.0)

- **Audit hash chain.** Each audit entry stores the hash of the previous one
  and its own hash, so edits, deletions and reordering are detectable.
  `php artisan audit:verify` walks the chain from the first record and
  reports the first break; `audit:checkpoint` (scheduled daily) records the
  latest chain hash as an audit event. Database triggers reject `UPDATE` and
  `DELETE` on `audit_logs` on every supported driver (and `TRUNCATE` on
  PostgreSQL; MySQL and MariaDB triggers cannot block it).
- **Audit export to external systems.** Opt-in sinks, set with
  `QUERYPROXY_SIEM_SINKS`: JSON lines on stdout, RFC 5424 syslog over
  udp, tcp or tls, and an HMAC-signed HTTPS webhook. Delivery is queued after
  the audit record commits, retried, and dead-lettered to `failed_jobs`, so a
  down sink never blocks the audit write. Events follow
  `docs/audit-event.schema.json` and carry no per-event chain hashes.
- **Prometheus metrics on `/metrics`**: counters for requests, approvals,
  executions (completed, failed, cancelled, deferred for a busy connection),
  guard rejections by rule and masked columns. Off by default (`404`); it
  turns on when `QUERYPROXY_METRICS_TOKEN` or
  `QUERYPROXY_METRICS_ALLOWED_IPS` is set. A scrape passes with the Bearer
  token or from an allowed address, so an address on the allowlist needs no
  token; anything else gets `401`. Labels never carry SQL, user names or
  hosts. A Grafana dashboard ships as `docs/grafana-queryproxy.json`.
- **`php artisan queryproxy:doctor`** checks an installation without changing
  it: configuration and `APP_KEY`, the database, queue worker and scheduler
  heartbeat, storage, TLS enforcement on connections and their PEM files,
  masking coverage, the audit chain (`--full-audit` verifies all of it), SIEM
  sinks and metrics. `--json` prints a machine-readable report and `--strict`
  also fails on warnings.
- **Secrets from files.** `APP_KEY`, `APP_PREVIOUS_KEYS`, `DB_PASSWORD`,
  `MAIL_PASSWORD`, `REDIS_PASSWORD`, `SLACK_BOT_USER_OAUTH_TOKEN`,
  `QUERYPROXY_ADMIN_PASSWORD`, `QUERYPROXY_METRICS_TOKEN` and
  `QUERYPROXY_SIEM_WEBHOOK_SECRET` can be given as the same name with a
  `_FILE` suffix, by the application config and by the Docker entrypoint.
  Setting both `NAME` and `NAME_FILE` is an error and the container does not
  start; a plain value of `null`, `(null)` or `(empty)` counts as unset.
- **Stop a running query** from the request page. The requester, an approver
  or an admin can stop it: `pg_cancel_backend` on PostgreSQL, `KILL QUERY` on
  MySQL and MariaDB, `KILL` on SQL Server, sent from a separate connection. On
  PostgreSQL and SQL Server the signal first checks the session's start time;
  MySQL and MariaDB connection ids are not reused. Not available on SQLite. A
  stopped run is rolled back, ends as `cancelled`, is audited as
  `execution.cancelled`, and notifies the requester when someone else stopped
  it. On SQL Server the target connection's user needs `VIEW SERVER STATE` and
  `ALTER ANY CONNECTION`; without them the request is still marked `cancelled`
  and a warning is shown, but the query runs on until its statement timeout.
  `KILL` ends the whole session there, where the other drivers cancel only the
  statement.
- **`hmac` masking strategy and checksum validators.** `hmac` pseudonymises
  values with a 256-bit key per team (stored encrypted), so analysts can join
  and count on masked columns. `queryproxy:masking:rotate-key` replaces the
  key; old and new pseudonyms then stop matching. A `validator` match type
  adds TCKN, VKN, TR IBAN and Luhn checks, and a Turkey PII rule pack
  (national id, tax id, IBAN, mobile and E.164 phone numbers, vehicle plates)
  can be installed per team. The VKN content validator ships disabled
  because its check digit matches too many unrelated numbers; the column rule
  still covers `vkn` and `tax_no` columns.
- **Sensitive-filter badge.** A request that filters, joins or sorts on a
  column covered by a masking rule is flagged in the approval list and on the
  request page, because the result can leak masked values through the
  predicate.
- **Connection TLS settings and a read-only identity.** Each connection has a
  TLS mode and an optional CA, client certificate and key (PEM material is
  stored encrypted and written to `storage/app/tls/` with owner-only
  permissions). A connection can also hold an optional, encrypted read-only
  identity that read requests use.
- **Suspending users.** Administrators suspend and resume users on the Users
  screen.
- **Pending-request reminders.** `requests:remind` reminds the team's DBAs
  and admins once, `QUERYPROXY_PENDING_REMIND_AFTER` hours (default `4`, `0`
  turns it off) after a request is filed, and re-posts chat cards with a
  fresh approval token once the old tokens have expired. Requests never
  expire.
- **Connection slots.** `QUERYPROXY_CONNECTION_SLOTS` (default `2`) limits
  how many requests run against one connection at a time. A request that
  finds every slot busy is re-queued after
  `QUERYPROXY_CONNECTION_SLOT_RETRY_DELAY` seconds (default `5`), audited as
  `request.execution_deferred` and shown with a "Waiting for slot" badge.
- **Integration tests on real databases.** A workflow runs the integration
  and live suites against PostgreSQL, MySQL, MariaDB and SQL Server, with and
  without TLS.

### Changed (0.3.0)

- **Breaking — `QUERYPROXY_CHAT_INCLUDE_SQL` now defaults to
  `literals_redacted`.** The Slack and Teams preview replaces string and
  number literals with `?`. Values: `literals_redacted` (default), `full` (the
  earlier behaviour) and `none`; `true` still means `full` and `false` means
  `none`. The audit screen and the audit CSV no longer carry raw SQL for any
  role, and the CSV column `sql` is now `sql_redacted`. Update scripts that
  read it by name.
- **Breaking — users are soft-deleted and overrides need a reason.**
  `query_requests.user_id` and `reviewed_by` become `RESTRICT`, so request
  history cannot disappear with a user, and code that hard-deletes users now
  fails on the foreign key. Deleting a user blocks their sign-in, sessions and
  chat identities and cancels their `pending` requests (audited as
  `request.cancelled`, actor `system`); approved and queued requests are not
  touched, and the e-mail address stays reserved. A suspended user is blocked
  the same way but keeps their pending requests. Every admin override
  approval now needs a written reason on every channel, stored in the audit
  log: a Slack **Approve** press that would be an override is not applied and
  answers with a link to a web form, and the Teams action endpoint answers
  `422` to an override without `reason`. Rolling the user migration back is
  refused while any deleted or suspended account exists.
- **Breaking — new connections require TLS.** PostgreSQL uses
  `sslmode=require`, which encrypts but does not verify the server (use
  `verify-full` against a man-in-the-middle); MySQL and MariaDB require TLS
  without verifying the server certificate unless you tick "Verify the server
  certificate" and add a CA; SQL Server uses `Encrypt=true` and
  `TrustServerCertificate=false` (`pdo_sqlsrv` must be installed), so a
  self-signed or privately issued certificate fails until its CA is trusted or
  `TrustServerCertificate` is turned on for that connection. SQLite has no
  TLS. Existing connections keep their old behaviour and are flagged in the
  connection list: PostgreSQL shows "TLS not enforced" (`sslmode=prefer`), the
  others "TLS: driver default". Edit each connection to enforce TLS;
  `queryproxy:doctor` lists those that do not. On SQL Server the enforcement
  is the ODBC driver's documented behaviour, not something QueryProxy
  implements, and the tests cannot simulate a server that offers no TLS; treat
  the network path as untrusted until you have checked it.
- **Breaking for custom flows — Teams Workflows URLs receive an Adaptive
  Card.** Webhook URLs on `*.powerplatform.com` and `*.logic.azure.com` now
  receive an Adaptive Card 1.5 instead of a MessageCard, and
  `*.powerplatform.com` joins the default webhook host allowlist. The
  `queryproxy` envelope with the action token stays at the top level, so the
  approval flow keeps working. A Power Automate flow that reads the
  MessageCard fields (`title`, `summary`, `sections[0].facts`) now reads them
  empty: read the card from `attachments[0].content`, or forward the body
  unchanged. Legacy connector URLs (`*.webhook.office.com`) still receive a
  MessageCard (see Deprecated).
- **Reads run in a read-only session with timeouts derived from
  `QUERYPROXY_EXECUTION_TIMEOUT`.** The statement timeout is the execution
  timeout minus 5 seconds (at least 1) and the lock timeout is
  `QUERYPROXY_LOCK_TIMEOUT` (default `5`); writes get the same limits. On
  MySQL the statement timeout applies to `SELECT` only. On PostgreSQL,
  `SELECT … FOR UPDATE` and `FOR SHARE` are rejected inside a read request.
  Once a statement has started, QueryProxy no longer reconnects to the target
  after a network drop: a brief drop fails the run instead of re-running it on
  a new session.
- **Results are streamed.** Rows are read through a server-side cursor on
  PostgreSQL and an unbuffered result on MySQL and MariaDB, so memory no
  longer grows with the result size.
- **Slots can make requests wait.** If you ran more workers against one
  connection than `QUERYPROXY_CONNECTION_SLOTS` allows, requests now stay
  `queued` where they used to run at once. `0` switches the limit off.
- **Breaking, check before upgrading — MySQL and MariaDB: the application
  connection time zone is pinned to UTC.** `config/database.php` sets
  `'timezone' => '+00:00'` on the `mysql` and `mariadb` connections.
  `TIMESTAMP` columns are stored as UTC and shown in the session time zone,
  which until now was the database server's. On a server that is not on UTC,
  every existing `TIMESTAMP` value (`created_at`, `updated_at` and the other
  `*_at` columns) is shown shifted by the server's UTC offset after the
  upgrade: a server at UTC+3 shows values 3 hours earlier, one at UTC-5 shows
  them 5 hours later (with a named zone such as `Europe/Berlin` the shift
  follows daylight saving). `DATETIME` columns and servers already on UTC are
  not affected. Open chat approval tokens (`chat_approval_tokens.expires_at`,
  24-hour lifetime) expire early by that offset on UTC+ servers, and a token
  with less time left becomes invalid at once; on UTC- servers they live that
  much longer. Delete the open tokens before upgrading to avoid either effect;
  the affected requests then get a fresh chat card from the reminder: `DELETE
  FROM chat_approval_tokens WHERE used_at IS NULL;`. Check the server with
  `SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone,
  TIMEDIFF(NOW(), UTC_TIMESTAMP());` first. The audit hash chain depends on
  the pin: `audit_logs.created_at` is hashed as UTC wall time and must read
  back unchanged.
- **Breaking, before upgrading — the audit migration needs trigger
  privileges.** The audit chain migration creates triggers. If it fails, the
  container does not start, because the entrypoint runs `migrate` on every
  start. The migration user needs the `TRIGGER` privilege on MySQL and MariaDB
  (with binary logging on, also `SUPER` or
  `log_bin_trust_function_creators=1`, otherwise it fails with error 1419) and
  ownership of the table on PostgreSQL. Existing audit records are chained
  from the first one during the migration, which can be run again after a
  partial failure. After the migration, `audit_logs` rows can no longer be
  updated or deleted.
- **The first reminder run can post many cards at once.** Pending requests
  older than 24 hours with no live approval token, in teams with an enabled
  chat integration, get a renewed chat card on the first `requests:remind`
  run after the upgrade, so a channel with a backlog sees a one-time burst.
- **The scheduler gains three jobs**: a daily `audit:checkpoint`,
  `requests:remind` every 15 minutes and a one-minute heartbeat written to
  the cache (read by `queryproxy:doctor`). Keep the scheduler running.
- **Masking.** The `hash` strategy is deprecated (see Deprecated). The
  built-in card-number regex is replaced by a Luhn validator rule in new
  default sets; existing teams keep the old rule and "Add default rules"
  adds the Luhn rule next to it. The TCKN validator no longer treats a digit
  run glued to a letter or an underscore as a candidate; a column named
  `tckn` is still caught by its column rule.
- As before, the Docker entrypoint sets the worker timeout to
  `QUERYPROXY_EXECUTION_TIMEOUT` plus 10 seconds and ignores
  `QUERYPROXY_WORKER_TIMEOUT`; `queryproxy:doctor` reports that value.

### Deprecated (0.3.0)

- **The `hash` masking strategy** stays selectable and is labelled
  deprecated in the UI; use `hmac`.
- **MessageCard payloads to Teams legacy connector URLs.** Microsoft's
  [retirement notice](https://devblogs.microsoft.com/microsoft365dev/retirement-of-office-365-connectors-within-microsoft-teams/)
  (April 14, 2026 update) says the rollout ran May 18 to May 22, 2026, after
  which Office 365 Connectors no longer function, so a legacy URL may already
  have stopped working. Move to a Workflows URL.

### Fixed (0.3.0)

- A job that fails now cancels its query on the target database instead of
  leaving it running, and reads cut off at the row cap on MySQL and MariaDB
  send `KILL QUERY`.

## [0.2.7] - 2026-10-18

### Security (0.2.7)

- **`EXPLAIN`, `KILL` and `WITH` bodies are parsed with bounded memory.** The
  inner statement behind these keywords was parsed by a parser that kept every
  error with a full backtrace, so a 63 KB dotted-name chain behind `EXPLAIN`
  peaked at about 300 MB and could exhaust a 256 MB `memory_limit`. Inner
  bodies now keep only the first error and stay well under 64 MB on the same
  input.
- **`UPDATE` and `DELETE` statements the guard cannot parse are refused.**
  When such a statement could not be parsed, the guard fell back to a text
  search for `WHERE`, which also matched the `WHERE` of a subquery and let
  some statements without their own `WHERE` clause through. They are now
  refused as too complex to verify.

### Fixed (0.2.7)

- Nested `DO` blocks no longer repeat the same refusal for one language
  literal; independent violations keep their own messages. Which statements
  are accepted or refused is unchanged.
- The test suite no longer needs a generated `APP_KEY`: `phpunit.xml` sets a
  fixed, non-secret test key.

### Changed (0.2.7)

- **`QUERYPROXY_EXECUTION_TIMEOUT` is capped at 86400 seconds.** A valid value
  above 86400 is clamped to 86400 instead of saturating in the application or
  overflowing the image entrypoint's arithmetic. The entrypoint prints a
  warning at container start when it clamps; the application clamps silently.
- Inspecting a large `DO` body is faster (a 63 KB body takes about 29% less
  time), because lexing results are shared instead of repeated.

## [0.2.6] - 2026-10-18

### Fixed (0.2.6)

- **`QUERYPROXY_EXECUTION_TIMEOUT` is read by one rule everywhere.** The
  application config, the queue's `retry_after` and the image entrypoint now
  all accept only a whole number of seconds greater than zero and fall back to
  300 for anything else (text, empty, `0`, `000`, negative, decimal, a value
  with a unit, `true`). Before, the entrypoint took `0` and `000` literally
  and derived a 10-second worker timeout from them, and a value such as
  `true` was read as 1 second by the config. `retry_after` for an invalid
  value is 330, as for the default. The config falls back silently; the
  entrypoint still warns at container start.
- **The `sql_mode` refusal pointed at the wrong cause.** The message told you
  to remove `NO_BACKSLASH_ESCAPES` / `ANSI_QUOTES` from the server's or the
  user's default `sql_mode`, but QueryProxy overrides the session's mode when
  it connects, so a default cannot be the source. It now says the flag was set
  after connecting (for example by a proxy or connection pooler that rewrites
  session variables) and to look there.
- `DO LANGUAGE E'SQL' …` was refused with two messages for the same problem;
  it now gets one. The refusal itself is unchanged.

### Security (0.2.6)

Tightening of the SQL guard. Unless noted, every item below is a refusal at
submission, with the same explicit message as the existing rules. The guard
remains a layer of defence in depth; the DBA approval is still what authorizes
a statement.

- **Role and user switches are refused.** `SET [SESSION|LOCAL] ROLE`,
  `RESET ROLE` (also written `RESET "role"`), `SET … SESSION AUTHORIZATION`,
  `RESET SESSION AUTHORIZATION`, MySQL and MariaDB `SET ROLE` /
  `SET DEFAULT ROLE`, and `set_config('role', …)` /
  `set_config('session_authorization', …)`. `role` and
  `session_authorization` join the built-in list of guarded settings, which
  `QUERYPROXY_EXTRA_DANGEROUS_VARIABLES` can only extend.
- **`ALTER SYSTEM` is refused** on PostgreSQL, whatever it sets.
- **Writes to the system catalog are refused.** PostgreSQL: `INSERT`,
  `UPDATE`, `DELETE` and `MERGE` into `pg_catalog`, `pg_toast` and `pg_…`
  tables. MySQL and MariaDB: the same statements and `REPLACE` against the
  `mysql` schema. Qualified, quoted and CTE-wrapped spellings are covered.
  On MySQL and MariaDB `USE mysql` is refused as well (also inside `PREPARE`,
  `EXECUTE IMMEDIATE` and `SET STATEMENT … FOR`), so that a name without a
  schema cannot be resolved against the catalog. Reading the catalog
  (`SELECT * FROM pg_proc`, `SELECT … FROM mysql.user`) still passes.
- **`READ WRITE` is refused** in `SET TRANSACTION`, `SET SESSION
  CHARACTERISTICS`, `BEGIN` and `START TRANSACTION`, so a submission cannot
  lift a read-only transaction. `READ ONLY` still passes.
- **Writes to the MySQL / MariaDB read-only switch are refused** in every
  scope and spelling (`transaction_read_only`, `tx_read_only`, `SESSION`,
  `GLOBAL`, `@@…`, `SET STATEMENT … FOR`).
- **A MySQL / MariaDB write whose default database is `mysql` is refused at
  execution.** A table name without a schema would otherwise resolve inside
  the system schema. The executor reads `DATABASE()` on the connection before
  the first statement; a single-statement read and a connection with no default
  database are not affected.
- **`ALTER DATABASE|ROLE|USER … RESET` of a guarded setting (or `ALL`) is
  refused** on PostgreSQL, and the action of a `CREATE [OR REPLACE] RULE`
  (`DO [ALSO|INSTEAD] …`) is now inspected by the guard's own rules instead of
  being skipped.
- **SQL Server impersonation is refused:** `EXECUTE AS`, `EXEC AS`, `SETUSER`
  and `REVERT`, including inside `EXEC('…')` and `sp_executesql`, and
  `WITH EXECUTE AS` in object definitions. A column named `execute_as` and the
  text inside a string literal are not affected.
- **A very long dotted-name chain could stall a worker.** The guard re-walked
  the rest of the chain at every part, so the cost grew with the square of the
  chain length and a statement near the editor's size limit could keep a PHP
  process busy for a long time. It now scans in linear time. No refusal was
  added or relaxed by this change.
- The new rules apply inside `DO` and routine bodies, `PREPARE` and
  `EXECUTE IMMEDIATE` text, CTE bodies, `EXPLAIN ANALYZE`, MariaDB `ANALYZE`
  and `SET STATEMENT … FOR`.

## [0.2.5] - 2026-10-10

### Added (0.2.5)

- `php artisan queryproxy:mask-error-messages [--dry-run]` masks the error
  messages already stored on query requests and the copy of them inside in-app
  "request failed" notifications, using each connection's current rules (a
  request or connection that no longer exists gets the fixed message). Safe to
  run repeatedly; the immutable audit log is left untouched and the run is
  recorded as one `error_messages.masked` entry (scanned, changed, replaced by
  the fixed message, skipped; and scanned, changed, replaced by the fixed
  message and skipped for notifications) even when it stops halfway. A record
  whose connection rules cannot be read is skipped, left unchanged and reported,
  and the command exits non-zero.
- **DDL flag on requests.** A request that contains DDL now carries a `DDL`
  badge in the approvals queue and on the request page, and the Slack and
  Teams "new request" messages say so, so the approving DBA sees it before
  deciding. The flag was already computed and stored; it was never shown.

### Fixed (0.2.5)

- **A request failed by the job's `failed()` handler left no trace.** It now
  writes the `request.execution_failed` audit entry (with
  `source: job_failed`) and notifies the requester, as a failure inside the
  executor already did. A request that has already completed is still never
  flipped.
- The Teams HMAC docblock described the key as `base64_decoded_secret`; it now
  states what the code does: the secret base64-decoded in strict mode, or the
  raw secret when it is not valid base64.
- **Auditors could not reach the Requests list from the menu.** The sidebar
  hid the link for the auditor role although the page, the route and the
  policy already allowed it. Auditors now see the team's requests from the
  menu; result data stays closed to them.
- The Slack and Teams "account not linked" replies, the chat-ops setup
  steps and the add-member error pointed to "Admin → Users"; they now name
  the actual screen, Teams & Users → Manage users.

### Security (0.2.5)

- **Error messages of failed queries are masked like result data.** Driver
  errors echo the offending values (`Duplicate entry 'bob@x.io'`,
  `Key (phone)=(...)`, the SQL with its bindings), and the message was stored
  and shown unmasked in the request, the audit log and the in-app
  notification. It now goes through the connection's masking rules first:
  content rules mask matches in place, and when the message names a column
  covered by a column rule every literal value in it is masked. The raw
  message stays in the application log only. This closes the gap in ADR-014
  for new failures. It does not rewrite history: audit entries written by
  earlier versions (`request.execution_failed`, `execution.late_completion`)
  keep the raw text, because the audit log is immutable. Run
  `php artisan queryproxy:mask-error-messages` to mask the messages stored on
  query requests and in the in-app "request failed" notifications by earlier
  versions.
- **The job `failed()` safety net now redacts and masks too.** It stored the
  raw exception message; it now uses the same redact-and-mask path as the
  executor.

## [0.2.4] - 2026-10-04

### Fixed (0.2.4)

- The live PostgreSQL test that lifts a read-only transaction failed when
  `pdo_pgsql` was built against libpq older than 17: the driver frees prepared
  statements with `DEALLOCATE`, which takes a snapshot and makes PostgreSQL
  refuse the switch back to read-write. The test's setup statements now run
  unprepared. The guard itself is unchanged.

### Security (0.2.4)

- **`league/commonmark` updated to 2.10.3.** 2.10.0 is affected by
  GHSA-3q6v-r5mr-hxv8 (high) and GHSA-97jj-33gv-5xf9 (medium). The release
  image scan failed on them, so the container images for 0.2.1, 0.2.2 and
  0.2.3 were never published; 0.2.4 is the first image after 0.2.0 and
  carries all of their changes.

## [0.2.3] - 2026-10-04

### Changed (0.2.3)

- **CI and the live test setup.** `ci.yml` runs with a read-only `contents`
  token and checks out without persisting credentials, as `live-pgsql.yml`
  already did. PostgreSQL for the `live-pgsql` group (the workflow's service
  and `docker-compose.test.yml`) is initialised with `--auth-host=scram-sha-256`,
  because the image trusts loopback otherwise and the `dblink_exec` test never
  sent the password it is meant to prove it passes. The `dblink_exec` test now
  passes `host`, `port` and `password` in an escaped connection string
  (`QUERYPROXY_LIVE_PGSQL_SERVER_HOST` / `_SERVER_PORT` override the address the
  server sees itself at). The compose file listens on `55432` inside the
  container as well, so the documented command no longer needs
  `QUERYPROXY_LIVE_PGSQL_SERVER_PORT`, and keeps its data directory on tmpfs so
  every container starts from a fresh `initdb`. **If you run the compose
  example locally, recreate the container once**
  (`docker compose -f docker-compose.test.yml down`, then `up -d --wait`).

### Fixed (0.2.3)

- **A write refused by a read-only session was silently run again.** Laravel
  counts PostgreSQL SQLSTATE `25006` and MySQL/MariaDB's `--read-only` error as
  a lost connection, so outside a transaction it reconnected and re-ran the
  write on a fresh, writable session. The lost-connection detector now reports
  read-only errors as not lost and defers everything else to the framework's
  detector. **Trade-off:** after a MySQL/Aurora failover that leaves a
  connection on a read-only node, or on a PostgreSQL hot standby, the request
  fails with the database's error instead of being retried on a new connection;
  a queue worker that lands there no longer stops itself on `25006` either, so
  restart workers after such a failover.
- **Large SQL took seconds and hundreds of megabytes to inspect.** The guard
  lexed the same SQL several times per inspection, kept lexer errors with their
  backtraces and re-scanned its lexical ranges linearly. Each SQL text is now
  lexed once per inspection, dialect ranges are kept sorted and binary-searched,
  and the parser no longer leaves a cycle of garbage behind each request. On
  the worst 64 KiB SQLite input that was tried (`[a],` repeated) inspection
  went from 15.9 s and 589M of peak memory to 0.69 s and 21.5M, measured on a
  development machine under load; the verdicts do not change. New tests pin
  the lex count, the memory peak and a time bound relative to a bare lexer pass.

### Security (0.2.3)

- **PostgreSQL `DO` bodies were read with the MySQL lexer.** The guard scanned
  a `DO` body for blocked functions with MySQL's string rules, so a body such
  as `PERFORM 'a\', pg_read_file(…) --` hid the call inside what the lexer took
  for a string, while PostgreSQL ran it. Block and routine bodies (`DO`,
  `CREATE FUNCTION`, `CREATE PROCEDURE`) are now read with PostgreSQL's lexical
  rules (comments, `'…'`, `E'…'`, `U&'…'`, quoted identifiers, nested
  dollar-quoted strings) and go through the same blocked-function and
  guarded-setting rules as top-level SQL. **Behaviour change:** the following
  are now rejected.
  - Dynamic SQL (`EXECUTE`) inside a `DO` body or a function or procedure body,
    trigger functions included: the statement it runs is built at run time and
    cannot be inspected. Trigger `EXECUTE FUNCTION|PROCEDURE` clauses are not
    affected.
  - Any language other than `plpgsql` and `sql` for `DO`, `CREATE FUNCTION` and
    `CREATE PROCEDURE`. A quoted language name keeps its case, so
    `LANGUAGE "PLPGSQL"` is refused. `CREATE LANGUAGE` and
    `ALTER LANGUAGE … RENAME` are refused too.
  - `BEGIN ATOMIC` bodies, and `CREATE FUNCTION` / `CREATE PROCEDURE` without
    a body.
  - `query_to_xml*`, `ts_stat`, `ts_rewrite`, `crosstab*` and `connectby`
    join the blocked functions: they run SQL passed to them as a string.
  - A `SET` clause on a routine (`CREATE/ALTER FUNCTION|PROCEDURE … SET`), a
    database (`ALTER DATABASE … SET`) or a role (`ALTER ROLE|USER … SET`) now
    goes through the guarded-setting rule; one whose setting cannot be resolved
    is rejected.
- **`sql_mode` could change how the guard's lexer and the server read quotes.**
  `NO_BACKSLASH_ESCAPES` and `ANSI_QUOTES` change what a backslash and a double
  quote mean, so the guard could approve SQL the server then reads differently.
  `sql_mode` is now a guarded variable in every scope and on every driver:
  `SET sql_mode`, `SET SESSION sql_mode`, `SET @@sql_mode`, the `@@SESSION.` /
  `@@GLOBAL.` forms, a version comment around them and `set_config('sql_mode',
  …)` are refused. Reading it (`SELECT @@sql_mode`) is unaffected. On `mysql`
  and `mariadb` the executor also reads `@@SESSION.sql_mode` before running the
  SQL and fails the request, without running anything, when it contains
  `NO_BACKSLASH_ESCAPES` or `ANSI_QUOTES`. Today QueryProxy sets a strict
  `sql_mode` on every connection it opens (Laravel's `strict` option), which
  replaces the server's default, so the check only fires if that setting
  changes or something in between alters the mode; a connection that does end
  up with either flag is refused until the flag is removed. The `/*M!`
  behaviour on the `mysql` driver is unchanged and now pinned by a regression
  test.
- **Three `SET` spellings were not judged.** `SET GLOBAL general_log := 1`
  (a space before `:=`) read as a label and passed the dangerous-variable list;
  `SET @@general_log = 1` (no scope) was not judged at all; and in MariaDB
  `SET STATEMENT … FOR <statement>` the statement after `FOR` skipped every
  rule, so `SET STATEMENT max_statement_time=1 FOR DELETE FROM t` ran without
  a `WHERE` check. All three are now judged. The statement after `FOR` goes
  through the same rules as a top-level one (type, denylist, `WHERE`, `LIMIT`,
  nesting depth); `SET STATEMENT` without a statement after `FOR` is rejected.
  **Behaviour change:** `SET @@<name>` is read as a session variable on every
  driver, so on PostgreSQL (and when the target database is unknown)
  `SET @@general_log = 1` is now refused.

### Notes (0.2.3)

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval. Dynamic SQL
  inside them is now refused, but a plainly written `DELETE` without `WHERE`
  in a `DO` body still passes the guard.
- Not covered yet: DML against system catalogs (`UPDATE pg_language`,
  `UPDATE pg_proc SET prosrc …`; these need a superuser) and `ALTER SYSTEM SET`.
  Multibyte character sets that change backslash handling (`SET NAMES big5`,
  `sjis`, `gbk`) are not checked either.

## [0.2.2] - 2026-10-03

### Added (0.2.2)

- **Live PostgreSQL guard tests.** A `live-pgsql` Pest group in `tests/Live`
  runs the guard against a real PostgreSQL server and checks both the verdict
  and the effect on the server (settings, `dblink_exec`,
  `pg_terminate_backend`, `pg_cancel_backend`, `EXPLAIN ANALYZE DELETE`,
  `DO` blocks and more). It is skipped unless `QUERYPROXY_LIVE_PGSQL_HOST` is
  set. `docker-compose.test.yml` starts a throwaway PostgreSQL 17 for local runs,
  and the new `live-pgsql` GitHub Actions workflow runs the group in CI.

### Fixed (0.2.2)

- **The queue timing warning missed `queue:listen` and `queue:work --once`.**
  The warning that `retry_after` is at or below the execution timeout was
  logged only when a daemon `queue:work` started. It is now also logged when
  `queue:listen` or `queue:work --once` starts, once per process; the
  short-lived `queue:work --once` children that `queue:listen` spawns do not
  repeat it.
- **The Compose worker example hard-coded `--timeout=310`.** `docker-compose.yml`
  now passes the timeout the image entrypoint derives
  (`QUERYPROXY_WORKER_TIMEOUT`, `QUERYPROXY_EXECUTION_TIMEOUT` + 10), so
  changing `QUERYPROXY_EXECUTION_TIMEOUT` in the example also moves the worker
  timeout. The example needs the image's default entrypoint.

### Security (0.2.2)

- **PostgreSQL settings that load code or lift protections were not guarded.**
  The dangerous-variable list now also names `session_preload_libraries`,
  `local_preload_libraries`, `dynamic_library_path`,
  `default_transaction_read_only`, `transaction_read_only`,
  `session_replication_role`, `allow_system_table_mods`,
  `lo_compat_privileges`, `zero_damaged_pages` and `ignore_checksum_failure`.
  **Behaviour change:** on PostgreSQL (and when the target database is
  unknown) these are refused in every scope: `SET <name>`, `SET LOCAL <name>`,
  `SET SESSION <name>`, the `TO` spelling and `set_config('<name>', …)`.
  Before this release no PostgreSQL name was on the list. On MySQL, MariaDB,
  SQLite and SQL Server the `SET` rule is unchanged (only the persistent scopes
  `GLOBAL`, `PERSIST` and `PERSIST_ONLY` are judged), while
  `set_config('<name>', …)` with one of the new names is refused on every driver.
  Harmless settings such as `work_mem` or `search_path` still pass.
  `QUERYPROXY_EXTRA_DANGEROUS_VARIABLES` still extends the list.
- **MariaDB `ANALYZE <statement>` ran its statement unchecked.** Like
  `EXPLAIN ANALYZE`, MariaDB's `ANALYZE [FORMAT = x] <statement>` executes the
  statement. It is now judged as the statement inside it (type, denylist,
  `WHERE` rule, `LIMIT`, nesting depth). Only `SELECT`, `WITH`, `INSERT`,
  `REPLACE`, `UPDATE`, `DELETE` or a parenthesized query may follow; anything
  else (`DROP`, `TRUNCATE`, `SET`, a nested `ANALYZE`, …), and a parenthesized
  `(ANALYZE <statement>)`, is rejected. The mysql driver and an unknown driver
  are judged the same way. `ANALYZE TABLE …` (maintenance) is unchanged.
- **Multibyte comments could hide the real statement from `EXPLAIN ANALYZE` and
  `ANALYZE`.** The guard cut the inner statement at a character offset where a
  byte offset was needed, so a comment with non-ASCII text in front of it
  shifted the cut and let a `DELETE` without `WHERE` through. Offsets are now
  converted to bytes.
- **MariaDB executable comments were not inspected.** `/*M!100000 … */` was
  treated as a plain comment, so its body ran on MariaDB without being checked.
  **On the `mariadb` driver** its body is now read as SQL, whatever the version
  condition. On the `mysql` driver `/*M!` is still a plain comment, so connect
  a MariaDB server with the `mariadb` driver. Nesting deeper than 3 levels is
  rejected, and when the target database is unknown a `/*M!` comment is
  rejected outright.
- **Version-conditional comments (`/*!NNNNN … */`) could hide a clause from the
  guard.** A server skips the body when its version is lower than the one
  written, so a `WHERE` or `LIMIT` inside it may or may not exist. On the
  `mysql` and `mariadb` drivers the statement is now checked twice, once as if
  the bodies run and once as if the server skips them, and is rejected when
  either reading is refused or when the two readings differ in statement count,
  transaction state, type or prepared text. **Behaviour change:** these are now
  rejected on `mysql` and `mariadb`, which 0.2.1 accepted: different versions in
  one request (`SELECT /*!40101 1 */, /*!50503 2 */` is one), a version of other
  than 5 digits (5 or 6 on MariaDB), a versioned comment nested in or holding
  another comment, and one with `*/` inside a string or line comment in its
  body. A request with a single version and a plain body is unaffected.
- **SQL that was not valid UTF-8 slipped past the lexer.** A stray byte inside
  a comment (`DROP DATABASE prod /* é\xFF */`) made the guard read an empty
  statement. SQL that is not valid UTF-8 is now rejected
  (`SQL must be valid UTF-8.`), and so is text in which the lexer finds no
  statement (`QueryProxy could not read this SQL: …`).
- **`UPDATE` / `DELETE` with an always-true `WHERE` passed.** `WHERE 1 = 1`,
  `WHERE true`, `WHERE 'a' = 'a'` or an `OR` with such an operand counted as a
  `WHERE` clause. **Behaviour change:** these are now rejected like a missing
  `WHERE`, in every statement the guard looks into (CTE bodies, `EXPLAIN
  ANALYZE`, MariaDB `ANALYZE`, T-SQL batches, dynamic SQL). Detection is
  static: literals, comparisons, `IS [NOT] NULL|TRUE|FALSE`, `AND`, `OR`,
  `NOT` and parentheses are evaluated, and an operand it cannot evaluate never
  counts as true. A `WHERE` clause nested too deeply or too large to evaluate
  is rejected as well (`… too complex to verify`).
- **A byte-order mark or Unicode whitespace could hide keywords.** The lexer
  glues characters such as U+FEFF, U+00A0, U+2028 or U+3000 to the neighbouring
  word while a server may read them as separators, so `\u{FEFF}DROP DATABASE
  prod` showed no `DROP`. **Behaviour change:** these characters are rejected
  outside string literals, quoted identifiers and comments, on every driver and
  inside dynamic SQL.
- **PostgreSQL reads `\` and `#` differently from the lexer.** On PostgreSQL
  (and when the target database is unknown) a backslash is now rejected
  anywhere except in comments, dollar-quoted bodies, `E'…'` strings and
  well-formed `U&` strings, and `#` is rejected outside literals, quoted
  identifiers and comments. A plain string such as `'a\'` is therefore
  rejected on PostgreSQL even though MySQL and MariaDB behave as before.

### Notes (0.2.2)

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval, where the
  reviewer sees the whole body. A `DELETE` without `WHERE` inside a
  `DO` block therefore passes the guard.

## [0.2.1] - 2026-10-02

### Fixed (0.2.1)

- `retry_after` for the database, Beanstalkd and Redis queues no longer falls
  back to Laravel's 90 seconds, which is below the worker's 310-second timeout
  and let a slow query be re-reserved while it was still running and then marked
  failed (notably with `QUEUE_CONNECTION=redis`). It is now derived from
  `QUERYPROXY_EXECUTION_TIMEOUT` (timeout + 30 seconds, 330 by default)
  instead of a fixed number. An explicit `*_QUEUE_RETRY_AFTER` still wins; a
  value at or below the timeout logs a warning when a `queue:work` worker
  starts. The Docker entrypoint derives the worker `--timeout`
  (timeout + 10) and `stopwaitsecs` (timeout + 20) the same way.
- **A query that finished late could turn a Failed request into Completed.** The
  executor now moves a request to Completed only if it is still Running. A late
  result is discarded, the result file is removed, and an
  `execution.late_completion` audit entry is written; for a write it records
  that the write was committed.

### Security (0.2.1)

- **`EXPLAIN ANALYZE <DML>` was classified as a read and ran the DML.**
  `EXPLAIN ANALYZE` executes the statement it explains, but the guard treated
  every `EXPLAIN` as a read, so `EXPLAIN ANALYZE DELETE FROM t` skipped the
  `WHERE` rule, the write classification and the approval flow. An `EXPLAIN`
  that analyzes is now judged as the statement inside it (type, denylist and
  `WHERE` rule); `EXPLAIN` without `ANALYZE` stays a read. The option list is
  checked against PostgreSQL's known options, and an option block the guard
  cannot read (quoted or unknown options, an unclosed list, an expression value)
  is rejected.
- **`SELECT … INTO <table>` created a table but was classified as a read.** It
  is now a write and DDL, and no `LIMIT` is added. `SELECT … INTO @var` stays a
  read; `INTO OUTFILE` / `INTO DUMPFILE` were already denied.
- **Functions with side effects passed as reads.** Calling `nextval`, `setval`,
  the advisory-lock family, `get_lock`, `release_lock`, `pg_sleep*`, `sleep` or
  `benchmark` now makes a statement a write. `pg_terminate_backend`,
  `pg_cancel_backend`, `pg_reload_conf`, `pg_rotate_logfile`, `pg_promote`,
  `pg_switch_wal`, `dblink*` and the server-side file functions
  (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `lo_import`, `lo_export`,
  `load_file`) are denied. `set_config` is a write, and is denied when its first
  argument is not a literal. Names are matched after quoting and `U&` escapes
  are resolved. `dblink*` is a prefix match, so a user-defined function that
  starts with `dblink` is denied too. The new
  `QUERYPROXY_EXTRA_BLOCKED_FUNCTIONS`,
  `QUERYPROXY_EXTRA_STATE_CHANGING_FUNCTIONS` and
  `QUERYPROXY_EXTRA_RESOURCE_CONSUMING_FUNCTIONS` settings extend these lists,
  and `QUERYPROXY_EXTRA_BLOCKED_PROCEDURES` (SQL Server, below) and
  `QUERYPROXY_EXTRA_SQLITE_PRAGMAS` (SQLite, below) do the same for theirs;
  they can only add to the built-in entries.
- **SQL Server and SQLite constructs that reach outside the database were not
  covered.** On SQL Server the guard now denies `xp_*`, `sp_configure`,
  `sp_oa*`, `sp_addlinkedserver`, `sp_addlinkedsrvlogin`, `sp_serveroption`,
  `sp_addextendedproc`, `sp_execute_external_script`, `OPENROWSET`,
  `OPENDATASOURCE`, `OPENQUERY` and `BULK INSERT`, and also denies `DENY`
  statements. A batch without semicolons is split at T-SQL statement boundaries
  and every part is checked, so `SELECT 1 DELETE FROM t` is rejected. On SQLite
  it denies `ATTACH`, `DETACH`, `VACUUM … INTO` and `load_extension(`, and only
  allows a built-in set of read-only `PRAGMA`s (`table_info`, `table_xinfo`,
  `index_list`, `index_info`, `index_xinfo`, `foreign_key_list`,
  `database_list`, `table_list`, `collation_list`, `function_list`,
  `compile_options`), which `QUERYPROXY_EXTRA_SQLITE_PRAGMAS` can extend (bare
  form only); any `PRAGMA` that sets a value, and any not on the list, is
  rejected. The connection form warns when `QUERYPROXY_SQLITE_ALLOWED_DIR` is
  empty.
- **Dynamic SQL was not inspected.** `EXEC('…')`, `sp_executesql`,
  `EXECUTE IMMEDIATE '…'`, MySQL `PREPARE … FROM '…'` and PostgreSQL
  `PREPARE … AS <statement>` are now checked together with the SQL they carry.
  When the SQL is a literal it is judged by the same rules as a top-level
  statement; when it is a variable, an expression, a concatenation or an
  `EXEC(…) AT <server>`, the request is rejected. **This can reject legitimate
  use**: code that builds SQL at runtime and passes it through these forms no
  longer runs through QueryProxy. **Behaviour change:** on SQL Server these 14
  system procedures are rejected whatever their arguments are: `sp_prepare`,
  `sp_prepexec`, `sp_prepexecrpc`, `sp_execute`, `sp_cursoropen`,
  `sp_cursorprepare`, `sp_cursorprepexec`, `sp_msforeachtable`,
  `sp_msforeachdb`, `sp_msforeach_worker`, `sp_sqlexec`, `sp_send_dbmail`,
  `sp_add_jobstep` and `sp_update_jobstep`. `sp_executesql` is not on the list;
  its SQL text is inspected. Dynamic SQL nested more than 3 levels deep is
  rejected.
- **`UPDATE` / `DELETE` inside a CTE skipped the `WHERE` rule.** The bodies of
  `WITH … AS (…)` and the statement that follows the `WITH` are now checked,
  including nested `WITH`s up to 3 levels; a `WITH` the guard cannot read is
  rejected. `INSERT` and `MERGE` bodies are not checked separately; the outer
  statement is a write.
- **SQL Server `TOP` was only half enforced.** A `SELECT` without `TOP` was not
  bounded by the guard, and `TOP` values with `PERCENT`, decimals or expressions
  were read as plain numbers. An existing `TOP n` / `TOP (n)` stays capped
  at 10000; now a top-level `SELECT` without `TOP` gets
  `TOP (<default row limit>)`, and `TOP … PERCENT`, expressions and decimals
  are rejected. For `WITH`, `OFFSET`, `UNION`, `EXCEPT` and `INTERSECT`
  no `TOP` is added; the executor's row cap applies.
- **New teams started with no masking rules.** A team created from now on starts
  with the default masking rules. Existing teams are not changed (see
  Upgrading).

### Upgrading (0.2.1)

- **Remove the fixed `retry_after` lines from your `.env`.** The 0.2.0
  `.env.example` shipped `DB_QUEUE_RETRY_AFTER=330` as an active setting, and an
  explicit value always wins over the derived one. If that line is still in your
  `.env` (or `REDIS_QUEUE_RETRY_AFTER=330`, `BEANSTALKD_QUEUE_RETRY_AFTER`), delete
  it unless you set it on purpose; otherwise raising
  `QUERYPROXY_EXECUTION_TIMEOUT` leaves `retry_after` at 330, a long query is
  re-reserved while it still runs, and a write can commit while its request is
  marked Failed. A `--timeout` set by hand on a separately run worker (the
  Docker image derives it itself) should be the execution timeout + 10 seconds.
- **Existing teams do not get masking rules automatically.** A DBA opens the
  team's **Masking** page and runs **Add default rules**. Query Studio and the
  Masking page now show a banner for a team that has no masking rules, or none
  enabled.
- **Some statements now need approval.** `SELECT` statements that call
  `nextval`, `setval`, an advisory-lock function, `get_lock`, `release_lock`,
  `pg_sleep*`, `sleep`, `benchmark` or `set_config` are classified as writes, so
  they go through the approval flow.
- **Some statements that used to pass are now rejected.** In particular:
  `EXPLAIN ANALYZE` on a `DELETE` / `UPDATE` without `WHERE`, dynamic SQL built
  from a variable or an expression, the 14 SQL Server procedures listed under
  Security, SQL Server `TOP … PERCENT`, any `PRAGMA` outside the allowed set on
  SQLite, and, on PostgreSQL, any `UESCAPE` clause and any `EXPLAIN` option
  block the guard cannot read. `SELECT … INTO <table>` is now a write.
- **SQL Server `SELECT` without `TOP` now returns 1000 rows by default.** The
  previous ceiling was 10000.
- The full list is in the
  [SQL guards wiki page](https://github.com/QueryProxy/QueryProxy/wiki/SQL-guards).

## [0.2.0] - 2026-09-18

### Changed (0.2.0)

- **Breaking — set `TRUSTED_PROXIES` when running behind a reverse proxy.** It is
  empty by default. Until it names your proxy, `X-Forwarded-Proto` is ignored, so
  HTTPS is not detected and audit entries record the proxy's address rather than
  the client's. Never use `*`.
- **Breaking — a system administrator must set the ChatOps signing secret.**
  DBAs keep control of the webhook URL and the enabled flag, but can no longer
  enter or rotate the secret. Existing installations should rotate it once after
  upgrading: any DBA who configured ChatOps already knows the current value.
- **Breaking — Approve / Reject buttons in chat messages posted before this
  version stop working**, because they carry no action token. Decide those
  requests in the web UI. Teams automations must start sending the `token` field
  from the card's `queryproxy` envelope.
- A TOTP code is now single-use, so signing in on a second device within the same
  30-second window requires waiting for the next code.
- Removing a ChatOps integration is administrator-only, matching the bar for
  setting its signing secret — a DBA could otherwise delete an integration and
  then be unable to put it back. DBAs still untick **Enabled** to switch one off.
- `TRUSTED_HOSTS` accepts extra hostnames for installations served under more
  than one name, since `X-Forwarded-Host` is no longer honoured.
- New content masking rules apply to new teams only. Run **Masking → Add
  defaults** per team to pick them up; it is idempotent and leaves existing rules
  untouched.

### Security (0.2.0)

- **ChatOps approvals could be made in someone else's name.** The webhook HMAC
  proves only that the caller knows the signing secret, but the approver's
  identity was read straight out of the callback body — and any team DBA could
  overwrite the signing secret without knowing the old one. Together that let a
  DBA approve their own request as a colleague (or as an admin), defeating the
  four-eyes rule and writing that colleague's name into the audit trail. Signing
  secrets are now administrator-only and rotating one requires the current
  secret; every Approve / Reject control carries a single-use action token bound
  to one request; and the resolved approver must belong to the integration's team.
- **Every proxy was trusted (`trustProxies(at: '*')`).** Any client could forge
  `X-Forwarded-For` and get unlimited login and 2FA attempts (both throttles are
  keyed per IP) and write a false client IP into the audit log, while a forged
  `X-Forwarded-Host` rewrote the link in password-reset mail. Trust is now opt-in
  through `TRUSTED_PROXIES`, `X-Forwarded-Host` is no longer honoured, and
  `trustHosts` pins the request host to `APP_URL`.
- **Content masking rules silently skipped non-string values.** PDO returns
  native integers for `BIGINT`/`DECIMAL`, so a numeric column that matched no
  column pattern — a card number or a national id stored as `BIGINT` — reached
  the result store unmasked. Content rules now see every scalar.
- **The SQL guard's denylist had bypasses.** `SET @@GLOBAL.…` walked straight
  past the `SET GLOBAL` rule (arbitrary file write through the MySQL general
  log), and `CREATE FUNCTION … SONAME`, `pg_read_file()` and friends were not
  listed at all. The guard now judges the dangerous part rather than the
  keyword: `CREATE EXTENSION` and `DO` are refused for untrusted procedural
  languages, `SET GLOBAL` / `SET PERSIST` for file-path, logging and plugin
  variables, and a `DO` body is scanned for the unconditionally blocked
  statements — so `DO $$ BEGIN DROP DATABASE prod; END $$` does not get through.
  That scan is best-effort by nature: SQL a block assembles at run time
  (`EXECUTE format(…)`) cannot be read statically, and the DBA approval remains
  the real boundary.
- **TOTP codes could be replayed** for the ~90 seconds their window stayed open;
  an accepted code is now burned via `two_factor_last_used_timestamp`.
- **Chat webhook URLs leaked into the logs.** A connection error carried the full
  URL — and for Slack the secret *is* the URL path — into `laravel.log`.
- **CSV exports did not guard the header row** against spreadsheet formula
  injection, although the data rows did; column names come from the target schema
  or the requester's own aliases.
- **Query results could be published without authorization** if the operator
  pointed `QUERYPROXY_RESULT_DISK` at a public disk: result paths are guessable,
  so `storage:link` exposed every team's rows. Such a disk is now refused.
- Default masking rules gained content patterns for vendor API keys, JWTs,
  PEM private keys and high-entropy secrets, so a secret hidden behind a column
  alias (`SELECT api_key AS k`) is still masked. The credit-card content rule was
  narrowed to real IIN ranges so it no longer fires on epoch timestamps.
- Third-party GitHub Actions are pinned to commit SHAs, and the release workflow
  scans the image before any registry credential is on the runner.

## [0.1.3] - 2026-09-08

### Changed (0.1.3)

- Image bases moved to `php:8.5-fpm-alpine` and `node:26-alpine`. OPcache is no
  longer installed explicitly: PHP 8.5 links it statically and enables it by
  default, and `docker-php-ext-install opcache` fails there.
- Release workflow actions updated to `docker/login-action@v4`,
  `setup-buildx-action@v4`, `setup-qemu-action@v4` and `metadata-action@v6`.

## [0.1.2] - 2026-09-08

### Added (0.1.2)

- `php artisan queryproxy:create-admin` creates the first administrator, and
  `QUERYPROXY_ADMIN_EMAIL` / `QUERYPROXY_ADMIN_PASSWORD` / `QUERYPROXY_ADMIN_NAME`
  create it on first boot — a fresh instance no longer needs demo seeding to
  have an account to log in with.
- Container `HEALTHCHECK` against `/up`, so `docker run` reports health without
  a compose file.

### Changed (0.1.2)

- **One-container install.** The image now runs the queue worker and the
  scheduler alongside the web tier, so `docker run -p 7432:7432 -v
  queryproxy-data:/var/www/html/storage/app queryproxy/queryproxy` is a
  complete instance. `QUERYPROXY_RUN_WORKER` / `QUERYPROXY_RUN_SCHEDULER` turn
  them off for split deployments, and `QUERYPROXY_WORKER_PROCESSES` runs more
  than one worker. `docker-compose.yml` collapsed to that single service.
- **One volume.** The SQLite database and the generated `APP_KEY` moved next to
  the result files under `/var/www/html/storage/app`. Volumes from earlier
  versions keep working: an existing `/var/www/html/database/database.sqlite`
  (or `.app_key`) is still used when present.
- Releases publish to Docker Hub (`queryproxy/queryproxy`) as well as GHCR.

## [0.1.1] - 2026-09-08

### Added (0.1.1)

- `.dockerignore`, so local `docker build` uses the same clean context as CI
  instead of copying host `vendor/`, `node_modules/` and `.env` into the image.

### Fixed (0.1.1)

- Demo seeding (`QUERYPROXY_SEED_DEMO=true`) crashed the production container
  on first boot: the seeder relied on Faker, which is a dev-only dependency and
  absent from `--no-dev` installs. The seeder no longer uses factories.

## [0.1.0] - 2026-09-08

The first public release.

### Added (0.1.0)

- **Teams & RBAC** — Admin / DBA / Developer / Auditor roles, hard team
  isolation, per-developer connection grants.
- **Connection vault** — PostgreSQL, MySQL, MariaDB, SQL Server and SQLite
  targets with AES-256-encrypted credentials and a connection tester.
- **Query Studio** — CodeMirror editor with server-side AST guards:
  WHERE-less `UPDATE`/`DELETE` rejected, `LIMIT` injection (default 1000) and
  clamping (cap 10000), explicit-transaction rule for multi-statement
  submissions, administrative statements blocked.
- **Approval workflow** — pending queue, mandatory rejection reasons,
  self-approval prevention with audited admin override, requester
  cancellation, in-app notifications.
- **ChatOps** — interactive Slack approvals (HMAC-SHA256 `v0` signatures,
  ±5-minute replay window, member-ID identity mapping) and a Teams
  HMAC-verified action endpoint plus channel announcements.
- **Async execution** — database-backed queue worker, cursor streaming to
  NDJSON result files with constant memory, atomic multi-statement
  transactions with rollback on failure.
- **Dynamic data masking** — column-pattern and content-regex rules with
  full / partial / hash strategies, applied at write time; default rule set
  and a test playground.
- **Results** — paginated viewer, streamed CSV export, configurable retention
  with automatic pruning.
- **Immutable audit log** — every security-relevant event, filterable auditor
  UI, CSV export.
- **Password management** — profile page (name + password change),
  enumeration-safe e-mail reset flow, admin password reset.
- **Deployment** — zero-config `docker compose up` (app + worker + scheduler,
  SQLite default), published container image `ghcr.io/queryproxy/queryproxy`.

[Unreleased]: https://github.com/QueryProxy/QueryProxy/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/QueryProxy/QueryProxy/compare/v0.3.3...v0.4.0
[0.3.3]: https://github.com/QueryProxy/QueryProxy/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/QueryProxy/QueryProxy/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/QueryProxy/QueryProxy/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.7...v0.3.0
[0.2.7]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.6...v0.2.7
[0.2.6]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.5...v0.2.6
[0.2.5]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
