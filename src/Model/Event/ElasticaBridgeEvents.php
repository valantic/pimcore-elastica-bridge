<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

interface ElasticaBridgeEvents
{
    public const PRE_REFRESH_ELEMENT = 'valantic.elastica_bridge.pre_refreshed_element';
    public const POST_REFRESH_ELEMENT = 'valantic.elastica_bridge.post_refreshed_element';
    public const PRE_REFRESH_ELEMENT_IN_INDEX = 'valantic.elastica_bridge.pre_refreshed_element_in_index';
    public const POST_REFRESH_ELEMENT_IN_INDEX = 'valantic.elastica_bridge.post_refreshed_element_in_index';
    public const CALLBACK_EVENT = 'valantic.elastica_bridge.populate.callback_event';
    public const PRE_EXECUTE = 'valantic.elastica_bridge.pre_execute';
    public const PRE_PROCESS_MESSAGES_EVENT = 'valantic.elastica_bridge.pre_process_messages_event';
    public const PRE_DOCUMENT_CREATE = 'valantic.elastica_bridge.pre_document_create';
    public const POST_DOCUMENT_CREATE = 'valantic.elastica_bridge.post_document_create';
    public const PRE_SWITCH_INDEX = 'valantic.elastica_bridge.pre_switch_index';
    public const WAIT_FOR_COMPLETION_EVENT = 'valantic.elastica_bridge.wait_for_completion_event';
    public const POST_SWITCH_INDEX = 'valantic.elastica_bridge.post_switch_index';

}
