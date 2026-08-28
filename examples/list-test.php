<?php

/**
 * Live Microsoft Graph API test script: list structure + new list item.
 *
 * Given the name of a document library (Drive), the script:
 *   1. resolves the Graph List backing that Drive,
 *   2. reads and prints the list structure (columns, types, required),
 *   3. adds a new item to the list:
 *        - file based lists (document library): uploads a small test file
 *          into the Drive and sets the column values on its list item
 *          (raw list items cannot be created in document libraries),
 *        - other lists: creates a plain list item,
 *   4. re-reads the item from the API to verify it.
 *
 * Column values are generated automatically for the required writable
 * columns (text, number, currency, dateTime, boolean, choice). Columns
 * that cannot be filled automatically (person, lookup, ...) are skipped
 * with a note.
 *
 * Usage:
 *
 *   php examples/list-test.php
 *     --tenant 11111111-2222-3333-4444-555555555555
 *     --client-id 00000000-0000-0000-0000-000000000000
 *     --client-secret "your-client-secret"
 *     --domain contoso.sharepoint.com
 *     [--site sites/team]
 *     --drive "Documents"
 *     [--cleanup]
 *
 * --drive    required: name of the document library (Drive)
 * --cleanup  delete the created item (and file) after a passing test
 */

declare(strict_types=1);

use SharepointGraphClient\GraphDrive;
use SharepointGraphClient\GraphException;
use SharepointGraphClient\GraphFile;
use SharepointGraphClient\GraphItem;
use SharepointGraphClient\GraphList;
use SharepointGraphClient\GraphSite;

require __DIR__.'/../vendor/autoload.php';

function usage(): void
{
    fwrite(STDERR, <<<TXT
Usage:
  php examples/list-test.php
    --tenant <tenant-id>              Azure AD Tenant ID (GUID)
    --client-id <id>                  Azure AD application (client) ID
    --client-secret <secret>          Azure AD application client secret
    --domain <domain>                 SharePoint domain (eg. contoso.sharepoint.com)
    [--site <path>]                   Site path below the domain (default: root site)
    --drive <name>                    Name of the document library (Drive)
    [--cleanup]                       Delete the created item after a passing test

TXT);

    exit(1);
}

/**
 * Detect the type facet of a Graph column
 */
function columnType(array $column): string
{
    $facets = [
        'text', 'multiLineText', 'number', 'currency', 'dateTime', 'boolean',
        'choice', 'personOrGroup', 'lookup', 'hyperlinkOrPicture', 'calculated',
        'term', 'thumbnail', 'geoLocation', 'contentApprovalStatus',
    ];

    foreach ($facets as $facet) {
        if (isset($column[$facet])) {
            return $facet;
        }
    }

    return 'unknown';
}

/**
 * Generate a test value for a column, or null when the column
 * cannot be filled automatically
 */
function generateFieldValue(string $type, array $column): mixed
{
    switch ($type) {
        case 'text':
        case 'multiLineText':
            return 'Test '.bin2hex(random_bytes(4));

        case 'number':
        case 'currency':
            return random_int(1, 9999);

        case 'dateTime':
            return gmdate('Y-m-d\TH:i:s\Z');

        case 'boolean':
            return true;

        case 'choice':
            $choices = $column['choice']['choices'] ?? [];

            return $choices !== [] ? $choices[0] : null;

        default:
            return null;
    }
}

/**
 * Generate random text content (for file based lists)
 */
function generateRandomText(): string
{
    $lines = [];

    for ($line = 0; $line < 20; $line++) {
        $lines[] = sprintf('%03d: random content %08d', $line + 1, random_int(0, 99999999));
    }

    return implode("\n", $lines)."\n";
}

// ---------------------------------------------------------------------------
// parse and validate the user supplied options
// ---------------------------------------------------------------------------
$options = [];
$arguments = array_slice($_SERVER['argv'], 1);

for ($i = 0; $i < count($arguments); $i++) {
    $argument = $arguments[$i];

    if (! str_starts_with($argument, '--')) {
        continue;
    }

    $argument = substr($argument, 2);

    if (($position = strpos($argument, '=')) !== false) {
        $options[substr($argument, 0, $position)] = substr($argument, $position + 1);

        continue;
    }

    $next = $arguments[$i + 1] ?? null;

    if ($next !== null && ! str_starts_with($next, '--')) {
        $options[$argument] = $next;
        $i++;
    } else {
        $options[$argument] = true;
    }
}

foreach (['tenant', 'client-id', 'client-secret', 'domain', 'drive'] as $required) {
    if (empty($options[$required])) {
        fwrite(STDERR, "Missing required option: --{$required}\n\n");
        usage();
    }
}

$domain = (string) $options['domain'];
$sitePath = trim((string) ($options['site'] ?? ''), '/');
$driveName = (string) $options['drive'];
$cleanup = ! empty($options['cleanup']);

echo 'Connecting to https://'.$domain.'/'.($sitePath !== '' ? $sitePath : '(root site)')."\n";
echo "Drive: {$driveName}\n";
echo 'Cleanup: '.($cleanup ? 'on' : 'off')."\n";

// ---------------------------------------------------------------------------
// run the test against the live API
// ---------------------------------------------------------------------------
try {
    $site = GraphSite::create(sprintf('https://%s/%s', $domain, $sitePath), [
        'site' => [
            'tenant'    => (string) $options['tenant'],
            'client_id' => (string) $options['client-id'],
            'secret'    => (string) $options['client-secret'],
        ],
    ]);

    printf("Site ID: %s\n", $site->getSiteId());

    // 1. resolve the Drive by name, and the List behind it
    $drive = GraphDrive::getByName($site, $driveName);

    printf("Drive ID: %s (%s)\n", $drive->getId(), $drive->getDriveType());

    $list = $drive->getGraphList();

    printf("List: %s (id %s, template %s)\n", $list->getTitle(), $list->getId(), $list->getTemplate());

    // 2. read the list structure
    $columns = $list->getGraphColumns();

    printf("List structure (%d writable columns):\n", count($columns));

    $types = [];

    foreach ($columns as $column) {
        $type = columnType($column);
        $types[$column['name']] = $type;

        $suffix = '';

        if ($type === 'choice') {
            $suffix = ' ['.implode('|', $column['choice']['choices'] ?? []).']';
        }

        printf(
            "  %-28s %s%s%s\n",
            (string) ($column['name'] ?? '?'),
            $type,
            $suffix,
            ! empty($column['required']) ? ' (required)' : '',
        );
    }

    // build the column values for the new item: Title plus
    // all required writable columns that can be filled automatically
    $fields = [];
    $skipped = [];

    foreach ($columns as $column) {
        $name = (string) ($column['name'] ?? '');
        $type = $types[$name];
        $required = ! empty($column['required']);

        if ($name === 'Title') {
            $fields['Title'] = 'Graph test item '.date('Y-m-d H:i:s');

            continue;
        }

        if (! $required) {
            continue;
        }

        $value = generateFieldValue($type, $column);

        if ($value === null) {
            $skipped[] = $name;

            continue;
        }

        $fields[$name] = $value;
    }

    foreach ($skipped as $name) {
        echo "Note: required column '{$name}' cannot be filled automatically (person/lookup/...) and was skipped\n";
    }

    echo "New item fields:\n".json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

    // 3. add the new item
    if ($list->isWritable()) {
        // file based list (document library): create a file first,
        // then set the column values on its list item
        $file = GraphFile::create($drive, generateRandomText(), sprintf('list-test-%s.txt', date('Ymd-His')), overwrite: true);

        printf("Test file uploaded: %s (id %s)\n", $file->getName(), $file->getId());

        $item = $file->getGraphListItem($list);

        if ($fields !== []) {
            $item->update($fields);
        }
    } else {
        $item = $list->createGraphItem($fields);
    }

    printf("New list item created: id %s\n", $item->getId());

    // 4. re-read the item to verify
    $fetched = GraphItem::getByID($list, $item->getId());

    echo "Verified item fields:\n".json_encode($fetched->getFields(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

    if ($cleanup) {
        $fetched->delete();
        echo "Item deleted\n";
    }

    echo "TEST PASSED\n";
} catch (GraphException $e) {
    fwrite(STDERR, sprintf("Graph API error (HTTP %d): %s\n", $e->getCode(), $e->getMessage()));
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
    exit(1);
}
