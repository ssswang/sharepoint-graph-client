# SharePoint Graph Client

A SharePoint client library for PHP 8, backed by the **Microsoft Graph API**.

This library is a rewrite of the [WeAreArchitect SharePoint OAuth App Client](https://github.com/wearearchitect/sharepoint-oauth-app-client)
(`ssswang/sharepoint-oauth-app-client`), which used the legacy SharePoint REST API
(`_api/web/...`) with tokens issued by the Azure Access Control Service (ACS). That
stack is deprecated: ACS was retired, and Microsoft recommends the Graph API for all
new SharePoint development. This rewrite targets the Graph API exclusively and
requires PHP 8.1+.

## Requirements

- PHP 8.1 or newer
- An Azure AD application (app registration) with permissions to the target SharePoint site

## Installation

```sh
composer require ssswang/sharepoint-graph-client
```

## Azure AD application setup

1. Register an application in **Entra ID (Azure AD) → App registrations**.
2. Grant **application permissions** (app-only):
   - `Sites.ReadWrite.All` (all sites), or preferably `Sites.Selected` (specific sites, granted via the Graph API/PIM by an admin).
   - Add `User.Read.All` if you need tenant-wide user lookups.
3. Grant **admin consent**.
4. Authenticate with either a **client secret** or a **certificate**.

## Quick start

```php
use SharepointGraphClient\GraphSite;

$site = GraphSite::create('https://contoso.sharepoint.com/sites/team', [
    'site' => [
        'tenant'    => '11111111-2222-3333-4444-555555555555',   // Azure AD Tenant ID (GUID)
        'client_id' => '00000000-0000-0000-0000-000000000000',
        'secret'    => 'your-client-secret',

        // ...or certificate credentials instead of a secret:
        // 'certificate' => [
        //     'private_key' => '/path/to/key.pem',  // or the PEM contents
        //     'thumbprint'  => 'base64url-sha1-thumbprint', // (x5t)
        // ],
    ],
]);
```

The Access Token is requested automatically (and refreshed when expired or rejected
mid-flight) using the OAuth 2.0 *client credentials* flow against the Microsoft
identity platform. You can also inject a delegated token obtained elsewhere:

```php
$site->setGraphAccessToken(new GraphAccessToken([
    'access_token' => 'eyJ0eXAi...',
    'expires_in'   => 3600,
]));
```

### Lists and items

```php
use SharepointGraphClient\GraphList;

// all lists of the site (allowed template types only)
$lists = GraphList::getAll($site);

// by title or GUID
$list = GraphList::getByTitle($site, 'Projects');
$list = GraphList::getByGUID($site, '00000000-0000-0000-0000-000000000000');

// items (paginated automatically)
$items = $list->getGraphItems();

$item = $list->getGraphItem('42');
$item = GraphItem::getByTitle($list, 'My entry');

// CRUD
$created  = $list->createGraphItem(['Title' => 'New entry', 'Status' => 'Open']);
$created->update(['Status' => 'Done']);
$created->delete();

// columns/fields
$columnId = $list->createGraphColumn([
    'name' => 'Status',
    'choice' => ['choices' => ['Open', 'Done']],
]);
$columns = $list->getGraphColumns();
```

### Document libraries, folders and files

Document libraries are addressed as **drives**:

```php
$drive = $site->getDefaultGraphDrive();           // or getGraphDriveByName('Documents')
$root  = $drive->getRootGraphFolder();
$docs  = $drive->getGraphFolderByPath('Shared Docs');

$sub = GraphFolder::create($root, 'Archive');
$files = $docs->getGraphItems();                  // folders and files, keyed by item ID

// upload (simple, up to 250 MB)
$file = GraphFile::create($docs, 'hello world', 'hello.txt', overwrite: true);
$file = GraphFile::create($docs, new SplFileInfo('/path/to/report.pdf'));

// upload large files with a resumable upload session (chunked)
$file = GraphFile::createResumable($docs, new SplFileInfo('/path/to/huge.zip'));

// or drive the session manually (replaces startUpload/continueUpload/finishUpload)
$session = GraphUploadSession::create($docs, 'huge.zip', overwrite: true);
$session->uploadChunk($chunk1, offset: 0, totalSize: 10485760);
$session->uploadChunk($chunk2, offset: 5242880, totalSize: 10485760);
// ...the final chunk returns the GraphFile

// download
$contents = $file->getContents();

// update, move, copy, delete
$file->update('new contents');
$file->move($sub, 'renamed.txt');                 // same drive only (Graph limitation)
$copied = $file->copy($sub);                      // async operation, monitored automatically
$file->delete();

// the underlying list item of a file
$listItem = $file->getGraphListItem();
```

### Users

```php
use SharepointGraphClient\GraphUser;

$user = GraphUser::getByAccount($site, 'user@test.com');
$user = GraphUser::getByEmail($site, 'user@test.com');
$all  = GraphUser::getAll($site);

// current user (requires a delegated access token)
$me = GraphUser::getCurrent($site);
```

## Configuration reference

| Key            | Description                                                        | Default                                |
| -------------- | ------------------------------------------------------------------ | -------------------------------------- |
| `tenant`       | Azure AD Tenant ID (GUID)                                   | required                               |
| `client_id`    | Azure AD application (client) ID                                   | required                               |
| `secret`       | Client secret (or use `certificate`)                               | null                                   |
| `certificate`  | `private_key`, `private_key_passphrase`, `thumbprint`              | null                                   |
| `authority`    | Identity platform endpoint (national clouds)                       | `https://login.microsoftonline.com/`   |
| `scope`        | OAuth scope                                                        | `https://graph.microsoft.com/.default` |
| `graph`        | Graph endpoint (national clouds)                                   | `https://graph.microsoft.com/v1.0/`    |
| `retry.attempts` | Max attempts for throttled (429) / transient (503, 504) requests | 3                                      |
| `retry.delay`  | Fixed delay in seconds (the `Retry-After` header is used when null)| null                                   |

## Migrating from the SharePoint REST client

| Old (SP REST)                                | New (Graph)                                        |
| -------------------------------------------- | -------------------------------------------------- |
| `SPSite::create($url, $settings)`            | `GraphSite::create($url, $settings)`               |
| `SPSite::createSPAccessToken()`              | automatic (or `createGraphAccessToken()`)          |
| `SPAccessToken::createAOP()` (ACS)           | `GraphAccessToken::create()` (identity platform)   |
| `SPAccessToken::createUOP()` (context token) | not applicable — use a delegated token via `setGraphAccessToken()` |
| `SPSite::createSPFormDigest()`               | **removed** — the Graph API has no form digests    |
| `SPList::getAll/getByTitle/getByGUID()`      | same names on `GraphList`                          |
| `SPList::createSPItem()/getSPItems()`        | `GraphList::createGraphItem()/getGraphItems()`     |
| `SPItem::getByID/getByTitle()`               | `GraphItem::getByID/getByTitle()`                  |
| `SPFolder::getByRelativeUrl()`               | `GraphDrive::getGraphFolderByPath()` (drive-root relative) |
| `SPFile::getByRelativeUrl()`                 | `GraphFile::getByPath()`                           |
| `SPFile::getByName()`                        | same name on `GraphFile`                           |
| `SPFile::create()`                           | same name (+ `createResumable()` for large files)  |
| `SPFile::start()/continue()/finish()`        | `GraphUploadSession::uploadChunk()/upload()`       |
| `SPFile::move()/copy()/delete()/update()`    | same names on `GraphFile`                          |
| `SPFile::recycle()`                          | **removed** — no Graph equivalent                  |
| `SPFile::createByTemplate()`                 | **removed** — use `copy()` from a template file    |
| `SPList::createSPField()` (FieldTypeKind)    | `GraphList::createGraphColumn()` (Graph column schema) |
| `SPUser::getCurrent()/getByAccount()`        | same names on `GraphUser`                          |
| server-relative URLs (`/sites/x/Shared Docs`)| drive-root relative paths (`Shared Docs`)          |
| items keyed by GUID                          | items keyed by Graph item ID                       |

### Behavioural notes

- **Authentication is automatic**: requests attach the access token, refresh expired
  tokens, retry throttled requests honoring `Retry-After`, and re-authenticate once
  on a mid-flight `401`. Form digests no longer exist.
- **Pagination is automatic**: collection getters follow `@odata.nextLink` internally.
- **Copy is asynchronous** in Graph: `GraphFile::copy()` polls the monitor URL and
  returns the copied file once the operation completes.
- **Path addressing** is relative to the drive root (e.g. `Shared Docs/Sub`), and all
  path segments are URL-encoded for you.
- National clouds (e.g. `https://graph.microsoft.us`) are supported via the
  `authority`, `scope` and `graph` configuration keys.

## Examples

A runnable live-API test script is included: it creates a folder, uploads a file
with a Unicode-heavy name (`Lastäåãæ, Firstëêēèéßæãùóœ, X999999`) containing
random text, downloads it again and verifies the content round trip. All
connection details are supplied by the user on the command line:

```sh
php examples/upload-test.php \
  --tenant 11111111-2222-3333-4444-555555555555 \
  --client-id 00000000-0000-0000-0000-000000000000 \
  --client-secret "your-client-secret" \
  --domain contoso.sharepoint.com \
  --site sites/team \
  --folder "Graph Client Test" \
  --cleanup
```

## Troubleshooting

**`SSL certificate problem: unable to get local issuer certificate` (cURL error 60)**

Windows PHP builds ship without a CA certificate bundle. Download the standard
bundle and point php.ini at it:

```sh
curl -sSL https://curl.se/ca/cacert.pem -o C:/path/to/php/cacert.pem
```

```ini
curl.cainfo = "C:/path/to/php/cacert.pem"
openssl.cafile = "C:/path/to/php/cacert.pem"
```

Alternatively, pass a bundle (or `false`, not recommended for production) per
client through the Guzzle options: `['http' => ['verify' => '/path/to/cacert.pem']]`.

A second live-API script, `examples/list-test.php` (wrapper: `list-test.bat`),
takes the name of a document library (Drive), reads the structure of the list
behind it (columns, types, required), and adds a new item to the list — for
document libraries it uploads a small file and sets the column values on its
list item, since raw list items cannot be created there:

```sh
php examples/list-test.php \
  --tenant 11111111-2222-3333-4444-555555555555 \
  --client-id 00000000-0000-0000-0000-000000000000 \
  --client-secret "your-client-secret" \
  --domain contoso.sharepoint.com \
  --site sites/team \
  --drive "Documents" \
  --cleanup
```

A third live-API script, `examples/delete-item.php` (wrapper: `delete-item.bat`),
deletes a list item by name in a given document library (Drive). In document
libraries the name is matched as a file path relative to the drive root, then as
an item Title, then as the file name (`FileLeafRef`); other lists match on
Title. The item is shown first and deleted after a confirmation prompt
(`--yes` skips it), and the deletion is verified afterwards:

```sh
php examples/delete-item.php \
  --tenant 11111111-2222-3333-4444-555555555555 \
  --client-id 00000000-0000-0000-0000-000000000000 \
  --client-secret "your-client-secret" \
  --domain contoso.sharepoint.com \
  --site sites/team \
  --drive "Documents" \
  --name "list-test-20260101-0000.txt" \
  --yes
```

## Testing

The test suite runs on PHP 8.4+ (PHPUnit 13); the library itself supports PHP 8.1+.

```sh
composer install
vendor/bin/phpunit
```

The suite runs fully offline: HTTP responses are queued with Guzzle's
`MockHandler`, so every request-layer behaviour (authentication, throttling
retries, pagination, upload sessions, async copy monitoring) is exercised
without touching the real Microsoft Graph API.

## License

MIT — see the LICENSE file. Based on the SharePoint OAuth App Client by
Quetzy Garcia (Architect 365); Graph rewrite by Song Wang.
