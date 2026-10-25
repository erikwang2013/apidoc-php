![Apidoc 宠物](assets/apidoc-pet.svg)

# Apidoc

基于 PHP 8 attributes 的 API 文档生成与接口开发工具，兼容 Laravel、ThinkPHP、Hyperf、Webman、Yii2、Yii3

[![Packagist Version](https://img.shields.io/packagist/v/erikwang2013/apidoc-php)](https://packagist.org/packages/erikwang2013/apidoc-php)
[![Packagist Downloads](https://img.shields.io/packagist/dt/erikwang2013/apidoc-php)](https://packagist.org/packages/erikwang2013/apidoc-php)
[![Packagist Monthly Downloads](https://img.shields.io/packagist/dm/erikwang2013/apidoc-php)](https://packagist.org/packages/erikwang2013/apidoc-php)
[![License](https://img.shields.io/packagist/l/erikwang2013/apidoc-php)](https://packagist.org/packages/erikwang2013/apidoc-php)
[![GitHub Issues](https://img.shields.io/github/issues/erikwang2013/apidoc-php)](https://github.com/erikwang2013/apidoc-php)
[![GitHub Forks](https://img.shields.io/github/forks/erikwang2013/apidoc-php)](https://github.com/erikwang2013/apidoc-php)

## 📖项目介绍

Apidoc 是一款通过解析 **PHP 8 attributes** 自动生成 API 接口文档的 PHP 扩展包，兼容 Laravel、ThinkPHP、Hyperf、Webman、Yii2、Yii3 等主流框架。除文档自动生成外，还集成了在线接口调试、Mock 调试数据、Json/TypeScript 代码生成、接口生成器、代码生成器等能力，覆盖接口开发、调试、交付的全流程，致力于提升 API 开发效率。

> **项目来源**：本项目源自 [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php)，继承其 attributes 注解体系与配置结构，并在此基础上移除了 doctrine/annotations 旧注解依赖、仅支持 PHP 8 attributes（PHP >= 8.0），由 erikwang2013 持续维护与扩展。

### ✨项目说明

- **开箱即用**：无需繁杂配置，安装后按文档编写 attributes 即可自动生成 API 文档。
- **轻松编写**：支持通用注释定义（definitions）、数据表字段的 `ref` / `table` 引用，几句注解即可完成完整字段定义。
- **在线调试**：文档页内直接调试接口，支持全局参数带入、Mock 数据、前置/后置调试事件。
- **任意协议**：用 `Protocol` / `Channel` / `Debug` 注解声明 MQTT、STOMP、gRPC、WebSocket、SSE、TCP/UDP 等协议，详情页生成对应调试面板；`Definition` 可解析 OpenAPI、protobuf、WSDL 等本地定义。
- **多应用/多版本**：单应用、多应用、多版本项目均可配置，接口按应用/版本分组展示与切换。
- **分组/Tag**：控制器与接口支持多级分组与 Tag 标记。
- **Markdown 文档**：可将 `.md` 文件挂载为文档页。
- **Json/TypeScript 生成**：每个接口自动生成 Json 请求/响应示例与 TypeScript 类型定义，直接用于前端。
- **代码生成器**：配置 + 模板即可生成业务代码、数据表与前端 Api 文件。
- **接口分享**：可生成指定应用/接口的分享链接、导出 `swagger.json`。
- **安全访问**：支持全局密码与应用/版本独立密码授权，可开启文档缓存。

## 🚀快速开始

安装：

```bash
composer require erikwang2013/apidoc-php
```

配置应用目录与通用定义（不同框架的配置发布方式略有差异，详见下方使用文档“框架接入”；ThinkPHP 为例，编辑 `config/apidoc.php`）：

```php
return [
    'apps' => [
        ['title' => 'Api接口', 'path' => 'app\controller', 'key' => 'api'],
    ],
    'definitions' => "app\common\controller\Definitions",
];
```

在控制器中通过 PHP 8 attributes 编写接口注解：

```php
use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("用户")]
#[Apidoc\Desc("用户相关接口")]
class User
{
    #[Apidoc\Method("GET")]
    #[Apidoc\Url("/user/info")]
    #[Apidoc\Query(name: "id", type: "int", require: true, desc: "用户ID")]
    #[Apidoc\Returned(name: "nickname", type: "string", desc: "昵称")]
    public function info()
    {
        // ...
    }
}
```

访问 `http://你的域名/apidoc` 即可查看自动生成的接口文档（在线调试、Mock、Json/TypeScript 生成等能力开箱即用）。

## 🔌任意协议调试

除 HTTP 接口外，还可以用 `#[Protocol]` / `#[Channel]` / `#[Debug]` 注解为 MQTT、STOMP、gRPC、TCP/UDP 等任意协议生成调试面板：HTTP/WebSocket/SSE 由浏览器直连，其余协议经服务端调试中继执行。中继**默认关闭**，开启后要求鉴权，目标只能来自接口注解（客户端无法修改），并受超时、大小、速率与会话数限制。

![任意协议调试：浏览器直连、协议注解与服务端中继](assets/protocol-debug.svg)

```php
#[Apidoc\Protocol('mqtt')]
#[Apidoc\Channel('/sensors/+/temp', action: 'publish')]
#[Apidoc\Debug(driver: 'mqtt', target: 'tcp://broker:1883', encoding: 'json', meta: ['qos' => 1])]
#[Apidoc\Param(name: 'value', type: 'number', require: true)]
public function publishTemperature() {}
```

确需服务端中继时在配置中开启（以下均为默认值）：

```php
'protocols' => [
    'debug' => [
        'relay_enable' => false,          // 默认关闭，确认鉴权与目标后再开启
        'relay_require_auth' => true,
        'rate_limit_per_minute' => 60,
        'max_timeout' => 30,
        'max_bytes' => 1048576,
        'max_sessions' => 4,
    ],
],
```

### 协议覆盖与回退

P1 已有专用驱动的协议使用专用面板，其余协议回退到通用面板（`webrtc`/`webrtc-datachannel` 除外）。**通用面板不实现协议语义**：`raw` 只按 `text`/`hex`/`base64` 发送原始字节，HTTP 中继只发普通 HTTP 请求，浏览器 WebSocket 只收发 WebSocket 帧。

| 协议 id | P1 专用驱动 | P2 通用回退 |
| - | - | - |
| `http` `rest` `soap` `graphql` `json-rpc` `xml-rpc` `webhooks` `odata` `mcp` `a2a` | `http`（浏览器 HTTP 面板） | — |
| `websocket` | 浏览器 WebSocket | — |
| `sse` | 浏览器 EventSource | — |
| `mqtt` `stomp` | `mqtt` / `stomp`（发布单次中继、订阅流式） | — |
| `coap` `modbus-tcp` `iso8583` `rtsp` `sip` `hls` `grpc` `hessian` | 同名专用驱动 | — |
| `tcp` `udp` `unix-socket` `raw-socket` | `raw` | — |
| `thrift` `dubbo` `amqp` `kafka` `xmpp` `rtmp` `opc-ua` `dnp3` `iec-104` `lwm2m` `rtp` `rtcp` `bacnet-ip` | 无 | `raw`（TCP/UDP/UDS 文本/hex/base64） |
| `binary-body` | 无 | `http-relay`（单次 HTTP 中继） |
| `websocket-binary` | 无 | 浏览器 WebSocket |
| `socket.io` | 无 | 浏览器 WebSocket 原始回退（Engine.IO 驱动仍为 P2） |
| `stomp-binary` | 无 | `stomp`（发布/发送单次中继，接收流式） |
| `http2` `http3` | 无 | 浏览器 HTTP（不实现 HTTP/2、HTTP/3 语义） |
| `webrtc` `webrtc-datachannel` | 无 | 无（需手动 SDP/信令，P2） |

注解参数、调试模式与中继安全说明见 [Attributes 使用说明](docs/ATTRIBUTES.md) 的「任意协议调试」。

### 协议定义解析

`#[Definition]` 可读取本地 `.proto`、`.wsdl`、`.graphql`、OpenAPI/AsyncAPI（JSON/YAML）、JSON Schema、JSON-RPC/XML-RPC 定义和 OData `$metadata`，解析出操作、消息与字段，并在详情页展示定义校验/样例校验结果。支持本地 import 与 `$ref`，只读本地文件、不发起网络请求；文件大小、行数、递归深度和 import 数有上限。P1 未覆盖的 `thrift`/`dubbo` 等仍可用 raw 调试面板。

```php
#[Protocol('grpc')]
#[Channel('helloworld.Greeter/SayHello', action: 'call')]
#[Definition(file: 'proto/helloworld.proto', operation: 'helloworld.Greeter/SayHello')]
public function sayHello() {}
```

支持的格式、参数、限制与配置见 [Attributes 使用说明](docs/ATTRIBUTES.md) 的「协议定义解析」。


## ⚡性能预算

文档读接口（菜单、详情、配置、Markdown、分享列表）在缓存命中时的本机 CPU 处理目标为 **P95 < 10ms**；协议定义解析/渲染同样纳入该预算。墙钟时间还受宿主 IO 与调度影响，测试会一并打印。运行 `php tests/performance.php` 验证。

- 预算不含 PHP-FPM/框架启动、HTTP 服务器与客户端网络耗时
- 调试中继的外部目标往返耗时不可控，预算只约束本机处理部分
- 首次冷启动扫描、`createAllCache`、生成器、代码模板等批量/写操作不属于读接口预算；生产环境建议开启 `cache.enable` 并预热

## 📌兼容

以下框架已内置兼容，可开箱即用

| 框架     | 版本     | 说明                                 |
| -------- |--------| ------------------------------------ |
| ThinkPHP | \>=5.1 |                                      |
| Webman   | \>=1.x |                                      |
| Laravel  | \>=8.x | 低于 Laravel8 版本未测试，可自行尝试 |
| Hyperf   | \>=2.x |                                      |
| Yii2     | \>=2.0  | 需开启 urlManager 的 enablePrettyUrl，手动调用 `Yii2Service::register()` |
| Yii3     | \>=3.0  | 手动调用 `Yii3Service::register($container, $config)` |


## 📖使用文档

[Attributes 使用说明](docs/ATTRIBUTES.md)

（安装与框架接入、注解写法教程、参数说明）


## 🏆支持我们

如果本项目对您有所帮助，请点个Star支持我们

- [Github](https://github.com/erikwang2013/apidoc-php) [![Star on GitHub](https://img.shields.io/github/stars/erikwang2013/apidoc-php?style=social)](https://github.com/erikwang2013/apidoc-php)

<!-- pet-expressions:start -->
## 项目宠物表情形态 · Apidoc

<table align="center">
<tr><td align="center"><img src="docs/pet-expressions/smile.svg" width="88" alt="笑"><br><b>笑</b><br><code>:emoji-smile:</code></td><td align="center"><img src="docs/pet-expressions/joy.svg" width="88" alt="欢乐"><br><b>欢乐</b><br><code>:emoji-joy:</code></td><td align="center"><img src="docs/pet-expressions/cry.svg" width="88" alt="哭"><br><b>哭</b><br><code>:emoji-cry:</code></td><td align="center"><img src="docs/pet-expressions/depressed.svg" width="88" alt="沮丧"><br><b>沮丧</b><br><code>:emoji-depressed:</code></td><td align="center"><img src="docs/pet-expressions/terrified.svg" width="88" alt="惊恐"><br><b>惊恐</b><br><code>:emoji-terrified:</code></td><td align="center"><img src="docs/pet-expressions/angry.svg" width="88" alt="愤怒"><br><b>愤怒</b><br><code>:emoji-angry:</code></td></tr>
<tr><td align="center"><img src="docs/pet-expressions/coquettish.svg" width="88" alt="撒娇"><br><b>撒娇</b><br><code>:emoji-coquettish:</code></td><td align="center"><img src="docs/pet-expressions/cute.svg" width="88" alt="卖萌"><br><b>卖萌</b><br><code>:emoji-cute:</code></td><td align="center"><img src="docs/pet-expressions/pleasing.svg" width="88" alt="讨好"><br><b>讨好</b><br><code>:emoji-pleasing:</code></td><td align="center"><img src="docs/pet-expressions/knocking.svg" width="88" alt="敲打"><br><b>敲打</b><br><code>:emoji-knocking:</code></td><td align="center"><img src="docs/pet-expressions/facepalm.svg" width="88" alt="捂脸"><br><b>捂脸</b><br><code>:emoji-facepalm:</code></td><td align="center"><img src="docs/pet-expressions/tongue.svg" width="88" alt="吐舌头"><br><b>吐舌头</b><br><code>:emoji-tongue:</code></td></tr>
</table>

> 12 种彩色动态形态：笑 / 欢乐 / 哭 / 沮丧 / 惊恐 / 愤怒 / 撒娇 / 卖萌 / 讨好 / 敲打 / 捂脸 / 吐舌头。每个 SVG 自带表情、情绪特效与整体动效，并遵循 `prefers-reduced-motion`。
<!-- pet-expressions:end -->

<!-- assets-license:start -->
## 视觉资产授权 / Visual Assets License

本项目的项目宠物、图片、图标、表情（包括 `docs/pet-expressions/` 等目录下的
SVG / PNG）**不是开源资产**，版权归作者所有。未经书面许可不得复制、修改、再分发或
用于商业用途；商业使用需取得商业授权，联系 **erik@erik.xyz**。详见
[ASSETS-LICENSE.md](ASSETS-LICENSE.md)。

> 代码部分仍遵循仓库原有 `LICENSE`；第三方素材（如明确标注的 Go Gopher 等）仍遵循其原许可证。
<!-- assets-license:end -->

