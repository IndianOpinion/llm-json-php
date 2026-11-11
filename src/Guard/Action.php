<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

enum Action: string
{
    /** The attempt fails and is retried. */
    case Reject = 'reject';
    /** The value is fixed and the fix is recorded on the result. */
    case Repair = 'repair';
}
