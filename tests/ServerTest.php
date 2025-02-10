<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Ripple\WebSocket\Server\Connection;
use Ripple\WebSocket\Server\Options;
use Ripple\WebSocket\Server\Server;
use Symfony\Component\HttpFoundation\Request;

class ServerTest extends TestCase
{
    private Server $server;

    /**
     * @return void
     */
    public function testServerCreation()
    {
        $this->assertInstanceOf(Server::class, $this->server);
    }

    /**
     * @return void
     */
    public function testServerWithCustomOptions()
    {
        $options = new Options(pingPong: true, deflate: true);
        $server  = new Server('ws://127.0.0.1:8081', options: $options);

        $this->assertTrue($server->getOptions()->getPingPong());
    }

    /**
     * @return void
     */
    public function testOnConnectCallback()
    {
        $called = false;

        $this->server->onConnect = function (Connection $connection) use (&$called) {
            $called = true;
        };

        $this->server->listen();
        $this->assertIsCallable($this->server->onConnect);
    }

    /**
     * @return void
     */
    public function testOnRequestCallback()
    {
        $called = false;

        $this->server->onRequest = function (Request $request, Connection $connection) use (&$called) {
            $called = true;
        };

        $this->server->listen();
        $this->assertIsCallable($this->server->onRequest);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->server = new Server('ws://127.0.0.1:8080');
    }
}
