# Cloud storage providers

All five providers below are built in. Select the destination in **Settings**, enter its credentials, save the connection and use **Test Settings**. A connection test lists the destination, uploads a random temporary file, verifies its downloaded contents and deletes it. Credentials use the existing authenticated credential-encryption and recovery-key system.

Cloud connections require HTTPS. They verify server certificates by default and honor the SSL verification and CA settings under **Expert settings**. Disabling SSL stops the connection. Redirects are followed only where the provider explicitly obtains and validates a content endpoint; credentials are not forwarded to unrelated content hosts.

Uploads use bounded memory: Azure, B2 and Google Cloud send 8 MiB chunks; OneDrive sends 10 MiB fragments; pCloud streams directly from the local file through PHP cURL. A retried backup can restart an upload and replace the same remote name. Failed B2 large-file uploads and OneDrive sessions are cancelled when the process can perform cleanup; a killed process may leave an uncommitted upload, so configure the service's lifecycle rules as appropriate. Downloads stream to a temporary sibling file and replace the local archive only after the expected byte count is received. These clients restart interrupted downloads in full.

## Microsoft Azure Blob Storage

Create a private Blob container. Enter the storage **account name**, its base64 **account key**, the **container name** and an optional folder prefix. The default endpoint suffix is `blob.core.windows.net`; `blob.core.usgovcloudapi.net` and `blob.core.chinacloudapi.cn` are also supported. The retired Germany endpoint is rejected.

The implementation uses [Shared Key authorization](https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key) and [block-blob commits](https://learn.microsoft.com/en-us/rest/api/storageservices/put-block-list). The account must permit Shared Key authorization. Container creation, SAS and Entra ID authentication are not provided. Deletion includes blob snapshots; Azure versioning, soft-delete and retention policies can retain service-managed copies.

Run `php scripts/test-azure.php` for signatures, encoded paths, pagination, binary/empty transfers, block commits, failed uploads and malformed XML.

## Backblaze B2

Create a B2 bucket and an application key with `listFiles`, `readFiles`, `writeFiles` and `deleteFiles` access to that bucket and prefix. Enter the **application key ID**, **application key**, **bucket ID** (not its display name) and optional prefix. Restricted bucket keys do not need permission to list all buckets.

The provider uses the [B2 Native API](https://www.backblaze.com/apidocs/introduction-to-the-b2-native-api), including authorization, upload URLs, SHA1 checksums and large-file assembly. Archives larger than 8 MiB use large-file uploads. Individual archives must be smaller than 80 GiB; use BackupPlus's archive splitting for larger backups. Retention deletes every version of each selected exact filename, including hide markers; it preserves other names that share the prefix. Object Lock or retention policies can prevent deletion and will produce an error.

Run `php scripts/test-backblaze.php` for authorization, pagination, part checksums, cancellation, and deletion across file versions.

## Google Cloud Storage

Create a bucket. The recommended connection uses a service account with **Storage Object Admin** access to that bucket: paste its downloaded JSON key into **Service account JSON key**. A bucket name and optional prefix complete the configuration. The token endpoint is fixed to Google's OAuth service; a `token_uri` from an imported key cannot redirect credentials elsewhere.

Alternatively supply your own OAuth **client ID**, **client secret** and **refresh token** with the `https://www.googleapis.com/auth/devstorage.read_write` scope. This provider does not use Google Drive credentials or the upstream authentication broker. Existing broker-only settings need a service account or custom OAuth credentials before they can transfer files.

The provider uses the [Cloud Storage JSON API](https://docs.cloud.google.com/storage/docs/json_api) and [resumable upload protocol](https://docs.cloud.google.com/storage/docs/performing-resumable-uploads). Service-account assertions use RS256 through the bundled phpseclib library. Bucket creation is not provided. Service-side versioning, soft-delete and retention policies can retain historical copies after deletion.

Run `php scripts/test-googlecloud.php` for OAuth refresh/retry, independently verified service-account signatures, pagination, upload fragments and atomic downloads.

## Microsoft OneDrive

Register your own Microsoft Entra **Web** application for the account types you need. Configure delegated `Files.ReadWrite` and `offline_access` permissions and create a client secret. Register this redirect URI, replacing the site and WordPress administration path:

```text
https://YOUR-SITE/wp-admin/options-general.php?action=backupplusmethod-onedrive-auth&page=backupplus
```

Enter the application ID, secret and tenant (`common` by default), save the destination, then use its authentication link. You can instead enter an existing refresh token issued for that application. Renewed refresh tokens are saved to the same connection without replacing unrelated settings. An optional **Drive ID** selects another drive accessible to the account; otherwise the connection uses the account's own drive. The configured folder must already exist; an empty path selects the root. Use folder names rather than a sharing URL.

This implementation targets the global [Microsoft Graph driveItem API](https://learn.microsoft.com/en-us/graph/api/resources/driveitem?view=graph-rest-1.0). Uploads follow Graph's [upload-session fragment requirements](https://learn.microsoft.com/en-us/graph/api/driveitem-createuploadsession?view=graph-rest-1.0). National Graph deployments and the retired Germany endpoint are not supported. OneDrive's recycle-bin and service retention policies still apply to deleted files.

Run `php scripts/test-onedrive.php` for pagination, aligned fragment uploads, refresh-token rotation, credential isolation and administrator-bound, expiring, one-use consent callbacks.

## pCloud

Enter an OAuth access token and choose **1** for the United States or **2** for Europe. Alternatively register a [pCloud application](https://docs.pcloud.com/) and enter its ID and secret. Register this redirect URI:

```text
https://YOUR-SITE/wp-admin/options-general.php?action=backupplusmethod-pcloud-auth&page=backupplus
```

Save the connection and use its authentication link. The callback selects the authorized account's data region. Enter a backup-folder path, which is created on upload; an empty path selects the root. The `pclauth` token field is included in credential encryption by the provider's own bootstrap hook.

The provider uses pCloud's [HTTP/JSON protocol](https://docs.pcloud.com/protocols/http_json_protocol/uploading_files.html), streaming raw file contents to `uploadfile` and validating the returned filename and size. PHP cURL is required for these streamed uploads. Downloads use validated content links without forwarding the account token. pCloud's trash and revision policies still apply after deletion.

Run `php scripts/test-pcloud.php` for region selection, folder creation, binary/empty streaming, API-level errors, token encryption policy and consent callbacks.

## Tests and independence

`make test-unit` runs the provider suites automatically. `make test-integration` includes the real HTTPS streaming transport test; it does not require cloud accounts. `make test-wordpress` checks settings, credential lifecycle, individual provider removal and each provider installed on its own. Every provider can be removed by deleting its entry file and its own helper directory; the other providers continue to work.

Fake API tests validate the implemented contracts and failure handling. They do not substitute for acceptance testing with each service's live account permissions and retention configuration.
