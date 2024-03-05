<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Pimcore\Model\Element\ElementInterface;

class RefreshElement extends AbstractRefresh
{
    public function __construct(ElementInterface $element)
    {
        $this->setElement($element);
    }
}