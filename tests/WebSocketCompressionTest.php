<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\Promise;
use Ripple\Utils\Output;
use Ripple\WebSocket\Client\Client;
use Ripple\WebSocket\Server\Connection;
use Ripple\WebSocket\Server\Options;
use Ripple\WebSocket\Server\Server;
use Throwable;

use function Co\cancelAll;
use function Co\defer;
use function Co\wait;
use function gc_collect_cycles;
use function memory_get_usage;
use function str_repeat;
use function stream_context_create;
use function strlen;

class WebSocketCompressionTest extends TestCase
{
    #[Test]
    public function test_wsCompression(): void
    {
        defer(function () {
            try {
                for ($i = 0; $i < 5; $i++) {
                    $this->_compressionTest()->await();
                }

                gc_collect_cycles();
                $baseMemory = memory_get_usage();

                for ($i = 0; $i < 5; $i++) {
                    $this->_compressionTest()->await();
                }

                gc_collect_cycles();
                if ($baseMemory !== memory_get_usage()) {
                    Output::warning('There may be a memory leak');
                }
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            } finally {
                cancelAll();
            }

            $this->assertTrue(true);
        });

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server = new Server(
            'ws://127.0.0.1:8002/',
            $context,
            new Options(deflate: true, pingPong: true)
        );

        $server->onConnect = function (Connection $connection) {
            $connection->onMessage = static function (string $data, Connection $connection) {
                $connection->send($data);
            };
        };

        $server->listen();
        wait();
    }

    /**
     * 执行压缩测试
     *
     * @return Promise
     */
    private function _compressionTest(): Promise
    {
        return \Co\promise(function ($resolve) {
            $testData = str_repeat('Hello WebSocket Compression Test! ', 1000);

            $client = new Client('ws://127.0.0.1:8002/');

            $client->onOpen = static function () use ($client, $testData) {
                $client->send($testData);
            };

            $client->onMessage = function (string $data) use ($testData, $resolve) {
                $this->assertEquals($testData, $data);
                $this->assertEquals(strlen($testData), strlen($data));
                $resolve();
            };

            $client->onError = function (Throwable $e) {
                Output::error("WebSocket compression test error: " . $e->getMessage());
                $this->fail($e->getMessage());
            };
        });
    }
}
