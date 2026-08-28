<?php

declare(strict_types=1);

namespace SharepointGraphClient;

/**
 * A Graph Drive represents a document library
 * and contains Graph Folders and Graph Files
 */
class GraphDrive extends GraphObject implements GraphRequesterInterface, GraphItemInterface
{
    use GraphTimestampsTrait;

    /**
     * Graph Site
     */
    protected GraphSite $site;

    /**
     * Graph Drive ID
     */
    protected string $id = '';

    /**
     * Graph Drive Name (document library title)
     */
    protected string $name = '';

    /**
     * Graph Drive Type (eg. documentLibrary)
     */
    protected string $driveType = '';

    /**
     * Graph web URL
     */
    protected ?string $webUrl = null;

    /**
     * Graph Drive constructor
     *
     * @param  string[] $json     JSON response from the Graph API
     * @param  string[] $extra    Extra Graph Drive properties to map
     */
    public function __construct(GraphSite $site, array $json, array $extra = [])
    {
        parent::__construct([
            'id'        => 'id',
            'name'      => 'name',
            'driveType' => 'driveType',
            'created'   => 'createdDateTime',
            'modified'  => 'lastModifiedDateTime',
            'webUrl'    => 'webUrl',
            'quota'     => 'quota',
        ], $extra);

        $this->site = $site;

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'drive_type' => $this->driveType,
            'web_url'    => $this->webUrl,
            'created'    => $this->created,
            'modified'   => $this->modified,
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
     * Get the Graph Drive Name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the Graph Drive Type
     */
    public function getDriveType(): string
    {
        return $this->driveType;
    }

    /**
     * {@inheritdoc}
     */
    public function getGraphSite(): GraphSite
    {
        return $this->site;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $path, array $options = [], string $method = 'GET', bool $json = true): mixed
    {
        return $this->site->request($path, $options, $method, $json);
    }

    /**
     * {@inheritdoc}
     */
    public function getGraphAccessToken(): GraphAccessToken
    {
        return $this->site->getGraphAccessToken();
    }

    /**
     * Get the Graph Drive ID
     */
    public function getDriveId(): string
    {
        return $this->id;
    }

    /**
     * Get the Graph API item path of this Drive
     * (Drives are addressed by their root item)
     */
    public function getGraphItemPath(): string
    {
        return sprintf('drives/%s/root', $this->id);
    }

    /**
     * Get the root Graph Folder of this Drive
     *
     * @throws GraphException
     */
    public function getRootGraphFolder(array $settings = []): GraphFolder
    {
        return GraphFolder::getRoot($this, $settings);
    }

    /**
     * Get the Graph List backing this Drive
     *
     * SharePoint document libraries are both a Drive and a List;
     * the List ID is resolved through the sharepointIds of the
     * Drive root item.
     *
     * @throws GraphException
     */
    public function getGraphList(): GraphList
    {
        $json = $this->request($this->getGraphItemPath(), [
            'query' => ['$select' => 'id,sharepointIds'],
        ]);

        $listId = $json['sharepointIds']['listId'] ?? null;

        if (empty($listId)) {
            throw new GraphException('Unable to determine the Graph List of this Drive (not a SharePoint document library?)');
        }

        return GraphList::getByGUID($this->site, (string) $listId);
    }

    /**
     * Get a Graph Folder of this Drive by path (relative to the Drive root)
     *
     * @throws GraphException
     */
    public function getGraphFolderByPath(string $path, array $settings = []): GraphFolder
    {
        return GraphFolder::getByPath($this, $path, $settings);
    }

    /**
     * Get a Graph File of this Drive by path (relative to the Drive root)
     *
     * @throws GraphException
     */
    public function getGraphFileByPath(string $path, array $extra = []): GraphFile
    {
        return GraphFile::getByPath($this, $path, $extra);
    }

    /**
     * Get a Graph Folder of this Drive by Item ID
     *
     * @throws GraphException
     */
    public function getGraphFolderById(string $id, array $settings = []): GraphFolder
    {
        return GraphFolder::getById($this, $id, $settings);
    }

    /**
     * Get a Graph File of this Drive by Item ID
     *
     * @throws GraphException
     */
    public function getGraphFileById(string $id, array $extra = []): GraphFile
    {
        return GraphFile::getById($this, $id, $extra);
    }

    /**
     * Get a Graph Item (Graph Folder or Graph File) of this Drive by Item ID
     *
     * @throws GraphException
     */
    public function getGraphItemById(string $id): GraphFolder|GraphFile
    {
        $json = $this->request($this->getGraphItemPath().sprintf('/items/%s', rawurlencode($id)));

        if (isset($json['folder'])) {
            return new GraphFolder($this, $json);
        }

        return new GraphFile($this, $json);
    }

    /**
     * Get all Graph Drives (document libraries) of a Graph Site
     *
     * @static
     * @throws GraphException
     * @return GraphDrive[]
     */
    public static function getAll(GraphSite $site, array $settings = []): array
    {
        $drives = [];

        foreach ($site->getAllPages(sprintf('sites/%s/drives', $site->getSiteId()), [
            'query' => [
                '$top' => $settings['top'] ?? 100,
            ],
        ]) as $drive) {
            $drives[$drive['id']] = new static($site, $drive, $settings['extra'] ?? []);
        }

        return $drives;
    }

    /**
     * Get a Graph Drive of a Graph Site by ID
     *
     * @static
     * @throws GraphException
     */
    public static function getByID(GraphSite $site, string $id, array $settings = []): static
    {
        $json = $site->request(sprintf('drives/%s', rawurlencode($id)));

        return new static($site, $json, $settings['extra'] ?? []);
    }

    /**
     * Get the default Graph Drive of a Graph Site
     *
     * @static
     * @throws GraphException
     */
    public static function getDefault(GraphSite $site, array $settings = []): static
    {
        $json = $site->request(sprintf('sites/%s/drive', $site->getSiteId()));

        return new static($site, $json, $settings['extra'] ?? []);
    }

    /**
     * Get a Graph Drive of a Graph Site by name (document library title)
     *
     * @static
     * @throws GraphException
     */
    public static function getByName(GraphSite $site, string $name, array $settings = []): static
    {
        foreach (static::getAll($site, $settings) as $drive) {
            if ($drive->getName() === $name) {
                return $drive;
            }
        }

        throw new GraphException('Graph Drive not found: '.$name);
    }
}
