<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

class IndexContext
{
    public function __construct(
        public readonly ?string $tenant = null,
        public readonly ?string $language = null,
    ) {}
}
