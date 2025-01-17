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
 * 特此免费授予任何获得本软件及相关文档文件（"软件"）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按"原样"提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple\WebSocket\Server;

use Closure;
use DeflateContext;
use InflateContext;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\WebSocket\Frame\Type;
use Ripple\WebSocket\Utils;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_merge;
use function array_shift;
use function base64_encode;
use function call_user_func;
use function chr;
use function count;
use function deflate_add;
use function deflate_init;
use function explode;
use function inflate_add;
use function inflate_init;
use function ord;
use function pack;
use function parse_url;
use function rawurldecode;
use function sha1;
use function str_replace;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function unpack;

use const PHP_URL_PATH;
use const ZLIB_DEFAULT_STRATEGY;
use const ZLIB_ENCODING_RAW;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:44
 *
 * @property Closure $onMessage
 * @property Closure $onConnect
 * @property Closure $onClose
 * @property Closure $onRequest
 */
class Connection
{
    protected const NEED_HEAD = array(
        'host'                  => true,
        'upgrade'               => true,
        'connection'            => true,
        'sec-websocket-key'     => true,
        'sec-websocket-version' => true,
    );

    protected const EXTEND_HEAD = 'Sec-WebSocket-Extensions';

    /*** @var bool */
    protected bool $isDeflate = false;

    /*** @var string */
    protected string $buffer = '';

    /**
     * @Description Loaded using Request
     * @var string
     */
    protected string $headerContent = '';

    /*** @var Request */
    protected Request $request;

    /*** @var int */
    protected int $step = 0;

    /*** @var Closure */
    protected Closure $onMessage;

    /*** @var Closure */
    protected Closure $onConnect;

    /*** @var Closure */
    protected Closure $onClose;

    /*** @var Closure */
    protected Closure $onRequest;

    /*** @var mixed */
    public mixed $context;

    /*** @var DeflateContext|false */
    protected DeflateContext|false $deflator = false;

    /*** @var InflateContext|false */
    protected InflateContext|false $inflator = false;

    /**
     * @param Socket $stream
     * @param Server $server
     */
    public function __construct(public readonly Socket $stream, protected readonly Server $server)
    {
        $this->stream->onReadable(fn (Socket $stream) => $this->handleRead($stream));
        $this->stream->onClose(fn () => $this->onStreamClose());
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:44
     *
     * @param Socket $stream
     *
     * @return void
     */
    protected function handleRead(Socket $stream): void
    {
        try {
            $data = $stream->readContinuously(1024);
            if ($data === '') {
                if ($stream->eof()) {
                    throw new ConnectionException('Connection closed by peer', ConnectionException::CONNECTION_CLOSED);
                }
                return;
            }
            $this->push($data);
        } catch (ConnectionException) {
            $this->stream->close();
            return;
        } catch (Throwable $exception) {
            Output::warning($exception->getMessage());
            $this->stream->close();
            return;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     *
     * @param string $data
     *
     * @return void
     * @throws ConnectionException
     */
    protected function push(string $data): void
    {
        $this->buffer .= $data;
        if ($this->step === 0) {
            $handshake = $this->accept();
            if ($handshake === null) {
                return;
            } elseif ($handshake === false) {
                throw new ConnectionException('Handshake failed', ConnectionException::CONNECTION_HANDSHAKE_FAIL);
            } else {
                $this->step = 1;
                if (isset($this->onConnect)) {
                    call_user_func($this->onConnect, $this);
                }

                foreach ($this->parse() as $message) {
                    if (isset($this->onMessage)) {
                        call_user_func($this->onMessage, $message, $this);
                    }
                }
            }
        } else {
            foreach ($this->parse() as $message) {
                if (isset($this->onMessage)) {
                    call_user_func($this->onMessage, $message, $this);
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return bool|null
     * Return null to indicate that the handshake is not completed,
     * return false to indicate that the handshake failed,
     * and return true to indicate that the handshake was successful.
     * @throws ConnectionException
     */
    protected function accept(): bool|null
    {
        $identityInfo = $this->tick();
        if ($identityInfo === null) {
            return null;
        } elseif ($identityInfo === false) {
            return false;
        } else {
            $secWebSocketAccept = $this->getSecWebSocketAccept($identityInfo->headers->get('Sec-WebSocket-Key'));
            $this->stream->write($this->generateResponseContent($secWebSocketAccept));
            return true;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return Request|false|null
     */
    protected function tick(): Request|false|null
    {
        if ($index = strpos($this->buffer, "\r\n\r\n")) {
            $verify = Connection::NEED_HEAD;
            $headerWithBody = explode("\r\n\r\n", $this->buffer, 2);
            $headerContent = $headerWithBody[0];
            $bodyContent = $headerWithBody[1] ?? '';

            $lines = explode("\r\n", $headerContent);
            $header = array();

            if (count($firstLineInfo = explode(" ", array_shift($lines))) !== 3) {
                return false;
            }

            $method          = $firstLineInfo[0];
            $url             = $firstLineInfo[1];
            $version         = $firstLineInfo[2];

            foreach ($lines as $line) {
                if ($_ = explode(":", $line)) {
                    $header[trim($_[0])] = trim($_[1] ?? '');
                    unset($verify[strtolower(trim($_[0]))]);
                }
            }

            if (count($verify) > 0) {
                return false;
            } else {
                $this->buffer = substr($this->buffer, $index + 4);
                // Going here means that the onRequest event can be triggered after the Request is completed.

                # query
                $query       = [];
                $urlExploded = explode('?', $url);
                $path        = parse_url($url, PHP_URL_PATH);
                if (isset($urlExploded[1])) {
                    $queryArray = explode('&', $urlExploded[1]);
                    foreach ($queryArray as $item) {
                        $item = explode('=', $item);
                        if (count($item) === 2) {
                            $query[$item[0]] = $item[1];
                        }
                    }
                }

                # server
                $server = [
                    'REQUEST_METHOD'  => $method,
                    'REQUEST_URI'     => $path,
                    'SERVER_PROTOCOL' => $version,

                    'REMOTE_ADDR' => $this->stream->getHost(),
                    'REMOTE_PORT' => $this->stream->getPort(),
                    'HTTP_HOST'   => $header['Host'],
                ];

                # cookie
                $cookies = [];
                foreach ($header as $key => $value) {
                    $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
                }

                if (isset($server['HTTP_COOKIE'])) {
                    $cookie = $server['HTTP_COOKIE'];
                    $cookie = explode('; ', $cookie);
                    foreach ($cookie as $item) {
                        $item              = explode('=', $item);
                        $cookies[$item[0]] = rawurldecode($item[1]);
                    }
                }

                $this->request = new Request($query, [], [], $cookies, [], $server);
                if (isset($this->onRequest)) {
                    call_user_func($this->onRequest, $this->request, $this);
                }
                return $this->request;
            }
        } else {
            return null;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     *
     * @param string $key
     *
     * @return string
     */
    protected function getSecWebSocketAccept(string $key): string
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     *
     * @param string $accept
     *
     * @return string
     */
    protected function generateResponseContent(string $accept): string
    {
        $headers = array_merge(array(
            'Upgrade'              => 'websocket',
            'Connection'           => 'Upgrade',
            'Sec-WebSocket-Accept' => $accept,
        ), $this->extensions());
        $context = "HTTP/1.1 101 Switching Protocols\r\n";
        foreach ($headers as $key => $value) {
            $context .= "{$key}: {$value} \r\n";
        }
        $context .= "\r\n";
        return $context;
    }

    /**
     * @return array
     */
    protected function extensions(): array
    {
        $extendHeaders    = [];
        $clientExtendHead = $this->getRequest()->headers->get(Connection::EXTEND_HEAD);
        if (!$clientExtendHead) {
            return $extendHeaders;
        }

        $value     = '';
        $isDeflate = stripos($clientExtendHead, 'permessage-deflate') !== false;
        if ($isDeflate && $this->server->getOptions()->getDeflate()) {
            $value           .= 'permessage-deflate; server_no_context_takeover; client_max_window_bits=15';
            $this->isDeflate = true;
        }
        //Other extensions like: encryption…

        if ($value) {
            $extendHeaders[Connection::EXTEND_HEAD] = $value;
        }

        return $extendHeaders;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/30 14:51
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return array
     */
    protected function parse(): array
    {
        if (strlen($this->buffer) > 0) {
            $this->frameType();
        }

        $results     = array();
        $prevPayload = '';
        while (strlen($this->buffer) > 0) {
            $context    = $this->buffer;
            $dataLength = strlen($context);
            $index      = 0;

            // 验证足够的数据来读取第一个字节
            if ($dataLength < 2) {
                // 等待更多数据...
                break;
            }

            $byte   = ord($context[$index++]);
            $fin    = ($byte & 0x80) != 0;
            $opcode = $byte & 0x0F;
            $rsv1   = 64 === ($byte & 64);

            $byte          = ord($context[$index++]);
            $mask          = ($byte & 0x80) != 0;
            $payloadLength = $byte & 0x7F;

            // Handles 2 or 8 byte length fields
            if ($payloadLength > 125) {
                if ($payloadLength == 126) {
                    // Verify that there is enough data to read the 2-byte length field
                    if ($dataLength < $index + 2) {
                        // Waiting for more data...
                        break;
                    }
                    $payloadLength = unpack('n', substr($context, $index, 2))[1];
                    $index         += 2;
                } else {
                    // Verify there is enough data to read the 8-byte length field
                    if ($dataLength < $index + 8) {
                        // Waiting for more data...
                        break;
                    }
                    $payloadLength = unpack('J', substr($context, $index, 8))[1];
                    $index         += 8;
                }
            }

            // Handle mask keys
            if ($mask) {
                // Verify there is enough data to read the mask key
                if ($dataLength < $index + 4) {
                    // Waiting for more data...
                    break;
                }
                $maskingKey = substr($context, $index, 4);
                $index      += 4;
            }

            // Verify sufficient data to read payload data
            if ($dataLength < $index + $payloadLength) {
                // Waiting for more data...
                break;
            }

            // Process load data
            $payload = substr($context, $index, $payloadLength);
            if ($mask) {
                for ($i = 0; $i < strlen($payload); $i++) {
                    $payload[$i] = chr(ord($payload[$i]) ^ ord($maskingKey[$i % 4]));
                }
            }

            if ($rsv1) {
                $payload = $this->inflate($payload, $fin);
            }

            $this->buffer = substr($context, $index + $payloadLength);

            $prevPayload .= $payload;
            if ($fin) {
                $results[]   = $prevPayload;
                $prevPayload = '';
            }
        }

        return $results;
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
     * @return void
     */
    protected function frameType(): void
    {
        $firstByte = ord($this->buffer[0]);
        $opcode    = $firstByte & 0x0F;

        switch ($opcode) {
            case Type::PING:
                $this->pong();
                break;
            case Type::BINARY:
            case Type::CLOSE:
                $this->close();
                break;
            case Type::TEXT:
            case Type::PONG:
            default:
                break;
        }
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
     * @return bool
     */
    protected function pong(): bool
    {
        if (!$this->server->getOptions()->getPingPong()) {
            return false;
        }

        return $this->sendFrame('', opcode: TYPE::PONG);
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
     *
     * @param string $context
     * @param int    $opcode
     * @param bool   $fin
     *
     * @return bool
     */
    public function sendFrame(string $context, int $opcode = 0x1, bool $fin = true): bool
    {
        try {
            if (!$this->isHandshake()) {
                throw new ConnectionException('Connection is not established yet', ConnectionException::CONNECTION_HANDSHAKE_FAIL);
            }
            $this->stream->write($this->build($context, $opcode, $fin));
        } catch (ConnectionException) {
            $this->stream->close();
            return false;
        }
        return true;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return bool
     */
    public function isHandshake(): bool
    {
        return $this->step === 1;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     *
     * @param string $context
     * @param int    $opcode
     * @param bool   $fin
     *
     * @return string
     */
    protected function build(string $context, int $opcode = 0x1, bool $fin = true): string
    {
        $frame = chr(($fin ? 0x80 : 0) | $opcode);
        if ($this->isDeflate && $opcode === 0x1) {
            $frame[0] = chr(ord($frame[0]) | 0x40);
            $context  = $this->deflate($context);
        }

        $contextLen = strlen($context);
        if ($contextLen < 126) {
            $frame .= chr($contextLen);
        } elseif ($contextLen <= 0xFFFF) {
            $frame .= chr(126) . pack('n', $contextLen);
        } else {
            $frame .= chr(127) . pack('J', $contextLen);
        }
        $frame .= $context;

        return $frame;
    }

    /**
     * @param $payload
     *
     * @return string
     */
    protected function deflate($payload): string
    {
        if (!$this->deflator) {
            $this->deflator = deflate_init(
                ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 9,
                    'strategy' => ZLIB_DEFAULT_STRATEGY
                ]
            );
        }

        return substr(deflate_add($this->deflator, $payload), 0, -4);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return void
     */
    public function close(): void
    {
        if (!$this->stream->isClosed()) {
            $this->sendFrame('', Type::CLOSE);
            \Co\sleep(0.1);
            $this->stream->close();
        }
    }

    /**
     * @param $payload
     * @param $fin
     *
     * @return bool|string
     */
    protected function inflate($payload, $fin): bool|string
    {
        if (!isset($this->inflator)) {
            $this->inflator = inflate_init(
                ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 9,
                    'strategy' => ZLIB_DEFAULT_STRATEGY
                ]
            );
        }

        if ($fin) {
            $payload .= "\x00\x00\xff\xff";
        }

        return inflate_add($this->inflator, $payload);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return int
     */
    public function getId(): int
    {
        return $this->stream->id;
    }

    /**
     * @Description Request information has been loaded using the Request object
     * @Author      cclilshy
     * @Date        2024/8/15 14:45
     * @return string
     */
    public function getHeaderContent(): string
    {
        return $this->headerContent;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     *
     * @param string $message
     *
     * @return bool
     */
    public function send(string $message): bool
    {
        try {
            if (!$this->isHandshake()) {
                throw new ConnectionException('Connection is not established yet', ConnectionException::CONNECTION_HANDSHAKE_FAIL);
            }
            $this->stream->write($this->build($message));
        } catch (ConnectionException) {
            $this->stream->close();
            return false;
        } catch (Throwable $exception) {
            Output::warning($exception->getMessage());
            $this->stream->close();
            return false;
        }

        return true;
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
            case 'onMessage':
                Utils::validateClosureParameters(
                    closure: $value,
                    rules: ['string', Connection::class]
                );
                break;

            case 'onClose':
            case 'onConnect':
                Utils::validateClosureParameters(
                    closure: $value,
                    rules: [Connection::class]
                );
                break;

            case 'onRequest':
                Utils::validateClosureParameters(
                    closure: $value,
                    rules: [Request::class, Connection::class]
                );
                break;
        }

        $this->{$name} = $value;
    }

    /**
     * @return void
     */
    protected function onStreamClose(): void
    {
        if (isset($this->onClose)) {
            call_user_func($this->onClose, $this);
        }
    }
}
