<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Tests\TestCase;
use IndianOpinion\LlmJson\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

/** PRD 4.7, tests T61 to T67. */
final class SchemaValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $property
     */
    private static function one(array $property): Schema
    {
        return Schema::fromArray('one', ['type' => 'object', 'properties' => ['v' => $property], 'required' => ['v'], 'additionalProperties' => false]);
    }

    /** @return iterable<string, array{array<string, mixed>, mixed, mixed}> */
    public static function keywords(): iterable
    {
        yield 'type string' => [['type' => 'string'], 'x', 1];
        yield 'type integer' => [['type' => 'integer'], 3, 3.5];
        yield 'type number' => [['type' => 'number'], 3.5, '3.5'];
        yield 'type boolean' => [['type' => 'boolean'], false, 0];
        yield 'type null' => [['type' => 'null'], null, ''];
        yield 'type array' => [['type' => 'array'], [1], ['a' => 1]];
        yield 'type object' => [['type' => 'object'], ['a' => 1], [1, 2]];
        yield 'nullable list' => [['type' => ['string', 'null']], null, 5];
        yield 'enum' => [['enum' => ['a', 'b']], 'b', 'c'];
        yield 'const' => [['const' => 'fixed'], 'fixed', 'other'];
        yield 'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], 4, 4.5];
        yield 'minItems' => [['type' => 'array', 'minItems' => 2], [1, 2], [1]];
        yield 'maxItems' => [['type' => 'array', 'maxItems' => 1], [1], [1, 2]];
        yield 'minLength code points' => [['type' => 'string', 'minLength' => 3], 'नमस', 'ab'];
        yield 'maxLength code points' => [['type' => 'string', 'maxLength' => 3], 'नमस', 'abcd'];
        yield 'pattern' => [['type' => 'string', 'pattern' => '^[a-z]+/[0-9]+$'], 'ab/12', 'AB/12'];
        yield 'minimum' => [['type' => 'number', 'minimum' => 1], 1, 0.5];
        yield 'maximum' => [['type' => 'number', 'maximum' => 1], 1, 1.5];
        yield 'exclusiveMinimum' => [['type' => 'number', 'exclusiveMinimum' => 1], 1.1, 1];
        yield 'exclusiveMaximum' => [['type' => 'number', 'exclusiveMaximum' => 1], 0.9, 1];
        yield 'multipleOf' => [['type' => 'number', 'multipleOf' => 0.5], 2.5, 2.3];
        yield 'items' => [['type' => 'array', 'items' => ['type' => 'integer']], [1, 2], [1, 'x']];
        yield 'additionalProperties schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], ['a' => 1], ['a' => 'x']];
    }

    /**
     * @param array<string, mixed> $property
     */
    #[DataProvider('keywords')]
    public function testT61EachKeyword(array $property, mixed $valid, mixed $invalid): void
    {
        $validator = new Validator();
        $schema = self::one($property);
        $validator->validate($schema, ['v' => $valid]);
        $this->expectException(SchemaViolationException::class);
        $validator->validate($schema, ['v' => $invalid]);
    }

    public function testRefToDefs(): void
    {
        $schema = Schema::fromArray('refs', [
            'type' => 'object',
            '$defs' => ['item' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n'], 'additionalProperties' => false]],
            'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/item']]],
            'required' => ['items'],
            'additionalProperties' => false,
        ]);
        self::assertTrue($schema->isStrictCompatible());
        $validator = new Validator();
        $validator->validate($schema, ['items' => [['n' => 1]]]);
        try {
            $validator->validate($schema, ['items' => [['n' => 1], ['m' => 2]]]);
            self::fail('expected exception');
        } catch (SchemaViolationException $e) {
            self::assertSame('/items/1/n', $e->path());
        }
    }

    public function testT62MissingNestedRequiredKeyNamesFullPath(): void
    {
        $schema = Schema::fromArray('nested', [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false]]],
            'required' => ['items'],
            'additionalProperties' => false,
        ]);
        try {
            (new Validator())->validate($schema, ['items' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c'], []]]);
            self::fail('expected exception');
        } catch (SchemaViolationException $e) {
            self::assertSame('/items/3/title', $e->path());
            self::assertStringContainsString('/items/3/title', $e->getMessage());
        }
    }

    public function testT63WrongTypeEnumAndExtraKey(): void
    {
        $validator = new Validator();
        $schema = $this->article();
        foreach (
            [
            ['title' => 5, 'paragraphs' => [], 'tags' => []],
            ['title' => 't', 'paragraphs' => 'not a list', 'tags' => []],
            ['title' => 't', 'paragraphs' => [], 'tags' => [], 'extra' => true],
            ] as $data
        ) {
            try {
                $validator->validate($schema, $data);
                self::fail('expected exception');
            } catch (SchemaViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        $enum = self::one(['type' => 'string', 'enum' => ['red', 'green']]);
        $this->expectException(SchemaViolationException::class);
        $validator->validate($enum, ['v' => 'blue']);
    }

    public function testT64UnsupportedKeywordIgnoredAndLoggedOnce(): void
    {
        $schema = self::one(['type' => 'string', 'format' => 'date-time']);
        self::assertSame(['format'], $schema->unsupportedKeywords());
        (new Validator())->validate($schema, ['v' => 'not a date']);
        $this->transport->push($this->ok(['v' => 'x']), $this->ok(['v' => 'y']));
        $client = $this->client();
        $client->complete($this->request($schema));
        $client->complete($this->request($schema));
        $lines = array_filter($this->logger->records('debug'), static fn ($r) => str_contains($r['message'], 'ignores'));
        self::assertCount(1, $lines);
        self::assertSame(['format'], array_values($lines)[0]['context']['keywords']);
        self::assertTrue($schema->isStrictCompatible(), 'format is accepted by providers in strict mode');
        $withIf = self::one(['type' => 'string', 'if' => ['type' => 'string']]);
        self::assertStringContainsString('"if"', (string) $withIf->strictIncompatibility());
    }

    public function testT65InvalidPatternIsConfiguration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('/properties/v/pattern');
        self::one(['type' => 'string', 'pattern' => '([a-z']);
    }

    public function testT66RecursiveSchemaFollowsTheDataNotARefLimit(): void
    {
        $schema = Schema::fromArray('tree', [
            'type' => 'object',
            '$defs' => ['node' => ['type' => 'object', 'properties' => ['child' => ['anyOf' => [['$ref' => '#/$defs/node'], ['type' => 'null']]]], 'required' => ['child'], 'additionalProperties' => false]],
            'properties' => ['root' => ['$ref' => '#/$defs/node']],
            'required' => ['root'],
            'additionalProperties' => false,
        ]);
        $build = static function (int $depth, mixed $leaf = null): array {
            $node = ['child' => $leaf];
            for ($i = 0; $i < $depth; $i++) {
                $node = ['child' => $node];
            }

            return ['root' => $node];
        };
        $validator = new Validator();
        // A legitimate tree deeper than 32 levels is valid; the data, not a
        // $ref counter, bounds the recursion (JSON nesting stops at 64).
        $validator->validate($schema, $build(40));
        $this->expectException(SchemaViolationException::class);
        $validator->validate($schema, $build(40, 'leaf'));
    }

    public function testT66RefCycleThrowsAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cycle');
        Schema::fromArray('loop', ['type' => 'object', '$defs' => ['a' => ['$ref' => '#/$defs/b'], 'b' => ['$ref' => '#/$defs/a']], 'properties' => ['v' => ['$ref' => '#/$defs/a']], 'required' => ['v'], 'additionalProperties' => false]);
    }

    public function testT66CycleThroughAnyOfThrowsAtCallTime(): void
    {
        $schema = Schema::fromArray('loop', ['type' => 'object', '$defs' => ['a' => ['anyOf' => [['$ref' => '#/$defs/a']]]], 'properties' => ['v' => ['$ref' => '#/$defs/a']], 'required' => ['v'], 'additionalProperties' => false]);
        $this->transport->push(MockTransport::completion('{"v":1}'));
        $this->expectException(ConfigurationException::class);
        $this->client()->complete($this->request($schema));
    }

    public function testObjectIsNotAcceptedForAnArrayAndViceVersa(): void
    {
        $schema = Schema::fromArray('t', ['type' => 'object', 'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'meta' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        ], 'required' => ['tags', 'meta'], 'additionalProperties' => false]);
        $validator = new Validator();
        $validator->validate($schema, json_decode('{"tags":[],"meta":{}}', false));
        foreach (['{"tags":{"0":"a"},"meta":{}}', '{"tags":{},"meta":{}}', '{"tags":[],"meta":[]}'] as $json) {
            try {
                $validator->validate($schema, json_decode($json, false));
                self::fail('accepted ' . $json);
            } catch (SchemaViolationException) {
                self::addToAssertionCount(1);
            }
        }
        // Through the client: an object where an array belongs is retried.
        $this->transport->push(MockTransport::completion('{"tags":{"0":"a"},"meta":{}}'), MockTransport::completion('{"tags":["a"],"meta":{}}'));
        $result = $this->client()->complete($this->request($schema));
        self::assertSame(['tags' => ['a'], 'meta' => []], $result->data());
        self::assertSame(Outcome::SchemaViolation, $result->attempts()[0]->outcome);
    }

    public function testSchemaKeywordValuesAreChecked(): void
    {
        foreach ([['minimum' => 'x'], ['minLength' => -1], ['maxItems' => 1.5], ['multipleOf' => 0]] as $bad) {
            try {
                Schema::fromArray('t', ['type' => 'object', 'properties' => ['v' => ['type' => 'number'] + $bad]]);
                self::fail('accepted ' . json_encode($bad));
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testT67StrictOutputIsStillValidated(): void
    {
        $this->transport->push(MockTransport::completion('{"title":"T","paragraphs":[]}'), MockTransport::completion('{"title":"T","paragraphs":[]}'));
        try {
            $this->client(Route::to($this->openAi(), 'gpt-test')->withMode(OutputMode::Strict))->complete($this->request());
            self::fail('expected exception');
        } catch (ChainExhaustedException $e) {
            self::assertSame(Outcome::SchemaViolation, $e->attempts()[0]->outcome);
            self::assertSame(OutputMode::Strict, $e->attempts()[0]->mode);
            $error = $e->attempts()[0]->error;
            self::assertInstanceOf(SchemaViolationException::class, $error);
            self::assertSame('/tags', $error->path());
        }
    }
}
