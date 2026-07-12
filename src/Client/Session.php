<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\Client;

readonly class Session
{
    public function __construct(
        public string $did,
        public string $handle,
        public string $accessJwt,
        public string $refreshJwt,
    ) {
    }
}
