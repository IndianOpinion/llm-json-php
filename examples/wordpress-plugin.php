<?php

/**
 * Plugin Name: Example: structured summaries
 * Description: Shows llm-json-php inside WordPress without Composer.
 *
 * Copy llm-json-php into your plugin folder as lib/llm-json-php. Define
 * EXAMPLE_OPENROUTER_KEY in wp-config.php.
 */

declare(strict_types=1);

namespace ExamplePlugin;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';

function client(): Client
{
    static $client = null;
    if ($client === null) {
        $provider = Provider::openRouter((string) constant('EXAMPLE_OPENROUTER_KEY'), home_url('/'), get_bloginfo('name'));
        $client = Client::create(
            Chain::of(Route::to($provider, 'openai/gpt-4o-mini'), Route::to($provider, 'google/gemini-2.0-flash-001')),
            new WordPressTransport(),
        )->withStore(new WordPressTransientStore());
    }

    return $client;
}

/**
 * @return array<mixed>
 */
function summarise(string $text): array
{
    $schema = Schema::fromArray('summary', [
        'type' => 'object',
        'properties' => ['summary' => ['type' => 'string']],
        'required' => ['summary'],
        'additionalProperties' => false,
    ]);

    return client()->ask('summary', $schema, 'Summarise in two sentences.', $text);
}
