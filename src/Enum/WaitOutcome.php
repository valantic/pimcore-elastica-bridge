<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

/**
 * How waiting for the queued messages of a population ended.
 *
 * @internal
 */
enum WaitOutcome
{
    /** All messages have been processed. */
    case Completed;

    /** A listener asked to skip the switch. */
    case Skipped;

    /** Messages remain, so the message was queued again for later. */
    case Rescheduled;
}
