<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

use erikwang2013\apidoc\protocol\debug\Driver\CoapDriver;
use erikwang2013\apidoc\protocol\debug\Driver\DubboDriver;
use erikwang2013\apidoc\protocol\debug\Driver\GrpcDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HessianDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HlsDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Http2Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Http3Driver;
use erikwang2013\apidoc\protocol\debug\Driver\HttpRelayDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Iso8583Driver;
use erikwang2013\apidoc\protocol\debug\Driver\ModbusTcpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\MqttDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RawDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtspDriver;
use erikwang2013\apidoc\protocol\debug\Driver\SipDriver;
use erikwang2013\apidoc\protocol\debug\Driver\StompDriver;
use erikwang2013\apidoc\protocol\debug\Driver\ThriftDriver;

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
        'coap' => CoapDriver::class,
        'modbus-tcp' => ModbusTcpDriver::class,
        'iso8583' => Iso8583Driver::class,
        'rtsp' => RtspDriver::class,
        'sip' => SipDriver::class,
        'grpc' => GrpcDriver::class,
        'hessian' => HessianDriver::class,
        'hls' => HlsDriver::class,
        'thrift' => ThriftDriver::class,
        'dubbo' => DubboDriver::class,
        'http2' => Http2Driver::class,
        'http3' => Http3Driver::class,
    ];

    /**
     * @return array<int,string> 已注册驱动名,供 UIC-1 能力声明使用
     */
    public static function names(): array
    {
        return array_keys(static::$drivers);
    }

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
