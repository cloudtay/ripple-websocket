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

use function Co\cancelAll;
use function Co\defer;
use function Co\wait;
use function gc_collect_cycles;
use function md5;
use function memory_get_usage;
use function stream_context_create;
use function uniqid;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:49
 */
class WebSocketTest extends TestCase
{
    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return void
     */
    #[Test]
    public function test_wsServer(): void
    {
        defer(function () {
            for ($i = 0; $i < 10; $i++) {
                $this->_webSocketTest()->await();
            }
            gc_collect_cycles();
            $baseMemory = memory_get_usage();

            for ($i = 0; $i < 10; $i++) {
                $this->_webSocketTest()->await();
            }
            gc_collect_cycles();

            if ($baseMemory !== memory_get_usage()) {
                Output::warning('There may be a memory leak');
            }

            cancelAll();
            $this->assertTrue(true);
        });

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server = new Server(
            'ws://127.0.0.1:8001/',
            $context,
            new Options(true, true)
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
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return Promise
     */
    private function _webSocketTest(): Promise
    {
        return \Co\promise(function ($r) {
            $hash           = md5(uniqid());
            $client         = new Client('ws://127.0.0.1:8001/');
            $client->onOpen = static function () use ($client, $hash) {
                $client->send($hash);
            };

            $client->onMessage = function (string $data) use ($hash, $r) {
                $this->assertEquals($hash, $data);
                $r();
            };
        });
    }
}
