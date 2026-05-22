<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

class DocumentContext
{
    public function __construct(
        public readonly ?string $tenant = null,
        public readonly ?string $language = null,
        public readonly ?string $country = null,
    ) {}
}
