<?php

/**
 * Live Microsoft Graph API test script.
 *
 * Creates a folder and uploads a file named
 * "Lastäåãæ, Firstëêēèéßæãùóœ, X999999" with random text content,
 * then downloads it again and verifies the content round trip.
 *
 * All connection details are supplied by the user on the command line.
 *
 * Required Azure AD application permissions (application type, with
 * admin consent): Sites.ReadWrite.All (or Sites.Selected with the
 * target site granted).
 *
 * Usage:
 *
 *   php examples/upload-test.php
 *     --tenant 11111111-2222-3333-4444-555555555555
 *     --client-id 00000000-0000-0000-0000-000000000000
 *     --client-secret "your-client-secret"
 *     --domain contoso.sharepoint.com
 *     [--site sites/team]
 *     [--folder "Graph Client Test"]
 *     [--drive "Documents"]
 *     [--cleanup]
 *
 * --site     Site path below the domain (default: the root site)
 * --folder   Folder to create (default: GraphClientTest)
 * --drive    Document library name (default: the site default drive)
 * --cleanup  Delete the uploaded file and the folder after a passing test
 */

declare(strict_types=1);

use SharepointGraphClient\GraphDrive;
use SharepointGraphClient\GraphException;
use SharepointGraphClient\GraphFile;
use SharepointGraphClient\GraphFolder;
use SharepointGraphClient\GraphSite;

require __DIR__.'/../vendor/autoload.php';

const FILE_NAME = 'Lastäåãæ, Firstëêēèéßæãùóœ, X999999';

function usage(): void
{
    fwrite(STDERR, <<<TXT
Usage:
  php examples/upload-test.php
    --tenant <tenant>                 Azure AD tenant (GUID or domain, eg. 11111111-2222-3333-4444-555555555555)
    --client-id <id>                  Azure AD application (client) ID
    --client-secret <secret>          Azure AD application client secret
    --domain <domain>                 SharePoint domain (eg. contoso.sharepoint.com)
    [--site <path>]                   Site path below the domain (eg. sites/team); default: root site
    [--folder <name>]                 Folder to create (default: GraphClientTest)
    [--drive <name>]                  Document library name (default: the site default drive)
    [--cleanup]                       Delete the file and folder after a passing test

TXT);

    exit(1);
}

/**
 * Generate random text content (one numbered line at a time,
 * terminated by a marker line containing the file name)
 */
function generateRandomText(int $lines): string
{
    $consonants = 'bcdfghjklmnpqrstvwxyz';
    $vowels = 'aeiou';

    $word = static function () use ($consonants, $vowels): string {
        $word = '';

        for ($i = 0, $n = random_int(3, 9); $i < $n; $i++) {
            $word .= $i % 2 === 0
                ? $consonants[random_int(0, strlen($consonants) - 1)]
                : $vowels[random_int(0, strlen($vowels) - 1)];
        }

        return $word;
    };

    $out = [];

    for ($line = 0; $line < $lines; $line++) {
        $words = [];

        for ($i = 0, $n = random_int(5, 14); $i < $n; $i++) {
            $words[] = $word();
        }

        $out[] = sprintf('%04d: %s', $line + 1, implode(' ', $words));
    }

    $out[] = 'marker: '.FILE_NAME;

    return implode("\n", $out)."\n";
}

// ---------------------------------------------------------------------------
// parse and validate the user supplied options
// ---------------------------------------------------------------------------
// (custom parsing: getopt does not capture space separated values
// for options declared with an optional value)
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

foreach (['tenant', 'client-id', 'client-secret', 'domain'] as $required) {
    if (empty($options[$required])) {
        fwrite(STDERR, "Missing required option: --{$required}\n\n");
        usage();
    }
}

$domain = (string) $options['domain'];
$sitePath = trim((string) ($options['site'] ?? ''), '/');
$folderName = (string) ($options['folder'] ?? 'GraphClientTest');
$driveName = isset($options['drive']) ? (string) $options['drive'] : null;
$cleanup = ! empty($options['cleanup']);

echo 'Connecting to https://'.$domain.'/'.($sitePath !== '' ? $sitePath : '(root site)')."\n";
echo "Folder: {$folderName}\n";
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

    $drive = $driveName !== null && $driveName !== ''
        ? GraphDrive::getByName($site, $driveName)
        : $site->getDefaultGraphDrive();

    printf("Drive: %s (%s)\n", $drive->getName(), $drive->getId());

    // create the folder (or reuse the existing one)
    $createdFolder = false;

    try {
        $folder = GraphFolder::getByPath($drive, $folderName);
        echo "Reusing existing folder: {$folderName}\n";
    } catch (GraphException $e) {
        if ($e->getCode() !== 404) {
            throw $e;
        }

        $folder = GraphFolder::create($drive->getRootGraphFolder(), $folderName);
        $createdFolder = true;
        echo "Folder created: {$folderName}\n";
    }

    // generate the random text content
    $content = generateRandomText(150);

    printf("Random content: %d bytes, sha256 %s\n", strlen($content), hash('sha256', $content));

    // upload the file into the folder
    $file = GraphFile::create($folder, $content, FILE_NAME, overwrite: true);

    printf(
        "Uploaded:\n  name: %s\n  id:   %s\n  size: %d\n  path: %s\n",
        $file->getName(),
        $file->getId(),
        $file->getSize(),
        (string) $file->getRelativeUrl(),
    );

    // folder path for manual verification in the browser
    printf(
        "Verify manually in SharePoint:\n  site:   %s\n  drive:  %s\n  folder: %s\n",
        rtrim($site->getUrl(), '/'),
        $drive->getName(),
        (string) $folder->getRelativeUrl(),
    );

    // download it again and verify the content round trip
    $downloaded = GraphFile::getByName($folder, FILE_NAME)->getContents();

    if (hash_equals(hash('sha256', $content), hash('sha256', $downloaded))) {
        printf("Round trip OK: downloaded content matches (%d bytes)\n", strlen($downloaded));
    } else {
        throw new RuntimeException('Round trip FAILED: downloaded content does not match the uploaded content');
    }

    if ($cleanup) {
        $file->delete();
        echo "File deleted\n";

        // remove the folder only when it has become empty again
        if ($createdFolder && $folder->getGraphItemCount() === 0) {
            $folder->delete();
            echo "Folder deleted\n";
        }
    }

    echo "TEST PASSED\n";
} catch (GraphException $e) {
    fwrite(STDERR, sprintf("Graph API error (HTTP %d): %s\n", $e->getCode(), $e->getMessage()));
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
    exit(1);
}
