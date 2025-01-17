<?php declare(strict_types=1);

namespace Ripple\WebSocket;

use Closure;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;

use function count;
use function explode;
use function in_array;
use function str_ends_with;

/**
 * Class Utils
 * 该工具禁止ripple外部调用,它随时可能被整合到ripple基础工具中
 */
class Utils
{
    /**
     * 验证闭包参数类型, 目前不验证参数名称, 目前不支持联合类型
     * 仅支持明确数量的参数
     *
     * @param mixed $closure 闭包
     * @param array $rules   参数类型
     * @param int   $min     最小参数数量
     *
     * @return bool
     */
    public static function validateClosureParameters(mixed $closure, array $rules, int $min = 0): bool
    {
        if (!$closure instanceof Closure) {
            throw new InvalidArgumentException('The closure is invalid');
        }

        try {
            $reflection = new ReflectionFunction($closure);
        } catch (ReflectionException) {
            throw new InvalidArgumentException('The closure is invalid');
        }

        if (count($parameters = $reflection->getParameters()) < $min) {
            throw new InvalidArgumentException("The closure must have at least {$min} parameters");
        }

        foreach ($parameters as $index => $parameter) {
            if (!$rule = $rules[$index] ?? null) {
                continue;
            }

            $typeName = $parameter->getType()?->getName();
            if (in_array($typeName, ['mixed', null], true)) {
                continue;
            }

            if ($parameter->allowsNull()) {
                if (!str_ends_with($rule, '?')) {
                    throw new InvalidArgumentException(
                        Utils::generateMessage($parameter->getName(), $rule, $typeName)
                    );
                }
            }

            if ($typeName !== $rule) {
                throw new InvalidArgumentException(
                    Utils::generateMessage($parameter->getName(), $rule, $typeName)
                );
            }
        }

        return true;
    }

    /**
     * @param string $queryString
     *
     * @return array
     */
    public static function parseQuery(string $queryString): array
    {
        $query      = [];
        $queryArray = explode('&', $queryString);
        foreach ($queryArray as $item) {
            $item = explode('=', $item);
            if (count($item) === 2) {
                $query[$item[0]] = $item[1];
            }
        }
        return $query;
    }

    /**
     * @param string $name
     * @param string $request
     * @param string $give
     *
     * @return string
     */
    protected static function generateMessage(string $name, string $request, string $give = 'null'): string
    {
        return "The parameter {$name} must be {$request}, {$give} given";
    }
}
