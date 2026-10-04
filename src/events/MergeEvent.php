<?php

declare(strict_types=1);

namespace justinholtweb\glue\events;

use craft\events\CancelableEvent;
use justinholtweb\glue\models\MergePlan;
use justinholtweb\glue\models\MergeResult;
use justinholtweb\glue\models\Pair;

/**
 * Raised around a merge.
 *
 * Carries the plan, the analysed pair and the result rather than just the entry, because the
 * interesting things another plugin wants to do here — veto a merge of a section it owns, copy
 * across a value Glue could not, write its own audit row — all need to know *what was decided*,
 * not only what came out.
 *
 * Cancelling (`$event->isValid = false`) only does anything on `EVENT_BEFORE_MERGE`, and it
 * happens before the transaction opens.
 */
class MergeEvent extends CancelableEvent
{
    public ?MergePlan $plan = null;
    public ?Pair $pair = null;
    public ?MergeResult $result = null;
}
