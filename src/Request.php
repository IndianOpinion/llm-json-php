<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Internal\Text;

/**
 * One call: purpose, instructions, input, schema and per-call overrides.
 * Immutable; every with* method returns a copy.
 */
final class Request
{
    private string $system = '';
    private string $input = '';
    /** @var list<array{role: string, content: string}>|null */
    private ?array $messages = null;
    private ?Chain $chain = null;
    private ?int $tokenCap = null;
    private ?float $temperature = null;
    private ?float $connectTimeout = null;
    private ?float $requestTimeout = null;
    private ?float $deadline = null;
    private ?GuardProfile $guardProfile = null;
    private bool $keepRaw = false;
    private ?bool $strictCredit = null;

    private function __construct(private readonly string $purpose, private readonly Schema $schema)
    {
        if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $purpose) !== 1) {
            throw new ConfigurationException('Purpose must be 1 to 64 letters, digits, underscores, hyphens or dots. It is used in store keys and logs.');
        }
    }

    public static function for(string $purpose, Schema $schema): self
    {
        return new self($purpose, $schema);
    }

    public function withSystem(string $instruction): self
    {
        self::utf8($instruction, 'system');
        $clone = clone $this;
        $clone->system = $instruction;

        return $clone;
    }

    public function withInput(string $input): self
    {
        self::utf8($input, 'input');
        $clone = clone $this;
        $clone->input = $input;

        return $clone;
    }

    /**
     * Send this message list instead of system plus input. Roles are
     * system, user or assistant.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function withMessages(array $messages): self
    {
        if ($messages === []) {
            throw new ConfigurationException('A message list must not be empty.');
        }
        foreach ($messages as $i => $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'developer', 'user', 'assistant'], true) || !is_string($message['content'] ?? null)) {
                throw new ConfigurationException(sprintf('Message %d needs a role (system, developer, user or assistant) and string content.', $i));
            }
            self::utf8($message['content'], sprintf('messages[%d].content', $i));
        }
        $clone = clone $this;
        $clone->messages = array_values(array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']], $messages));

        return $clone;
    }

    /** Replace the client's chain for this call only. */
    public function withChain(Chain $chain): self
    {
        $clone = clone $this;
        $clone->chain = $chain;

        return $clone;
    }

    public function withTokenCap(int $cap): self
    {
        $clone = clone $this;
        $clone->tokenCap = Route::checkTokenCap($cap);

        return $clone;
    }

    public function withTemperature(float $temperature): self
    {
        $clone = clone $this;
        $clone->temperature = Route::checkTemperature($temperature);

        return $clone;
    }

    public function withTimeouts(?float $connectSeconds, ?float $requestSeconds): self
    {
        if (($connectSeconds !== null && $connectSeconds <= 0) || ($requestSeconds !== null && $requestSeconds <= 0)) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }
        $clone = clone $this;
        $clone->connectTimeout = $connectSeconds;
        $clone->requestTimeout = $requestSeconds;

        return $clone;
    }

    /** Total time budget for this call, in seconds. */
    public function withDeadline(float $seconds): self
    {
        if ($seconds <= 0) {
            throw new ConfigurationException('Deadline must be greater than zero.');
        }
        $clone = clone $this;
        $clone->deadline = $seconds;

        return $clone;
    }

    public function withGuardProfile(GuardProfile $profile): self
    {
        $clone = clone $this;
        $clone->guardProfile = $profile;

        return $clone;
    }

    /** Keep the raw content and the pre-repair data on the result. */
    public function withKeepRaw(bool $on = true): self
    {
        $clone = clone $this;
        $clone->keepRaw = $on;

        return $clone;
    }

    /** Throw CreditExhaustedException even when a later provider succeeded. */
    public function withStrictCredit(bool $on = true): self
    {
        $clone = clone $this;
        $clone->strictCredit = $on;

        return $clone;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function system(): string
    {
        return $this->system;
    }

    public function input(): string
    {
        return $this->input;
    }

    /** @return list<array{role: string, content: string}>|null */
    public function messages(): ?array
    {
        return $this->messages;
    }

    public function chain(): ?Chain
    {
        return $this->chain;
    }

    public function tokenCap(): ?int
    {
        return $this->tokenCap;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function connectTimeout(): ?float
    {
        return $this->connectTimeout;
    }

    public function requestTimeout(): ?float
    {
        return $this->requestTimeout;
    }

    public function deadline(): ?float
    {
        return $this->deadline;
    }

    public function guardProfile(): ?GuardProfile
    {
        return $this->guardProfile;
    }

    public function keepRaw(): bool
    {
        return $this->keepRaw;
    }

    public function strictCredit(): ?bool
    {
        return $this->strictCredit;
    }

    /** Throws when there is nothing to send. */
    public function assertHasContent(): void
    {
        if ($this->messages === null && trim($this->system) === '' && trim($this->input) === '') {
            throw new ConfigurationException('The system instruction and the user input are both empty.');
        }
    }

    private static function utf8(string $value, string $field): void
    {
        if (!Text::isUtf8($value)) {
            throw new ConfigurationException(sprintf('The %s is not valid UTF-8.', $field));
        }
    }
}
