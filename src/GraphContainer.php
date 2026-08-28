<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * Base class for Graph Objects that contain other
 * Graph Items (Graph Lists and Graph Folders)
 */
abstract class GraphContainer extends GraphObject implements ArrayAccess, Countable, IteratorAggregate, GraphFolderInterface
{
    use GraphTimestampsTrait;

    /**
     * Graph Site
     */
    protected ?GraphSite $site = null;

    /**
     * Graph Drive ID (when the container lives inside a Drive)
     */
    protected ?string $driveId = null;

    /**
     * Graph web URL
     */
    protected ?string $webUrl = null;

    /**
     * Graph Item Count
     */
    protected int $itemCount = 0;

    /**
     * Graph Items
     */
    protected array $items = [];

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (isset($this->items[$offset])) {
            return $this->items[$offset];
        }

        throw new GraphException('Invalid Graph Item ID');
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! $value instanceof GraphItemInterface) {
            throw new GraphException('Graph Item expected');
        }

        // always set the Item ID as the array index
        $this->items[(string) $value->getId()] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Get the contained Graph Items
     */
    public function getGraphItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritdoc}
     */
    public function getGraphSite(): GraphSite
    {
        if (! $this->site instanceof GraphSite) {
            throw new GraphException('Invalid Graph Site');
        }

        return $this->site;
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
     * Get the ID of the Graph Drive this container belongs to
     */
    public function getDriveId(): ?string
    {
        return $this->driveId;
    }

    /**
     * Get the Graph API item path of this container
     * (drives are addressed by their root item)
     */
    public function getGraphItemPath(): string
    {
        return sprintf('drives/%s/root', $this->getDriveId());
    }

    /**
     * {@inheritdoc}
     */
    public function getRelativeUrl(?string $path = null): ?string
    {
        return sprintf('%s/%s', rtrim((string) $this->relativeUrl, '/'), ltrim((string) $path, '/'));
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(?string $path = null): ?string
    {
        return $this->webUrl;
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(bool $exception = false): bool
    {
        return true;
    }

    /**
     * Get the Graph Item count
     */
    public function getGraphItemCount(): int
    {
        return $this->itemCount;
    }
}
