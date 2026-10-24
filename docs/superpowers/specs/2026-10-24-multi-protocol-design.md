# 多协议 API 文档支持设计

日期：2026-10-24
仓库：`/home/wwwroot/erikwang2013/apidoc-php` + `/home/wwwroot/erikwang2013/apidoc-ui`

## 1. 意图

apidoc-php 目前只描述 HTTP 接口：`Method` + `Url` + `Param`/`Returned`，UI 用 axios 调试。
本设计让同一套文档体系可以描述并**真正解析/校验** HTTP/2、HTTP/3、REST、SOAP、GraphQL、
WebSocket、SSE、Socket.IO、WebRTC、gRPC、Dubbo、Thrift、JSON-RPC、XML-RPC、Hessian、
AMQP、MQTT、STOMP、Kafka、Webhooks、TCP、UDP、CoAP、LwM2M、XMPP、SIP、RTSP、RTMP、HLS、
MCP、A2A、ISO 8583、OData、Raw Socket、Unix Domain Socket、二进制 Body、OPC UA、
Modbus TCP、DNP3、IEC 60870-5-104、BACnet/IP、RTP/RTCP 等协议。

“真正解析/校验”指两件事：

1. **定义校验**：读取 `.proto`、`.thrift`、`.wsdl`、`.graphql`、AsyncAPI、OpenAPI、JSON Schema、
   $metadata、OMA XML、SDP、m3u8、二进制布局描述等，解析出操作与消息结构，并报告语法/语义错误。
2. **样例校验**：给一段请求/响应/事件样例（JSON、XML、文本帧、hex/base64 二进制），按定义校验结构。

同时，**任意协议的接口都要有可用的调试面板**：像现在的 REST 一样，在文档页填参数、发请求、看响应。
调试执行分两条路径（D15）：HTTP/WebSocket/SSE 由浏览器直连；浏览器发不了的协议由 apidoc-php
的受控 relay 代发。协议专用驱动按 D19 的矩阵尽量做，没有专用驱动的协议退化为 raw TCP/UDP 面板。

## 2. 目标 / 非目标 / 反目标

### 目标

- 一条接口可声明：协议、协议版本、传输、动作、通道地址、消息方向、调试方式。
- 定义来源支持两种，且可混用：PHP 8 attributes 内联；外部定义文件引用。
- 所有协议归一成同一份 JSON 契约，后端一次输出，UI 按协议渲染。
- 校验错误在文档中可见，但**不阻塞**文档渲染，不抛 500。
- 任意协议接口在文档页都有调试面板：填参数、发请求、看响应；HTTP/WS/SSE 浏览器直连，
  其余协议走受控 relay。
- 协议专用驱动尽量做（D19 矩阵）；没有专用驱动的协议用 raw TCP/UDP/UDS 面板发 hex/text。
- 零新增 composer/npm 运行时依赖；保持 PHP 8.0 兼容。
- 现有 HTTP 接口的输出、UI、调试行为完全向后兼容。

### 非目标

- 不实现完整的协议服务端/客户端栈；专用驱动只覆盖“发一次、看响应/收一段消息”的调试场景。
- 不追求 100% 规范一致性：HPACK/QPACK、完整 XSD、完整 YAML、完整 GraphQL 执行语义不做。
- 不做开放代理：relay 只能发往注解声明的目标，默认关闭，必须鉴权。
- 不自动从网络拉取定义文件；P0+P1 只读本地文件系统，没有远程模式。
- WebRTC 只做浏览器直连的手动 SDP/DataChannel 面板，不自建 STUN/TURN/信令服务。

### 反目标

- 不为每个协议各写一套详情页和字段表。
- 不在每次请求里无上限解析大文件或递归结构。
- 不因一个坏定义文件让某个应用的菜单或详情整体失败。
- 不引入“只有以后才会用”的抽象层；适配器接口只保留 parse/validate 两个动作。

## 3. 约束

- PHP `^8.0`，不使用 enum/readonly/never 等 8.1+ 语法。
- 后端无 composer 运行时依赖；解析器手写常用子集。
- UI 无新增运行时依赖；复用 ant-design-vue、现有 TableCard/Markdown/CodeEditor。
- 两个仓库通过 JSON 契约解耦；后端先定契约，UI 只依赖契约。
- UI 改动直接落在 `apidoc-ui` 仓库并单独提交；后端与 UI 各一个提交序列。
- 定义文件路径必须落在允许根目录内；默认根为 `APIDOC_ROOT_PATH`。
- WSDL/XSD/LwM2M/XMPP 适配器依赖 `ext-dom`；扩展缺失时返回明确 validation error，不抛异常。
  `composer.json` 用 `suggest` 声明 `ext-dom`，不设为硬性 `require`，避免影响只用 HTTP 文档的用户。
- 开发环境 PHP 8.5.6（`dom/libxml` 可用，无 `yaml`）；代码语法仍保持 PHP 8.0 兼容。
- relay 默认关闭；开启后必须走现有鉴权，目标只能来自注解声明，请求体不能覆盖目标主机/端口。
- relay 有超时、请求/响应大小、并发、速率限制；响应只回传文本/JSON/hex，不落盘。

## 4. 关键决策

- **D1 双入口**：`#[Protocol]`/`#[Channel]`/`#[Definition]` 三个通用注解，既可写在方法上，也可写在类上；
  类级声明被方法继承，合并规则见第 7 节（`Protocol`/`Channel` 字段级覆盖，`Definition` 追加/同键替换）。
- **D2 家族化解析器**：按“定义格式”而不是按“协议名”实现适配器。例如 gRPC 与 Dubbo Triple 共用
  `proto` 适配器；MQTT/AMQP/Kafka 共用 `asyncapi` 与二进制帧描述符；MCP/A2A/Webhooks 共用 `json-schema`。
- **D3 二进制布局 DSL**：工业/二进制协议没有统一 IDL，用一份 JSON 布局描述（字节序、位宽、长度域、
  TLV、校验和）驱动通用校验器；标准协议再附内置帧描述符。
- **D4 校验不阻塞**：解析失败返回 `validation.errors`，同时保留注解里的参数表；详情页照常打开。
- **D5 解析缓存**：按 `格式 + 真实路径 + mtime + size + operation/message 选项` 缓存到现有 Cache，
  定义文件变更自动失效。
- **D6 契约先行**：先落地输出契约和 UI 类型，再逐个加适配器；每个适配器只新增一个文件 + 一组 fixture。
- **D7 任意协议可调试**：一条接口只要声明了 `Protocol`/`Channel`/`Url`，就生成调试面板。
  HTTP/WebSocket/SSE 用浏览器原生能力直连；浏览器发不了的协议走 relay。没有专用驱动的协议
  用 raw TCP/UDP/UDS 面板发文本/hex，保证“任意协议”都有入口。
  定义文件解析出的消息可以预填表单，但表单字段最终以 `Param`/`Returned` + 驱动字段为准。
- **D8 缓存指纹**：详情响应缓存里记录所有定义文件（含 import 图）的 `realpath+mtime+size` 指纹；
  命中缓存时先 stat 指纹，任一变化/缺失即视为未命中并重新解析。`v2` 前缀只负责一次性换契约。
- **D9 内联来源**：现有 `Param`/`Returned` 就是内联来源；没有 `Definition` 文件时，归一化产出
  `definitions[]` 中一项 `format:"inline", source:"inline"`，request 消息来自 `Param`，
  response 消息来自 `ResponseSuccess/ResponseError`。文件与内联可以同时存在。
- **D10 跨文件引用**：P1 支持本地 import —— proto `import`、OpenAPI/AsyncAPI/JSON Schema `$ref`
  到本地文件、WSDL/XSD 本地 import/include、GraphQL `#import "..."`（thrift `include` 随 P2 实现）。
  import 目标同样受 `allowed_roots`、大小、深度限制，并计入 D8 指纹；`google/protobuf/*.proto`
  不内置，按普通本地文件解析；找不到时返回 error（strict=false 降级 warning）。
- **D11 strict 语义**：`strict=true`（默认）把不支持的语法/构造记为 error；`strict=false` 降级为
  warning 并返回已解析的部分结果；语法错误始终是 error，不会静默吞掉。
- **D12 路径与远程**：`protocols.allowed_roots` 为有序根目录数组，`Definition.file` 用相对路径，
  按顺序取第一个存在的文件；禁止绝对路径。`source` 返回声明时的相对路径，不返回 realpath。
  不提供 `allow_remote` 配置，P0+P1 完全不发网络请求。
- **D13 Webhook 签名**：P1 只在 JSON Schema 的 `x-webhook-signature` 扩展里记录签名头名称/算法/
  编码，不做存在性检查，也不做 HMAC 实算（没有密钥来源）；签名样例校验与重放窗口留到 P4。
- **D14 调试能力规则**：所有声明了 `Protocol`/`Channel`/`Url` 的接口都有调试面板。
  `debuggable=false` 只表示“暂时发不出去”（没有目标、relay 未开启、协议 P2 才有驱动），
  用 `debugReason` 说明；面板仍然显示字段和代码/原始报文入口。
- **D15 混合执行**：`browser-http` 用 axios；`browser-websocket` 用原生 WebSocket；
  `browser-sse` 用原生 EventSource；其余走 `relay-unary`（POST 一次返回一次）或
  `relay-stream`（长轮询回传消息流）。UI 只认 `debug.mode`，不写协议分支。
- **D16 统一调试契约**：详情新增 `debug` 对象：`mode/driver/relayEnabled/fields/defaults/
  responseEncoding/hints`。`fields` 复用现有参数项形状，额外 `in` 表示 target/header/query/body/meta。
  驱动返回自己的字段定义，UI 通用渲染；加协议只加驱动，不改 UI。
- **D17 受控 relay**：默认关闭；开启后必须鉴权。目标只能来自接口注解的 `Debug.target` 或
  `Channel.address`/`Url`，客户端不能改目标主机/端口。带超时、请求/响应大小、并发、速率限制。
- **D18 驱动注册表**：`DebugDriverInterface` 提供 `mode()/fields()/send()`。专用驱动实现协议报文，
  通用 `raw` 驱动负责 TCP/UDP/UDS 文本/hex。驱动不依赖 composer 包。
- **D19 驱动矩阵**：P1 做 HTTP 家族、WebSocket、SSE、raw TCP/UDP/UDS、HTTP-relay、MQTT、STOMP、
  CoAP、Modbus TCP、ISO 8583、RTSP、SIP、gRPC unary、Hessian、HLS；P2 做 AMQP、Kafka、Dubbo
  遗留协议、Thrift、Socket.IO、XMPP、RTMP、DNP3、IEC 104、BACnet、OPC UA、LwM2M、RTP/RTCP、
  WebRTC 手动信令。Kafka/AMQP 订阅、MQTT/STOMP 订阅在 P1 用 `relay-stream` 长轮询回传。
- **D20 流式调试**：`relay-stream` 由 UI 先 `POST /debug/start` 拿 sessionId，再循环
  `POST /debug/poll`；每次 poll 最多保持上游连接 25s，返回这段时间收到的新消息后结束，UI 立即
  再次 poll。session 默认 60s 过期，可显式 stop。用长轮询而不是 SSE/WebSocket，避免
  Laravel/ThinkPHP/Webman 的流式响应差异和常驻服务。
  MQTT 用 `cleanSession=false` + 固定 clientId 减少轮询间隙丢消息；STOMP/Kafka/AMQP 的间隙
  丢失在面板上明确提示。

## 5. 架构

后端新增目录：

```
src/annotation/Protocol.php          # 协议身份：name/version/transport/mode
src/annotation/Channel.php           # 通道：address/action/meta
src/annotation/Definition.php        # 定义文件：file/format/operation/message/direction/sample
src/annotation/Debug.php             # 调试方式：driver/target/encoding/timeout/meta/fields
src/protocol/ProtocolCatalog.php     # 全部协议的元数据（label/transport/http/debuggable/mode）
src/protocol/debug/DebugDriverInterface.php
src/protocol/debug/DebugRegistry.php
src/protocol/debug/DebugProxy.php    # 目标校验、鉴权后执行、统一响应
src/protocol/debug/Driver/RawDriver.php
src/protocol/debug/Driver/HttpRelayDriver.php
src/protocol/debug/Driver/MqttDriver.php
src/protocol/debug/Driver/StompDriver.php
src/protocol/debug/Driver/CoapDriver.php
src/protocol/debug/Driver/ModbusTcpDriver.php
src/protocol/debug/Driver/Iso8583Driver.php
src/protocol/debug/Driver/RtspDriver.php
src/protocol/debug/Driver/SipDriver.php
src/protocol/debug/Driver/GrpcDriver.php
src/protocol/debug/Driver/HessianDriver.php
src/protocol/debug/Driver/HlsDriver.php
src/protocol/ProtocolRegistry.php    # 格式注册、自动识别、缓存、错误聚合
src/protocol/Support/Yaml.php        # YAML 子集
src/protocol/Support/SafeXml.php     # DOM 安全封装（禁外部实体）
src/protocol/Support/TokenStream.php # proto/thrift/graphql 共用词法流
src/protocol/Support/BinaryReader.php# 有界二进制读取
src/protocol/Support/BinaryLayout.php# 二进制布局 DSL 校验器
src/protocol/Adapter/*.php           # 每个定义格式一个适配器
src/parses/ParseProtocol.php         # 注解 -> 定义解析 -> 归一化输出
```

`ParseProtocol` 由 `ParseApiDetail::parseApiMethod()` 调用一次，结果合并进现有返回数组。
`ParseApiMenus` 只多取 `protocol`/`channel` 两个键用于菜单徽章与筛选。

UI 新增：

```
src/components/ProtocolBadge/                 # 协议徽章（label + transport）
src/pages/apiDetail/ProtocolTab/Index.vue     # 定义源、操作、消息、校验结果
src/pages/apiDetail/ProtocolTab/MessageTable.vue # 复用 TableCard 的字段表
src/pages/apiDetail/DebugTab/ProtocolDebug.vue   # 通用协议调试面板
src/pages/apiDetail/DebugTab/drivers/*.ts        # 浏览器 http/ws/sse 执行器 + relay 调用
src/pages/apiDetail/DebugTab/ResponseLog.vue     # 单次响应/消息流日志
```

## 6. 输出契约

`getApiDetail` 在现有字段上新增（全部可选，旧 UI 忽略即可）：

```json
{
  "protocol": {
    "name": "grpc",
    "label": "gRPC",
    "version": "1.0",
    "transport": "http2",
    "mode": "unary",
    "http": false,
    "debuggable": false,
    "debugReason": "gRPC 暂不支持浏览器内调试，请使用生成的代码片段"
  },
  "channel": {
    "address": "helloworld.Greeter/SayHello",
    "action": "call",
    "meta": {}
  },
  "debug": {
    "mode": "relay-unary",
    "driver": "grpc",
    "relayEnabled": true,
    "fields": [
      {"name": "target", "in": "target", "type": "string", "require": true, "default": "localhost:50051"},
      {"name": "name", "in": "body", "type": "string", "require": true, "desc": "Name"}
    ],
    "defaults": {"target": "localhost:50051", "encoding": "json", "timeout": 5},
    "responseEncoding": "json",
    "hints": "unary call"
  },
  "definitions": [
    {
      "format": "proto",
      "source": "tests/fixtures/protocol/helloworld.proto",
      "operation": "helloworld.Greeter/SayHello",
      "meta": {},
      "messages": [
        {
          "name": "HelloRequest",
          "direction": "request",
          "fields": [
            {"name": "name", "type": "string", "require": true, "fieldNumber": 1, "desc": ""}
          ],
          "sample": "",
          "sampleValidation": {"ok": true, "errors": [], "warnings": []}
        }
      ],
      "validation": {"ok": true, "errors": [], "warnings": []}
    }
  ],
  "validation": {"ok": true, "errors": [], "warnings": []}
}
```

- `messages[].fields[]` 复用现有 `ApiDetailParamItem` 形状，额外键（`fieldNumber`、`wireType`、
  `bitOffset`、`bits`、`length`、`encoding`、`endian`、`enum`、`oneof`、`map`）原样透传。
- `definitions[].meta` 为可选协议扩展信息；P1 只有 Webhook 的 `x-webhook-signature` 放这里，
  形状为 `{header,algorithm,encoding}`。
- 校验错误形状：`{path, line?, column?, severity: "error"|"warning", message}`。
  定义错误放在 `definitions[].validation`；样例错误放在 `messages[].sampleValidation`，
  不混入定义校验；顶层 `validation` 只聚合定义错误。
- `protocol.http=true` 时 UI 继续显示现有 method + url 行；`false` 时显示 protocol/action/channel。
- `protocol.http` 规则：REST/OpenAPI、SOAP、GraphQL、JSON-RPC、XML-RPC、OData、MCP(HTTP)、
  A2A(HTTP)、HTTP/1.1 为 `true`；Webhooks 为 `false`（回调方不可控）；HTTP/2、HTTP/3 为 `true`
  但 `debuggable=false`（axios/浏览器不能强制版本）。
- 菜单项为避开与详情对象重名，使用 `protocolKey`（协议 id）、`protocolLabel`（已本地化标签）、
  `channelAddress`（地址）三个标量键；详情页使用 `protocol`/`channel` 对象，不改 `ApiMenuItem` 现有字段。
- `debug.mode` 取 `browser-http|browser-websocket|browser-sse|relay-unary|relay-stream`；
  `debug.driver` 取 `http|websocket|sse|raw|http-relay|mqtt|stomp|coap|modbus-tcp|iso8583|rtsp|sip|grpc|hessian|hls`。
- `debug.fields[]` 复用 `ApiDetailParamItem` 形状，额外 `in` 取 `target|header|query|body|meta`；
  `target` 字段只用于展示默认目标，客户端提交时后端仍以注解声明为准。
- `relayEnabled=false` 时，`relay-*` 面板仍显示字段，但发送按钮禁用并显示原因。

## 7. 注解

```php
use erikwang2013\apidoc\annotation\{Protocol, Channel, Definition};

#[Protocol(name: 'grpc', version: '1.0', transport: 'http2', mode: 'unary')]
#[Channel(address: 'helloworld.Greeter/SayHello', action: 'call')]
#[Definition(file: 'proto/helloworld.proto', operation: 'helloworld.Greeter/SayHello')]
public function sayHello() {}
```

```php
#[Protocol(name: 'mqtt', transport: 'tcp', mode: 'pub_sub')]
#[Channel(address: 'sensors/+/temp', action: 'subscribe', meta: ['qos' => 1])]
#[Definition(file: 'asyncapi.yaml', operation: 'receiveTemperature', direction: 'event')]
public function onTemperature() {}
```

- `Protocol`：`name` 必填；`version`/`transport`/`mode` 可选，缺省从 `ProtocolCatalog` 推导。
- `Channel`：`address` 必填；`action` 取 `call|publish|subscribe|send|receive|request|response|notify|webhook`；
  `meta` 放协议专属信息（exchange、routingKey、qos、service、method 等）。
- `Definition`：`file` 可选；没有文件时用 `format:"inline"`（消息来自 `Param`/`Returned`）或
  内置 `frame`/`binary` 描述符。`format` 缺省 `auto`（按扩展名 + 内容嗅探）；`operation`/`message`
  选择子项；`direction` 提示消息方向；`sample`/`sampleFile` 提供样例；`sampleEncoding` 取
  `hex|base64|text`（二进制格式默认 `hex`）；`strict` 默认 `true`。
- `Debug`：`driver` 取 `auto|http|websocket|sse|raw|http-relay|mqtt|stomp|coap|modbus-tcp|iso8583|
  rtsp|sip|grpc|hessian|hls`；`target` 是调试目标（URL 或 `host:port`/`unix:///path`）；
  `encoding` 取 `text|json|hex|base64`；`timeout` 秒；`meta` 放驱动专属默认值（qos、unitId、
  service/method、headers 等）；`fields` 可追加驱动字段。`driver:'auto'` 按协议目录推导。
  目标为空时从 `Channel.address`/`Url` 推导；无法推导则 `debuggable=false`。

```php
#[Protocol('mqtt')]
#[Channel('/sensors/+/temp', action: 'publish')]
#[Debug(driver: 'mqtt', target: 'tcp://broker:1883', encoding: 'json', meta: ['qos' => 1])]
#[Param(name: 'value', type: 'number', require: true)]
public function publishTemperature() {}
```
- 三个注解都支持 `TARGET_CLASS | TARGET_METHOD`；`Definition` 可重复。
- **方法收录门禁不变**：方法必须至少有一个方法级 apidoc 注解才进入文档；类级 `Protocol`/`Channel`/
  `Definition` 只对被收录的方法继承。这样不会把类里所有 public 方法意外暴露出去。
- **合并规则**：`Protocol`/`Channel` 按字段覆盖（方法级非空字段优先，空字段回退类级）；
  `Definition` 类级项继承 + 方法级项追加，同 `file+operation+message` 时方法级替换类级。
- 非 HTTP 协议且未写 `Url` 时，`url` 自动取 `channel.address`，保证旧菜单/搜索/导出仍可用。
- 非 HTTP 协议且未写 `Method` 时，`method` 保持现有默认值 `*`；UI 依据 `protocol.http` 决定是否显示。

## 8. 协议 → 解析机制映射

| 协议 | 定义/校验方式 | 适配器 |
|---|---|---|
| REST | OpenAPI 3.0/3.1（JSON/YAML 子集） | `openapi` |
| SOAP | WSDL 1.1 + XSD 子集；SOAP Envelope 用 DOM 校验 | `wsdl` |
| GraphQL | SDL + 查询文档子集 | `graphql` |
| gRPC / Dubbo Triple | `.proto` 子集 + protobuf wire 校验 | `proto` |
| Dubbo（非 Triple） | Java 接口源码子集（package/interface/method/注解） | `java-interface` |
| Thrift | `.thrift` 子集 | `thrift` |
| OData | `$metadata` EDMX XML + 基础查询选项校验 | `edmx` |
| Webhooks / MCP / A2A | JSON Schema draft-07 子集；Webhook 只记录 `x-webhook-signature` 声明 | `json-schema` |
| MQTT / AMQP / Kafka / Socket.IO | AsyncAPI 2/3（JSON/YAML 子集）+ 内置帧描述符 | `asyncapi` / `frame` |
| STOMP | STOMP 帧文本（COMMAND/headers/body/NUL） | `stomp` |
| SSE | `text/event-stream` 行解析（data/event/id/retry） | `sse` |
| WebSocket | 握手元数据 + 文本消息 JSON Schema + 二进制帧描述符 | `json-schema` / `frame` |
| XMPP | XML stanza 子集（message/presence/iq） | `xmpp` |
| SIP | RFC 3261 消息行 + 头 + SDP body | `sip` |
| RTSP | RFC 2326 请求/响应行 + 头 + SDP body | `rtsp` |
| WebRTC / WebRTC DataChannel | SDP（RFC 4566）子集 + `webrtc-datachannel` 属性 | `sdp` |
| HLS | m3u8 master/media playlist | `m3u8` |
| RTMP | 握手/chunk 头子集 + AMF0 元数据子集 | `frame` |
| RTP / RTCP | 固定头字段校验（version/PT/seq/timestamp/SSRC） | `frame` |
| ISO 8583 | JSON 布局描述（MTI/bitmap/域类型/长度/BCD） | `iso8583` |
| CoAP | RFC 7252 头 + option TLV + payload schema | `frame` / `json-schema` |
| LwM2M | OMA LwM2M 对象 XML | `lwm2m` |
| OPC UA Binary | 消息头/chunk 子集 + 二进制布局 | `frame` |
| Modbus TCP | MBAP + PDU（功能码） | `frame` |
| DNP3 | 0x0564 起始 + 长度 + CRC + 链路层子集 | `frame` |
| IEC 60870-5-104 | APCI 0x68 + 长度/控制域 + ASDU 头子集 | `frame` |
| BACnet/IP | BVLC + NPDU/APDU 头子集 | `frame` |
| JSON-RPC | 2.0 报文结构 + 方法参数 schema | `json-rpc` |
| XML-RPC | methodCall/methodResponse XML 结构 | `xml-rpc` |
| Hessian | Hessian 2.0 序列化子集 | `hessian` |
| HTTP/2 / HTTP/3 | 版本/传输元数据 + 伪头规则；不做 HPACK/QPACK | `catalog` |
| HTTP/HTTPS Binary Body | `Definition(format: 'binary')` + Content-Type | `binary` |
| TCP / UDP / Raw Socket / UDS | 传输元数据 + payload schema（binary/json/text） | `transport` |

“frame” 不是单独格式，而是内置帧描述符集合，共用 `BinaryLayout` 引擎；协议名决定用哪份描述符。

### 8.1 调试驱动矩阵

| 阶段 | 驱动 | 执行方式 | 说明 |
|---|---|---|---|
| P1 | `http` | browser-http | REST/SOAP/GraphQL/JSON-RPC/XML-RPC/OData/MCP(HTTP)/A2A/Webhooks 模拟 |
| P1 | `websocket` | browser-websocket | 文本/二进制帧，连接/发送/日志 |
| P1 | `sse` | browser-sse | EventSource 事件日志 |
| P1 | `raw` | relay-unary | TCP/UDP/UDS，text/hex/base64 载荷 |
| P1 | `http-relay` | relay-unary | 浏览器 CORS 失败时的 HTTP 兜底 |
| P1 | `mqtt` | relay-unary / relay-stream | CONNECT/PUBLISH/SUBSCRIBE，QoS 0/1 |
| P1 | `stomp` | relay-unary / relay-stream | SEND/SUBSCRIBE 帧 |
| P1 | `coap` | relay-unary | CON 请求，基础 option/TLV |
| P1 | `modbus-tcp` | relay-unary | MBAP + 常用功能码读写 |
| P1 | `iso8583` | relay-unary | 长度前缀 + 原始报文/布局描述 |
| P1 | `rtsp` | relay-unary | OPTIONS/DESCRIBE/SETUP/PLAY 基础流程 |
| P1 | `sip` | relay-unary | OPTIONS/REGISTER/MESSAGE 基础请求 |
| P1 | `grpc` | relay-unary | HTTP/2 + gRPC 帧 + 动态 protobuf 编解码 |
| P1 | `hessian` | relay-unary | Hessian 2.0 编解码子集 |
| P1 | `hls` | browser-http / relay-unary | m3u8 + 分片 GET |
| P2 | `amqp` | relay-unary / relay-stream | 0-9-1 publish/consume |
| P2 | `kafka` | relay-unary / relay-stream | Produce/Consume 固定版本子集 |
| P2 | `dubbo` | relay-unary | 遗留 Dubbo 协议（Hessian2） |
| P2 | `thrift` | relay-unary | Binary/Compact 协议 |
| P2 | `socket.io` | relay-stream | Engine.IO + WebSocket |
| P2 | `xmpp` | relay-stream | XML 流 |
| P2 | `rtmp` | relay-unary | 握手 + AMF0 子集 |
| P2 | `dnp3` / `iec-104` / `bacnet-ip` | relay-unary | 链路/应用层子集 |
| P2 | `opc-ua` | relay-unary | SecureChannel 子集 |
| P2 | `lwm2m` | relay-unary | CoAP + TLV |
| P2 | `rtp` / `rtcp` | relay-stream | UDP 收包/发包 |
| P2 | `webrtc` | browser-webrtc | 手动 SDP/ICE + DataChannel |

没有专用驱动的协议一律落到 `raw` 面板：目标来自注解，载荷支持 text/hex/base64，响应按同样编码显示。
每个驱动自己声明表单字段；UI 不为协议写分支。

## 9. 二进制布局 DSL

```json
{
  "endian": "big",
  "fields": [
    {"name": "mti", "type": "ascii", "length": 4},
    {"name": "bitmap", "type": "bits", "length": 16},
    {"name": "pan", "type": "bcd", "length": 19, "when": "bitmap[2]"},
    {"name": "amount", "type": "bcd", "length": 12, "scale": 2, "when": "bitmap[4]"},
    {"name": "crc", "type": "u16", "checksum": "crc16-modbus", "over": "fields[0..n-1]"}
  ]
}
```

支持：`u8/u16/u24/u32/u64`、`i8..i64`、`f32/f64`、`ascii/utf8/bcd/hex/bytes`、定长/长度前缀/TLV、
bitmap 存在位、`crc16`/`crc32`/`sum8`、大小端。所有读取有边界检查；`when` 表达式仅支持
`bitmap[n]`、`field == value`、`field > value` 三种形式。

## 10. 校验、缓存与安全

- **校验时机**：详情请求时按需解析并缓存；`validation.errors` 不抛出，只返回。
- **strict**：`true` 时不支持构造记 error；`false` 时降级 warning 并返回部分结果；语法错误始终 error。
- **适配器缓存键**：`protocol:{format}:{root}:{relativePath}:{optionsHash}`，值为解析结果 + import 图
  指纹（每个文件 `realpath+mtime+size`）。命中后仍校验指纹，任一文件变化/缺失即重解析。
- **详情响应缓存**：`apiDetail` 缓存值附 `_definitionFingerprints`；`Controller` 命中后先 stat，
  不一致则重新调用 `ParseApiDetail`。因此改 `.proto`/WSDL 不需要手动清缓存。
- **契约版本**：`apiMenu`/`apiDetail` 缓存键加 `v2` 前缀，升级后一次性失效旧缓存。
- **路径**：`protocols.allowed_roots` 为有序数组，默认 `[APIDOC_ROOT_PATH]`；`Definition.file` 必须是
  相对路径，逐根查找第一个存在的文件；禁止绝对路径与 `..` 段；`source` 返回声明的相对路径，
  不泄露 realpath。import/include 目标同样走这套解析。
- **import 图**：proto/thrift/OpenAPI/JSON Schema/WSDL/XSD 本地 import 递归解析；循环引用按已访问集合
  截断；所有 import 计入大小/深度/行数限制与指纹。P0+P1 不发任何网络请求。
- **XML**：`LIBXML_NONET`，拒绝 DOCTYPE，禁用外部实体；WSDL/XSD 内联 import 只允许本地相对路径。
- **YAML**：只支持 mapping/sequence/scalar/注释/块标量；不支持锚点、别名、自定义 tag、多文档。
- **大小/深度**：`protocols.max_file_bytes` 默认 2MB，`max_depth` 默认 32，`max_lines` 默认 50000，
  `max_imports` 默认 64；超限返回 error，不解析。
- **二进制**：所有读取先判长度；长度域大于剩余字节直接报错；不按长度域预分配大内存。
- **正则**：不用可回溯的重型正则做语法解析；统一走 `TokenStream` 与逐行扫描。
- **relay 目标**：目标只能是注解声明的 `Debug.target`/`Channel.address`/`Url`；请求体里的 target
  字段仅用于 UI 显示，后端忽略。解析目标时拒绝 `file://`、`gopher://` 等非预期 scheme；
  HTTP-relay 只允许 http/https。
- **relay 开关**：`protocols.debug.relay_enable` 默认 `false`；`relay_require_auth` 默认 `true`。
  关闭时 `relay-*` 面板只展示不发；开启后仍走现有 `Auth` token/分享鉴权。
- **relay 限制**：`timeout` 默认 5s、最大 30s；请求/响应 `max_bytes` 默认 1MB；每 IP/会话
  每分钟请求数默认 60；并发默认 4。超限返回结构化错误，不抛 500。
- **relay 输出**：响应只回传文本/JSON/hex/base64 和元信息，不写磁盘、不回显服务器路径；
  UI 侧对文本做转义，代码块用纯文本渲染。
- **流式 session**：sessionId 随机、绑定 appKey+apiKey+用户 token、默认 60s 过期；
  `POST /debug/poll` 校验 session 后只转发该 session 的消息，不暴露任意上游；每次 poll 最多 25s。

## 11. UI 改动（apidoc-ui）

1. `src/api/apidocApi/types.ts`：新增 `ApiProtocolInfo`、`ApiChannelInfo`、`ApiDefinitionInfo`、
   `ApiMessageInfo`、`ApiValidationInfo`；`ApiDetailResult` 增加 `protocol`/`channel`/`definitions`/
   `validation` 可选对象，`ApiMenuItem` 增加 `protocolKey`/`protocolLabel`/`channelAddress` 可选标量。
   两者键名不同，避免 `ApiDetailResult extends ApiMenuItem` 的类型冲突。
2. `src/components/ProtocolBadge/*`：协议徽章，颜色/标签来自 `config/constant.ts`，未知协议用中性色。
3. `src/pages/apiDetail/index.vue`：
   - `protocol.http === false` 时，头部改为 `协议徽章 + action + channel`，channel 可复制；
   - `protocol` 存在时新增 `protocol` Tab；`debug` 存在时 debug Tab 展示通用协议面板；
     `debuggable=false` 时面板仍显示，发送按钮禁用并显示 `debugReason`；
   - HTTP 接口没有协议注解时渲染完全不变。
4. `src/pages/apiDetail/ProtocolTab/*`：定义源与格式、操作、消息方向子 Tab（request/response/event）、
   字段表（复用 TableCard）、校验错误列表（path/line/message）。
5. `src/components/Menu/src/MenuItemContent.vue`：有 `protocolKey` 时显示 `protocolLabel` 徽章
   （未知 key 回退为大写 key）；HTTP 接口仍显示原 method 徽章。
6. `src/utils/apidocExport/collect.ts`、`render.ts`：JSON 导出保留新字段；Markdown 导出增加协议/通道/消息段。
7. `public/config.js`：`LANG` 增加协议名、新 Tab、校验文案；`API_DETAIL_TABS` 追加 `protocol`。
8. `src/pages/apiDetail/DebugTab/Index.vue`：保留现有 HTTP 表单；当 `detail.debug` 存在时渲染
   `ProtocolDebug.vue`，按 `debug.mode` 分派：
   - `browser-http`：沿用现有 Params + axios，字段来自 `debug.fields`/`Param`。
   - `browser-websocket`：URL、连接/断开、消息输入（text/json/hex）、发送、消息日志。
   - `browser-sse`：URL、连接/断开、事件日志。
   - `relay-unary`：通用字段表单 + 发送按钮 + 响应查看器；`relayEnabled=false` 时禁用发送并提示。
   - `relay-stream`：开始/停止 session，循环 `POST /debug/poll` 拉消息并追加日志；到期自动续期。
   响应查看器统一显示状态、耗时、headers/meta、body（text/json/hex 可切换）。
9. `src/pages/apiDetail/DebugTab/drivers/*.ts`：只实现浏览器执行器（http/websocket/sse）与 relay
   调用（start/stream）；协议差异全部来自后端 `debug.fields`，不在 UI 写协议分支。

## 12. 兼容与迁移

- 未使用新注解的接口输出不变；旧 UI 忽略新增字段即可工作。
- 新 Tab 只在 `protocol` 字段存在时出现。
- 缓存契约版本升级会清空一次旧缓存；后端已有 `cancelAllCache`/`createAllCache` 可复用。
- `docs/ATTRIBUTES.md` 增补三个注解；README 增加一段“非 HTTP 协议”示例。
- 版本号遵循现有发布习惯，本次为 minor；适配器按阶段增量发布。

## 13. 交付阶段

- **P0 调试骨架**：`Protocol`/`Channel`/`Debug` 注解、Catalog、`ParseProtocol` 元数据、
  统一 `debug` 契约、驱动接口/注册表、受控 relay 端点与安全限制、UI 通用调试面板、
  浏览器 http/websocket/sse、relay raw TCP/UDP/UDS、协议徽章/头部。P0 完成后任意协议都有面板。
- **P1 专用驱动 + 定义解析**：HTTP-relay、MQTT（含订阅 SSE）、STOMP（含订阅 SSE）、CoAP、
  Modbus TCP、ISO 8583、RTSP、SIP、gRPC unary、Hessian、HLS；同时做 OpenAPI、WSDL、GraphQL、
  proto、AsyncAPI、JSON Schema、JSON-RPC、XML-RPC、EDMX 适配器，用于字段预填与校验。
- **P2 扩展驱动**：AMQP、Kafka、Dubbo 遗留协议、Thrift、Socket.IO、XMPP、RTMP、DNP3、IEC 104、
  BACnet、OPC UA、LwM2M、RTP/RTCP、WebRTC 手动信令；以及这些协议的定义适配器。
- **P3 收尾**：代码片段生成、Markdown/TypeScript 导出扩展、协议筛选、HTTP/2/3 元数据展示。

首个交付为 **P0 + P1 合并发布**：任意协议可声明、可调试，常用专用驱动与定义解析一起上线。
P2–P3 作为后续独立发布；P0 是后续所有期的硬前置。

## 14. 测试

- 后端沿用 `tests/smoke.php` 风格，新增 `tests/protocol.php`：纯 PHP、无 PHPUnit。
- fixtures 放 `tests/fixtures/protocol/<format>/`，每个适配器至少一组正例 + 一组反例；
  当前没有真实业务定义文件，P1 fixture 全部自造，覆盖子集内语法即可。
- 断言内容：解析出的 operation/message/字段、校验 ok/errors、错误行号、缓存命中。
- 安全用例：XXE、路径穿越、超大文件、超深嵌套、YAML 锚点、二进制长度越界。
- UI：`npm run build`（含 vue-tsc）必须通过；手工验证 HTTP 旧页面无回归 + 三种协议展示。
- 每个阶段结束跑一次 `php tests/smoke.php && php tests/protocol.php`。

## 15. 留给构建者的取舍（已定）

- YAML 只做子集，不实现锚点/别名/多文档。
- proto/thrift/GraphQL 只做文档化所需的语法子集；不支持自定义选项、扩展、宏。
- 二进制协议以“内置帧描述符 + 可选 payload schema”为准，不追求完整协议栈。
- 在线调试采用混合执行：HTTP/WS/SSE 浏览器直连，其余走受控 relay；没有专用驱动的协议用 raw 面板。
- relay 默认关闭、必须鉴权、目标只能来自注解；不做开放代理。
- P1 专用驱动按 D19 矩阵；gRPC/Hessian 尽量进 P1，Kafka/AMQP/OPC UA/WebRTC 等留 P2。
- 非 HTTP 接口不强行套用 HTTP method 语义；`method` 保持默认，UI 用 `debug.mode` 分支。
- P0+P1 只读本地文件系统，不提供远程 URL 开关。
- P1 支持本地 import 图（D10）；这是 P1 里工作量最大的一块，但真实 `.proto`/WSDL 几乎都带 import。
- Webhook 只记录签名头声明，不做存在性检查与 HMAC 实算（D13）。
- 类级注解不扩大方法收录范围，必须有方法级 apidoc 注解（D1/D6 补充）。
- 菜单与详情使用不同键名（`protocolKey` vs `protocol`），避免 `ApiDetailResult extends ApiMenuItem` 冲突。

## 16. 风险

- **子集边界**：真实世界的 `.proto`/WSDL 可能用到未支持语法，解析会报“不支持”而非静默错误；
  错误必须带行号，便于用户判断是定义问题还是解析器限制。
- **二进制描述符维护成本**：标准协议帧头会演进；描述符集中放置并带版本，便于单点更新。
- **UI 契约扩散**：新增字段必须保持可选；后端先发布，UI 后适配，避免旧 UI 崩。
- **性能**：大 WSDL/OpenAPI 解析有成本；缓存 + 2MB/深度限制兜底，必要时加请求级解析预算。
- **relay 安全**：调试代发是服务器侧主动连接能力，配置错误会变成 SSRF/内网探测入口；
  目标必须来自注解、默认关闭、鉴权 + 速率限制，文档需显著提示。
- **驱动维护**：专用驱动是手写协议子集，broker/服务端版本变化可能导致失败；驱动要返回可读错误，
  并保留 raw 面板作为兜底。gRPC/Dubbo/Kafka/OPC UA 的完整兼容不在 P1 承诺范围内。
- **流式 relay**：长轮询每次 25s 占用一个 PHP 进程；并发高会耗尽 FPM worker，必须限并发并
  写清楚部署要求。轮询间隙仍可能丢消息（MQTT QoS0/STOMP），面板要提示。
