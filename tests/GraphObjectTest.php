<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use DateTimeImmutable;

use PHPUnit\Framework\TestCase;

use SharepointGraphClient\GraphException;
use SharepointGraphClient\GraphObject;

class HydrationDummy extends GraphObject
{
    protected ?string $name = null;

    protected ?string $childName = null;

    protected ?DateTimeImmutable $created = null;

    public function __construct(array $json, array $extra = [])
    {
        parent::__construct([
            'name'      => 'name',
            'childName' => 'child->name',
            'created'   => 'createdDateTime',
        ], $extra);

        $this->hydrate($json);
    }

    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'childName' => $this->childName,
            'created'   => $this->created,
        ];
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getChildName(): ?string
    {
        return $this->childName;
    }

    public function getCreated(): ?DateTimeImmutable
    {
        return $this->created;
    }
}

class GraphObjectTest extends TestCase
{
    public function test_hydration_maps_dot_notation_paths(): void
    {
        $object = new HydrationDummy([
            'name'     => 'John',
            'child'    => ['name' => 'Doe'],
            'unknown'  => 'extra value',
        ], [
            'unknown' => 'unknown', // map an additional JSON property into extra
        ]);

        $this->assertSame('John', $object->getName());
        $this->assertSame('Doe', $object->getChildName());
        $this->assertSame('extra value', $object->getExtra('unknown'));
    }

    public function test_hydration_converts_iso_8601_dates_into_datetime_objects(): void
    {
        $object = new HydrationDummy([
            'createdDateTime' => '2023-01-02T03:04:05Z',
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $object->getCreated());
        $this->assertSame('2023-01-02T03:04:05+00:00', $object->getCreated()->format('Y-m-d\TH:i:sP'));
    }

    public function test_hydration_converts_iso_8601_dates_with_fractions(): void
    {
        $object = new HydrationDummy([
            'createdDateTime' => '2023-01-02T03:04:05.123Z',
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $object->getCreated());
    }

    public function test_hydration_drops_values_that_do_not_match_the_property_type(): void
    {
        $object = new HydrationDummy([
            'createdDateTime' => 'not a date',
        ]);

        $this->assertNull($object->getCreated());
    }

    public function test_get_extra_throws_on_unknown_property(): void
    {
        $object = new HydrationDummy([]);

        $this->expectException(GraphException::class);

        $object->getExtra('nope');
    }

    public function test_encode_path_encodes_each_segment_and_keeps_slashes(): void
    {
        $this->assertSame('Docs/My%20File.txt', GraphObject::encodePath('Docs/My File.txt'));
        $this->assertSame('Folder%20A/Sub%23Dir/file%251.txt', GraphObject::encodePath('Folder A/Sub#Dir/file%1.txt'));
        $this->assertSame('', GraphObject::encodePath(null));
    }
}
