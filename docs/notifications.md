# Notifications

BackupPlus reports through email, a Slack-compatible webhook (a URL in the report address) and [ntfy](https://ntfy.sh). Channels are independent: ntfy can be used next to email or on its own, with no report address configured.

## ntfy

**Settings → Reporting → ntfy** takes a server (default `https://ntfy.sh`; `http` or `https`, any port, for a self-hosted server), a topic (letters, digits, `_` and `-`, up to 64 characters) and an optional access token. A topic enables ntfy; clearing it disables it. Invalid values are dropped when saving.

ntfy receives:

- the report after every backup, with the same text as the email, shortened to ntfy's 4096 byte message limit. A successful backup is published at default priority, one with warnings carries a warning tag, and one with errors or an aborted one is published at high priority. Tapping the notification opens BackupPlus.
- storage alerts and quota notices. An alert counts as delivered when either email or ntfy accepted it; it is retried only when both failed.
- credential key rotation notices.

Messages are published as JSON to the server root, so titles with any character are safe. The token is sent as a `Bearer` header, and redirects are not followed so a server can never forward it to another host. Failures are logged in the backup log without the topic or token.

The topic and the token are encrypted at rest with the other credentials (see [stored credential encryption](credential-encryption.md)) and are left out of settings exports. On a public server the topic is the only access control, so use a hard-to-guess name or an access token.

Tests: `.cache/php-tools/vendor/bin/phpunit --testsuite unit --filter NtfyTest`.
