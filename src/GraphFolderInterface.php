<?php

declare(strict_types=1);

namespace SharepointGraphClient;

interface GraphFolderInterface extends GraphRequesterInterface
{
    /**
     * Get the Graph Site
     */
    public function getGraphSite(): GraphSite;

    /**
     * Get the path relative to the Drive root
     *
     * @param  string|null $path Path to append to the relative path
     * @return string|null
     */
    public function getRelativeUrl(?string $path = null): ?string;

    /**
     * Get the URL
     *
     * @param  string|null $path Path to append to the URL
     * @return string|null
     */
    public function getUrl(?string $path = null): ?string;

    /**
     * Is the container writable (allows Folder/File operations)?
     *
     * @throws GraphException
     */
    public function isWritable(bool $exception = false): bool;
}
