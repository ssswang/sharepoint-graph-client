<?php

declare(strict_types=1);

namespace SharepointGraphClient;

class GraphFolder extends GraphContainer implements GraphItemInterface
{
    /**
     * System Folder names (not shown in the SharePoint UI)
     *
     * @static
     * @access  public
     * @var     string[]
     */
    public static array $systemFolders = [
        'forms',
    ];

    /**
     * Graph Drive or parent Graph Folder
     */
    protected GraphDrive|GraphFolder $parent;

    /**
     * Graph Folder ID
     */
    protected string $id = '';

    /**
     * Graph Folder Name
     */
    protected string $name = '';

    /**
     * Path of the parent item inside the Drive (eg. /drives/{id}/root:/Sub/Dir)
     */
    protected ?string $parentPath = null;

    /**
     * Root facet (set for the root Folder only)
     */
    protected mixed $root = null;

    /**
     * Graph Folder ETag
     */
    protected ?string $eTag = null;

    /**
     * Graph Folder constructor
     *
     * @param  GraphDrive|GraphFolder $parent   Graph Drive or parent Graph Folder
     * @param  string[]               $json     JSON response from the Graph API
     * @param  string[]               $settings Instantiation settings
     * @throws GraphException
     */
    public function __construct(GraphDrive|GraphFolder $parent, array $json, array $settings = [])
    {
        $settings = array_replace_recursive([
            'fetch' => false, // fetch Graph Items (Folders/Files)?
        ], $settings, [
            'extra' => [], // extra Graph Folder properties to map
            'items' => [], // Graph Item instantiation settings
        ]);

        parent::__construct([
            'id'         => 'id',
            'name'       => 'name',
            'title'      => 'name',
            'created'    => 'createdDateTime',
            'modified'   => 'lastModifiedDateTime',
            'itemCount'  => 'folder->childCount',
            'webUrl'     => 'webUrl',
            'parentPath' => 'parentReference->path',
            'root'       => 'root',
            'eTag'       => 'eTag',
        ], $settings['extra']);

        $this->parent = $parent;
        $this->site = $parent->getGraphSite();
        $this->driveId = $parent->getDriveId();

        if ($this->driveId === null || $this->driveId === '') {
            throw new GraphException('Unable to determine the Graph Drive ID of the parent');
        }

        $this->hydrate($json);

        if ($settings['fetch'] && $this->itemCount > 0) {
            $this->getGraphItems($settings['items']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'name'         => $this->name,
            'created'      => $this->created,
            'modified'     => $this->modified,
            'relative_url' => $this->getRelativeUrl(),
            'items'        => $this->items,
            'item_count'   => $this->itemCount,
            'extra'        => $this->extra,
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
     * Get the Graph Folder Name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the parent Graph Drive
     */
    public function getGraphDrive(): GraphDrive
    {
        return $this->parent instanceof GraphDrive ? $this->parent : $this->parent->getGraphDrive();
    }

    /**
     * {@inheritdoc}
     */
    public function getDriveId(): ?string
    {
        return $this->driveId;
    }

    /**
     * {@inheritdoc}
     *
     * Graph Folders are addressed by their own Item ID.
     */
    public function getGraphItemPath(): string
    {
        return sprintf('drives/%s/items/%s', $this->driveId, rawurlencode($this->id));
    }

    /**
     * Is this the root Graph Folder of the Drive?
     */
    public function isRootFolder(): bool
    {
        return $this->root !== null
            || ($this->parentPath !== null && str_ends_with($this->parentPath, 'root:'));
    }

    /**
     * {@inheritdoc}
     *
     * The path is relative to the Drive root
     * (eg. "Sub/Dir" or null for the root Folder).
     */
    public function getRelativeUrl(?string $path = null): ?string
    {
        $base = '';

        if (! $this->isRootFolder()) {
            // strip the drive prefix of the parent path (eg. /drives/{id}/root:/Sub/Dir)
            $prefix = (string) $this->parentPath;

            if (($pos = strpos($prefix, 'root:')) !== false) {
                $prefix = substr($prefix, $pos + strlen('root:'));
            }

            $base = trim($prefix, '/');

            $base = $base === '' ? $this->name : $base.'/'.$this->name;
        }

        $relative = trim($base.'/'.ltrim((string) $path, '/'), '/');

        return $relative === '' ? null : $relative;
    }

    /**
     * Check if a name matches a SharePoint System Folder
     *
     * @static
     */
    public static function isSystemFolder(string $name): bool
    {
        return in_array(strtolower(basename($name)), static::$systemFolders, true);
    }

    /**
     * Get the root Graph Folder of a Graph Drive
     *
     * @static
     * @throws GraphException
     */
    public static function getRoot(GraphDrive $drive, array $settings = []): static
    {
        $json = $drive->request($drive->getGraphItemPath());

        return new static($drive, $json, $settings);
    }

    /**
     * Get a Graph Folder by path (relative to the Drive root)
     *
     * @static
     * @throws GraphException
     */
    public static function getByPath(GraphDrive $drive, string $path, array $settings = []): static
    {
        $path = trim($path, '/');

        if ($path === '') {
            return static::getRoot($drive, $settings);
        }

        $json = $drive->request(sprintf('drives/%s/root:/%s', $drive->getDriveId(), static::encodePath($path)));

        return new static($drive, $json, $settings);
    }

    /**
     * Get a Graph Folder by Item ID
     *
     * @static
     * @throws GraphException
     */
    public static function getById(GraphDrive|GraphFolder $parent, string $id, array $settings = []): static
    {
        $json = $parent->request(sprintf('drives/%s/items/%s', $parent->getDriveId(), rawurlencode($id)));

        return new static($parent, $json, $settings);
    }

    /**
     * Get the SubFolders of a Graph Folder
     *
     * @static
     * @throws GraphException
     * @return GraphFolder[]
     */
    public static function getSubFolders(GraphDrive|GraphFolder $parent, array $settings = []): array
    {
        $items = $parent->getGraphSite()->getAllPages($parent->getGraphItemPath().'/children', [
            'query' => [
                '$top' => $settings['top'] ?? 200,
            ],
        ]);

        $folders = [];

        foreach ($items as $subFolder) {
            // Folders only, and skip System Folders
            if (! isset($subFolder['folder']) || static::isSystemFolder($subFolder['name'] ?? '')) {
                continue;
            }

            $folders[$subFolder['id']] = new static($parent, $subFolder, $settings);
        }

        return $folders;
    }

    /**
     * Create a Graph Folder
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent   Graph Drive or parent Graph Folder
     * @param  string                 $name     Graph Folder name
     * @throws GraphException
     */
    public static function create(GraphDrive|GraphFolder $parent, string $name, array $settings = []): static
    {
        $json = $parent->request($parent->getGraphItemPath().'/children', [
            'json' => [
                'name'                             => $name,
                'folder'                           => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => $settings['conflict'] ?? 'fail',
            ],
        ], 'POST');

        return new static($parent, $json, $settings);
    }

    /**
     * Update a Graph Folder
     *
     * @throws GraphException
     */
    public function update(array $properties): static
    {
        $json = $this->request($this->getGraphItemPath(), [
            'headers' => ['If-Match' => '*'],
            'json'    => $properties,
        ], 'PATCH');

        // the Graph API returns the updated Graph Folder
        return $this->hydrate($json, true);
    }

    /**
     * Delete a Graph Folder
     *
     * @throws GraphException
     */
    public function delete(): bool
    {
        $this->request($this->getGraphItemPath(), [], 'DELETE');

        return true;
    }

    /**
     * Get the Graph Folder Item count (Folders and Files)
     *
     * @throws GraphException
     */
    public function getGraphItemCount(): int
    {
        $json = $this->request($this->getGraphItemPath(), [
            'query' => ['$select' => 'folder'],
        ]);

        return $this->itemCount = (int) ($json['folder']['childCount'] ?? 0);
    }

    /**
     * Get all Graph Items (Folders/Files)
     *
     * @throws GraphException
     * @return GraphFolder[]|GraphFile[]
     */
    public function getGraphItems(array $settings = []): array
    {
        $settings = array_replace_recursive($settings, [
            'folders' => [
                'extra' => [], // extra Graph Folder properties to map
            ],

            'files' => [
                'extra' => [], // extra Graph File properties to map
            ],
        ]);

        $folders = static::getSubFolders($this, $settings['folders']);
        $files = GraphFile::getAll($this, $settings['files']['extra']);

        $this->items = array_merge($folders, $files);

        return $this->items;
    }
}
