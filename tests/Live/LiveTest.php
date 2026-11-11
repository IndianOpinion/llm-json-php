<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Live;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\PriceTable;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use PHPUnit\Framework\TestCase;

/**
 * T125. Opt in by setting LLMJSON_LIVE=1 and one or both of
 * OPENROUTER_API_KEY (with OPENROUTER_MODEL) and OPENAI_API_KEY (with
 * OPENAI_MODEL). Costs a fraction of a cent per run.
 */
final class LiveTest extends TestCase
{
    private function schema(): Schema
    {
        return Schema::fromArray('capital', [
            'type' => 'object',
            'properties' => ['country' => ['type' => 'string'], 'capital' => ['type' => 'string']],
            'required' => ['country', 'capital'],
            'additionalProperties' => false,
        ]);
    }

    private function check(Provider $provider, string $model): void
    {
        $client = Client::create(Chain::of(Route::to($provider, $model)->withMode(OutputMode::Strict)->withTokenCap(200)))
            ->withPriceTable(PriceTable::fromArray([$model => ['input' => 1.0, 'output' => 1.0]]));
        $result = $client->complete(Request::for('live', $this->schema())->withSystem('Answer with the capital city.')->withInput('India'));
        self::assertSame('New Delhi', $result->data()['capital']);
        self::assertNotNull($result->usage()->promptTokens);
        self::assertNotNull($result->usage()->cost);
    }

    public function testOpenRouter(): void
    {
        $key = (string) getenv('OPENROUTER_API_KEY');
        if (getenv('LLMJSON_LIVE') !== '1' || $key === '') {
            self::markTestSkipped('Live tests are opt-in.');
        }
        $this->check(Provider::openRouter($key), (string) (getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini'));
    }

    public function testOpenAi(): void
    {
        $key = (string) getenv('OPENAI_API_KEY');
        if (getenv('LLMJSON_LIVE') !== '1' || $key === '') {
            self::markTestSkipped('Live tests are opt-in.');
        }
        $this->check(Provider::openAi($key), (string) (getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'));
    }
}
