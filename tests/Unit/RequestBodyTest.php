<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Internal\BodyBuilder;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Tests\TestCase;

/** PRD 4.3, tests T11 to T22. */
final class RequestBodyTest extends TestCase
{
    public function testT11TokenCapAlwaysSentWithTheRightName(): void
    {
        $this->transport->push($this->ok(), $this->ok(), $this->ok(), $this->ok());
        $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
        $body = $this->lastBody();
        self::assertSame(8192, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);

        $this->client(Route::to($this->openRouter(), 'vendor/model-a'))->complete($this->request());
        self::assertSame(8192, $this->lastBody()['max_tokens']);

        $this->client(Route::to($this->openRouter(), 'vendor/model-a')->withTokenCap(2000))->complete($this->request());
        self::assertSame(2000, $this->lastBody()['max_tokens']);

        $this->client(Route::to($this->openRouter()->withTokenParameter('max_output_tokens'), 'vendor/model-a'))->complete($this->request()->withTokenCap(300));
        self::assertSame(300, $this->lastBody()['max_output_tokens']);
    }

    public function testT12StrictModeBody(): void
    {
        $this->transport->push($this->ok());
        $result = $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
        self::assertSame(OutputMode::Strict, $result->mode());
        $format = $this->lastBody()['response_format'];
        self::assertSame('json_schema', $format['type']);
        self::assertSame('article', $format['json_schema']['name']);
        self::assertTrue($format['json_schema']['strict']);
        self::assertSame($this->article()->toArray(), $format['json_schema']['schema']);
        self::assertStringNotContainsString('JSON schema', $this->lastBody()['messages'][0]['content']);
    }

    public function testT13JsonModeBody(): void
    {
        $this->transport->push($this->ok());
        $this->client(Route::to($this->openAi(), 'gpt-test')->withMode(OutputMode::Json))->complete($this->request());
        $body = $this->lastBody();
        self::assertSame(['type' => 'json_object'], $body['response_format']);
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertStringStartsWith('Summarise the article.', $body['messages'][0]['content']);
        self::assertStringContainsString(BodyBuilder::schemaInstruction($this->article()), $body['messages'][0]['content']);
        self::assertStringContainsString('one JSON object and nothing else', $body['messages'][0]['content']);
    }

    public function testT14PromptModeBody(): void
    {
        $this->transport->push($this->ok());
        $this->client(Route::to($this->openAi(), 'gpt-test')->withMode(OutputMode::Prompt))->complete($this->request());
        $body = $this->lastBody();
        self::assertArrayNotHasKey('response_format', $body);
        self::assertStringContainsString('one JSON object and nothing else', $body['messages'][0]['content']);
    }

    public function testGenericProviderDefaultsToPromptMode(): void
    {
        $this->transport->push($this->ok(), $this->ok());
        $generic = Provider::generic('local', 'http://localhost:8080/v1', keyless: true);
        $result = $this->client(Route::to($generic, 'local-model'))->complete($this->request());
        self::assertSame(OutputMode::Prompt, $result->mode());
        self::assertArrayNotHasKey('Authorization', $this->transport->lastRequest()?->headers ?? []);
        $result = $this->client(Route::to($generic->withStructuredOutputs(true, true), 'local-model'))->complete($this->request());
        self::assertSame(OutputMode::Strict, $result->mode());
    }

    public function testInstructionAddedToMessageList(): void
    {
        $this->transport->push($this->ok(), $this->ok());
        $client = $this->client(Route::to($this->openAi(), 'gpt-test')->withMode(OutputMode::Json));
        $client->complete(Request::for('chat', $this->article())->withMessages([['role' => 'user', 'content' => 'Hello']]));
        $messages = $this->lastBody()['messages'];
        self::assertSame('system', $messages[0]['role']);
        self::assertSame(['role' => 'user', 'content' => 'Hello'], $messages[1]);
        $client->complete(Request::for('chat', $this->article())->withMessages([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hello']]));
        $messages = $this->lastBody()['messages'];
        self::assertCount(2, $messages);
        self::assertStringStartsWith('Be brief.', $messages[0]['content']);
    }

    public function testT15OpenRouterUsageAndRequireParameters(): void
    {
        $this->transport->push($this->ok(), $this->ok(), $this->ok());
        $this->client(Route::to($this->openRouter(), 'vendor/model-a'))->complete($this->request());
        $body = $this->lastBody();
        self::assertSame(['include' => true], $body['usage']);
        self::assertSame(['require_parameters' => true], $body['provider']);

        $this->client(Route::to($this->openRouter(), 'vendor/model-a')->withUsageReporting(false)->withRequireParameters(false))->complete($this->request());
        $body = $this->lastBody();
        self::assertArrayNotHasKey('usage', $body);
        self::assertArrayNotHasKey('provider', $body);

        $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
        self::assertArrayNotHasKey('usage', $this->lastBody());
        self::assertArrayNotHasKey('provider', $this->lastBody());
    }

    public function testT16HostPreferencesPassThrough(): void
    {
        $this->transport->push($this->ok());
        $this->client(Route::to($this->openRouter(), 'vendor/model-a')->withHostPreferences(['host-b', 'host-a'], false))->complete($this->request());
        self::assertSame(['require_parameters' => true, 'order' => ['host-b', 'host-a'], 'allow_fallbacks' => false], $this->lastBody()['provider']);
    }

    public function testT17ReasoningOff(): void
    {
        $this->transport->push($this->ok(), $this->ok(), $this->ok());
        $this->client(Route::to($this->openRouter(), 'vendor/model-a')->withoutReasoning())->complete($this->request());
        self::assertSame(['enabled' => false], $this->lastBody()['reasoning']);
        $this->client(Route::to($this->openAi(), 'gpt-test')->withoutReasoning('minimal'))->complete($this->request());
        self::assertSame('minimal', $this->lastBody()['reasoning_effort']);
        $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request());
        self::assertArrayNotHasKey('reasoning_effort', $this->lastBody());
        self::assertArrayNotHasKey('reasoning', $this->lastBody());
    }

    public function testT18TemperatureOnlyWhenSet(): void
    {
        $this->transport->push($this->ok(), $this->ok(), $this->ok());
        $client = $this->client(Route::to($this->openAi(), 'gpt-test'));
        $client->complete($this->request());
        self::assertArrayNotHasKey('temperature', $this->lastBody());
        $client->complete($this->request()->withTemperature(0.0));
        self::assertSame(0.0, $this->lastBody()['temperature']);
        $this->client(Route::to($this->openAi(), 'gpt-test')->withTemperature(0.7))->complete($this->request());
        self::assertSame(0.7, $this->lastBody()['temperature']);
    }

    public function testT19HeadersAndUserAgent(): void
    {
        $this->transport->push($this->ok(), $this->ok());
        $this->client(Route::to($this->openRouter(), 'vendor/model-a'))->complete($this->request());
        $headers = $this->transport->lastRequest()?->headers ?? [];
        self::assertArrayNotHasKey('HTTP-Referer', $headers);
        self::assertArrayNotHasKey('X-Title', $headers);
        self::assertStringStartsWith('llm-json-php/' . Client::VERSION, $headers['User-Agent']);
        self::assertSame('Bearer test-key-openrouter-one', $headers['Authorization']);

        $provider = Provider::openRouter('test-key-openrouter-one', 'https://example.org', 'Example App');
        $this->client(Route::to($provider, 'vendor/model-a'))->complete($this->request());
        $headers = $this->transport->lastRequest()?->headers ?? [];
        self::assertSame('https://example.org', $headers['HTTP-Referer']);
        self::assertSame('Example App', $headers['X-Title']);
    }

    public function testT20QuoteInstructionOnlyWhenEnabled(): void
    {
        $this->transport->push($this->ok(), $this->ok());
        $client = $this->client(Route::to($this->openAi(), 'gpt-test'));
        $client->complete($this->request());
        self::assertStringNotContainsString('straight double quote', $this->lastBody()['messages'][0]['content']);
        $client->complete($this->request()->withGuardProfile(GuardProfile::default()->withQuoteInstruction()));
        self::assertStringContainsString(BodyBuilder::QUOTE_INSTRUCTION, $this->lastBody()['messages'][0]['content']);
    }

    public function testT21ExtraBodyMergesButCannotOverwrite(): void
    {
        $this->transport->push($this->ok());
        $this->client(Route::to($this->openAi(), 'gpt-test')->withExtraBody(['seed' => 7, 'top_p' => 0.9]))->complete($this->request());
        self::assertSame(7, $this->lastBody()['seed']);
        self::assertSame(0.9, $this->lastBody()['top_p']);
        foreach (['model', 'messages', 'response_format', 'max_completion_tokens', 'max_tokens'] as $field) {
            try {
                Route::to($this->openAi(), 'gpt-test')->withExtraBody([$field => 1]);
                self::fail('expected exception for ' . $field);
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testT22InvalidUtf8ThrowsBeforeAnyRequest(): void
    {
        try {
            $this->request()->withInput("bad \xC3\x28 bytes");
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('input', $e->getMessage());
        }
        try {
            Request::for('x', $this->article())->withMessages([['role' => 'user', 'content' => "\xFF"]]);
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('messages[0].content', $e->getMessage());
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testBodyEncodingKeepsSlashesAndUnicode(): void
    {
        $this->transport->push($this->ok());
        $this->client()->complete($this->request()->withInput('नमस्ते https://example.org/a'));
        $raw = $this->transport->lastRequest()?->body ?? '';
        self::assertStringContainsString('नमस्ते https://example.org/a', $raw);
    }
}
