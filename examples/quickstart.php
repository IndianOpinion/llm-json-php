<?php

/**
 * Runs offline: a mock transport stands in for the provider.
 *
 *     php examples/quickstart.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;

$schema = Schema::fromArray('summary', [
    'type' => 'object',
    'properties' => [
        'title' => ['type' => 'string'],
        'points' => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required' => ['title', 'points'],
    'additionalProperties' => false,
]);

// The primary answers 503 twice; the sibling model answers properly.
$transport = new MockTransport(
    MockTransport::status(503, 'busy'),
    MockTransport::status(503, 'busy'),
    MockTransport::completion(['title' => 'Monsoon arrives early', 'points' => ['Kerala got rain three days early.']]),
);

$client = Client::create(Chain::of(
    Route::to(Provider::openRouter('your-openrouter-key'), 'vendor/model-a'),
    Route::to(Provider::openRouter('your-openrouter-key'), 'vendor/model-b'),
    Route::to(Provider::openAi('your-openai-key'), 'gpt-4o-mini'),
), $transport)->withSleeper(new RecordingSleeper());

$result = $client->complete(
    Request::for('summary', $schema)
        ->withSystem('Summarise the article in one title and short points.')
        ->withInput('The monsoon reached the Kerala coast three days ahead of schedule...'),
);

print_r($result->data());
printf("Answered by %s/%s after %d attempt(s).\n", $result->provider(), $result->model(), count($result->attempts()));
foreach ($result->attempts() as $attempt) {
    printf("  #%d %s %s -> %s\n", $attempt->number, $attempt->provider, $attempt->model, $attempt->outcome->value);
}
