<?php

namespace App\DTO;

/**
 * Outcome of a call to the "apply" API.
 *
 * The upstream status code is kept as-is (200/201/400/404/413/429/502) so the
 * controller can mirror it instead of turning every failure into a 500.
 */
final readonly class JobApplicationResult
{
    /**
     * @param array<string, mixed>  $payload the decoded API response, or our own error payload
     * @param array<string, string> $headers response headers worth forwarding (e.g. Retry-After on a 429)
     */
    public function __construct(
        public int $statusCode,
        public array $payload,
        public array $headers = [],
    ) {
    }
}
