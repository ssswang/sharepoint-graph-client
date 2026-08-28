<?php

declare(strict_types=1);

namespace SharepointGraphClient;

interface GraphItemInterface
{
    /**
     * Get the Graph Item ID
     */
    public function getId(): ?string;

    /**
     * Get the Graph Item Title
     */
    public function getTitle(): ?string;
}
