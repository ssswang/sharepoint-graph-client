<?php

declare(strict_types=1);

namespace SharepointGraphClient;

trait GraphTimestampsTrait
{
    /**
     * Creation Time
     */
    protected ?\DateTimeImmutable $created = null;

    /**
     * Modification Time
     */
    protected ?\DateTimeImmutable $modified = null;

    /**
     * Get Creation Time
     */
    public function getTimeCreated(): ?\DateTimeImmutable
    {
        return $this->created;
    }

    /**
     * Get Modification Time
     */
    public function getTimeModified(): ?\DateTimeImmutable
    {
        return $this->modified;
    }
}
