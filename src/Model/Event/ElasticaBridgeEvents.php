<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

interface ElasticaBridgeEvents
{
    public const PRE_REFRESH_ELEMENT = 'valantic.elastica_bridge.pre_refreshed_element';

    public const POST_REFRESH_ELEMENT = 'valantic.elastica_bridge.post_refreshed_element';

    public const PRE_REFRESH_ELEMENT_IN_INDEX = 'valantic.elastica_bridge.pre_refreshed_element_in_index';

    public const POST_REFRESH_ELEMENT_IN_INDEX = 'valantic.elastica_bridge.post_refreshed_element_in_index';
}
