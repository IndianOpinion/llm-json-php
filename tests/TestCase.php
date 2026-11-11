<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\MemoryStore;
use IndianOpinion\LlmJson\Testing\FrozenClock;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingLogger;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;
use IndianOpinion\LlmJson\Transport\HttpResponse;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FrozenClock $clock;
    protected MemoryStore $store;
    protected RecordingSleeper $sleeper;
    protected RecordingLogger $logger;
    protected MockTransport $transport;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->store = new MemoryStore($this->clock);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->logger = new RecordingLogger();
        $this->transport = new MockTransport();
    }

    protected function article(): Schema
    {
        return Schema::fromArray('article', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['title', 'paragraphs', 'tags'],
            'additionalProperties' => false,
        ]);
    }

    /** @return array<string, mixed> */
    protected function okData(): array
    {
        return [
            'title' => 'Monsoon arrives early in Kerala',
            'paragraphs' => ['The monsoon reached the Kerala coast three days ahead of schedule.', 'Farmers welcomed the rain.'],
            'tags' => ['weather'],
        ];
    }

    protected function openRouter(string $key = 'test-key-openrouter-one', string $name = 'openrouter'): Provider
    {
        return Provider::openRouter($key, name: $name);
    }

    protected function openAi(string $key = 'test-key-openai-one', string $name = 'openai'): Provider
    {
        return Provider::openAi($key, $name);
    }

    protected function client(Route ...$routes): Client
    {
        if ($routes === []) {
            $routes = [Route::to($this->openRouter(), 'vendor/model-a')];
        }

        return Client::create(Chain::of(...$routes), $this->transport)
            ->withClock($this->clock)
            ->withStore($this->store)
            ->withSleeper($this->sleeper)
            ->withLogger($this->logger);
    }

    protected function request(?Schema $schema = null, string $purpose = 'summary'): Request
    {
        return Request::for($purpose, $schema ?? $this->article())
            ->withSystem('Summarise the article.')
            ->withInput('PROMPT-TEXT-MARKER The monsoon reached Kerala early this year.');
    }

    /**
     * @param array<mixed>|null $data
     */
    protected function ok(?array $data = null, ?array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]): HttpResponse
    {
        return MockTransport::completion($data ?? $this->okData(), usage: $usage);
    }

    protected function fixture(string $name): HttpResponse
    {
        return MockTransport::fromFixture(__DIR__ . '/Fixtures/responses/' . $name . '.json');
    }

    protected function options(): Options
    {
        return Options::defaults();
    }

    /** @return array<string, mixed> */
    protected function lastBody(): array
    {
        $body = $this->transport->lastRequest()?->json();
        self::assertIsArray($body);

        return $body;
    }
}
