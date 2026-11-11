<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** T123 and T124. */
final class PackagingTest extends TestCase
{
    public function testT123BootstrapAloneRunsTheQuickStart(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/bootstrap.php';
require $argv[1] . '/bootstrap.php';

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Store\FileStore;
use IndianOpinion\LlmJson\Testing\MockTransport;

if (class_exists('Composer\Autoload\ClassLoader', false)) {
    fwrite(STDERR, "composer loaded\n");
    exit(2);
}
$schema = Schema::fromArray('summary', [
    'type' => 'object',
    'properties' => ['title' => ['type' => 'string'], 'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']]],
    'required' => ['title', 'paragraphs'],
    'additionalProperties' => false,
]);
$transport = new MockTransport(MockTransport::status(500), MockTransport::completion(['title' => 'Hello', 'paragraphs' => ['It works. Cut']]));
$client = Client::create(Chain::of(
    Route::to(Provider::openRouter('test-key-a'), 'vendor/model-a'),
    Route::to(Provider::openAi('test-key-b'), 'gpt-test'),
), $transport)
    ->withStore(new FileStore(sys_get_temp_dir() . '/llmjson-bootstrap-' . getmypid(), null, 0))
    ->withSleeper(new IndianOpinion\LlmJson\Testing\RecordingSleeper())
    ->withGuardProfile('summary', GuardProfile::forSchema($schema)->withSentenceEnd(['/paragraphs'], Action::Repair));
$data = $client->ask('summary', $schema, 'Summarise.', 'Text.');
echo json_encode($data);
PHP;
        $file = tempnam(sys_get_temp_dir(), 'llmjson-boot') . '.php';
        file_put_contents($file, $script);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
        unlink($file);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame('{"title":"Hello","paragraphs":["It works."]}', implode('', $out));
    }

    public function testT124NoRuntimeDependencies(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertIsArray($composer);
        foreach (array_keys($composer['require']) as $package) {
            self::assertMatchesRegularExpression('/^(php|ext-[a-z]+)$/', $package);
        }
    }
}
