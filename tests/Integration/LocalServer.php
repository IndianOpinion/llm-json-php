<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Integration;

/**
 * Starts PHP's built-in server on a free loopback port.
 */
final class LocalServer
{
    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;

    public static function start(): string
    {
        if (self::$process !== null) {
            return self::url();
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('cannot find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        $router = dirname(__DIR__) . '/Fixtures/server.php';
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router];
        $env = ['PHP_CLI_SERVER_WORKERS' => '4'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the built-in server');
        }
        self::$process = $process;
        register_shutdown_function([self::class, 'stop']);
        for ($i = 0; $i < 100; $i++) {
            set_error_handler(static fn (): bool => true);
            try {
                $conn = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.1);
            } finally {
                restore_error_handler();
            }
            if ($conn !== false) {
                fclose($conn);

                return self::url();
            }
            usleep(50_000);
        }
        throw new \RuntimeException('built-in server did not start');
    }

    public static function url(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }
}
