<?php

declare(strict_types=1);

namespace Charlotte\Http;

/** A framework-neutral answer: a status and a JSON-encodable body. */
final readonly class ApiResponse
{
    public function __construct(public int $status, public array|object $body) {}

    public function json(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
