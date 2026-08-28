<?php

declare(strict_types=1);

namespace SharepointGraphClient;

/**
 * A resumable Graph upload session
 *
 * Replaces the legacy SharePoint startUpload/continueUpload/finishUpload
 * workflow: chunks are PUT to a pre-authenticated upload URL, and the
 * final chunk returns the uploaded Graph File.
 */
class GraphUploadSession extends GraphObject
{
    /**
     * Chunk sizes must be multiples of 320 KiB
     */
    const MIN_CHUNK_SIZE = 327680;

    /**
     * Default chunk size (5 MiB)
     */
    const DEFAULT_CHUNK_SIZE = 5242880;

    /**
     * Graph Drive or parent Graph Folder
     */
    protected GraphDrive|GraphFolder $parent;

    /**
     * Upload URL (pre-authenticated)
     */
    protected ?string $uploadUrl = null;

    /**
     * Next expected ranges (eg. ["262144-"])
     */
    protected array $nextExpectedRanges = [];

    /**
     * Graph Upload Session constructor
     *
     * @param  string[] $json  JSON response from the Graph API
     * @param  string[] $extra Extra Graph Upload Session properties to map
     * @throws GraphException
     */
    public function __construct(GraphDrive|GraphFolder $parent, array $json, array $extra = [])
    {
        parent::__construct([
            'uploadUrl'          => 'uploadUrl',
            'nextExpectedRanges' => 'nextExpectedRanges',
        ], $extra);

        $this->parent = $parent;

        $this->hydrate($json);

        if ($this->uploadUrl === null || $this->uploadUrl === '') {
            throw new GraphException('The Graph API did not return an upload URL');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'upload_url'          => $this->uploadUrl,
            'next_expected_ranges' => $this->nextExpectedRanges,
            'extra'               => $this->extra,
        ];
    }

    /**
     * Get the next expected byte ranges
     */
    public function getNextExpectedRanges(): array
    {
        return $this->nextExpectedRanges;
    }

    /**
     * Get the next expected byte offset (from the next expected range)
     */
    public function getExpectedOffset(): int
    {
        foreach ($this->nextExpectedRanges as $range) {
            if (preg_match('/^(\d+)-/', (string) $range, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * Get the parent Graph Drive or Graph Folder
     */
    public function getGraphParent(): GraphDrive|GraphFolder
    {
        return $this->parent;
    }

    /**
     * Create a Graph Upload Session
     *
     * @static
     * @param  GraphDrive|GraphFolder $parent    Graph Drive or parent Graph Folder
     * @param  string                 $name      Name for the file being uploaded
     * @throws GraphException
     */
    public static function create(GraphDrive|GraphFolder $parent, string $name, bool $overwrite = false, array $extra = []): static
    {
        $json = $parent->request($parent->getGraphItemPath().':/'.static::encodePath($name).':/createUploadSession', [
            'json' => [
                'item' => [
                    '@microsoft.graph.conflictBehavior' => $overwrite ? 'replace' : 'fail',
                ],
            ],
        ], 'POST');

        return new static($parent, $json, $extra);
    }

    /**
     * Upload a chunk of the file
     *
     * The chunk size must be a multiple of 320 KiB, except for the
     * final chunk of the file. Intermediate chunks require the total
     * size of the file to be known (for the Content-Range header).
     *
     * @param  string     $chunk    Chunk contents
     * @param  int        $offset   Offset of the chunk inside the file
     * @param  int|null   $totalSize Total size of the file (required for intermediate chunks)
     * @param  string[]   $extra    Extra Graph File properties to map
     * @throws GraphException
     * @return GraphFile|null The uploaded Graph File, or null when the upload is still incomplete
     */
    public function uploadChunk(string $chunk, ?int $offset = null, ?int $totalSize = null, array $extra = []): ?GraphFile
    {
        if ($chunk === '') {
            throw new GraphException('The upload chunk is empty');
        }

        $offset ??= $this->getExpectedOffset();
        $length = strlen($chunk);

        if ($totalSize === null) {
            // assume this is the final chunk
            $totalSize = $offset + $length;
        }

        $end = $offset + $length - 1;

        if ($end >= $totalSize) {
            throw new GraphException('The upload chunk exceeds the total file size');
        }

        $response = $this->parent->getGraphSite()->request($this->uploadUrl, [
            'graph_auth' => false, // the upload URL is pre-authenticated
            'headers'    => [
                'Content-Range'  => sprintf('bytes %d-%d/%d', $offset, $end, $totalSize),
                'Content-Length' => (string) $length,
            ],
            'body' => $chunk,
        ], 'PUT');

        if (isset($response['id'])) {
            // the upload is complete, the Graph API returned the Drive Item
            return new GraphFile($this->parent, $response, $extra);
        }

        // the upload is incomplete, refresh the expected ranges
        $this->hydrate($response, true);

        return null;
    }

    /**
     * Upload a complete file in chunks
     *
     * @param  string|resource $data      File contents (string or stream resource)
     * @param  int             $chunkSize Chunk size in bytes (multiple of 320 KiB)
     * @param  string[]        $extra     Extra Graph File properties to map
     * @throws GraphException
     */
    public function upload(mixed $data, int $chunkSize = self::DEFAULT_CHUNK_SIZE, array $extra = []): GraphFile
    {
        if ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize % self::MIN_CHUNK_SIZE !== 0) {
            throw new GraphException('The upload chunk size must be a multiple of '.self::MIN_CHUNK_SIZE.' bytes');
        }

        if (is_resource($data)) {
            $handle = $data;
        } elseif (is_string($data)) {
            $handle = fopen('php://temp', 'r+b');

            if ($handle === false) {
                throw new GraphException('Unable to buffer the file contents');
            }

            fwrite($handle, $data);
            rewind($handle);
        } else {
            throw new GraphException('Invalid upload data type: '.get_debug_type($data));
        }

        $stat = fstat($handle);

        if ($stat === false || $stat['size'] === 0) {
            if (is_resource($handle) && $handle !== $data) {
                fclose($handle);
            }

            throw new GraphException('The upload data is empty');
        }

        $totalSize = (int) $stat['size'];
        $offset = 0;

        try {
            while (! feof($handle)) {
                $chunk = (string) fread($handle, $chunkSize);

                if ($chunk === '') {
                    break;
                }

                $file = $this->uploadChunk($chunk, $offset, $totalSize, $extra);

                if ($file !== null) {
                    return $file;
                }

                $offset += strlen($chunk);
            }
        } finally {
            // close the buffer we created, but not the caller's stream
            if (is_resource($handle) && $handle !== $data) {
                fclose($handle);
            }
        }

        throw new GraphException('The upload session did not return a Graph File (expected offset: '.$this->getExpectedOffset().' of '.$totalSize.')');
    }

    /**
     * Cancel the upload session
     *
     * @throws GraphException
     */
    public function cancel(): void
    {
        $this->parent->getGraphSite()->request($this->uploadUrl, [
            'graph_auth' => false, // the upload URL is pre-authenticated
        ], 'DELETE');
    }
}
