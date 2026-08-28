<?php

/**
 * Live Microsoft Graph API test script: delete a list item by name.
 *
 * Given the name of a document library (Drive) and an item name, the
 * script finds the item and deletes it after a confirmation prompt:
 *   1. file based lists (document library): the name is first looked
 *      up as a file path relative to the Drive root, then as the Title
 *      of a list item, then as the file name (FileLeafRef) of an item,
 *   2. other lists: the name is looked up as the Title of a list item,
 *   3. use --field to match any other column instead.
 *
 * The item is deleted through its list item (in document libraries
 * this also deletes the underlying file). After deletion the script
 * verifies that the item no longer exists.
 *
 * Usage:
 *
 *   php examples/delete-item.php
 *     --tenant 11111111-2222-3333-4444-555555555555
 *     --client-id 00000000-0000-0000-0000-000000000000
 *     --client-secret "your-client-secret"
 *     --domain contoso.sharepoint.com
 *     [--site sites/team]
 *     --drive "Documents"
 *     --name "report.docx"
 *     [--field FileLeafRef]
 *     [--yes]
 *
 * --name   required: name of the item to delete
 * --field  match this column instead of Title (eg. FileLeafRef)
 * --yes    skip the confirmation prompt
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
  php examples/delete-item.php
    --tenant <tenant-id>              Azure AD Tenant ID (GUID)
    --client-id <id>                  Azure AD application (client) ID
    --client-secret <secret>          Azure AD application client secret
    --domain <domain>                 SharePoint domain (eg. contoso.sharepoint.com)
    [--site <path>]                   Site path below the domain (default: root site)
    --drive <name>                    Name of the document library (Drive)
    --name <name>                     Name of the list item to delete
    [--field <column>]                Match this column instead of Title
    [--yes]                           Skip the confirmation prompt

TXT);

    exit(1);
}

/**
 * Ask the user to confirm
 */
function confirm(string $question): bool
{
    fwrite(STDOUT, $question.' (y/N): ');

    $answer = trim((string) fgets(STDIN));

    return in_array(strtolower($answer), ['y', 'yes'], true);
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

foreach (['tenant', 'client-id', 'client-secret', 'domain', 'drive', 'name'] as $required) {
    if (empty($options[$required])) {
        fwrite(STDERR, "Missing required option: --{$required}\n\n");
        usage();
    }
}

$domain = (string) $options['domain'];
$sitePath = trim((string) ($options['site'] ?? ''), '/');
$driveName = (string) $options['drive'];
$itemName = (string) $options['name'];
$fieldName = isset($options['field']) ? (string) $options['field'] : null;
$skipConfirm = ! empty($options['yes']);

echo 'Connecting to https://'.$domain.'/'.($sitePath !== '' ? $sitePath : '(root site)')."\n";
echo "Drive: {$driveName}\n";
echo "Item name: {$itemName}\n";
echo 'Confirmation: '.($skipConfirm ? 'skipped (--yes)' : 'interactive')."\n";

// ---------------------------------------------------------------------------
// run against the live API
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

    $drive = GraphDrive::getByName($site, $driveName);
    $list = $drive->getGraphList();

    printf("List: %s (id %s, template %s)\n", $list->getTitle(), $list->getId(), $list->getTemplate());

    // locate the item
    $item = null;
    $source = '';

    if ($fieldName !== null) {
        $item = GraphItem::getByField($list, $fieldName, $itemName);
        $source = "column '{$fieldName}'";
    } elseif ($list->isWritable()) {
        // file based list: try the name as a file path first
        try {
            $file = GraphFile::getByPath($drive, $itemName);
            $item = $file->getGraphListItem($list);
            $source = 'file path (relative to the Drive root)';
        } catch (GraphException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        if ($item === null) {
            $item = GraphItem::getByTitle($list, $itemName);
            $source = 'item Title';
        }

        if ($item === null) {
            $item = GraphItem::getByField($list, 'FileLeafRef', $itemName);
            $source = 'file name (FileLeafRef)';
        }
    } else {
        $item = GraphItem::getByTitle($list, $itemName);
        $source = 'item Title';
    }

    if ($item === null) {
        fwrite(STDERR, "Item not found: {$itemName}\n");
        exit(1);
    }

    printf(
        "Found item (matched by %s):\n  id:      %s\n  title:   %s\n  file:    %s\n  created: %s\n  modified: %s\n",
        $source,
        $item->getId(),
        (string) $item->getTitle(),
        (string) ($item->getField('FileLeafRef') ?? '-'),
        (string) ($item->getTimeCreated()?->format('Y-m-d H:i:s') ?? '-'),
        (string) ($item->getTimeModified()?->format('Y-m-d H:i:s') ?? '-'),
    );

    // confirm the deletion
    if (! $skipConfirm && ! confirm('Delete this item?')) {
        echo "Aborted - nothing was deleted\n";
        exit(0);
    }

    $item->delete();

    echo "Item deleted: {$itemName} (id {$item->getId()})\n";

    // verify the item is gone
    try {
        GraphItem::getByID($list, $item->getId());
        fwrite(STDERR, "WARNING: the item still exists after deletion\n");
        exit(1);
    } catch (GraphException $e) {
        if ($e->getCode() !== 404) {
            throw $e;
        }

        echo "Verified: the item no longer exists\n";
    }

    echo "TEST PASSED\n";
} catch (GraphException $e) {
    fwrite(STDERR, sprintf("Graph API error (HTTP %d): %s\n", $e->getCode(), $e->getMessage()));
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
    exit(1);
}
