<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use RuntimeException;
use Throwable;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

class GraphException extends RuntimeException
{
    /**
     * Get the previous Exception message
     */
    public function getPreviousMessage(): ?string
    {
        $previous = $this->getPrevious();

        return $previous ? $previous->getMessage() : null;
    }

    /**
     * Create a GraphException from a TransferException
     */
    public static function fromTransferException(TransferException $e): static
    {
        if ($e instanceof ConnectException) {
            $message = $e->getMessage();
            $code = $e->getCode();
            $matches = [];

            // if it's a cURL error, throw an exception with a more meaningful error
            if (preg_match('/^cURL error (?<code>\d+): (?<message>.*)$/', $message, $matches) === 1) {
                $message = match ((int) $matches['code']) {
                    4   => $matches['message'].' Hint: Check which SSL/TLS protocols your build of libcURL supports',
                    35  => $matches['message'].' Hint: Handshake failed. If supported, try enabling newer TLS versions',
                    6, 7 => $matches['message'].' Hint: Check your DNS/network configuration and any proxy settings',
                    default => $matches['message'],
                };

                $code = (int) $matches['code'];
            }

            return new static('Unable to make HTTP request: '.$message, $code, $e);
        }

        if ($e instanceof RequestException) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            return new static('Unable to make HTTP request: '.$e->getMessage().$body, $e->getCode(), $e);
        }

        return new static('Unable to make HTTP request', 0, $e);
    }

    /**
     * Create a GraphException from a previous Throwable
     */
    public static function wrap(string $message, Throwable $previous): static
    {
        return new static($message, 0, $previous);
    }
}
