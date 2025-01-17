<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple\WebSocket\Server;

use Closure;
use InvalidArgumentException;
use Ripple\Kernel;
use Ripple\Socket;
use Ripple\WebSocket\Utils;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function parse_url;

use const SO_KEEPALIVE;
use const SO_REUSEADDR;
use const SO_REUSEPORT;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

/**
 * [Protocol related]
 * Book: https://datatracker.ietf.org/doc/html/rfc6455
 * Latest specifications: https://websockets.spec.whatwg.org/
 *
 * @property Closure $onConnect
 * @property Closure $onRequest
 */
class Server
{
    /*** @var Closure(Connection $connection):void */
    protected Closure $onConnect;

    /*** @var Closure */
    protected Closure $onRequest;

    /*** @var Socket */
    protected Socket $server;

    /*** @var Options */
    protected Options $options;

    /**
     * @param string       $address
     * @param mixed|null   $context
     * @param Options|null $options
     */
    public function __construct(string $address, mixed $context = null, Options|null $options = null)
    {
        $this->options = $options ?: new Options();
        $addressInfo   = parse_url($address);

        if (!$addressInfo['scheme'] ?? null) {
            throw new InvalidArgumentException('The address must contain a scheme');
        }

        if (!$host = $addressInfo['host'] ?? null) {
            throw new InvalidArgumentException('The address must contain a host');
        }

        if (!$port = $addressInfo['port'] ?? null) {
            throw new InvalidArgumentException('The address must contain a port');
        }

        $this->server = Socket::server("tcp://{$host}:{$port}", $context);

        $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        $this->server->setOption(SOL_SOCKET, SO_REUSEADDR, 1);

        /*** @compatible:Windows */
        if (Kernel::getInstance()->supportProcessControl()) {
            $this->server->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        }

        $this->server->setBlocking(false);
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        $this->server->onReadable(function (Socket $stream) {
            try {
                if (!$client = $stream->accept()) {
                    return;
                }

                $client->setBlocking(false);

                $client->setOption(SOL_TCP, SO_KEEPALIVE, 1);
                $client->setOption(SOL_TCP, TCP_NODELAY, 1);

                $connection            = new Connection($client, $this);
                $connection->onConnect = function (Connection $connection) {
                    if (isset($this->onConnect)) {
                        ($this->onConnect)($connection);
                    }
                };

                $connection->onRequest = function (Request $request, Connection $connection) {
                    if (isset($this->onRequest)) {
                        ($this->onRequest)($request, $connection);
                    }
                };
            } catch (Throwable) {
                return;
            }
        });
    }

    /**
     * @return \Ripple\WebSocket\Server\Options
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * @param string $name
     * @param mixed  $value
     *
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        switch ($name) {
            case 'onConnect':
                Utils::validateClosureParameters($value, [Connection::class], 1);
                break;

            case 'onRequest':
                Utils::validateClosureParameters($value, [Request::class, Connection::class], 1);
                break;
        }

        $this->{$name} = $value;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->{$name};
    }
}
