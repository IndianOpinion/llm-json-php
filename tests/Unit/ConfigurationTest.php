<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Tests\TestCase;

/** PRD 4.2, tests T1 to T10. */
final class ConfigurationTest extends TestCase
{
    public function testT1EmptyChainThrowsAndSendsNothing(): void
    {
        $client = Client::create(null, $this->transport);
        try {
            $client->complete($this->request());
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('chain', $e->getMessage());
        }
        self::assertSame([], $this->transport->requests());

        $this->expectException(ConfigurationException::class);
        Chain::of();
    }

    public function testT2EmptyKeyThrowsUnlessKeylessGeneric(): void
    {
        foreach ([fn () => Provider::openRouter(''), fn () => Provider::openAi(''), fn () => Provider::generic('local', 'http://localhost:8080/v1')] as $make) {
            try {
                $make();
                self::fail('expected exception');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('empty API key', $e->getMessage());
            }
        }
        $keyless = Provider::generic('local', 'http://localhost:8080/v1', keyless: true);
        self::assertTrue($keyless->isKeyless());
        self::assertNull($keyless->authorization());
    }

    public function testT3PlainHttpRejectedExceptLoopbackOrFlag(): void
    {
        try {
            Provider::generic('remote', 'http://llm.example.com/v1', 'test-key-generic');
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('https', $e->getMessage());
        }
        foreach (['http://localhost:11434/v1', 'http://127.0.0.1:8000/v1', 'http://[::1]:8000/v1'] as $url) {
            self::assertSame($url, Provider::generic('local', $url, 'k')->baseUrl());
        }
        self::assertSame('http://llm.internal/v1', Provider::generic('lan', 'http://llm.internal/v1', 'k', allowPlainHttp: true)->baseUrl());
        $this->expectException(ConfigurationException::class);
        $this->openAi()->withBaseUrl('http://api.example.com/v1');
    }

    public function testT4SchemaRootMustBeObject(): void
    {
        $this->expectException(ConfigurationException::class);
        Schema::fromArray('list', ['type' => 'array', 'items' => ['type' => 'string']]);
    }

    public function testT4SchemaJsonArrayRootThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        Schema::fromJson('list', '[{"type":"object"}]');
    }

    public function testT5SchemaNames(): void
    {
        foreach (['', str_repeat('a', 65), 'has space', 'slash/name'] as $name) {
            try {
                Schema::fromArray($name, ['type' => 'object']);
                self::fail('expected exception for "' . $name . '"');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(str_repeat('a', 64), Schema::fromArray(str_repeat('a', 64), ['type' => 'object'])->name());
    }

    public function testT6StrictRouteWithLooseSchemaThrowsNamingThePath(): void
    {
        $loose = Schema::fromArray('loose', [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'meta' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]],
            'required' => ['title', 'meta'],
            'additionalProperties' => false,
        ]);
        $client = $this->client(Route::to($this->openAi(), 'gpt-test')->withMode(OutputMode::Strict));
        try {
            $client->complete($this->request($loose));
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('/properties/meta', $e->getMessage());
        }
        self::assertSame([], $this->transport->requests());

        $this->transport->push($this->ok(['title' => 'T', 'meta' => ['a' => 'x']]));
        $result = $this->client(Route::to($this->openAi(), 'gpt-test'))->complete($this->request($loose));
        self::assertSame(OutputMode::Json, $result->mode());
        self::assertStringContainsString('/properties/meta', $result->modeReason());
        self::assertSame(['type' => 'json_object'], $this->lastBody()['response_format']);
    }

    public function testT7TemperatureAndTokenCapBounds(): void
    {
        $route = Route::to($this->openAi(), 'gpt-test');
        foreach ([fn () => $route->withTemperature(2.5), fn () => $route->withTemperature(-0.1), fn () => $route->withTokenCap(0), fn () => $this->request()->withTokenCap(0), fn () => $this->request()->withTemperature(2.5)] as $make) {
            try {
                $make();
                self::fail('expected exception');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(2.0, $route->withTemperature(2.0)->temperature());
    }

    public function testT8PurposeCharacters(): void
    {
        $this->expectException(ConfigurationException::class);
        Request::for('news/summary', $this->article());
    }

    public function testT8PurposeLength(): void
    {
        $this->expectException(ConfigurationException::class);
        Request::for(str_repeat('p', 65), $this->article());
    }

    public function testT9EmptyInstructionAndInput(): void
    {
        $client = $this->client();
        try {
            $client->complete(Request::for('summary', $this->article()));
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('both empty', $e->getMessage());
        }
        $this->transport->push($this->ok(), $this->ok());
        $client->complete(Request::for('summary', $this->article())->withSystem('Only a system instruction.'));
        $client->complete(Request::for('summary', $this->article())->withInput('Only an input.'));
        self::assertCount(2, $this->transport->requests());
    }

    public function testT10GuardPathNotInSchemaThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('/paragrafs');
        GuardProfile::forSchema($this->article())->withSentenceEnd(['/paragrafs']);
    }

    public function testT10ProfileForAnotherSchemaIsCheckedAtCallTime(): void
    {
        $other = Schema::fromArray('other', ['type' => 'object', 'properties' => ['body' => ['type' => 'string']], 'required' => ['body'], 'additionalProperties' => false]);
        $profile = GuardProfile::forSchema($other)->withSentenceEnd(['/body']);
        $this->expectException(ConfigurationException::class);
        $this->client()->complete($this->request()->withGuardProfile($profile));
    }

    public function testPathRulesNeedASchema(): void
    {
        $this->expectException(ConfigurationException::class);
        GuardProfile::default()->withSentenceEnd(['/title']);
    }

    public function testProviderDebugInfoHidesKey(): void
    {
        $dump = print_r($this->openAi('test-key-secret-value'), true);
        self::assertStringNotContainsString('test-key-secret-value', $dump);
        self::assertStringContainsString('redacted', $dump);
    }

    public function testExtraHeadersCannotSetAuthorization(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->openAi()->withHeaders(['Authorization' => 'Bearer other']);
    }
}
