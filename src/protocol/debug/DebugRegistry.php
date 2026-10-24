<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

use erikwang2013\apidoc\protocol\debug\Driver\HttpRelayDriver;
use erikwang2013\apidoc\protocol\debug\Driver\MqttDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RawDriver;
use erikwang2013\apidoc\protocol\debug\Driver\StompDriver;

/**
 * 调试驱动注册表
 * @package erikwang2013\apidoc\protocol\debug
 */
class DebugRegistry
{
    /**
     * @var array<string, class-string<DebugDriverInterface>>
     */
    protected static $drivers = [
        'raw' => RawDriver::class,
        'http-relay' => HttpRelayDriver::class,
        'mqtt' => MqttDriver::class,
        'stomp' => StompDriver::class,
    ];

    /**
     * @param string $name 驱动名
     * @return DebugDriverInterface|null
     */
    public static function driver(string $name): ?DebugDriverInterface
    {
        if (!isset(static::$drivers[$name])) {
            return null;
        }
        $class = static::$drivers[$name];
        return new $class();
    }

    /**
     * @param string $name 驱动名
     * @return array 驱动字段定义;未知驱动返回 []
     */
    public static function driverFields(string $name): array
    {
        $driver = static::driver($name);
        return $driver === null ? [] : $driver->fields([]);
    }
}
