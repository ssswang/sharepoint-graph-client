<?php

declare(strict_types=1);

namespace SharepointGraphClient;

class GraphItem extends GraphObject implements GraphItemInterface
{
    use GraphTimestampsTrait;

    /**
     * Graph List
     */
    protected GraphList $list;

    /**
     * Graph Item ID
     */
    protected string $id = '';

    /**
     * Graph Item Title
     */
    protected string $title = '';

    /**
     * Graph Item fields (column values)
     */
    protected array $fields = [];

    /**
     * Graph Item ETag
     */
    protected ?string $eTag = null;

    /**
     * Graph Item web URL
     */
    protected ?string $webUrl = null;

    /**
     * Graph Item constructor
     *
     * @param  string[] $json  JSON response from the Graph API
     * @param  string[] $extra Extra Graph Item properties to map
     * @throws GraphException
     */
    public function __construct(GraphList $list, array $json, array $extra = [])
    {
        parent::__construct([
            'id'       => 'id',
            'title'    => 'fields->Title',
            'created'  => 'createdDateTime',
            'modified' => 'lastModifiedDateTime',
            'fields'   => 'fields',
            'eTag'     => 'eTag',
            'webUrl'   => 'webUrl',
        ], $extra);

        $this->list = $list;

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'fields'   => $this->fields,
            'extra'    => $this->extra,
            'created'  => $this->created,
            'modified' => $this->modified,
            'web_url'  => $this->webUrl,
        ];
    }

    /**
     * Get the Graph List
     */
    public function getGraphList(): GraphList
    {
        return $this->list;
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
     * Get all Graph Item fields
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get a Graph Item field
     */
    public function getField(string $name, mixed $default = null): mixed
    {
        return $this->fields[$name] ?? $default;
    }

    /**
     * Get all Graph Items of a Graph List
     *
     * @static
     * @throws GraphException
     * @return GraphItem[]
     */
    public static function getAll(GraphList $list, array $settings = []): array
    {
        $settings = array_replace_recursive([
            'top'   => 500, // Graph page size
        ], $settings, [
            'extra' => [],  // extra Graph Item properties to map
        ]);

        $items = $list->getGraphSite()->getAllPages(
            sprintf('sites/%s/lists/%s/items', $list->getGraphSite()->getSiteId(), rawurlencode($list->getId())),
            [
                'query' => [
                    '$expand' => 'fields',
                    '$top'    => $settings['top'],
                ],
            ]
        );

        $result = [];

        foreach ($items as $item) {
            $result[$item['id']] = new static($list, $item, $settings['extra']);
        }

        return $result;
    }

    /**
     * Get a Graph Item by ID
     *
     * @static
     * @throws GraphException
     */
    public static function getByID(GraphList $list, string $id, array $extra = []): static
    {
        $json = $list->request(sprintf(
            'sites/%s/lists/%s/items/%s',
            $list->getGraphSite()->getSiteId(),
            rawurlencode($list->getId()),
            rawurlencode($id)
        ), [
            'query' => ['$expand' => 'fields'],
        ]);

        return new static($list, $json, $extra);
    }

    /**
     * Get a Graph Item by Title
     *
     * Returns the first matching Graph Item, or null.
     *
     * @static
     * @throws GraphException
     */
    public static function getByTitle(GraphList $list, string $title, array $extra = []): ?static
    {
        return static::getByField($list, 'Title', $title, $extra);
    }

    /**
     * Get a Graph Item by the value of a field
     *
     * Returns the first matching Graph Item, or null.
     *
     * @static
     * @throws GraphException
     */
    public static function getByField(GraphList $list, string $field, string $value, array $extra = []): ?static
    {
        $json = $list->request(sprintf(
            'sites/%s/lists/%s/items',
            $list->getGraphSite()->getSiteId(),
            rawurlencode($list->getId())
        ), [
            'query' => [
                '$expand' => 'fields',
                '$filter' => sprintf("fields/%s eq '%s'", $field, str_replace("'", "''", $value)),
                '$top'    => 1,
            ],
        ]);

        $value = $json['value'][0] ?? null;

        return $value !== null ? new static($list, $value, $extra) : null;
    }

    /**
     * Create a Graph Item
     *
     * @static
     * @param  string[] $fields Graph Item fields (Title, ...)
     * @throws GraphException
     */
    public static function create(GraphList $list, array $fields, array $extra = []): static
    {
        $json = $list->request(sprintf(
            'sites/%s/lists/%s/items',
            $list->getGraphSite()->getSiteId(),
            rawurlencode($list->getId())
        ), [
            'json' => ['fields' => $fields],
        ], 'POST');

        return new static($list, $json, $extra);
    }

    /**
     * Update a Graph Item
     *
     * @throws GraphException
     */
    public function update(array $fields): static
    {
        $json = $this->list->request(sprintf(
            'sites/%s/lists/%s/items/%s',
            $this->list->getGraphSite()->getSiteId(),
            rawurlencode($this->list->getId()),
            rawurlencode($this->id)
        ), [
            'headers' => ['If-Match' => '*'],
            'json'    => ['fields' => $fields],
        ], 'PATCH');

        // the Graph API returns the updated Graph Item
        return $this->hydrate($json, true);
    }

    /**
     * Delete a Graph Item
     *
     * @throws GraphException
     */
    public function delete(): bool
    {
        $this->list->request(sprintf(
            'sites/%s/lists/%s/items/%s',
            $this->list->getGraphSite()->getSiteId(),
            rawurlencode($this->list->getId()),
            rawurlencode($this->id)
        ), [], 'DELETE');

        return true;
    }
}
