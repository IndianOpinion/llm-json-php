<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

/**
 * A store operation failed. The client catches it, records it on the result
 * and carries on as if nothing were cooled or held.
 */
final class StoreException extends LlmJsonException
{
}
