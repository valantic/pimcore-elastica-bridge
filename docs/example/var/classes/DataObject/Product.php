<?php

declare(strict_types=1);

namespace Pimcore\Model\DataObject;

abstract class Product extends Concrete
{
    abstract public function getSku(): string;

    abstract public function getName(?string $locale = null): string;

    abstract public function getUrl(?string $locale = null): string;

    /** @return Product[] */
    abstract public function getRelatedProducts(): array;
}
