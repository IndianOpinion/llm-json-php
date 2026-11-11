<?php

/**
 * The guard repairing a broken-quote answer. Runs offline.
 *
 *     php examples/guard-profile.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Testing\MockTransport;

$schema = Schema::fromArray('article', [
    'type' => 'object',
    'properties' => [
        'title' => ['type' => 'string'],
        'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required' => ['title', 'paragraphs'],
    'additionalProperties' => false,
]);

$profile = GuardProfile::forSchema($schema)
    ->withQuoteInstruction()
    ->withControlCharacters(Action::Reject, ['/title'])
    ->withSentenceEnd(['/paragraphs'], Action::Repair)
    ->withContinuationItems(['/paragraphs'], Action::Repair)
    ->withWordFloor('/paragraphs', 5);

// A straight double quote closed the first paragraph early and the rest
// spilled into a new item that starts in lower case.
$transport = new MockTransport(MockTransport::completion([
    'title' => 'Minister on the new dam',
    'paragraphs' => [
        'The minister visited the site on Monday. Speaking to reporters, she said ',
        'this is a historic day for the district.',
        'Work on the dam will begin in March.',
    ],
]));

$client = Client::create(Chain::of(Route::to(Provider::openAi('your-openai-key'), 'gpt-4o-mini')), $transport)
    ->withGuardProfile('article', $profile);

$result = $client->complete(Request::for('article', $schema)->withSystem('Write the article.')->withInput('Notes...'));

print_r($result->data()['paragraphs']);
foreach ($result->guardReport()->repairs as $repair) {
    printf("%s at %s: %s\n", $repair->rule, $repair->path, $repair->description);
}
