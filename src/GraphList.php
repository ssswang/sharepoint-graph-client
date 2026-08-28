<?php

declare(strict_types=1);

namespace SharepointGraphClient;

class GraphList extends GraphContainer implements GraphItemInterface
{
    /**
     * Graph List Template Types
     *
     * @link https://learn.microsoft.com/en-us/graph/api/resources/listinfo
     * @var  string
     */
    const TPL_GENERICLIST     = 'genericList';     // Custom list
    const TPL_DOCUMENTLIBRARY = 'documentLibrary'; // Document library
    const TPL_SURVEY          = 'survey';          // Survey
    const TPL_LINKS           = 'links';           // Links
    const TPL_ANNOUNCEMENTS   = 'announcements';   // Announcements
    const TPL_CONTACTS        = 'contacts';        // Contacts
    const TPL_EVENTS          = 'events';          // Calendar
    const TPL_TASKS           = 'tasks';           // Tasks
    const TPL_DISCUSSIONBOARD = 'discussionBoard'; // Discussion board
    const TPL_PICTURELIBRARY  = 'pictureLibrary';  // Picture library
    const TPL_WEBPAGELIBRARY  = 'webPageLibrary';  // Web page library
    const TPL_PAGES           = 'pages';           // Publishing pages

    /**
     * Allowed Graph List Types
     *
     * @static
     * @access  public
     * @var     string[]
     */
    public static array $allowedListTypes = [
        self::TPL_GENERICLIST,
        self::TPL_DOCUMENTLIBRARY,
        self::TPL_SURVEY,
        self::TPL_LINKS,
        self::TPL_ANNOUNCEMENTS,
        self::TPL_CONTACTS,
        self::TPL_EVENTS,
        self::TPL_TASKS,
        self::TPL_DISCUSSIONBOARD,
        self::TPL_PICTURELIBRARY,
        self::TPL_WEBPAGELIBRARY,
        self::TPL_PAGES,
    ];

    /**
     * Graph List Types that allow
     * Folder/File operations
     *
     * @static
     * @access  public
     * @var     string[]
     */
    public static array $writableListTypes = [
        self::TPL_DOCUMENTLIBRARY,
        self::TPL_PICTURELIBRARY,
        self::TPL_WEBPAGELIBRARY,
        self::TPL_PAGES,
    ];

    /**
     * Graph List ID
     */
    protected string $id = '';

    /**
     * Graph List Title (displayName)
     */
    protected string $title = '';

    /**
     * Graph List Name (system name)
     */
    protected string $name = '';

    /**
     * Graph List Template Type
     */
    protected string $template = '';

    /**
     * Graph List Description
     */
    protected ?string $description = null;

    /**
     * Graph List constructor
     *
     * @param  string[] $json     JSON response from the Graph API
     * @param  string[] $settings Instantiation settings
     * @throws GraphException
     */
    public function __construct(GraphSite $site, array $json, array $settings = [])
    {
        $settings = array_replace_recursive([
            'fetch' => false, // fetch Graph Items?
        ], $settings, [
            'extra' => [], // extra Graph List properties to map
            'items' => [], // Graph Item instantiation settings
        ]);

        parent::__construct([
            'id'          => 'id',
            'title'       => 'displayName',
            'name'        => 'name',
            'template'    => 'list->template',
            'description' => 'description',
            'created'     => 'createdDateTime',
            'modified'    => 'lastModifiedDateTime',
            'webUrl'      => 'webUrl',
        ], $settings['extra']);

        $this->site = $site;

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
            'template'     => $this->template,
            'description'  => $this->description,
            'items'        => $this->items,
            'item_count'   => $this->itemCount,
            'extra'        => $this->extra,
            'created'      => $this->created,
            'modified'     => $this->modified,
            'web_url'      => $this->webUrl,
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
        return $this->title;
    }

    /**
     * Get the Graph List Name (system name)
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the Graph List Template Type
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Get the Graph List Description
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * {@inheritdoc}
     */
    public function getRelativeUrl(?string $path = null): ?string
    {
        // Graph Lists do not expose a Drive path; use
        // getGraphDrive() for Folder/File operations
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(bool $exception = false): bool
    {
        $writable = in_array($this->template, static::$writableListTypes, true);

        if ($exception && ! $writable) {
            throw new GraphException('Graph List Template ['.$this->template.'] does not allow Graph Folder/File operations');
        }

        return $writable;
    }

    /**
     * Check if a List Type is allowed
     *
     * @static
     */
    public static function isListTypeAllowed(string $listType): bool
    {
        return in_array($listType, static::$allowedListTypes, true);
    }

    /**
     * Get all Graph Lists of a Graph Site
     *
     * @static
     * @throws GraphException
     * @return GraphList[]
     */
    public static function getAll(GraphSite $site, array $settings = []): array
    {
        $items = $site->getAllPages(sprintf('sites/%s/lists', $site->getSiteId()), [
            'query' => [
                '$top' => $settings['top'] ?? 200,
            ],
        ]);

        $lists = [];

        foreach ($items as $list) {
            // allowed Graph List Types only
            if (static::isListTypeAllowed($list['list']['template'] ?? '')) {
                $lists[$list['id']] = new static($site, $list, $settings);
            }
        }

        return $lists;
    }

    /**
     * Get a Graph List by GUID
     *
     * @static
     * @throws GraphException
     */
    public static function getByGUID(GraphSite $site, string $guid, array $settings = []): static
    {
        $json = $site->request(sprintf('sites/%s/lists/%s', $site->getSiteId(), rawurlencode($guid)));

        return new static($site, $json, $settings);
    }

    /**
     * Get a Graph List by Title
     *
     * @static
     * @throws GraphException
     */
    public static function getByTitle(GraphSite $site, string $title, array $settings = []): static
    {
        $json = $site->request(sprintf('sites/%s/lists/%s', $site->getSiteId(), rawurlencode($title)));

        return new static($site, $json, $settings);
    }

    /**
     * Create a Graph List
     *
     * @static
     * @param  string[] $properties Graph List properties (displayName, description, list => [template, ...])
     * @throws GraphException
     */
    public static function create(GraphSite $site, array $properties, array $settings = []): static
    {
        $properties = array_replace_recursive($properties, [
            'list' => [
                'template' => static::TPL_DOCUMENTLIBRARY,
            ],
        ]);

        $json = $site->request(sprintf('sites/%s/lists', $site->getSiteId()), [
            'json' => $properties,
        ], 'POST');

        return new static($site, $json, $settings);
    }

    /**
     * Update a Graph List
     *
     * @throws GraphException
     */
    public function update(array $properties): static
    {
        $json = $this->request(sprintf('sites/%s/lists/%s', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)), [
            'headers' => ['If-Match' => '*'],
            'json'    => $properties,
        ], 'PATCH');

        // the Graph API returns the updated List
        return $this->hydrate($json, true);
    }

    /**
     * Delete a List and all its content
     *
     * @throws GraphException
     */
    public function delete(): bool
    {
        $this->request(sprintf('sites/%s/lists/%s', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)), [], 'DELETE');

        return true;
    }

    /**
     * Get the Graph Drive (document library) of this List
     *
     * Only applicable to Graph Lists that allow
     * Graph Folder/File operations.
     *
     * @throws GraphException
     */
    public function getGraphDrive(array $settings = []): GraphDrive
    {
        $this->isWritable(true);

        $json = $this->request(sprintf('sites/%s/lists/%s/drive', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)));

        return new GraphDrive($this->getGraphSite(), $json, $settings['extra'] ?? []);
    }

    /**
     * Create a Graph Column (Field)
     *
     * @param  string[] $properties Graph Column properties (name, text/number/dateTime/choice/..., required, ...)
     * @throws GraphException
     */
    public function createGraphColumn(array $properties): string
    {
        $json = $this->request(sprintf('sites/%s/lists/%s/columns', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)), [
            'json' => $properties,
        ], 'POST');

        return (string) ($json['id'] ?? '');
    }

    /**
     * Get the Graph Columns (Fields)
     *
     * @throws GraphException
     * @return string[][]
     */
    public function getGraphColumns(array $settings = []): array
    {
        $items = $this->getGraphSite()->getAllPages(
            sprintf('sites/%s/lists/%s/columns', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)),
            ['query' => ['$top' => $settings['top'] ?? 200]]
        );

        $columns = [];

        foreach ($items as $column) {
            if (empty($column['hidden']) && empty($column['readOnly'])) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Get the Graph List Item count
     *
     * @throws GraphException
     */
    public function getGraphItemCount(): int
    {
        $json = $this->request(sprintf('sites/%s/lists/%s/items', $this->getGraphSite()->getSiteId(), rawurlencode($this->id)), [
            'query' => [
                '$count' => 'true',
                '$top'   => 1,
            ],
        ]);

        return $this->itemCount = (int) ($json['@odata.count'] ?? count($json['value'] ?? []));
    }

    /**
     * Get all Graph Items
     *
     * @throws GraphException
     * @return GraphItem[]
     */
    public function getGraphItems(array $settings = []): array
    {
        $this->items = GraphItem::getAll($this, $settings);

        return $this->items;
    }

    /**
     * Get a Graph Item by ID
     *
     * @throws GraphException
     */
    public function getGraphItem(string $id, array $extra = []): GraphItem
    {
        $item = GraphItem::getByID($this, $id, $extra);

        $this[] = $item;

        return $item;
    }

    /**
     * Create a Graph Item
     *
     * @throws GraphException
     */
    public function createGraphItem(array $fields, array $extra = []): GraphItem
    {
        $item = GraphItem::create($this, $fields, $extra);

        $this[] = $item;

        return $item;
    }

    /**
     * Update a Graph Item
     *
     * @throws GraphException
     */
    public function updateGraphItem(string $id, array $fields): GraphItem
    {
        return $this[$id]->update($fields);
    }

    /**
     * Delete a Graph Item
     *
     * @throws GraphException
     */
    public function deleteGraphItem(string $id): bool
    {
        if ($this[$id]->delete()) {
            unset($this[$id]);
        }

        return true;
    }
}
