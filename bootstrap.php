<?php

/**
 * Use llm-json-php without Composer:
 *
 *     require __DIR__ . '/llm-json-php/bootstrap.php';
 *
 * Registers a PSR-4 autoloader for IndianOpinion\LlmJson. Safe to require
 * more than once.
 */

declare(strict_types=1);

if (!defined('INDIANOPINION_LLMJSON_BOOTSTRAPPED')) {
    define('INDIANOPINION_LLMJSON_BOOTSTRAPPED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\LlmJson\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
