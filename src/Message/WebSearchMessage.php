<?php

namespace App\Message;

/** Búsqueda web de una parte: puede tardar minutos con un modelo local, por eso corre en segundo plano. */
final class WebSearchMessage
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $query,
        public readonly string $manufacturer,
        public readonly string $requestedBy,
    ) {
    }
}
