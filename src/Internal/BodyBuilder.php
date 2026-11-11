<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\ProviderKind;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;

/**
 * Builds the chat completions body and headers for one attempt (PRD 4.3).
 *
 * @internal
 */
final class BodyBuilder
{
    public const QUOTE_INSTRUCTION = 'Never type a straight double quote character (") inside a text value. When you need to quote someone, use curly single quotes (‘ and ’) instead.';

    public static function schemaInstruction(Schema $schema): string
    {
        return 'Reply with one JSON object and nothing else: no prose, no Markdown fence. The object must match this JSON schema: '
            . Json::encode($schema->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function body(Route $route, OutputMode $mode, Request $request, GuardProfile $profile, Options $options): array
    {
        $provider = $route->provider();
        $instructions = [];
        if ($mode === OutputMode::Json || $mode === OutputMode::Prompt) {
            $instructions[] = self::schemaInstruction($request->schema());
        }
        if ($profile->quoteInstruction()) {
            $instructions[] = self::QUOTE_INSTRUCTION;
        }

        $body = [
            'model' => $route->model(),
            'messages' => $this->messages($request, $instructions),
        ];
        $body[$provider->tokenParameter()] = $request->tokenCap() ?? $route->tokenCap() ?? $options->defaultTokenCap();

        if ($mode === OutputMode::Strict) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->schema()->name(),
                    'schema' => $request->schema()->toArray(),
                    'strict' => true,
                ],
            ];
        } elseif ($mode === OutputMode::Json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $temperature = $request->temperature() ?? $route->temperature();
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }

        if ($provider->kind() === ProviderKind::OpenRouter) {
            $preferences = [];
            if ($route->requireParameters()) {
                $preferences['require_parameters'] = true;
            }
            if ($route->hostOrder() !== null) {
                $preferences['order'] = $route->hostOrder();
            }
            if ($route->allowFallbacks() !== null) {
                $preferences['allow_fallbacks'] = $route->allowFallbacks();
            }
            if ($preferences !== []) {
                $body['provider'] = $preferences;
            }
            if ($route->usageReporting()) {
                $body['usage'] = ['include' => true];
            }
            if ($route->reasoningDisabled()) {
                $body['reasoning'] = ['enabled' => false];
            }
        } elseif ($route->reasoningDisabled()) {
            $body['reasoning_effort'] = $route->reasoningEffortWhenOff();
        }

        foreach ($route->extraBody() as $field => $value) {
            if (in_array($field, ['model', 'messages', 'response_format', 'stream', 'n', 'max_tokens', 'max_completion_tokens', $provider->tokenParameter()], true)) {
                throw new ConfigurationException(sprintf('Extra body field "%s" would overwrite a field the library owns.', $field));
            }
            $body[$field] = $value;
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(Route $route): array
    {
        $provider = $route->provider();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'llm-json-php/' . Client::VERSION . ' (+https://github.com/IndianOpinion/llm-json-php)',
        ];
        $auth = $provider->authorization();
        if ($auth !== null) {
            $headers['Authorization'] = $auth;
        }

        return array_merge($headers, $provider->headers());
    }

    /**
     * @param list<string> $instructions
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(Request $request, array $instructions): array
    {
        $extra = implode("\n\n", $instructions);
        $given = $request->messages();
        if ($given !== null) {
            if ($extra === '') {
                return $given;
            }
            foreach ($given as $i => $message) {
                if ($message['role'] === 'system' || $message['role'] === 'developer') {
                    $given[$i]['content'] = rtrim($message['content']) . ($message['content'] === '' ? '' : "\n\n") . $extra;

                    return $given;
                }
            }

            return array_merge([['role' => 'system', 'content' => $extra]], $given);
        }

        $system = $request->system();
        if ($extra !== '') {
            $system = trim($system) === '' ? $extra : rtrim($system) . "\n\n" . $extra;
        }
        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        if ($request->input() !== '') {
            $messages[] = ['role' => 'user', 'content' => $request->input()];
        }

        return $messages;
    }
}
