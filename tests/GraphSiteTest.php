<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

use SharepointGraphClient\GraphDrive;
use SharepointGraphClient\GraphException;
use SharepointGraphClient\GraphFile;
use SharepointGraphClient\GraphFolder;
use SharepointGraphClient\GraphSite;
use SharepointGraphClient\GraphUploadSession;

class GraphSiteTest extends TestCase
{
    public function test_create_parses_the_site_url(): void
    {
        $site = GraphSite::create('https://contoso.sharepoint.com/sites/team');

        $this->assertSame('https://contoso.sharepoint.com/sites/team/', $site->getUrl());
        $this->assertSame('https://contoso.sharepoint.com/', $site->getHostname());
        $this->assertSame('sites/team/', $site->getPath());
        $this->assertSame('https://contoso.sharepoint.com/sites/team/_layouts/SignOut.aspx', $site->getLogoutUrl());
    }

    public function test_create_uses_the_default_graph_endpoint(): void
    {
        $site = GraphSite::create('https://contoso.sharepoint.com/sites/team');

        $config = $site->getConfig();

        $this->assertSame('https://graph.microsoft.com/v1.0/', $config['graph']);
        $this->assertSame('https://login.microsoftonline.com/', $config['authority']);
        $this->assertSame('https://graph.microsoft.com/.default', $config['scope']);
    }

    public function test_create_rejects_invalid_urls(): void
    {
        $this->expectException(GraphException::class);

        GraphSite::create('not a url');
    }

    public function test_token_is_invalid_without_credentials(): void
    {
        $site = GraphSite::create('https://contoso.sharepoint.com/sites/team');

        $this->assertFalse($site->canCreateGraphAccessToken());
        $this->expectException(GraphException::class);

        $site->getGraphAccessToken();
    }

    public function test_can_create_token_with_credentials(): void
    {
        $site = GraphSite::create('https://contoso.sharepoint.com/sites/team', [
            'site' => [
                'tenant'    => '11111111-2222-3333-4444-555555555555',
                'client_id' => '00000000-0000-0000-0000-000000000000',
                'secret'    => 'shhh',
            ],
        ]);

        $this->assertTrue($site->canCreateGraphAccessToken());
    }
}

class GraphFolderTest extends TestCase
{
    protected function makeSite(): GraphSite
    {
        return new GraphSite(new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']), [
            'site_url' => 'https://contoso.sharepoint.com/sites/team',
        ]);
    }

    protected function makeDrive(GraphSite $site): GraphDrive
    {
        return new GraphDrive($site, [
            'id'        => 'drive-1',
            'name'      => 'Documents',
            'driveType' => 'documentLibrary',
        ]);
    }

    public function test_root_folder_paths(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        $root = new GraphFolder($drive, [
            'id'               => 'root-id',
            'name'             => 'root',
            'root'             => [],
            'folder'           => ['childCount' => 3],
            'parentReference'  => ['driveId' => 'drive-1', 'path' => '/drives/drive-1/root:'],
        ]);

        $this->assertTrue($root->isRootFolder());
        $this->assertNull($root->getRelativeUrl());
        $this->assertSame('Sub', $root->getRelativeUrl('Sub'));
        $this->assertSame('drive-1', $root->getDriveId());
        $this->assertSame('drives/drive-1/root', $root->getGraphItemPath());
        $this->assertSame('drives/drive-1/root', $drive->getGraphItemPath());
    }

    public function test_sub_folder_paths(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        $folder = new GraphFolder($drive, [
            'id'               => 'folder-1',
            'name'             => 'Sub',
            'folder'           => ['childCount' => 2],
            'parentReference'  => ['driveId' => 'drive-1', 'path' => '/drives/drive-1/root:/Docs'],
        ]);

        $this->assertFalse($folder->isRootFolder());
        $this->assertSame('Docs/Sub', $folder->getRelativeUrl());
        $this->assertSame('Docs/Sub/Deeper', $folder->getRelativeUrl('Deeper'));
        $this->assertSame('drives/drive-1/items/folder-1', $folder->getGraphItemPath());
    }

    public function test_system_folders_are_detected(): void
    {
        $this->assertTrue(GraphFolder::isSystemFolder('Forms'));
        $this->assertTrue(GraphFolder::isSystemFolder('/sites/team/Shared Documents/forms'));
        $this->assertFalse(GraphFolder::isSystemFolder('Documents'));
    }

    public function test_file_paths_and_metadata(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        $folder = new GraphFolder($drive, [
            'id'               => 'folder-1',
            'name'             => 'Sub',
            'folder'           => ['childCount' => 1],
            'parentReference'  => ['driveId' => 'drive-1', 'path' => '/drives/drive-1/root:/Docs'],
        ]);

        $file = new GraphFile($folder, [
            'id'              => 'file-1',
            'name'            => 'a.txt',
            'size'            => 42,
            'file'            => ['mimeType' => 'text/plain'],
            'parentReference' => ['driveId' => 'drive-1', 'path' => '/drives/drive-1/root:/Docs/Sub'],
        ]);

        $this->assertSame('file-1', $file->getId());
        $this->assertSame('a.txt', $file->getTitle());
        $this->assertSame('a.txt', $file->getName());
        $this->assertSame(42, $file->getSize());
        $this->assertSame('text/plain', $file->getMimeType());
        $this->assertSame('Docs/Sub/a.txt', $file->getRelativeUrl());
        $this->assertSame('drive-1', $file->getDriveId());
        $this->assertSame($folder, $file->getGraphParent());
    }

    public function test_container_array_access(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        $folder = new GraphFolder($drive, [
            'id'     => 'folder-1',
            'name'   => 'Sub',
            'folder' => ['childCount' => 0],
        ]);

        $file = new GraphFile($folder, [
            'id'   => 'file-1',
            'name' => 'a.txt',
            'file' => ['mimeType' => 'text/plain'],
        ]);

        $folder[] = $file;

        $this->assertCount(1, $folder);
        $this->assertTrue(isset($folder['file-1']));
        $this->assertSame($file, $folder['file-1']);
        $this->assertSame([$file], $folder->getGraphItems());

        unset($folder['file-1']);

        $this->assertCount(0, $folder);

        $this->expectException(GraphException::class);

        $folder['nope'];
    }

    public function test_container_rejects_non_items(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        $folder = new GraphFolder($drive, [
            'id'     => 'folder-1',
            'name'   => 'Sub',
            'folder' => ['childCount' => 0],
        ]);

        $this->expectException(GraphException::class);

        $folder[] = 'not a graph item';
    }

    public function test_folder_requires_a_drive_id(): void
    {
        $site = $this->makeSite();
        $drive = $this->makeDrive($site);

        // strip the drive ID to simulate an unusable parent
        $drive = new class($site, ['id' => '', 'name' => 'Broken']) extends GraphDrive {};

        $this->expectException(GraphException::class);

        new GraphFolder($drive, ['id' => 'folder-1', 'name' => 'Sub', 'folder' => ['childCount' => 0]]);
    }
}

class GraphUploadSessionTest extends TestCase
{
    protected function makeSession(string ...$ranges): GraphUploadSession
    {
        $site = new GraphSite(new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']), [
            'site_url' => 'https://contoso.sharepoint.com/sites/team',
        ]);

        $drive = new GraphDrive($site, ['id' => 'drive-1', 'name' => 'Documents']);

        return new GraphUploadSession($drive, [
            'uploadUrl'          => 'https://upload.graph.microsoft.com/session-1',
            'nextExpectedRanges' => $ranges,
        ]);
    }

    public function test_expected_offset_is_parsed_from_ranges(): void
    {
        $session = $this->makeSession('262144-');

        $this->assertSame(262144, $session->getExpectedOffset());
        $this->assertSame(['262144-'], $session->getNextExpectedRanges());
    }

    public function test_expected_offset_defaults_to_zero(): void
    {
        $session = $this->makeSession();

        $this->assertSame(0, $session->getExpectedOffset());
    }

    public function test_empty_chunks_are_rejected(): void
    {
        $session = $this->makeSession('0-');

        $this->expectException(GraphException::class);

        $session->uploadChunk('');
    }

    public function test_chunks_exceeding_the_total_size_are_rejected(): void
    {
        $session = $this->makeSession('0-');

        $this->expectException(GraphException::class);

        $session->uploadChunk('0123456789', 0, 5);
    }

    public function test_invalid_chunk_sizes_are_rejected(): void
    {
        $session = $this->makeSession('0-');

        $this->expectException(GraphException::class);

        // not a multiple of 320 KiB
        $session->upload('1234567', 500000);
    }

    public function test_empty_upload_data_is_rejected(): void
    {
        $session = $this->makeSession('0-');

        $this->expectException(GraphException::class);

        $session->upload('', GraphUploadSession::DEFAULT_CHUNK_SIZE);
    }

    public function test_file_lookup_by_empty_path_is_rejected(): void
    {
        $site = new GraphSite(new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']), [
            'site_url' => 'https://contoso.sharepoint.com/sites/team',
        ]);

        $drive = new GraphDrive($site, ['id' => 'drive-1', 'name' => 'Documents']);

        $this->expectException(GraphException::class);

        GraphFile::getByPath($drive, '');
    }
}
