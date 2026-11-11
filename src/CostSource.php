<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

enum CostSource: string
{
    /** The provider put a cost in the usage block. */
    case Reported = 'reported';
    /** Worked out from the caller's price table. */
    case PriceTable = 'price_table';
    /** No reported cost and no price for the model. Cost is null. */
    case Unknown = 'unknown';
    /** A total over attempts whose sources differ. */
    case Mixed = 'mixed';
    /**
     * A total where at least one billed attempt (an HTTP 2xx answer) has an
     * unknown cost. The figure is a lower bound, not the whole spend.
     */
    case Partial = 'partial';
}
