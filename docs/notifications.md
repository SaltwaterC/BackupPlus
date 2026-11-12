# Notifications

BackupPlus reports through email, a Slack-compatible webhook (a URL in the report address) and [ntfy](https://ntfy.sh). Channels are independent: ntfy can be used next to email or on its own, with no report address configured.

## ntfy

**Settings → Reporting → ntfy** takes a server (default `https://ntfy.sh`; `http` or `https`, any port, for a self-hosted server), a topic (letters, digits, `_` and `-`, up to 64 characters) and an optional access token. A topic enables ntfy; clearing it disables it. Invalid values are dropped when saving.

**The default server is public.** `ntfy.sh` is a public service, and a topic there has no access control of its own: anyone who knows or guesses the topic name can subscribe and read every report, which names the site, its backup files and any errors. Treat the topic as a password. Use a long random topic (the settings page suggests one, `backupplus-` followed by 32 random hexadecimal characters; it is only a suggestion and is not saved unless you enter it), or run your own ntfy server with access control and enter an access token. BackupPlus does not generate a topic for you, because entering a topic is what turns ntfy on.

An `http://` server address still works, but the notifications and the access token are then sent unencrypted. The settings page shows a warning next to such an address, and every notification sent over it adds a line to the backup log (without the topic or token). Use `https://` unless the server is only reachable over a trusted private network.

ntfy receives:

- the report after every backup, with the same text as the email, shortened to ntfy's 4096 byte message limit. A successful backup is published at default priority, one with warnings carries a warning tag, and one with errors or an aborted one is published at high priority. Tapping the notification opens BackupPlus.
- storage alerts and quota notices. An alert counts as delivered when either email or ntfy accepted it; it is retried only when both failed.
- credential key rotation notices.

Messages are published as JSON to the server root, so titles with any character are safe. The token is sent as a `Bearer` header, and redirects are not followed so a server can never forward it to another host. Failures are logged in the backup log without the topic or token.

The topic and the token are encrypted at rest with the other credentials (see [stored credential encryption](credential-encryption.md)) and are left out of settings exports.

Tests: `.cache/php-tools/vendor/bin/phpunit --testsuite unit --filter NtfyTest`.
