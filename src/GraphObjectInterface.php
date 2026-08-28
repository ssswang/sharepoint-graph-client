<?php

declare(strict_types=1);

namespace SharepointGraphClient;

interface GraphObjectInterface
{
    /**
     * Get an array with the Graph Object properties
     */
    public function toArray(): array;
}
