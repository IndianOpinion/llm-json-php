<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

enum ProviderKind: string
{
    case OpenRouter = 'openrouter';
    case OpenAi = 'openai';
    case Generic = 'generic';
}
