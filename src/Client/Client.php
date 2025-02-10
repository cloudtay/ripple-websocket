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

namespace Ripple\WebSocket\Client;

use Closure;
use Exception;
use Ripple\Socket;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\WebSocket\Utils;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function base64_encode;
use function call_user_func;
use function chr;
use function Co\async;
use function Co\promise;
use function explode;
use function http_build_query;
use function ord;
use function pack;
use function parse_url;
use function random_bytes;
use function sha1;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function ucwords;
use function unpack;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

//Random\RandomException require PHP>=8.2;

/**
 * @Author cclilshy
 * @Date   2:28 pm
 * White paper: https://datatracker.ietf.org/doc/html/rfc6455
 * Latest specification: https://websockets.spec.whatwg.org/
 * @property Closure $onOpen
 * @property Closure $onMessage
 * @property Closure $onClose
 * @property Closure $onError
 */
class Client
{
    /*** @var Closure */
    protected Closure $onOpen;

    /*** @var Closure */
    protected Closure $onMessage;

    /*** @var Closure */
    protected Closure $onClose;

    /*** @var Closure */
    protected Closure $onError;

    /*** @var Socket */
    protected Socket $stream;

    /*** @var string */
    protected string $buffer = '';

    /*** @var \Symfony\Component\HttpFoundation\Request */
    protected Request $request;

    /*** @var string */
    protected string $key;

    /**
     * @param \Symfony\Component\HttpFoundation\Request|string $request
     * @param int|float                                        $timeout
     * @param mixed|null                                       $context
     */
    public function __construct(
        Request|string               $request,
        protected readonly int|float $timeout = 10,
        protected readonly mixed     $context = null
    ) {
        if ($request instanceof Request) {
            $this->request = $request;
        } else {
            $urlExploded = explode('?', $request);
            $query       = isset($urlExploded[1])
                ? Utils::parseQuery($urlExploded[1])
                : [];
            $host        = parse_url($request, PHP_URL_HOST);
            $port        = parse_url($request, PHP_URL_PORT) ?? match (parse_url($request, PHP_URL_SCHEME)) {
                'ws'    => 80,
                'wss'   => 443,
                default => throw new RuntimeException('Unsupported scheme'),
            };

            $this->request = new Request(
                $query,
                [],
                [],
                [],
                [],
                [
                    'REQUEST_METHOD'  => 'GET',
                    'REQUEST_URI'     => parse_url($request, PHP_URL_PATH) ?? '/',
                    'HTTP_HOST'       => "{$host}:{$port}",
                    'HTTP_UPGRADE'    => 'websocket',
                    'HTTP_CONNECTION' => 'Upgrade',
                    'SERVER_PORT'     => parse_url($request, PHP_URL_PORT)
                ]
            );
        }

        $this->request->headers->set('Sec-WebSocket-Version', '13');
        try {
            $this->request->headers->set(
                'Sec-WebSocket-Key',
                $this->key = base64_encode(random_bytes(16))
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to generate random bytes');
        }

        async(function () {
            try {
                $this->handshake();
                if (isset($this->onOpen)) {
                    try {
                        call_user_func($this->onOpen, $this);
                    } catch (Throwable $e) {
                        Output::error($e->getMessage());
                    }
                }

                $this->tick();
            } catch (Throwable $e) {
                if (isset($this->onError)) {
                    try {
                        call_user_func($this->onError, $e, $this);
                    } catch (Throwable $e) {
                        Output::error($e->getMessage());
                    }
                }

                if (isset($this->stream)) {
                    $this->stream->close();
                }
                return;
            }

            $this->stream->onReadable(function () {
                try {
                    $buffer = $this->stream->readContinuously(8192);
                    if (!$buffer) {
                        $this->stream->close();
                        return;
                    }
                    $this->buffer .= $buffer;
                    $this->tick();
                } catch (Throwable $e) {
                    $this->stream->close();
                    if (isset($this->onError)) {
                        try {
                            call_user_func($this->onError, $e, $this);
                        } catch (Throwable $e) {
                            Output::error($e->getMessage());
                        }
                    }
                    return;
                }
            });
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return void
     * @throws Throwable
     */
    protected function handshake(): void
    {
        promise(function (Closure $resolve) {
            $scheme = $this->request->getScheme() === 'https' ? 'wss' : 'ws';
            $method = strtoupper($this->request->getMethod());
            $host   = $this->request->getHost();
            $uri    = $this->request->getRequestUri();
            $port   = $this->request->getPort();

            $query        = http_build_query($this->request->query->all());
            $this->stream = match ($scheme) {
                'ws'    => Socket::connect("tcp://{$host}:{$port}", $this->timeout, $this->context),
                'wss'   => Socket::connectWithSSL("ssl://{$host}:{$port}", $this->timeout, $this->context),
                default => throw new Exception('Unsupported scheme'),
            };

            $this->stream->setBlocking(false);

            $context = "{$method} {$uri}{$query} HTTP/1.1\r\n";
            foreach ($this->request->headers->all() as $name => $values) {
                $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
                foreach ($values as $value) {
                    $context .= "{$name}: {$value}\r\n";
                }
            }
            $context .= "\r\n";
            $context .= $this->request->getContent();

            $buffer = '';
            $this->stream->write($context);
            $this->stream->onReadable(function (Stream $stream, Closure $cancel) use (
                $resolve,
                &$buffer,
            ) {
                $response = $this->stream->readContinuously(8192);
                if ($response === '') {
                    if ($this->stream->eof()) {
                        $this->stream->close();
                        throw new ConnectionException('Connection closed by peer', ConnectionException::CONNECTION_CLOSED);
                    }
                    return;
                }

                $buffer .= $response;

                if (str_contains($buffer, "\r\n\r\n")) {
                    $headBody = explode("\r\n\r\n", $buffer);
                    $header   = $headBody[0];
                    $body     = $headBody[1] ?? '';

                    if (!str_contains(
                        strtolower($header),
                        strtolower("HTTP/1.1 101 Switching Protocols")
                    )) {
                        $this->stream->close();
                        throw new ConnectionException('Invalid response', ConnectionException::CONNECTION_HANDSHAKE_FAIL);
                    }

                    $headers  = array();
                    $exploded = explode("\r\n", $header);

                    foreach ($exploded as $index => $line) {
                        if ($index === 0) {
                            continue;
                        }
                        $exploded                          = explode(': ', $line);
                        $headers[strtolower($exploded[0])] = $exploded[1] ?? '';
                    }

                    if (!$signature = $headers['sec-websocket-accept'] ?? null) {
                        throw new Exception('Invalid response');
                    }

                    $expectedSignature = base64_encode(sha1($this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    if (trim($signature) !== $expectedSignature) {
                        throw new Exception('Invalid response');
                    }

                    $cancel();
                    $resolve();
                }
            });

            $this->stream->onClose(fn () => $this->onStreamClose());
        })->await();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @return void
     */
    public function close(): void
    {
        if (isset($this->stream)) {
            try {
                $this->sendFrame('', 0x8);
            } catch (Throwable $e) {

            } finally {
                $this->stream->close();
            }
        }
    }

    /**
     * @param string $data
     * @param int    $opcode
     *
     * @return void
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function sendFrame(string $data, int $opcode = 0x1): void
    {
        $finOpcode = 0x80 | $opcode;
        $packet    = chr($finOpcode);
        $length    = strlen($data);

        if ($length <= 125) {
            $packet .= chr($length);
        } elseif ($length < 65536) {
            $packet .= chr(126);
            $packet .= pack('n', $length);
        } else {
            $packet .= chr(127);
            $packet .= pack('J', $length);
        }

        $packet .= $data;
        $this->stream->write($packet);
    }

    /**
     * @return void
     */
    protected function onStreamClose(): void
    {
        if (isset($this->onClose)) {
            try {
                call_user_func($this->onClose, $this);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return void
     * @throws ConnectionException
     */
    protected function tick(): void
    {
        while (strlen($this->buffer) >= 2) {
            $firstByte     = ord($this->buffer[0]);
            $fin           = ($firstByte & 0x80) === 0x80;
            $opcode        = $firstByte & 0x0f;
            $secondByte    = ord($this->buffer[1]);
            $masked        = ($secondByte & 0x80) === 0x80;
            $payloadLength = $secondByte & 0x7f;
            $offset        = 2;
            if ($payloadLength === 126) {
                if (strlen($this->buffer) < 4) {
                    return;
                }
                $payloadLength = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset        += 2;
            } elseif ($payloadLength === 127) {
                if (strlen($this->buffer) < 10) {
                    return;
                }
                $payloadLength = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset        += 8;
            }

            if ($masked) {
                if (strlen($this->buffer) < $offset + 4) {
                    return;
                }
                $maskingKey = substr($this->buffer, $offset, 4);
                $offset     += 4;
            }

            if (strlen($this->buffer) < $offset + $payloadLength) {
                return;
            }

            $payloadData = substr($this->buffer, $offset, $payloadLength);
            $offset      += $payloadLength;

            if ($masked) {
                $unmaskedData = '';
                for ($i = 0; $i < $payloadLength; $i++) {
                    $unmaskedData .= chr(ord($payloadData[$i]) ^ ord($maskingKey[$i % 4]));
                }
            } else {
                $unmaskedData = $payloadData;
            }

            switch ($opcode) {
                case 0x1: // text
                    break;
                case 0x2: // binary
                    break;
                case 0x8: // close
                    $this->stream->close();
                    return;
                case 0x9: // ping
                    // Send pong response
                    $pongFrame = chr(0x8A) . chr(0x00);
                    $this->stream->write($pongFrame);
                    return;
                case 0xA: // pong
                    return;
                default:
                    break;
            }

            if (isset($this->onMessage)) {
                try {
                    call_user_func($this->onMessage, $unmaskedData, $this, $opcode);
                } catch (Throwable $e) {
                    Output::error($e->getMessage());
                }
            }
            $this->buffer = substr($this->buffer, $offset);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     *
     * @param string $data
     *
     * @return void
     * @throws ConnectionException
     * @throws Throwable
     */
    public function send(string $data): void
    {
        $finOpcode = 0x81;
        $packet    = chr($finOpcode);
        $length    = strlen($data);

        if ($length <= 125) {
            $packet .= chr($length | 0x80);
        } elseif ($length < 65536) {
            $packet .= chr(126 | 0x80);
            $packet .= pack('n', $length);
        } else {
            $packet .= chr(127 | 0x80);
            $packet .= pack('J', $length);
        }

        $maskingKey = random_bytes(4);
        $packet     .= $maskingKey;
        $maskedData = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedData .= chr(ord($data[$i]) ^ ord($maskingKey[$i % 4]));
        }
        $packet .= $maskedData;
        $this->stream->write($packet);
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
            case 'onClose':
            case 'onOpen':
                Utils::validateClosureParameters($value, [Client::class]);
                break;

            case 'onMessage':
                Utils::validateClosureParameters($value, ['string', Client::class, 'int']);
                break;

            case 'onError':
                Utils::validateClosureParameters($value, [Throwable::class, Client::class]);
                break;
        }

        $this->{$name} = $value;
    }
}
