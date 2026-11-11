<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Clock\Clock;
use IndianOpinion\LlmJson\Clock\SystemClock;
use IndianOpinion\LlmJson\Exception\AttemptException;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\GuardRejectedException;
use IndianOpinion\LlmJson\Exception\MalformedOutputException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Guard\Guard;
use IndianOpinion\LlmJson\Guard\GuardOutcome;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Guard\GuardReport;
use IndianOpinion\LlmJson\Guard\Rule;
use IndianOpinion\LlmJson\Guard\Violation;
use IndianOpinion\LlmJson\Internal\BodyBuilder;
use IndianOpinion\LlmJson\Internal\CallState;
use IndianOpinion\LlmJson\Internal\Classified;
use IndianOpinion\LlmJson\Internal\DeadlineReached;
use IndianOpinion\LlmJson\Internal\DecodeFailure;
use IndianOpinion\LlmJson\Internal\Json;
use IndianOpinion\LlmJson\Internal\Keys;
use IndianOpinion\LlmJson\Internal\ProcessMemory;
use IndianOpinion\LlmJson\Internal\Redactor;
use IndianOpinion\LlmJson\Internal\ResponseReader;
use IndianOpinion\LlmJson\Internal\Success;
use IndianOpinion\LlmJson\Internal\Text;
use IndianOpinion\LlmJson\Internal\Backoff;
use IndianOpinion\LlmJson\Log\Logger;
use IndianOpinion\LlmJson\Log\NullLogger;
use IndianOpinion\LlmJson\Log\Psr3Logger;
use IndianOpinion\LlmJson\Sleeper\RealSleeper;
use IndianOpinion\LlmJson\Sleeper\Sleeper;
use IndianOpinion\LlmJson\Store\MemoryStore;
use IndianOpinion\LlmJson\Store\Store;
use IndianOpinion\LlmJson\Transport\HttpRequest;
use IndianOpinion\LlmJson\Transport\Transport;
use IndianOpinion\LlmJson\Transport\TransportFactory;
use IndianOpinion\LlmJson\Validation\Validator;

/**
 * The entry point. One call in, a validated PHP array out, or a typed
 * exception that says what went wrong.
 *
 * Immutable: every with* method returns a copy. Copies share process-level
 * memory (learned downgrades, log throttles).
 */
final class Client
{
    public const VERSION = '0.1.0';

    private ?Chain $chain;
    private Transport $transport;
    private Store $store;
    private Logger $logger;
    private Clock $clock;
    private Sleeper $sleeper;
    private Options $options;
    private PriceTable $prices;
    /** @var array<string, GuardProfile> */
    private array $profiles = [];
    /** @var array<string, list<callable(object): mixed>> */
    private array $listeners = [];
    private ProcessMemory $memory;
    private bool $defaultStore = true;
    private Validator $validator;
    private Guard $guard;
    private ResponseReader $reader;
    private BodyBuilder $builder;

    private function __construct(?Chain $chain, Transport $transport)
    {
        $this->chain = $chain;
        $this->transport = $transport;
        $this->clock = new SystemClock();
        $this->store = new MemoryStore($this->clock);
        $this->logger = new NullLogger();
        $this->sleeper = new RealSleeper();
        $this->options = Options::defaults();
        $this->prices = new PriceTable();
        $this->memory = new ProcessMemory();
        $this->validator = new Validator();
        $this->guard = new Guard();
        $this->reader = new ResponseReader();
        $this->builder = new BodyBuilder();
    }

    /**
     * Without a transport, curl is used, or PHP streams when curl is
     * missing. Inside WordPress pass new WordPressTransport().
     *
     * @throws ConfigurationException when no transport is available
     */
    public static function create(?Chain $chain = null, ?Transport $transport = null): self
    {
        return new self($chain, $transport ?? TransportFactory::default());
    }

    public function withChain(Chain $chain): self
    {
        $clone = clone $this;
        $clone->chain = $chain;

        return $clone;
    }

    public function withTransport(Transport $transport): self
    {
        $clone = clone $this;
        $clone->transport = $transport;

        return $clone;
    }

    public function withStore(Store $store): self
    {
        $clone = clone $this;
        $clone->store = $store;
        $clone->defaultStore = false;

        return $clone;
    }

    /**
     * Accepts the library's Logger, any PSR-3 logger (when psr/log is
     * installed), or null for no logging.
     */
    public function withLogger(?object $logger): self
    {
        $clone = clone $this;
        if ($logger === null) {
            $clone->logger = new NullLogger();
        } elseif ($logger instanceof Logger) {
            $clone->logger = $logger;
        } elseif (Psr3Logger::accepts($logger)) {
            $clone->logger = new Psr3Logger($logger);
        } else {
            throw new ConfigurationException(sprintf('Logger must implement %s or Psr\\Log\\LoggerInterface, got %s.', Logger::class, $logger::class));
        }

        return $clone;
    }

    /**
     * The clock for deadlines and durations. When the client still has its
     * default memory store, that store is rebuilt on the new clock.
     */
    public function withClock(Clock $clock): self
    {
        $clone = clone $this;
        $clone->clock = $clock;
        if ($this->defaultStore) {
            $clone->store = new MemoryStore($clock);
        }

        return $clone;
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        $clone = clone $this;
        $clone->sleeper = $sleeper;

        return $clone;
    }

    public function withOptions(Options $options): self
    {
        $clone = clone $this;
        $clone->options = $options;

        return $clone;
    }

    public function withPriceTable(PriceTable $prices): self
    {
        $clone = clone $this;
        $clone->prices = $prices;

        return $clone;
    }

    public function withGuardProfile(string $purpose, GuardProfile $profile): self
    {
        if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $purpose) !== 1) {
            throw new ConfigurationException('Purpose must be 1 to 64 letters, digits, underscores, hyphens or dots.');
        }
        $clone = clone $this;
        $clone->profiles[$purpose] = $profile;

        return $clone;
    }

    /**
     * Add a listener. See Event for names and payload types. A listener
     * that throws is logged and recorded on the result; the call goes on.
     *
     * @param callable(object): mixed $listener
     */
    public function on(string $event, callable $listener): self
    {
        if (!in_array($event, Event::ALL, true)) {
            throw new ConfigurationException(sprintf('Unknown event "%s".', $event));
        }
        $clone = clone $this;
        $clone->listeners[$event][] = $listener;

        return $clone;
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * Shortcut: the data only.
     *
     * @return array<mixed>
     */
    public function ask(string $purpose, Schema $schema, string $system, string $input): array
    {
        return $this->complete(Request::for($purpose, $schema)->withSystem($system)->withInput($input))->data();
    }

    /**
     * @throws ConfigurationException  on a setup mistake, before any request where possible
     * @throws CreditExhaustedException when a provider ran out of credit and nothing succeeded, or always in strict credit mode
     * @throws ChainExhaustedException  when every route failed or the deadline ran out
     */
    public function complete(Request $request): Result
    {
        $start = $this->clock->now();
        $request->assertHasContent();
        $chain = $request->chain() ?? $this->chain;
        if ($chain === null || count($chain) === 0) {
            throw new ConfigurationException('No chain: give the client a chain or the request a chain override.');
        }
        $schema = $request->schema();
        $profile = $request->guardProfile() ?? $this->profiles[$request->purpose()] ?? GuardProfile::default();
        $profile->assertCompatibleWith($schema);
        foreach ($chain->routes() as $route) {
            if ($route->mode() === OutputMode::Strict && ($problem = $schema->strictIncompatibility()) !== null) {
                throw new ConfigurationException(sprintf('Route %s is set to strict mode but schema "%s" is not strict-compatible at %s.', $route->label(), $schema->name(), $problem));
            }
        }
        if ($schema->unsupportedKeywords() !== [] && !isset($this->memory->loggedSchemas[$schema->fingerprint()])) {
            $this->memory->loggedSchemas[$schema->fingerprint()] = true;
            $this->logger->log(Logger::DEBUG, 'Schema has keywords the validator ignores.', ['purpose' => $request->purpose(), 'schema' => $schema->name(), 'keywords' => $schema->unsupportedKeywords()]);
        }

        $state = new CallState($request->purpose(), $start, $request->deadline() ?? $this->options->deadline());
        $success = $this->runChainSafely($request, $chain, $profile, $state);
        if ($success === null) {
            $this->fail($state);
        }

        $kept = $success;
        $regenerated = false;
        $regenerationFailed = false;
        $keptLabel = null;
        if ($success->guard->floorShortfall) {
            $regenerated = true;
            $state->phase = 'regeneration';
            $this->logger->log(Logger::WARNING, 'Guard repair left a field under its word floor; regenerating once.', $this->context($state, $success->route, $success->attempt));
            $second = $this->runChainSafely($request, $chain, $profile, $state);
            if ($second === null) {
                $regenerationFailed = true;
                $keptLabel = 'first';
            } elseif ($second->guard->floorWords > $success->guard->floorWords) {
                $kept = $second;
                $keptLabel = 'regenerated';
            } else {
                $keptLabel = 'first';
            }
        }

        foreach ($kept->guard->repairs as $repair) {
            $this->emit(Event::GUARD_REPAIRED, $repair, $state);
            $this->logger->log(Logger::WARNING, 'Guard repaired the output.', $this->context($state, $kept->route, $kept->attempt) + ['rule' => $repair->rule, 'path' => $repair->path]);
        }

        $keep = $request->keepRaw();
        $result = new Result(
            $kept->guard->data,
            $request->purpose(),
            $kept->route->provider()->name(),
            $kept->route->model(),
            $kept->attempt->reportedModel,
            $kept->mode,
            $kept->modeReason,
            $state->attempts,
            $state->skips,
            $kept->attempt->usage,
            $this->totalUsage($state->attempts),
            new GuardReport($kept->guard->rulesRun, $kept->guard->repairs, $state->rejections, $regenerated, $keptLabel, $regenerationFailed),
            $state->credit,
            $state->storeErrors,
            $state->listenerErrors,
            $this->elapsedMs($state),
            $keep ? $kept->content : null,
            $keep ? $kept->parsed : null,
            $kept->attempt->fenceUnwrapped,
        );

        if ($state->credit !== [] && ($request->strictCredit() ?? $this->options->strictCredit())) {
            throw new CreditExhaustedException(
                sprintf('A provider ran out of credit for purpose "%s"; a later route answered (strict credit mode).', $request->purpose()),
                ChainExhaustedException::REASON_FAILURES,
                $state->attempts,
                $state->skips,
                null,
                $state->credit,
                $result,
            );
        }

        return $result;
    }

    /**
     * Failure counts, cooldowns, holds and today's chain failures.
     */
    public function status(?Chain $chain = null): Status
    {
        $chain ??= $this->chain;
        $keys = $this->keys();
        $errors = [];
        $routes = [];
        foreach ($chain?->routes() ?? [] as $route) {
            $routes[] = new RouteStatus(
                $route->provider()->name(),
                $route->model(),
                $this->storeRead($keys->failures($route), $errors) ?? 0,
                $this->storeRead($keys->cooldown($route), $errors),
                $this->storeRead($keys->hold($route->provider()), $errors),
                $this->storeRead($keys->downgrade($route), $errors) !== null,
            );
        }

        return new Status($routes, $this->storeRead($keys->day($this->clock->now()), $errors) ?? 0, $errors);
    }

    /** Clear the cooldown and failure count of one route. */
    public function resetCooldown(Route $route): void
    {
        $keys = $this->keys();
        $this->store->delete($keys->cooldown($route));
        $this->store->delete($keys->failures($route));
    }

    /** Clear cooldowns, counters and holds of every route in the chain. */
    public function resetCooldowns(?Chain $chain = null): void
    {
        foreach (($chain ?? $this->chain)?->routes() ?? [] as $route) {
            $this->resetCooldown($route);
            $this->store->delete($this->keys()->hold($route->provider()));
        }
    }

    private function runChainSafely(Request $request, Chain $chain, GuardProfile $profile, CallState $state): ?Success
    {
        try {
            return $this->runChain($request, $chain, $profile, $state);
        } catch (DeadlineReached) {
            $state->deadlineHit = true;

            return null;
        }
    }

    private function runChain(Request $request, Chain $chain, GuardProfile $profile, CallState $state): ?Success
    {
        $routes = $chain->routes();
        $last = count($routes) - 1;
        $reliable = $this->options->reliability();
        $keys = $this->keys();
        $previousFailed = false;

        foreach ($routes as $index => $route) {
            $provider = $route->provider();
            if (isset($state->accountFailed[$provider->id()])) {
                $this->skip($state, new Skip($index, $provider->name(), $route->model(), Skip::ACCOUNT));
                continue;
            }
            $override = false;
            if ($reliable) {
                $hold = $this->storeGet($keys->hold($provider), $state);
                if ($hold !== null) {
                    if ($index < $last) {
                        $this->skip($state, new Skip($index, $provider->name(), $route->model(), Skip::HOLD, (float) $hold));
                        continue;
                    }
                    $override = true;
                }
                $cooldown = $this->storeGet($keys->cooldown($route), $state);
                if ($cooldown !== null) {
                    if ($index < $last) {
                        $this->skip($state, new Skip($index, $provider->name(), $route->model(), Skip::COOLDOWN, (float) $cooldown));
                        continue;
                    }
                    $override = true;
                }
            }
            if ($previousFailed) {
                $this->logger->log(Logger::INFO, 'Falling back to a later route.', ['purpose' => $state->purpose, 'provider' => $provider->name(), 'model' => $route->model(), 'route' => $index]);
            }

            [$mode, $reason] = $this->resolveMode($route, $request->schema(), $state);
            $max = $reliable ? ($route->attempts() ?? $this->options->attemptsPerRoute()) : 1;
            $tries = 0;
            $downgraded = false;
            $final = null;
            $state->routesTried++;

            while (true) {
                $this->ensureTime($state, 0.0);
                [$attempt, $success, $classified] = $this->attempt($route, $index, $mode, $reason, $request, $profile, $state, $override);
                if ($success !== null) {
                    if ($reliable) {
                        $this->storeDelete($keys->failures($route), $state);
                        if ($override) {
                            $this->storeDelete($keys->cooldown($route), $state);
                            $this->storeDelete($keys->hold($provider), $state);
                        }
                    }

                    return $success;
                }
                $final = $attempt;
                $outcome = $attempt->outcome;
                if ($outcome === Outcome::CreditExhausted) {
                    $this->creditExhausted($route, $attempt, $state);
                }
                if ($classified !== null && $classified->formatRejected && $route->mode() === OutputMode::Auto && $mode === OutputMode::Strict && !$downgraded) {
                    $downgraded = true;
                    $mode = OutputMode::Json;
                    $reason = 'auto: the provider rejected the strict response format, so JSON mode is used (remembered)';
                    $this->rememberDowngrade($route, $state);
                    $this->logger->log(Logger::WARNING, 'Provider rejected strict structured output; retrying in JSON mode.', $this->context($state, $route, $attempt));
                    continue;
                }
                if ($outcome->isAccountLevel()) {
                    $state->accountFailed[$provider->id()] = true;
                    break;
                }
                if (!$outcome->isRetryable()) {
                    break;
                }
                $tries++;
                if ($tries >= $max) {
                    break;
                }
                $cap = $this->options->retryMaxDelay();
                $retryAfter = $attempt->retryAfter;
                if ($retryAfter !== null && in_array($outcome, [Outcome::RateLimited, Outcome::ServerError], true)) {
                    if ($retryAfter > $cap) {
                        break;
                    }
                    $delay = Backoff::fromRetryAfter($retryAfter, $cap);
                } else {
                    $delay = Backoff::delay($tries, $this->options->retryBaseDelay(), $cap);
                }
                if (!$this->hasTime($state, $delay)) {
                    // Not enough time to wait and still make a useful attempt
                    // here; the next route needs no wait, so try it instead.
                    // If nothing later answers, the deadline is the reason.
                    $state->deadlineHit = true;
                    break;
                }
                $this->emit(Event::RETRY_SCHEDULED, new RetryScheduled($provider->name(), $route->model(), $tries, $delay, $outcome), $state);
                $this->logger->log(Logger::WARNING, 'Retrying the same route.', $this->context($state, $route, $attempt) + ['delay' => round($delay, 3)]);
                $this->sleeper->sleep($delay);
            }

            $previousFailed = true;
            if ($final !== null && $final->outcome === Outcome::Refusal) {
                $state->routesRefused++;
            }
            if ($reliable && $final !== null && $final->outcome->countsTowardsCooldown()) {
                $this->recordFailure($route, $state);
            }
        }

        return null;
    }

    /**
     * @return array{0: Attempt, 1: ?Success, 2: ?Classified}
     */
    private function attempt(Route $route, int $index, OutputMode $mode, string $modeReason, Request $request, GuardProfile $profile, CallState $state, bool $override): array
    {
        $remaining = $state->deadline - ($this->clock->now() - $state->start);
        $connect = min($request->connectTimeout() ?? $route->connectTimeout() ?? $this->options->connectTimeout(), $remaining);
        $timeout = min($request->requestTimeout() ?? $route->requestTimeout() ?? $this->options->requestTimeout(), $remaining);
        $body = $this->builder->body($route, $mode, $request, $profile, $this->options);
        $http = new HttpRequest('POST', $route->provider()->endpoint(), $this->builder->headers($route), Json::encode($body), $connect, $timeout, $this->options->maxResponseBytes());

        $started = $this->clock->now();
        $response = null;
        $classified = null;
        $content = null;
        $parsed = null;
        $guardOutcome = null;
        $fence = false;
        try {
            $response = $this->transport->send($http);
            $classified = $this->reader->read($response, $route->provider(), $this->clock->now(), $this->apiKeyOf($route));
            $outcome = $classified->outcome;
            $error = $classified->error;
            if ($outcome === Outcome::Success && $classified->content !== null) {
                [$outcome, $error, $parsed, $guardOutcome, $fence, $content] = $this->process($classified->content, $request->schema(), $profile, $state, $route);
            }
        } catch (TransportException $e) {
            $outcome = $e->isBodyTooLarge() ? Outcome::InvalidResponse : ($e->isTlsVerificationFailure() ? Outcome::TlsVerificationFailed : Outcome::TransportError);
            $error = $e;
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $outcome = Outcome::TransportError;
            $error = new TransportException(sprintf('Transport threw %s: %s', $e::class, Redactor::excerpt($e->getMessage(), 200, [$this->apiKeyOf($route)])), previous: $e);
        }

        $usage = $classified !== null ? $classified->usage : new Usage();
        $usage = $this->price($route, $classified?->reportedModel, $usage);
        $attempt = new Attempt(
            count($state->attempts) + 1,
            $index,
            $route->provider()->name(),
            $route->model(),
            $mode,
            $outcome,
            $response?->status,
            (int) round(($this->clock->now() - $started) * 1000),
            $usage,
            $classified?->responseId,
            $classified?->requestId,
            $classified?->reportedModel,
            $classified?->errorExcerpt,
            $error,
            $classified?->retryAfter,
            $fence,
            $state->phase,
            $override,
        );
        $state->attempts[] = $attempt;
        if ($error !== null) {
            $state->lastError = $error;
        }

        $context = $this->context($state, $route, $attempt);
        if ($outcome !== Outcome::Success && $content !== null && $this->options->excerptLogging()) {
            $context['excerpt'] = Text::cut($content, 200);
        }
        if ($error !== null) {
            $context['error'] = $error->getMessage();
        }
        $this->logger->log(Logger::DEBUG, 'Attempt finished.', $context);
        $this->emit(Event::ATTEMPT_FINISHED, $attempt, $state);

        if ($outcome === Outcome::Success && $guardOutcome !== null && $parsed !== null && $content !== null) {
            return [$attempt, new Success($route, $attempt, $mode, $modeReason, $guardOutcome, $parsed, $content), $classified];
        }

        return [$attempt, null, $classified];
    }

    /**
     * Unwrap, decode, validate and guard one content string.
     *
     * @return array{0: Outcome, 1: ?AttemptException, 2: ?array<mixed>, 3: ?GuardOutcome, 4: bool, 5: string}
     */
    private function process(string $content, Schema $schema, GuardProfile $profile, CallState $state, Route $route): array
    {
        $fence = false;
        if (preg_match('/\A```[A-Za-z0-9_\-]*[ \t]*\r?\n(.*?)\r?\n?```\z/s', $content, $m) === 1) {
            $content = trim($m[1]);
            $fence = true;
        }
        // Decoded twice: with objects, so the validator can tell {} from []
        // and an object from an array exactly, then as arrays for the caller.
        $tree = Json::decode($content, Json::MAX_DEPTH, false);
        if ($tree instanceof DecodeFailure) {
            return [Outcome::Malformed, new MalformedOutputException('Output is not valid JSON (or nests deeper than 64 levels).', Text::cut($content, 200)), null, null, $fence, $content];
        }
        if (!$tree instanceof \stdClass) {
            return [Outcome::SchemaViolation, new SchemaViolationException('Schema violation at /: output is not a JSON object.', ''), null, null, $fence, $content];
        }
        try {
            $this->validator->validate($schema, $tree);
        } catch (SchemaViolationException $e) {
            return [Outcome::SchemaViolation, $e, null, null, $fence, $content];
        }
        $decoded = Json::decode($content, Json::MAX_DEPTH);
        if (!is_array($decoded)) {
            return [Outcome::Malformed, new MalformedOutputException('Output could not be decoded.', Text::cut($content, 200)), null, null, $fence, $content];
        }
        $guarded = $this->guard->run($decoded, $profile);
        $violations = $guarded->violations;
        if ($violations === [] && $guarded->repairs !== []) {
            // A repair must never hand back data outside the schema (an
            // emptied required string under minLength, a list cut under
            // minItems). Such a repair is a rejection.
            try {
                $this->validator->validate($schema, $guarded->data);
            } catch (SchemaViolationException $e) {
                $violations = [new Violation(Rule::REQUIRED_FIELDS, $e->path(), 'the repaired output no longer matches the schema: ' . $e->getMessage())];
            }
        }
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $state->rejections[] = $violation;
                $this->emit(Event::GUARD_REJECTED, $violation, $state);
            }

            return [Outcome::GuardRejected, new GuardRejectedException($violations), null, null, $fence, $content];
        }

        return [Outcome::Success, null, $decoded, $guarded, $fence, $content];
    }

    /**
     * Sum of every attempt. When some billed attempt (a 2xx answer) has no
     * known cost, the total cost is marked partial: it is a lower bound.
     *
     * @param list<Attempt> $attempts
     */
    private function totalUsage(array $attempts): Usage
    {
        $total = Usage::sum(array_map(static fn (Attempt $a): Usage => $a->usage, $attempts));
        if ($total->cost === null) {
            return $total;
        }
        foreach ($attempts as $attempt) {
            $status = $attempt->httpStatus ?? 0;
            if ($status >= 200 && $status < 300 && $attempt->usage->cost === null) {
                return $total->withCost($total->cost, CostSource::Partial);
            }
        }

        return $total;
    }

    private function price(Route $route, ?string $reportedModel, Usage $usage): Usage
    {
        if ($usage->cost !== null) {
            return $usage->withCost($usage->cost, CostSource::Reported);
        }
        $cost = $this->prices->cost($route->model(), $usage);
        if ($cost === null && $reportedModel !== null && !$this->prices->has($route->model())) {
            $cost = $this->prices->cost($reportedModel, $usage);
        }

        return $cost === null ? $usage->withCost(null, CostSource::Unknown) : $usage->withCost($cost, CostSource::PriceTable);
    }

    /**
     * @return array{0: OutputMode, 1: string}
     */
    private function resolveMode(Route $route, Schema $schema, CallState $state): array
    {
        if ($route->mode() !== OutputMode::Auto) {
            return [$route->mode(), 'set on the route'];
        }
        $provider = $route->provider();
        if ($provider->supportsStrict()) {
            $problem = $schema->strictIncompatibility();
            if ($problem !== null) {
                return $provider->supportsJsonObject()
                    ? [OutputMode::Json, 'auto: schema is not strict-compatible at ' . $problem]
                    : [OutputMode::Prompt, 'auto: schema is not strict-compatible at ' . $problem . ', and the provider has no JSON mode'];
            }
            if ($this->isDowngraded($route, $state)) {
                return [OutputMode::Json, 'auto: the provider rejected strict mode earlier (remembered)'];
            }

            return [OutputMode::Strict, 'auto: the provider supports strict structured outputs'];
        }
        if ($provider->supportsJsonObject()) {
            return [OutputMode::Json, 'auto: the provider supports JSON mode but not strict'];
        }

        return [OutputMode::Prompt, 'auto: the provider has no response format support'];
    }

    private function isDowngraded(Route $route, CallState $state): bool
    {
        $now = $this->clock->now();
        $until = $this->memory->downgrades[$route->id()] ?? null;
        if ($until !== null && $until > $now) {
            return true;
        }
        if (!$this->options->reliability()) {
            return false;
        }
        $stored = $this->storeGet($this->keys()->downgrade($route), $state);
        if ($stored !== null) {
            $this->memory->downgrades[$route->id()] = (float) $stored;

            return true;
        }

        return false;
    }

    private function rememberDowngrade(Route $route, CallState $state): void
    {
        $seconds = $this->options->downgradeSeconds();
        $until = $this->clock->now() + $seconds;
        $this->memory->downgrades[$route->id()] = $until;
        if ($this->options->reliability()) {
            $this->storeSet($this->keys()->downgrade($route), (int) ceil($until), $seconds, $state);
        }
    }

    private function creditExhausted(Route $route, Attempt $attempt, CallState $state): void
    {
        $provider = $route->provider();
        $info = new CreditExhaustion($provider->name(), $route->model(), $attempt->httpStatus, $attempt->errorExcerpt ?? '');
        $state->credit[] = $info;
        $this->emit(Event::CREDIT_EXHAUSTED, $info, $state);
        $reliable = $this->options->reliability();
        $hold = $this->options->creditHoldSeconds();
        if ($reliable && $hold > 0) {
            $this->storeSet($this->keys()->hold($provider), (int) ceil($this->clock->now() + $hold), $hold, $state);
        }
        $throttle = $this->options->logThrottleSeconds();
        $shouldLog = $reliable
            ? ($this->storeAdd($this->keys()->creditLog($provider), 1, $throttle, $state) ?? $this->throttledInProcess('credit.' . $provider->id(), $throttle))
            : $this->throttledInProcess('credit.' . $provider->id(), $throttle);
        if ($shouldLog) {
            $this->logger->log(Logger::CRITICAL, 'Provider credit exhausted. Top up the account; later routes are being used.', $this->context($state, $route, $attempt));
        }
    }

    private function recordFailure(Route $route, CallState $state): void
    {
        $keys = $this->keys();
        $count = $this->storeIncrement($keys->failures($route), $this->options->failureWindow(), $state);
        if ($count === null || $count < $this->options->cooldownThreshold()) {
            return;
        }
        $seconds = $this->options->cooldownSeconds();
        $until = (int) ceil($this->clock->now() + $seconds);
        $started = $this->storeAdd($keys->cooldown($route), $until, $seconds, $state);
        $this->storeDelete($keys->failures($route), $state);
        if ($started === true) {
            $this->logger->log(Logger::WARNING, 'Route put on cooldown after repeated failures.', ['purpose' => $state->purpose, 'provider' => $route->provider()->name(), 'model' => $route->model(), 'until' => $until, 'failures' => $count]);
            $this->emit(Event::COOLDOWN_STARTED, new CooldownStarted($route->provider()->name(), $route->model(), (float) $until), $state);
        }
    }

    private function fail(CallState $state): never
    {
        if ($this->options->reliability()) {
            $this->storeIncrement($this->keys()->day($this->clock->now()), 8 * 86400, $state);
        }
        $reason = $state->deadlineHit
            ? ChainExhaustedException::REASON_DEADLINE
            : ($state->routesTried > 0 && $state->routesTried === $state->routesRefused ? ChainExhaustedException::REASON_ALL_REFUSED : ChainExhaustedException::REASON_FAILURES);
        $message = match ($reason) {
            ChainExhaustedException::REASON_DEADLINE => sprintf('Deadline reached for purpose "%s" after %d attempt(s).', $state->purpose, count($state->attempts)),
            ChainExhaustedException::REASON_ALL_REFUSED => sprintf('Every route refused for purpose "%s".', $state->purpose),
            default => sprintf('Every route failed for purpose "%s" (%d attempt(s), %d skip(s)).', $state->purpose, count($state->attempts), count($state->skips)),
        };
        if ($state->lastError !== null) {
            $message .= ' Last error: ' . $state->lastError->getMessage();
        }
        $exception = $state->credit !== []
            ? new CreditExhaustedException($message, $reason, $state->attempts, $state->skips, $state->lastError, $state->credit)
            : new ChainExhaustedException($message, $reason, $state->attempts, $state->skips, $state->lastError);
        $this->emit(Event::CHAIN_EXHAUSTED, $exception, $state);
        $last = end($state->attempts);
        $this->logger->log(Logger::ERROR, 'Chain exhausted.', [
            'purpose' => $state->purpose,
            'provider' => $last !== false ? $last->provider : null,
            'model' => $last !== false ? $last->model : null,
            'attempt' => count($state->attempts),
            'outcome' => $last !== false ? $last->outcome->value : null,
            'status' => $last !== false ? $last->httpStatus : null,
            'reason' => $reason,
        ]);
        throw $exception;
    }

    private function ensureTime(CallState $state, float $extra): void
    {
        if (!$this->hasTime($state, $extra)) {
            throw new DeadlineReached();
        }
    }

    private function hasTime(CallState $state, float $extra): bool
    {
        $remaining = $state->deadline - ($this->clock->now() - $state->start);

        return $remaining - $extra >= $this->options->minUsefulTime();
    }

    private function skip(CallState $state, Skip $skip): void
    {
        $state->skips[] = $skip;
        $this->emit(Event::ROUTE_SKIPPED, $skip, $state);
    }

    private function emit(string $event, object $payload, CallState $state): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                $message = sprintf('%s listener threw %s: %s', $event, $e::class, Text::cut($e->getMessage(), 200));
                $state->listenerErrors[] = $message;
                $this->logger->log(Logger::WARNING, 'Event listener threw; the call goes on.', ['purpose' => $state->purpose, 'event' => $event, 'error' => $message]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(CallState $state, Route $route, Attempt $attempt): array
    {
        return [
            'purpose' => $state->purpose,
            'provider' => $route->provider()->name(),
            'model' => $route->model(),
            'attempt' => $attempt->number,
            'outcome' => $attempt->outcome->value,
            'status' => $attempt->httpStatus,
        ];
    }

    private function elapsedMs(CallState $state): int
    {
        return (int) round(($this->clock->now() - $state->start) * 1000);
    }

    private function keys(): Keys
    {
        return new Keys($this->options->storeKeyPrefix());
    }

    private function apiKeyOf(Route $route): string
    {
        $auth = $route->provider()->authorization();

        return $auth === null ? '' : substr($auth, 7);
    }

    private function throttledInProcess(string $key, int $seconds): bool
    {
        $now = $this->clock->now();
        if (($this->memory->throttle[$key] ?? 0.0) > $now) {
            return false;
        }
        $this->memory->throttle[$key] = $now + $seconds;

        return true;
    }

    private function storeFailed(\Throwable $e, CallState $state): void
    {
        $message = sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), Text::cut($e->getMessage(), 200));
        $state->storeErrors[] = $message;
        if ($this->throttledInProcess('store', $this->options->logThrottleSeconds())) {
            $this->logger->log(Logger::WARNING, 'Store failed; carrying on as if nothing were cooled or held.', ['purpose' => $state->purpose, 'error' => $message]);
        }
    }

    private function storeGet(string $key, CallState $state): ?int
    {
        try {
            return $this->store->get($key);
        } catch (\Throwable $e) {
            $this->storeFailed($e, $state);

            return null;
        }
    }

    private function storeSet(string $key, int $value, int $ttl, CallState $state): void
    {
        try {
            $this->store->set($key, $value, $ttl);
        } catch (\Throwable $e) {
            $this->storeFailed($e, $state);
        }
    }

    private function storeDelete(string $key, CallState $state): void
    {
        try {
            $this->store->delete($key);
        } catch (\Throwable $e) {
            $this->storeFailed($e, $state);
        }
    }

    private function storeIncrement(string $key, int $ttl, CallState $state): ?int
    {
        try {
            return $this->store->increment($key, $ttl);
        } catch (\Throwable $e) {
            $this->storeFailed($e, $state);

            return null;
        }
    }

    private function storeAdd(string $key, int $value, int $ttl, CallState $state): ?bool
    {
        try {
            return $this->store->add($key, $value, $ttl);
        } catch (\Throwable $e) {
            $this->storeFailed($e, $state);

            return null;
        }
    }

    /**
     * @param list<string> $errors
     */
    private function storeRead(string $key, array &$errors): ?int
    {
        try {
            return $this->store->get($key);
        } catch (\Throwable $e) {
            $errors[] = Text::cut($e->getMessage(), 200);

            return null;
        }
    }
}
