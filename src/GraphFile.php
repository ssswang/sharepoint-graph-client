<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use SplFileInfo;

class GraphFile extends GraphObject implements GraphItemInterface
{
    use GraphTimestampsTrait;

    /**
     * Graph Drive or parent Graph Folder
     */
    protected GraphDrive|GraphFolder $parent;

    /**
     * Graph File ID
     */
    protected string $id = '';

    /**
     * File Name
     */
    protected string $name = '';

    /**
     * File Size (in bytes)
     */
    protected int $size = 0;

    /**
     * File MIME type
     */
    protected ?string $mimeType = null;

    /**
     * Graph File ETag
     */
    protected ?string $eTag = null;

    /**
     * Graph web URL
     */
    protected ?string $webUrl = null;

    /**
     * Path of the parent item inside the Drive
     */
    protected ?string $parentPath = null;

    /**
     * Graph File constructor
     *
     * @param  GraphDrive|GraphFolder $parent Graph Drive or parent Graph Folder
     * @param  string[]               $json   JSON response from the Graph API
     * @param  string[]               $extra  Extra properties to map
     * @throws GraphException
     */
    public function __construct(GraphDrive|GraphFolder $parent, array $json, array $extra = [])
    {
        parent::__construct([
            'id'         => 'id',
            'name'       => 'name',
            'title'      => 'name',
            'size'       => 'size',
            'created'    => 'createdDateTime',
            'modified'   => 'lastModifiedDateTime',
            'mimeType'   => 'file->mimeType',
            'eTag'       => 'eTag',
            'webUrl'     => 'webUrl',
            'parentPath' => 'parentReference->path',
        ], $extra);

        $this->parent = $parent;

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'name'       => $this->name,
            'size'       => $this->size,
            'mime_type'  => $this->mimeType,
            'created'    => $this->created,
            'modified'   => $this->modified,
            'web_url'    => $this->webUrl,
            'extra'      => $this->extra,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return $this->name;
    }

    /**
     * Get the File Name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the File Size (in bytes)
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Get the File MIME type
     */
    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Get the File web URL
     */
    public function getUrl(): ?string
    {
        return $this->webUrl;
    }

    /**
     * Get the File metadata
     */
    public function getMetadata(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'size'     => $this->size,
            'created'  => $this->created,
            'modified' => $this->modified,
            'url'      => $this->getUrl(),
        ];
    }

    /**
     * Get the parent Graph Drive or Graph Folder
     */
    public function getGraphParent(): GraphDrive|GraphFolder
    {
        return $this->parent;
    }

    /**
     * Get the parent Graph Drive
     */
    public function getGraphDrive(): GraphDrive
    {
        return $this->parent instanceof GraphDrive ? $this->parent : $this->parent->getGraphDrive();
    }

    /**
     * Get the ID of the Graph Drive this File belongs to
     */
    public function getDriveId(): ?string
    {
        return $this->parent->getDriveId();
    }

    /**
     * Get the Graph Site
     */
    public function getGraphSite(): GraphSite
    {
        return $this->parent->getGraphSite();
    }

    /**
     * Get the Drive path of this File (relative to the Drive root)
     */
    public function getRelativeUrl(?string $path = null): ?string
    {
        $base = trim((string) $this->parentPath, '/');

        if (($pos = strpos($base, 'root:')) !== false) {
            $base = trim(substr($base, $pos + strlen('root:')), '/');
        }

        $relative = trim($base.'/'.$this->name.'/'.ltrim((string) $path, '/'), '/');

        return $relative === '' ? null : $relative;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $path, array $options = [], string $method = 'GET', bool $json = true): mixed
    {
        return $this->getGraphSite()->request($path, $options, $method, $json);
    }

    /**
     * {@inheritdoc}
     */
    public function getGraphAccessToken(): GraphAccessToken
    {
        return $this->getGraphSite()->getGraphAccessToken();
    }

    /**
     * Get the Graph Item (List Item) of this File
     *
     * The backing Graph List is taken from the optional $list argument
     * when provided, or resolved through the Drive of this File (the
     * listItem response of some tenants does not contain the list
     * reference, so it is not relied upon).
     *
     * @throws GraphException
     */
    public function getGraphListItem(?GraphList $list = null, array $extra = []): GraphItem
    {
        $json = $this->request(sprintf('drives/%s/items/%s/listItem', $this->getDriveId(), rawurlencode($this->id)), [
            'query' => ['$expand' => 'fields'],
        ]);

        if ($list === null) {
            $list = $this->getGraphDrive()->getGraphList();
        }

        return new GraphItem($list, $json, $extra);
    }

    /**
     * Get File Contents
     *
     * @throws GraphException
     */
    public function getContents(): string
    {
        $response = $this->request(sprintf('drives/%s/items/%s/content', $this->getDriveId(), rawurlencode($this->id)), [], 'GET', false);

        return (string) $response->getBody();
    }

    /**
     * Get all Graph Files of a Graph Folder
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent Graph Drive or parent Graph Folder
     * @throws GraphException
     * @return GraphFile[]
     */
    public static function getAll(GraphDrive|GraphFolder $parent, array $extra = []): array
    {
        $items = $parent->getGraphSite()->getAllPages($parent->getGraphItemPath().'/children', [
            'query' => [
                '$top' => 200,
            ],
        ]);

        $files = [];

        foreach ($items as $file) {
            // Files only
            if (! isset($file['file'])) {
                continue;
            }

            $files[$file['id']] = new static($parent, $file, $extra);
        }

        return $files;
    }

    /**
     * Get a Graph File by path (relative to the Drive root)
     *
     * @static
     * @throws GraphException
     */
    public static function getByPath(GraphDrive $drive, string $path, array $extra = []): static
    {
        $path = trim($path, '/');

        if ($path === '') {
            throw new GraphException('The Graph File path is empty');
        }

        $json = $drive->request(sprintf('drives/%s/root:/%s', $drive->getDriveId(), static::encodePath($path)));

        return new static($drive, $json, $extra);
    }

    /**
     * Get a Graph File by Item ID
     *
     * @static
     * @throws GraphException
     */
    public static function getById(GraphDrive|GraphFolder $parent, string $id, array $extra = []): static
    {
        $json = $parent->request(sprintf('drives/%s/items/%s', $parent->getDriveId(), rawurlencode($id)));

        return new static($parent, $json, $extra);
    }

    /**
     * Get a Graph File by Name
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent Graph Drive or parent Graph Folder
     * @throws GraphException
     */
    public static function getByName(GraphDrive|GraphFolder $parent, string $name, array $extra = []): static
    {
        $path = trim($parent instanceof GraphDrive ? $name : (($parent->getRelativeUrl() ?? '').'/'.$name), '/');

        $json = $parent->request(sprintf('drives/%s/root:/%s', $parent->getDriveId(), static::encodePath($path)));

        return new static($parent, $json, $extra);
    }

    /**
     * Content type handler
     *
     * @static
     * @throws GraphException
     * @return string|resource
     */
    protected static function contentHandler(mixed $input): mixed
    {
        if ($input instanceof SplFileInfo) {
            $handle = @fopen($input->getPathname(), 'rb');

            if ($handle === false) {
                throw new GraphException('Unable to get file contents');
            }

            return $handle;
        }

        if (is_string($input)) {
            return $input;
        }

        if (is_resource($input)) {
            if (get_resource_type($input) !== 'stream') {
                throw new GraphException('Invalid resource type: '.get_resource_type($input));
            }

            return $input;
        }

        throw new GraphException('Invalid input type: '.get_debug_type($input));
    }

    /**
     * Create a Graph File (simple upload, up to 250 MB)
     *
     * For larger files, use createResumable() or an upload session.
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent    Graph Drive or parent Graph Folder
     * @param  mixed                  $content   File content (SplFileInfo, string or stream resource)
     * @param  string|null            $name      Name for the file being uploaded
     * @param  bool                   $overwrite Overwrite if file already exists?
     * @throws GraphException
     */
    public static function create(GraphDrive|GraphFolder $parent, mixed $content, ?string $name = null, bool $overwrite = false, array $extra = []): static
    {
        if ($name === null || $name === '') {
            if ($content instanceof SplFileInfo) {
                $name = $content->getFilename();
            }

            if ($name === null || $name === '') {
                throw new GraphException('Graph File Name is empty/not set');
            }
        }

        $data = static::contentHandler($content);

        $json = $parent->request($parent->getGraphItemPath().':/'.static::encodePath($name).':/content', [
            'headers' => [
                'Content-Type' => 'application/octet-stream',
            ],
            'body'  => $data,
            'query' => [
                '@microsoft.graph.conflictBehavior' => $overwrite ? 'replace' : 'fail',
            ],
        ], 'PUT');

        return new static($parent, $json, $extra);
    }

    /**
     * Create a Graph File with a resumable upload session
     *
     * Suitable for large files: the content is uploaded in chunks.
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent    Graph Drive or parent Graph Folder
     * @param  mixed                  $content   File content (SplFileInfo, string or stream resource)
     * @param  string|null            $name      Name for the file being uploaded
     * @param  bool                   $overwrite Overwrite if file already exists?
     * @param  int                    $chunkSize Chunk size in bytes (multiple of 320 KiB)
     * @throws GraphException
     */
    public static function createResumable(GraphDrive|GraphFolder $parent, mixed $content, ?string $name = null, bool $overwrite = false, int $chunkSize = GraphUploadSession::DEFAULT_CHUNK_SIZE, array $extra = []): static
    {
        if ($name === null || $name === '') {
            if ($content instanceof SplFileInfo) {
                $name = $content->getFilename();
            }

            if ($name === null || $name === '') {
                throw new GraphException('Graph File Name is empty/not set');
            }
        }

        $session = GraphUploadSession::create($parent, $name, $overwrite);

        return $session->upload(static::contentHandler($content), $chunkSize, $extra);
    }

    /**
     * Update a Graph File
     *
     * @throws GraphException
     */
    public function update(mixed $content): static
    {
        $data = static::contentHandler($content);

        $json = $this->request(sprintf('drives/%s/items/%s/content', $this->getDriveId(), rawurlencode($this->id)), [
            'headers' => [
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $data,
        ], 'PUT');

        // the Graph API returns the updated Graph File
        return $this->hydrate($json, true);
    }

    /**
     * Build the parentReference payload for move/copy operations
     *
     * Graph Folders are addressed by Item ID, Graph Drives
     * are addressed by their root path.
     */
    protected static function parentReference(GraphDrive|GraphFolder $folder): array
    {
        if ($folder instanceof GraphDrive) {
            return [
                'driveId' => $folder->getDriveId(),
                'path'    => '/drives/'.$folder->getDriveId().'/root:',
            ];
        }

        return [
            'driveId' => $folder->getDriveId(),
            'id'      => $folder->getId(),
        ];
    }

    /**
     * Move a Graph File
     *
     * Moving between Drives is not supported by the Graph API;
     * use copy() followed by delete() instead.
     *
     * @throws GraphException
     */
    public function move(GraphDrive|GraphFolder $folder, ?string $name = null, array $extra = []): static
    {
        $json = $this->request(sprintf('drives/%s/items/%s', $this->getDriveId(), rawurlencode($this->id)), [
            'headers' => ['If-Match' => '*'],
            'json'    => [
                'name'            => $name ?: $this->name,
                'parentReference' => static::parentReference($folder),
            ],
        ], 'PATCH');

        // the moved File belongs to the new Folder now
        if ($folder instanceof GraphFolder) {
            $this->parent = $folder;
        }

        return $this->hydrate($json, true);
    }

    /**
     * Copy a Graph File
     *
     * The copy operation is asynchronous: the Graph API reports
     * progress via a monitor URL that is polled until completion.
     *
     * @throws GraphException
     */
    public function copy(GraphDrive|GraphFolder $folder, ?string $name = null, bool $overwrite = false, array $extra = [], int $maxSeconds = 120): static
    {
        $body = [
            'parentReference' => static::parentReference($folder),
        ];

        if ($name !== null && $name !== '') {
            $body['name'] = $name;
        }

        $response = $this->request(sprintf('drives/%s/items/%s/copy', $this->getDriveId(), rawurlencode($this->id)), [
            'headers' => ['Prefer' => 'respond-async'],
            'json'    => $body,
        ], 'POST', false);

        $monitorUrl = $response->getHeaderLine('Location');

        if ($monitorUrl === '') {
            throw new GraphException('The Graph API did not return a monitor URL for the copy operation');
        }

        // poll the monitor URL until the operation completes
        $json = [];
        $deadline = time() + max(1, $maxSeconds);

        while (true) {
            $json = $this->request($monitorUrl);

            $status = $json['status'] ?? '';

            if ($status === 'completed') {
                break;
            }

            if ($status === 'failed') {
                throw new GraphException('The Graph File copy operation failed');
            }

            if (time() >= $deadline) {
                throw new GraphException('Timed out waiting for the Graph File copy operation: '.$monitorUrl);
            }

            sleep(2);
        }

        // fetch the copied Graph File
        if (! empty($json['resourceId'])) {
            $json = $folder->request(sprintf('drives/%s/items/%s', $folder->getDriveId(), rawurlencode($json['resourceId'])));
        } elseif (! empty($json['resourceLocation'])) {
            $json = $folder->request((string) $json['resourceLocation']);
        } else {
            // fall back to a path lookup
            $path = trim(($folder instanceof GraphFolder ? ($folder->getRelativeUrl() ?? '') : '').'/', '/');
            $path = trim($path.'/'.($name ?: $this->name), '/');

            $json = $folder->request(sprintf('drives/%s/root:/%s', $folder->getDriveId(), static::encodePath($path)));
        }

        return new static($folder, $json, $extra);
    }

    /**
     * Delete a Graph File
     *
     * @throws GraphException
     */
    public function delete(): bool
    {
        $this->request(sprintf('drives/%s/items/%s', $this->getDriveId(), rawurlencode($this->id)), [], 'DELETE');

        return true;
    }
}
