<?php

namespace AppBundle\Elasticsearch\Index\Search\Document;

use AppBundle\Elasticsearch\Index\Search\SearchIndex;

trait SearchIndexAwareTrait
{
    /**
     * @param SearchIndex $index
     *
     * @required
     */
    public function setIndex(SearchIndex $index): void
    {
        $this->index = $index;
    }
}
