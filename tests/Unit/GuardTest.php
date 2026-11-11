<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\GuardRejectedException;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\Guard;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Guard\Rule;
use IndianOpinion\LlmJson\Guard\TextRules;
use IndianOpinion\LlmJson\Guard\Violation;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** PRD 4.8, tests T68 to T84. */
final class GuardTest extends TestCase
{
    private Guard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new Guard();
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function rules(array $data, ?GuardProfile $profile = null): array
    {
        return array_map(static fn (Violation $v): string => $v->rule, $this->guard->run($data, $profile ?? GuardProfile::default())->violations);
    }

    /** @return iterable<string, array{string}> */
    public static function invisibleCharacters(): iterable
    {
        foreach ([0x200B, 0x2060, 0xFEFF, 0x180E, 0x00AD, 0x034F, 0x115F, 0x1160, 0x3164, 0xFFA0, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2066, 0x2067, 0x2068, 0x2069, 0x200E, 0x200F, 0xE0000, 0xE0041, 0xE007F] as $cp) {
            yield sprintf('U+%04X', $cp) => [mb_chr($cp, 'UTF-8')];
        }
    }

    #[DataProvider('invisibleCharacters')]
    public function testT68InvisibleCharactersRejectedEverywhere(string $char): void
    {
        foreach ([$char . 'start', 'mid' . $char . 'dle', 'end' . $char] as $value) {
            self::assertSame([Rule::INVISIBLE_CHARACTERS], $this->rules(['title' => $value]), bin2hex($value));
        }
        self::assertSame([Rule::INVISIBLE_CHARACTERS], $this->rules(['ti' . $char . 'tle' => 'clean']));
        self::assertSame([Rule::INVISIBLE_CHARACTERS], $this->rules(['list' => [['deep' => 'a' . $char]]]));
    }

    public function testT69JoinersAllowedWhereScriptsNeedThem(): void
    {
        $zwnj = "\u{200C}";
        $zwj = "\u{200D}";
        $ok = [
            'क्' . $zwj . 'ष',
            'क्' . $zwnj . 'ष',
            'ന്' . $zwj . 'നു',
            'ല്' . $zwj,
            "\u{1F468}" . $zwj . "\u{1F469}" . $zwj . "\u{1F467}",
            "\u{1F3F3}\u{FE0F}" . $zwj . "\u{1F308}",
            "\u{1F469}\u{1F3FD}" . $zwj . "\u{1F4BB}",
            'می' . $zwnj . 'خواهم',
        ];
        self::assertSame([], $this->rules(['v' => array_slice($ok, 0, 3)]));
        self::assertSame([], $this->rules(['v' => array_slice($ok, 4)]));
        self::assertSame([Rule::INVISIBLE_CHARACTERS], $this->rules(['v' => $ok[3]]), 'a joiner at the end of a value has no right neighbour');
        foreach (['ab' . $zwj . 'cd', 'ab' . $zwnj . 'cd', 'क' . $zwj . 'a', $zwj . 'क'] as $bad) {
            self::assertSame([Rule::INVISIBLE_CHARACTERS], $this->rules(['v' => $bad]), bin2hex($bad));
        }

        $profile = GuardProfile::default()->withInvisibleCharacters(Action::Repair);
        $outcome = $this->guard->run(['v' => 'ab' . $zwj . 'cd' . "\u{200B}" . 'e', 'w' => 'क्' . $zwj . 'ष'], $profile);
        self::assertSame([], $outcome->violations);
        self::assertSame('abcde', $outcome->data['v']);
        self::assertSame('क्' . $zwj . 'ष', $outcome->data['w']);
        self::assertSame(2, $outcome->repairs[0]->charactersRemoved);
        self::assertSame('/v', $outcome->repairs[0]->path);
    }

    public function testT70ControlCharacters(): void
    {
        foreach (["\x00", "\x07", "\x1B", "\x7F", "\u{0085}", "\u{009F}"] as $char) {
            self::assertSame([Rule::CONTROL_CHARACTERS], $this->rules(['v' => 'a' . $char . 'b']), bin2hex($char));
        }
        self::assertSame([], $this->rules(['v' => "line one\nline two\ttabbed\r\n"]));

        $schema = $this->article();
        $profile = GuardProfile::forSchema($schema)->withControlCharacters(Action::Reject, ['/title']);
        self::assertSame([Rule::CONTROL_CHARACTERS], $this->rules(['title' => "Two\nlines", 'paragraphs' => ["ok\nfine"], 'tags' => []], $profile));

        $repair = GuardProfile::forSchema($schema)->withControlCharacters(Action::Repair, ['/title']);
        $outcome = $this->guard->run(['title' => "Two\nlines\x07", 'paragraphs' => ["keep\nthis\x1B"], 'tags' => []], $repair);
        self::assertSame([], $outcome->violations);
        self::assertSame('Two lines', $outcome->data['title']);
        self::assertSame("keep\nthis", $outcome->data['paragraphs'][0]);
        self::assertCount(2, $outcome->repairs);
    }

    public function testT71ReplacementCharacterCannotBeRepaired(): void
    {
        $profile = GuardProfile::default()->withInvisibleCharacters(Action::Repair)->withControlCharacters(Action::Repair);
        self::assertSame([Rule::REPLACEMENT_CHARACTER], $this->rules(['v' => "caf\u{FFFD}"], $profile));
    }

    /** @return iterable<string, array{string}> */
    public static function sentenceEnds(): iterable
    {
        foreach (['.', '!', '?', "\u{2026}", "\u{0964}", "\u{0965}", "\u{3002}", "\u{FF01}", "\u{FF1F}", "\u{061F}"] as $t) {
            yield 'plain ' . bin2hex($t) => ['It ended' . $t];
            yield 'quoted ' . bin2hex($t) => ['He said “it ended' . $t . '”'];
            yield 'bracketed ' . bin2hex($t) => ['(It ended' . $t . ')'];
        }
        yield 'straight quotes' => ['He said "done."'];
        yield 'single quotes' => ["He said 'done.'"];
        yield 'guillemet' => ['«Fini.»'];
        yield 'square bracket' => ['[Done.]'];
        yield 'markup' => ['<p>It ended <em>well</em>.</p>'];
        yield 'entity' => ['It ended&#46;'];
        yield 'trailing space' => ["It ended.  \n"];
        yield 'empty' => [''];
    }

    #[DataProvider('sentenceEnds')]
    public function testT72SentenceEndsPass(string $value): void
    {
        self::assertTrue(TextRules::endsSentence($value));
    }

    public function testT72FragmentsFail(): void
    {
        foreach (['It ended with a word', 'He said "', '<p>Cut off</p>', 'Ends with comma,', 'Price: 5'] as $value) {
            self::assertFalse(TextRules::endsSentence($value), $value);
        }
        $profile = GuardProfile::forSchema($this->article())->withSentenceEnd(['/paragraphs', '/title']);
        $violations = $this->guard->run(['title' => 'No end', 'paragraphs' => ['Fine.', 'Cut off mid'], 'tags' => []], $profile)->violations;
        $paths = array_map(static fn (Violation $v): string => $v->path, $violations);
        sort($paths);
        self::assertSame(['/paragraphs/1', '/title'], $paths);
    }

    public function testT73RepairCutsToLastCompleteSentence(): void
    {
        $abbr = GuardProfile::ENGLISH_ABBREVIATIONS;
        self::assertSame('First sentence. Second one!', TextRules::cutToLastSentence('First sentence. Second one! And then the third', $abbr));
        self::assertSame('She met Dr. Rao today.', TextRules::cutToLastSentence('She met Dr. Rao today. Then Dr. Sen said', $abbr));
        self::assertSame('', TextRules::cutToLastSentence('He moved to the U.S. last year and', $abbr));
        self::assertSame('It began in Jan. and ended.', TextRules::cutToLastSentence('It began in Jan. and ended. Then', $abbr));
        self::assertSame('', TextRules::cutToLastSentence('Nothing complete here', $abbr));
        self::assertSame('बारिश जल्दी आई।', TextRules::cutToLastSentence('बारिश जल्दी आई। किसान खुश', $abbr));
        self::assertSame('He said “yes.”', TextRules::cutToLastSentence('He said “yes.” Then she', $abbr));
        self::assertSame('Ask Prof. X now.', TextRules::cutToLastSentence('Ask Prof. X now. Eh', $abbr));
        self::assertSame('', TextRules::cutToLastSentence('Ask Prof', $abbr));

        $profile = GuardProfile::forSchema($this->article())->withSentenceEnd(['/paragraphs', '/title'], Action::Repair);
        $outcome = $this->guard->run(['title' => 'No sentence here', 'paragraphs' => ['Good one. Cut', 'nothing complete', 'Fine.'], 'tags' => []], $profile);
        self::assertSame([], $outcome->violations);
        self::assertSame('', $outcome->data['title']);
        self::assertSame(['Good one.', 'Fine.'], $outcome->data['paragraphs']);
        $repair = $outcome->repairs[0];
        self::assertSame(Rule::UNFINISHED_PROSE, $repair->rule);
        self::assertSame(3, $repair->wordsBefore);
        self::assertSame(2, $repair->wordsAfter);

        $custom = GuardProfile::forSchema($this->article())->withSentenceEnd(['/title'], Action::Repair)->withAbbreviations(['approx']);
        self::assertSame('It costs approx. five rupees.', $this->guard->run(['title' => 'It costs approx. five rupees. Or', 'paragraphs' => [], 'tags' => []], $custom)->data['title']);
        $replaced = GuardProfile::default()->withAbbreviations(['xyz'], true);
        self::assertSame(['xyz'], $replaced->abbreviations());
    }

    public function testT74ContinuationItems(): void
    {
        $schema = $this->article();
        $reject = GuardProfile::forSchema($schema)->withContinuationItems(['/paragraphs']);
        $data = ['title' => 'T', 'paragraphs' => ['lower first is fine.', 'Upper.', 'the tail of a cut sentence.', 'हिंदी पाठ।'], 'tags' => []];
        $violations = $this->guard->run($data, $reject)->violations;
        self::assertCount(1, $violations);
        self::assertSame('/paragraphs/2', $violations[0]->path);

        $repair = GuardProfile::forSchema($schema)->withContinuationItems(['/paragraphs'], Action::Repair);
        $outcome = $this->guard->run($data, $repair);
        self::assertSame(['lower first is fine.', 'Upper.', 'हिंदी पाठ।'], $outcome->data['paragraphs']);
        self::assertTrue($outcome->repairs[0]->itemRemoved);
    }

    public function testT75WordFloorTriggersOneRegeneration(): void
    {
        $schema = $this->article();
        $profile = GuardProfile::forSchema($schema)->withSentenceEnd(['/paragraphs'], Action::Repair)->withWordFloor('/paragraphs', 8);
        $short = ['title' => 'T', 'paragraphs' => ['Too short. And then the rest of it was cut'], 'tags' => []];
        $better = ['title' => 'T', 'paragraphs' => ['This second answer is complete and long enough to pass.'], 'tags' => []];
        $this->transport->push($this->ok($short), $this->ok($better));
        $result = $this->client()->withGuardProfile('summary', $profile)->complete($this->request());
        self::assertSame($better['paragraphs'], $result->data()['paragraphs']);
        self::assertTrue($result->guardReport()->regenerated);
        self::assertSame('regenerated', $result->guardReport()->kept);
        self::assertSame(['first', 'regeneration'], array_map(static fn ($a) => $a->phase, $result->attempts()));

        $worse = ['title' => 'T', 'paragraphs' => ['Tiny.'], 'tags' => []];
        $this->transport->push($this->ok($short), $this->ok($worse));
        $result = $this->client()->withGuardProfile('summary', $profile)->complete($this->request());
        self::assertSame(['Too short.'], $result->data()['paragraphs']);
        self::assertSame('first', $result->guardReport()->kept);

        $this->transport->push($this->ok($short), \IndianOpinion\LlmJson\Testing\MockTransport::status(500), \IndianOpinion\LlmJson\Testing\MockTransport::status(500));
        $result = $this->client()->withGuardProfile('summary', $profile)->complete($this->request());
        self::assertSame(['Too short.'], $result->data()['paragraphs']);
        self::assertTrue($result->guardReport()->regenerationFailed);
        self::assertSame('first', $result->guardReport()->kept);

        $this->transport->push($this->ok(['title' => 'T', 'paragraphs' => ['Short but whole.'], 'tags' => []]));
        $result = $this->client()->withGuardProfile('summary', $profile)->complete($this->request());
        self::assertFalse($result->guardReport()->regenerated, 'no repair, so no regeneration');
    }

    public function testT76WordCounts(): void
    {
        self::assertSame(6, TextRules::wordCount('The monsoon reached Kerala early, 2025.'));
        self::assertSame(4, TextRules::wordCount('बारिश जल्दी आई है।'));
        self::assertSame(6, TextRules::wordCount('季风提前到达'));
        self::assertSame(4, TextRules::wordCount('東京 is big'));
        self::assertSame(0, TextRules::wordCount(' ... '));
    }

    public function testT77ScriptShare(): void
    {
        $schema = Schema::fromArray('translation', ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string']], 'required' => ['title', 'body'], 'additionalProperties' => false]);
        $profile = GuardProfile::forSchema($schema)->withScriptShare('/body', 'Devanagari', 0.3);
        $hindi = str_repeat('मानसून इस साल केरल में जल्दी पहुंचा और किसान खुश हैं। ', 3) . 'IMD says so.';
        self::assertSame([], $this->rules(['title' => 'शीर्षक', 'body' => $hindi], $profile));
        $english = str_repeat('The monsoon reached Kerala early this year and farmers are happy. ', 3);
        self::assertSame([Rule::SCRIPT_SHARE], $this->rules(['title' => 'मानसून', 'body' => $english], $profile));
        self::assertSame([], $this->rules(['title' => 'मानसून', 'body' => 'Too short to judge.'], $profile));
        $this->expectException(ConfigurationException::class);
        GuardProfile::forSchema($schema)->withScriptShare('/body', 'Klingon', 0.3);
    }

    public function testT78MixedScriptWords(): void
    {
        $homoglyph = "The m\u{043E}nsoon arrived.";
        self::assertSame([], $this->rules(['v' => $homoglyph]));
        self::assertSame([Rule::MIXED_SCRIPT_WORDS], $this->rules(['v' => $homoglyph], GuardProfile::default()->withMixedScriptWords()));
        self::assertSame([], $this->rules(['v' => 'Москва and London are cities.'], GuardProfile::default()->withMixedScriptWords()));
    }

    public function testT79CustomRules(): void
    {
        $profile = GuardProfile::default()->withCustomRule('no-lorem', static function (array $data): iterable {
            if (str_contains((string) ($data['title'] ?? ''), 'Lorem')) {
                yield ['/title', 'placeholder text'];
            }
        });
        self::assertSame(['G12:no-lorem'], $this->rules(['title' => 'Lorem ipsum.'], $profile));
        self::assertSame([], $this->rules(['title' => 'Real.'], $profile));

        $broken = GuardProfile::default()->withCustomRule('broken', static function (array $data): array {
            throw new \LogicException('bug in caller code');
        });
        $this->transport->push($this->ok(), $this->ok());
        try {
            $this->client()->complete($this->request()->withGuardProfile($broken));
            self::fail('expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('broken', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
        self::assertCount(1, $this->transport->requests(), 'not retried as a model failure');
    }

    public function testT80GuardRejectionIsRetriedAndCounts(): void
    {
        $route = Route::to($this->openAi(), 'gpt-test');
        $bad = $this->ok(['title' => "Bad\u{200B}", 'paragraphs' => [], 'tags' => []]);
        $this->transport->push($bad, $this->ok());
        $result = $this->client($route)->complete($this->request());
        self::assertSame([Outcome::GuardRejected, Outcome::Success], array_map(static fn ($a) => $a->outcome, $result->attempts()));
        self::assertSame(Rule::INVISIBLE_CHARACTERS, $result->guardReport()->earlierRejections[0]->rule);
        $error = $result->attempts()[0]->error;
        self::assertInstanceOf(GuardRejectedException::class, $error);
        self::assertSame('/title', $error->path());
        self::assertStringNotContainsString('Bad', $error->getMessage());

        $this->transport->push($bad, $bad);
        try {
            $this->client($route)->complete($this->request());
        } catch (ChainExhaustedException) {
        }
        self::assertSame(1, $this->client($route)->status()->routes[0]->failureCount);
    }

    public function testT81RepairReportAndKeepRaw(): void
    {
        $profile = GuardProfile::forSchema($this->article())->withInvisibleCharacters(Action::Repair)->withSentenceEnd(['/paragraphs'], Action::Repair);
        $data = ['title' => "Title\u{200B}", 'paragraphs' => ['Whole. Cut'], 'tags' => []];
        $this->transport->push($this->ok($data), $this->ok($data));
        $client = $this->client()->withGuardProfile('summary', $profile);
        $result = $client->complete($this->request());
        $report = $result->guardReport();
        self::assertTrue($report->repaired());
        self::assertSame([[Rule::INVISIBLE_CHARACTERS, '/title', 1], [Rule::UNFINISHED_PROSE, '/paragraphs/0', null]], array_map(static fn ($r) => [$r->rule, $r->path, $r->charactersRemoved], $report->repairs));
        self::assertSame(2, $report->repairs[1]->wordsBefore);
        self::assertSame(1, $report->repairs[1]->wordsAfter);
        self::assertSame('Title', $result->data()['title']);
        self::assertNull($result->originalData());
        self::assertNull($result->raw());

        $result = $client->complete($this->request()->withKeepRaw());
        self::assertSame($data, $result->originalData());
        self::assertSame(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result->raw());
    }

    public function testT82DefaultProfileIsG1ToG5Only(): void
    {
        $this->transport->push($this->ok(['title' => 'no end', 'paragraphs' => ['ends badly', 'lower case start'], 'tags' => []]));
        $result = $this->client()->complete($this->request());
        self::assertSame([Rule::TRUNCATED_JSON, Rule::REQUIRED_FIELDS, Rule::INVISIBLE_CHARACTERS, Rule::CONTROL_CHARACTERS, Rule::REPLACEMENT_CHARACTER], $result->guardReport()->rulesRun);
        self::assertSame([], $result->guardReport()->repairs);
        $default = GuardProfile::default();
        self::assertSame(Action::Reject, $default->invisibleAction());
        self::assertSame(Action::Reject, $default->controlAction());
        self::assertSame([], $default->paths());
        self::assertFalse($default->mixedScriptWords());
        self::assertFalse($default->quoteInstruction());
    }

    public function testT83CorruptionFixture(): void
    {
        $paragraph = "The state government announced a new irrigation sch\u{200B}eme on Tuesday, promising water to forty thousand farm\u{00AD}ers before the next sowing season.";
        $violations = $this->guard->run(['title' => 'Irrigation', 'paragraphs' => [$paragraph], 'tags' => []], GuardProfile::default())->violations;
        self::assertCount(1, $violations);
        self::assertSame(Rule::INVISIBLE_CHARACTERS, $violations[0]->rule);
        self::assertSame('/paragraphs/0', $violations[0]->path);
        self::assertStringContainsString('2 invisible', $violations[0]->message);
    }

    public function testT84BrokenQuoteFixture(): void
    {
        // What a straight double quote does under a JSON grammar: the string
        // closes at the quote and the rest spills into a new item.
        $data = [
            'title' => 'Minister on the new dam',
            'paragraphs' => [
                'The minister visited the site on Monday. Speaking to reporters, she said ',
                'this is a historic day for the farmers of the district.',
                'Work on the dam will begin in March.',
            ],
            'tags' => ['dam'],
        ];
        $profile = GuardProfile::forSchema($this->article())
            ->withSentenceEnd(['/paragraphs'], Action::Repair)
            ->withContinuationItems(['/paragraphs'], Action::Repair)
            ->withQuoteInstruction();
        $outcome = $this->guard->run($data, $profile);
        self::assertSame([], $outcome->violations);
        self::assertSame(['The minister visited the site on Monday.', 'Work on the dam will begin in March.'], $outcome->data['paragraphs']);
        foreach ($outcome->data['paragraphs'] as $p) {
            self::assertTrue(TextRules::endsSentence($p));
        }
    }
}
