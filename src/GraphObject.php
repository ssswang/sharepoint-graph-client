<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use DateTimeImmutable;

abstract class GraphObject implements GraphObjectInterface
{
    /**
     * Property mapper (property name => dot notation path)
     */
    protected array $mapper = [];

    /**
     * Extra properties (mapped properties that do not match a class property)
     */
    protected array $extra = [];

    /**
     * Graph Object constructor
     *
     * @param  string[] $mapper Dot notation property mapper
     * @param  string[] $extra  Extra property paths to map
     */
    public function __construct(array $mapper, array $extra = [])
    {
        $this->mapper = array_merge($mapper, $extra);
    }

    /**
     * Get extra properties
     *
     * @throws GraphException when the property is not set
     */
    public function getExtra(?string $property = null): mixed
    {
        if ($property === null) {
            return $this->extra;
        }

        if (array_key_exists($property, $this->extra)) {
            return $this->extra[$property];
        }

        throw new GraphException('Invalid property: '.$property);
    }

    /**
     * Encode a Drive item path for the Graph API
     *
     * Each path segment is raw URL encoded, slashes are preserved.
     */
    public static function encodePath(?string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', (string) $path)
        );

        return implode('/', $segments);
    }

    /**
     * Assign a property value
     */
    protected function assign(string $property, mixed $value): void
    {
        // convert ISO 8601 dates into DateTimeImmutable objects
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $value) === 1) {
            $value = new DateTimeImmutable($value);
        }

        if (property_exists($this, $property)) {
            // keep the property default when the value does not
            // satisfy the declared property type (incl. null on
            // non-nullable properties)
            try {
                $this->{$property} = $value;
            } catch (\TypeError) {
            }
        } else {
            $this->extra[$property] = $value;
        }
    }

    /**
     * Get a value from a JSON array
     *
     * @param  array  $json JSON response from the Graph API
     * @param  string $path Dot notation path to the value we want to get
     */
    protected function getJsonValue(array $json, string $path): mixed
    {
        foreach (explode('->', $path) as $segment) {
            if (! is_array($json) || ! array_key_exists($segment, $json)) {
                return null;
            }

            $json = $json[$segment];
        }

        return $json;
    }

    /**
     * Hydration handler
     *
     * @param  static|array $data      GraphObject / JSON response from the Graph API
     * @param  bool         $rehydrate Are we rehydrating? (null values are skipped when rehydrating)
     * @throws GraphException when the data cannot be hydrated
     */
    protected function hydrate(self|array $data, bool $rehydrate = false): static
    {
        // hydrate from an equivalent GraphObject
        if ($data instanceof $this) {
            foreach (get_object_vars($data) as $key => $value) {
                $this->{$key} = $value;
            }

            return $this;
        }

        // hydrate from an array (JSON)
        foreach ($this->mapper as $property => $path) {
            $value = $this->getJsonValue($data, $path);

            if ($value !== null || $rehydrate === false) {
                $this->assign($property, $value);
            }
        }

        return $this;
    }
}
