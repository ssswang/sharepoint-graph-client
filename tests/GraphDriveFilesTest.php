<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use GuzzleHttp\Psr7\Response;

use SharepointGraphClient\GraphDrive;
use SharepointGraphClient\GraphFile;
use SharepointGraphClient\GraphFolder;
use SharepointGraphClient\GraphSite;
use SharepointGraphClient\GraphUploadSession;

class GraphDriveFilesTest extends MockHttpTestCase
{
    protected function makeFolder(GraphSite $site): GraphFolder
    {
        $drive = new GraphDrive($site, [
            'id'        => 'D1',
            'name'      => 'Documents',
            'driveType' => 'documentLibrary',
        ]);

        return new GraphFolder($drive, [
            'id'              => 'F1',
            'name'            => 'Sub',
            'folder'          => ['childCount' => 0],
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:/Docs'],
        ]);
    }

    protected function makeFile(GraphFolder $folder): GraphFile
    {
        return new GraphFile($folder, [
            'id'              => 'FILE1',
            'name'            => 're port.txt',
            'size'            => 5,
            'file'            => ['mimeType' => 'text/plain'],
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:/Docs/Sub'],
        ]);
    }

    public function test_drive_lookup_by_id(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue($this->jsonResponse(200, ['id' => 'D1', 'name' => 'Documents', 'driveType' => 'documentLibrary']));

        $drive = $site->getGraphDrive('D1');

        $this->assertSame('D1', $drive->getDriveId());
        $this->assertSame('drives/D1/root', $drive->getGraphItemPath());

        $this->assertSame('GET', $this->requestMethod(0));
        $this->assertSame('https://graph.microsoft.com/v1.0/drives/D1', $this->requestUri(0));
    }

    public function test_drive_resolves_its_backing_list(): void
    {
        $site = $this->makeSiteWithToken();

        $drive = new GraphDrive($site, [
            'id'        => 'D1',
            'name'      => 'Documents',
            'driveType' => 'documentLibrary',
        ]);

        $this->queue(
            $this->jsonResponse(200, [
                'id'            => 'R1',
                'sharepointIds' => ['listId' => 'L-GUID', 'siteId' => 'S1', 'webId' => 'W1'],
            ]),
            $this->jsonResponse(200, [
                'id'          => 'L-GUID',
                'displayName' => 'Documents',
                'list'        => ['template' => 'documentLibrary'],
            ]),
        );

        $list = $drive->getGraphList();

        $this->assertSame('L-GUID', $list->getId());
        $this->assertSame('Documents', $list->getTitle());

        $this->assertSame('/v1.0/drives/D1/root', $this->requestPath(0));
        $this->assertSame('id,sharepointIds', $this->requestQuery(0)['$select']);
        $this->assertSame('https://graph.microsoft.com/v1.0/sites/SITE-ID/lists/L-GUID', $this->requestUri(1));
    }

    public function test_file_resolves_its_list_item_through_the_drive(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $this->queue(
            // some tenants return the list item without a parentReference
            $this->jsonResponse(200, ['id' => 'LI-1', 'fields' => ['Title' => 'x']]),
            $this->jsonResponse(200, [
                'id'            => 'R1',
                'sharepointIds' => ['listId' => 'L-GUID', 'siteId' => 'S1'],
            ]),
            $this->jsonResponse(200, [
                'id'          => 'L-GUID',
                'displayName' => 'Documents',
                'list'        => ['template' => 'documentLibrary'],
            ]),
        );

        $item = $file->getGraphListItem();

        $this->assertSame('LI-1', $item->getId());
        $this->assertSame('x', $item->getTitle());
        $this->assertSame('L-GUID', $item->getGraphList()->getId());
    }

    public function test_file_resolves_its_list_item_with_a_known_list(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $list = new \SharepointGraphClient\GraphList($site, [
            'id'          => 'L-GUID',
            'displayName' => 'Documents',
            'list'        => ['template' => 'documentLibrary'],
        ]);

        $this->queue($this->jsonResponse(200, ['id' => 'LI-2', 'fields' => ['Title' => 'y']]));

        $item = $file->getGraphListItem($list);

        $this->assertSame('LI-2', $item->getId());
        $this->assertSame('y', $item->getTitle());
        $this->assertSame($list, $item->getGraphList());
        $this->assertSame(1, $this->requestCount());
    }

    public function test_folder_create_sends_folder_payload(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);

        $this->queue($this->jsonResponse(201, [
            'id'              => 'NEW-F',
            'name'            => 'New Folder',
            'folder'          => ['childCount' => 0],
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:/Docs/Sub'],
        ]));

        $created = GraphFolder::create($folder, 'New Folder');

        $this->assertSame('POST', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/F1/children', $this->requestPath(0));

        $json = $this->requestJson(0);

        $this->assertSame('New Folder', $json['name']);
        $this->assertSame('{"name":"New Folder","folder":{},"@microsoft.graph.conflictBehavior":"fail"}', $this->requestRawBody(0));
        $this->assertSame('Docs/Sub/New Folder', $created->getRelativeUrl());
    }

    public function test_file_create_uploads_to_encoded_path(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);

        $fileJson = [
            'id'              => 'FILE1',
            'name'            => 're port.txt',
            'size'            => 5,
            'file'            => ['mimeType' => 'text/plain'],
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:/Docs/Sub'],
        ];

        $this->queue(
            $this->jsonResponse(201, $fileJson),
            $this->jsonResponse(201, $fileJson),
        );

        $file = GraphFile::create($folder, 'hello', 're port.txt', overwrite: true);

        $this->assertSame('PUT', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/F1:/re%20port.txt:/content', $this->requestPath(0));
        $this->assertSame('replace', $this->requestQuery(0)['@microsoft.graph.conflictBehavior']);
        $this->assertSame('hello', $this->requestRawBody(0));
        $this->assertSame('Docs/Sub/re port.txt', $file->getRelativeUrl());

        GraphFile::create($folder, 'hello', 're port.txt', overwrite: false);

        $this->assertSame('fail', $this->requestQuery(1)['@microsoft.graph.conflictBehavior']);
    }

    public function test_file_contents_download(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $this->queue(new Response(200, [], 'binary-content'));

        $this->assertSame('binary-content', $file->getContents());

        $this->assertSame('GET', $this->requestMethod(0));
        $this->assertSame('https://graph.microsoft.com/v1.0/drives/D1/items/FILE1/content', $this->requestUri(0));
    }

    public function test_file_update_replaces_content_and_rehydrates(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $this->queue($this->jsonResponse(200, [
            'id'   => 'FILE1',
            'name' => 're port.txt',
            'size' => 3,
            'file' => ['mimeType' => 'text/plain'],
        ]));

        $file->update('abc');

        $this->assertSame(3, $file->getSize());

        $this->assertSame('PUT', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/FILE1/content', $this->requestPath(0));
        $this->assertSame('abc', $this->requestRawBody(0));
    }

    public function test_move_sends_parent_reference_for_folders_and_drives(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $this->queue($this->jsonResponse(200, [
            'id'              => 'FILE1',
            'name'            => 'moved.txt',
            'file'            => ['mimeType' => 'text/plain'],
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:/Docs/Sub'],
        ]));

        $file->move($folder, 'moved.txt');

        $this->assertSame('PATCH', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/FILE1', $this->requestPath(0));
        $this->assertSame('*', $this->requestHeader(0, 'If-Match'));
        $this->assertSame([
            'name'            => 'moved.txt',
            'parentReference' => ['driveId' => 'D1', 'id' => 'F1'],
        ], $this->requestJson(0));
        $this->assertSame($folder, $file->getGraphParent());

        // moving to the Drive root uses path based addressing
        $drive = $folder->getGraphDrive();

        $this->queue($this->jsonResponse(200, [
            'id'   => 'FILE1',
            'name' => 'moved.txt',
            'file' => ['mimeType' => 'text/plain'],
        ]));

        $file->move($drive);

        $this->assertSame([
            'name'            => 'moved.txt',
            'parentReference' => ['driveId' => 'D1', 'path' => '/drives/D1/root:'],
        ], $this->requestJson(1));
    }

    public function test_upload_session_chunked_upload(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $chunkSize = GraphUploadSession::MIN_CHUNK_SIZE;

        $this->queue(
            $this->jsonResponse(200, [
                'uploadUrl'          => 'https://upload.graph.microsoft.com/s1',
                'nextExpectedRanges' => ['0-'],
            ]),
            $this->jsonResponse(202, ['nextExpectedRanges' => [(string) $chunkSize.'-']]),
            $this->jsonResponse(201, [
                'id'   => 'BIG1',
                'name' => 'big.bin',
                'size' => $chunkSize + 4,
                'file' => ['mimeType' => 'application/octet-stream'],
            ]),
        );

        $session = GraphUploadSession::create($folder, 'big.bin', overwrite: true);

        $this->assertSame('POST', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/F1:/big.bin:/createUploadSession', $this->requestPath(0));
        $this->assertSame('replace', $this->requestJson(0)['item']['@microsoft.graph.conflictBehavior']);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, str_repeat('x', $chunkSize + 4));
        rewind($handle);

        $file = $session->upload($handle, $chunkSize);

        $this->assertSame('BIG1', $file->getId());

        // chunk 1: PUT to the pre-authenticated upload URL, no Authorization header
        $this->assertSame('PUT', $this->requestMethod(1));
        $this->assertSame('https://upload.graph.microsoft.com/s1', $this->requestUri(1));
        $this->assertSame(sprintf('bytes 0-%d/%d', $chunkSize - 1, $chunkSize + 4), $this->requestHeader(1, 'Content-Range'));
        $this->assertSame((string) $chunkSize, $this->requestHeader(1, 'Content-Length'));
        $this->assertSame('', $this->requestHeader(1, 'Authorization'));

        // chunk 2 (final): the Graph API returns the uploaded Drive Item
        $this->assertSame('PUT', $this->requestMethod(2));
        $this->assertSame('https://upload.graph.microsoft.com/s1', $this->requestUri(2));
        $this->assertSame(sprintf('bytes %d-%d/%d', $chunkSize, $chunkSize + 3, $chunkSize + 4), $this->requestHeader(2, 'Content-Range'));
    }

    public function test_copy_operation_polls_monitor_url(): void
    {
        $site = $this->makeSiteWithToken();
        $folder = $this->makeFolder($site);
        $file = $this->makeFile($folder);

        $this->queue(
            new Response(202, ['Location' => 'https://graph.microsoft.com/v1.0/operations/xyz'], ''),
            $this->jsonResponse(200, ['status' => 'inProgress', 'percentageComplete' => 30.0]),
            $this->jsonResponse(200, ['status' => 'completed', 'percentageComplete' => 100.0, 'resourceId' => 'COPY1']),
            $this->jsonResponse(200, ['id' => 'COPY1', 'name' => 're port.txt', 'file' => ['mimeType' => 'text/plain']]),
        );

        $copied = $file->copy($folder, 're port.txt');

        $this->assertSame('COPY1', $copied->getId());
        $this->assertSame($folder, $copied->getGraphParent());

        $this->assertSame('POST', $this->requestMethod(0));
        $this->assertSame('/v1.0/drives/D1/items/FILE1/copy', $this->requestPath(0));
        $this->assertSame('respond-async', $this->requestHeader(0, 'Prefer'));

        // the copy operation is monitored until completion
        $this->assertSame('https://graph.microsoft.com/v1.0/operations/xyz', $this->requestUri(1));
        $this->assertSame('https://graph.microsoft.com/v1.0/operations/xyz', $this->requestUri(2));

        // the copied file is fetched by the resourceId of the completed operation
        $this->assertSame('https://graph.microsoft.com/v1.0/drives/D1/items/COPY1', $this->requestUri(3));
    }
}
