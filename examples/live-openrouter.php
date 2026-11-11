<?php

/**
 * A real call with a fallback chain, a file store and an alert on credit
 * exhaustion. Needs OPENROUTER_API_KEY, and OPENAI_API_KEY for the last
 * route.
 *
 *     OPENROUTER_API_KEY=... OPENAI_API_KEY=... php examples/live-openrouter.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Event;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\FileStore;

$openRouterKey = (string) getenv('OPENROUTER_API_KEY');
$openAiKey = (string) getenv('OPENAI_API_KEY');
if ($openRouterKey === '' || $openAiKey === '') {
    fwrite(STDERR, "Set OPENROUTER_API_KEY and OPENAI_API_KEY to run this example.\n");
    exit(1);
}

$schema = Schema::fromArray('capital', [
    'type' => 'object',
    'properties' => ['country' => ['type' => 'string'], 'capital' => ['type' => 'string']],
    'required' => ['country', 'capital'],
    'additionalProperties' => false,
]);

$openRouter = Provider::openRouter($openRouterKey);
$client = Client::create(Chain::of(
    Route::to($openRouter, 'openai/gpt-4o-mini')->withTokenCap(400),
    Route::to($openRouter, 'google/gemini-2.0-flash-001')->withTokenCap(400),
    Route::to(Provider::openAi($openAiKey), 'gpt-4o-mini')->withTokenCap(400),
))
    ->withStore(new FileStore(sys_get_temp_dir() . '/llmjson-example'))
    ->withOptions(Options::defaults()->withDeadline(60))
    ->on(Event::CREDIT_EXHAUSTED, static function (CreditExhaustion $e): void {
        fwrite(STDERR, "ALERT: {$e->provider} is out of credit\n");
    });

try {
    $result = $client->complete(Request::for('capital', $schema)->withSystem('Name the capital.')->withInput('India'));
    print_r($result->data());
    printf("cost %s (%s)\n", var_export($result->totalUsage()->cost, true), $result->totalUsage()->costSource->value);
} catch (ChainExhaustedException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
