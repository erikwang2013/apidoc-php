# Arbitrary-Protocol Debug Panel P0+P1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any annotated protocol endpoint appear in the docs with a debug panel that fills fields, sends a request, and shows the response — HTTP/WS/SSE directly from the browser, everything else through a controlled apidoc-php relay with protocol-specific drivers.

**Architecture:** `#[Protocol]`/`#[Channel]`/`#[Debug]` annotations produce a normalized `debug` object (`mode`, `driver`, `fields`, `defaults`, `relayEnabled`). The UI renders that object generically. Browser modes call native APIs; relay modes POST to `/debugSend` or start/poll a `/debug` session. The relay resolves the target from the annotation, never from the request body, and is disabled by default.

**Tech Stack:** PHP 8.0-compatible code, existing `Cache`/`Auth`/`Controller`, PHP stream sockets, curl for HTTP-relay/gRPC when available, Vue 3 + TypeScript + ant-design-vue, existing CodeEditor/TableCard components.

**Spec:** `docs/superpowers/specs/2026-10-24-multi-protocol-design.md`

## Global Constraints

- PHP `^8.0`; no enum/readonly/never.
- No new composer/npm runtime dependencies.
- Relay is disabled by default (`protocols.debug.relay_enable=false`), requires auth, and can only send to the annotation-declared target.
- Existing HTTP endpoints without new annotations keep their exact JSON shape and existing debug behavior.
- Never trust a client-supplied target/host/port. The relay always re-reads the endpoint annotation.
- Timeouts: 5s default / 30s max; request and response max 1MB each; 60 requests/minute/token; 4 sessions/token.
- UI verification: `npm run build`; backend verification: `php tests/smoke.php`, `php tests/debug.php`, `php tests/protocol.php`.

## File Map

Backend create:

- `src/annotation/Protocol.php`, `src/annotation/Channel.php`, `src/annotation/Debug.php`, `src/annotation/Definition.php`
- `src/protocol/ProtocolCatalog.php`
- `src/parses/ParseProtocol.php` (metadata + debug object; definition parsing lives in the definitions plan)
- `src/protocol/debug/DebugDriverInterface.php`
- `src/protocol/debug/StreamingDriverInterface.php`
- `src/protocol/debug/DebugRegistry.php`
- `src/protocol/debug/DebugProxy.php`
- `src/protocol/debug/DebugSession.php`
- `src/protocol/debug/DebugTarget.php`
- `src/protocol/debug/Driver/RawDriver.php`
- `src/protocol/debug/Driver/HttpRelayDriver.php`
- `src/protocol/debug/Driver/MqttDriver.php`
- `src/protocol/debug/Driver/StompDriver.php`
- `src/protocol/debug/Driver/CoapDriver.php`
- `src/protocol/debug/Driver/ModbusTcpDriver.php`
- `src/protocol/debug/Driver/Iso8583Driver.php`
- `src/protocol/debug/Driver/RtspDriver.php`
- `src/protocol/debug/Driver/SipDriver.php`
- `src/protocol/debug/Driver/HlsDriver.php`
- `src/protocol/debug/Driver/GrpcDriver.php`
- `src/protocol/debug/Driver/HessianDriver.php`
- `src/protocol/debug/Support/Protobuf.php`, `src/protocol/debug/Support/HttpClient.php`, `src/protocol/debug/Support/Hex.php`
- `tests/debug.php`, `tests/fixtures/debug/**`

Backend modify:

- `src/providers/BaseService.php` (four debug routes)
- `src/Controller.php` (`debugSend`, `debugStart`, `debugPoll`, `debugStop`)
- `src/parses/ParseApiDetail.php` (protocol/debug merge)
- `src/parses/ParseApiMenus.php` (protocol menu keys, strip internal fields)
- `src/config.php` (`protocols.debug` block)
- `composer.json` (`suggest` ext-dom/ext-curl)
- `docs/ATTRIBUTES.md`, `README.md`

UI create:

- `src/api/debugApi/index.ts`, `src/api/debugApi/types.ts`
- `src/pages/apiDetail/DebugTab/ProtocolDebug.vue`
- `src/pages/apiDetail/DebugTab/ProtocolFields.vue`
- `src/pages/apiDetail/DebugTab/ResponseLog.vue`
- `src/pages/apiDetail/DebugTab/drivers/browserHttp.ts`, `browserWebSocket.ts`, `browserSse.ts`, `relay.ts`

UI modify:

- `src/api/apidocApi/types.ts`
- `src/pages/apiDetail/index.vue`
- `src/pages/apiDetail/DebugTab/Index.vue`
- `src/components/Menu/src/MenuItemContent.vue`
- `src/components/ProtocolBadge/*`
- `public/config.js`

## Review Focus

- An existing HTTP endpoint with no `Protocol`/`Debug` annotation must show the same debug tab and send the same request as before.
- With `relay_enable=false`, a TCP/MQTT/gRPC panel must still render fields, but Send is disabled with a clear reason; no connection is attempted.
- A request body that changes `target`, `host`, or `port` must not change where the relay connects; only the annotation target is used.
- A raw TCP panel sending `text` and `hex` to a loopback echo server must show the decoded response and duration; timeouts return a structured error, not a 500.
- A stream session must stop on `debugStop`, expire after 60s, and reject a poll from a different auth token.

---

### Task 1: Annotations, Catalog, and Metadata

**Files:**
- Create: `src/annotation/Protocol.php`, `src/annotation/Channel.php`, `src/annotation/Debug.php`, `src/annotation/Definition.php`
- Create: `src/protocol/ProtocolCatalog.php`
- Create: `src/parses/ParseProtocol.php`
- Create: `tests/protocol.php`
- Create: `tests/fixtures/protocol/AnnotationFixtures.php`

**Interfaces:**
- Produces:
  - Attributes `Protocol` (`name`,`version`,`transport`,`mode`), `Channel` (`name`,`address`,`action`,`meta`), `Debug` (`driver`,`target`,`encoding`,`timeout`,`meta`,`fields`), `Definition` (`name`,`file`,`format`,`operation`,`message`,`direction`,`sample`,`sampleFile`,`sampleEncoding`,`strict`; parsed by the definitions plan).
  - `ProtocolCatalog::get(string $name): array` with `name,label,transport,http,defaultDriver,defaultMode,defaultFormat,debuggable,debugReason`.
  - `ParseProtocol::metadata(ReflectionClass $refClass, ReflectionMethod $refMethod): array` returning `[]` or `['protocol'=>array,'channel'=>array,'debug'=>array]`.

- [ ] **Step 1: Write the failing tests**

`tests/protocol.php` uses the same constants/autoloader/`check()` header as `tests/smoke.php`, then `ConfigProvider::set($config)`.

Fixture:
```php
namespace Tests\Fixtures\Protocol;
use erikwang2013\apidoc\annotation\{Protocol, Channel, Debug, Param, Title};
#[Protocol('mqtt')]
class MqttFixture {
    #[Channel('/sensors/+/temp', action: 'publish')]
    #[Debug(driver: 'mqtt', target: 'tcp://broker:1883', encoding: 'json', meta: ['qos' => 1])]
    #[Param(name: 'value', type: 'number', require: true)]
    #[Title('Publish temperature')]
    public function publishTemperature() {}
}
#[Protocol('grpc')]
class GrpcFixture {
    #[Channel('helloworld.Greeter/SayHello', action: 'call')]
    #[Debug(driver: 'grpc', target: 'localhost:50051')]
    #[Title('SayHello')]
    public function sayHello() {}
}
```

Tests:
```php
$parser = new erikwang2013\apidoc\parses\ParseProtocol($config);
$mqtt = $parser->metadata(new ReflectionClass(\Tests\Fixtures\Protocol\MqttFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\MqttFixture::class))->getMethod('publishTemperature'));
check('mqtt protocol label', ($mqtt['protocol']['label'] ?? '') === 'MQTT');
check('mqtt channel action', ($mqtt['channel']['action'] ?? '') === 'publish');
check('mqtt debug mode', ($mqtt['debug']['mode'] ?? '') === 'relay-stream');
check('mqtt debug driver', ($mqtt['debug']['driver'] ?? '') === 'mqtt');
check('mqtt debug target', ($mqtt['debug']['defaults']['target'] ?? '') === 'tcp://broker:1883');
check('mqtt debug field value present', in_array('value', array_column($mqtt['debug']['fields'], 'name'), true));
$grpc = $parser->metadata(new ReflectionClass(\Tests\Fixtures\Protocol\GrpcFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\GrpcFixture::class))->getMethod('sayHello'));
check('grpc debug mode', ($grpc['debug']['mode'] ?? '') === 'relay-unary');
check('grpc debug driver', ($grpc['debug']['driver'] ?? '') === 'grpc');
check('unknown protocol falls back', erikwang2013\apidoc\protocol\ProtocolCatalog::get('nope')['label'] === 'NOPE');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "...annotation\Protocol" not found`.

- [ ] **Step 3: Implement the attribute classes**

Use `#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]` on all four, with declared public properties matching the names above. Constructor is `__construct(...$value) { parent::__construct(...$value); }`. Positional usage maps to `name`:
`#[Protocol('grpc')]`, `#[Channel('/topic')]`, `#[Debug('mqtt')]` (driver), `#[Definition('x.proto')]` (file later). Normalize in `ParseProtocol`.

- [ ] **Step 4: Implement the catalog**

P1 ids and defaults:
```php
'http' => ['label'=>'HTTP','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'rest' => ['label'=>'REST','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'soap' => ['label'=>'SOAP','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'graphql' => ['label'=>'GraphQL','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'json-rpc' => ['label'=>'JSON-RPC','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'xml-rpc' => ['label'=>'XML-RPC','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'webhooks' => ['label'=>'Webhooks','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'odata' => ['label'=>'OData','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'mcp' => ['label'=>'MCP','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'a2a' => ['label'=>'A2A','transport'=>'http','http'=>true,'defaultDriver'=>'http','defaultMode'=>'browser-http'],
'websocket' => ['label'=>'WebSocket','transport'=>'tcp','http'=>false,'defaultDriver'=>'websocket','defaultMode'=>'browser-websocket'],
'sse' => ['label'=>'SSE','transport'=>'http','http'=>true,'defaultDriver'=>'sse','defaultMode'=>'browser-sse'],
'mqtt' => ['label'=>'MQTT','transport'=>'tcp','http'=>false,'defaultDriver'=>'mqtt','defaultMode'=>'relay-stream'],
'stomp' => ['label'=>'STOMP','transport'=>'tcp','http'=>false,'defaultDriver'=>'stomp','defaultMode'=>'relay-stream'],
'coap' => ['label'=>'CoAP','transport'=>'udp','http'=>false,'defaultDriver'=>'coap','defaultMode'=>'relay-unary'],
'modbus-tcp' => ['label'=>'Modbus TCP','transport'=>'tcp','http'=>false,'defaultDriver'=>'modbus-tcp','defaultMode'=>'relay-unary'],
'iso8583' => ['label'=>'ISO 8583','transport'=>'tcp','http'=>false,'defaultDriver'=>'iso8583','defaultMode'=>'relay-unary'],
'rtsp' => ['label'=>'RTSP','transport'=>'tcp','http'=>false,'defaultDriver'=>'rtsp','defaultMode'=>'relay-unary'],
'sip' => ['label'=>'SIP','transport'=>'udp','http'=>false,'defaultDriver'=>'sip','defaultMode'=>'relay-unary'],
'hls' => ['label'=>'HLS','transport'=>'http','http'=>true,'defaultDriver'=>'hls','defaultMode'=>'browser-http'],
'grpc' => ['label'=>'gRPC','transport'=>'http2','http'=>false,'defaultDriver'=>'grpc','defaultMode'=>'relay-unary'],
'hessian' => ['label'=>'Hessian','transport'=>'http','http'=>true,'defaultDriver'=>'hessian','defaultMode'=>'relay-unary'],
'tcp' => ['label'=>'TCP','transport'=>'tcp','http'=>false,'defaultDriver'=>'raw','defaultMode'=>'relay-unary'],
'udp' => ['label'=>'UDP','transport'=>'udp','http'=>false,'defaultDriver'=>'raw','defaultMode'=>'relay-unary'],
'unix-socket' => ['label'=>'Unix Domain Socket','transport'=>'unix','http'=>false,'defaultDriver'=>'raw','defaultMode'=>'relay-unary'],
'raw-socket' => ['label'=>'Raw Socket','transport'=>'tcp','http'=>false,'defaultDriver'=>'raw','defaultMode'=>'relay-unary'],
```

Add P2 ids with the same shape and `defaultDriver=''`/`defaultMode=''`: `thrift`, `dubbo`, `amqp`, `kafka`, `socket.io`, `xmpp`, `webrtc`, `webrtc-datachannel`, `rtmp`, `rtp`, `rtcp`, `lwm2m`, `opc-ua`, `dnp3`, `iec-104`, `bacnet-ip`, `binary-body`, `websocket-binary`, `stomp-binary`, `http2`, `http3`. Unknown ids return `label=strtoupper($name)`, `defaultDriver=''`, `defaultMode=''`, `debuggable=false`, `debugReason='未支持的协议驱动（P2）'`.

Every entry also carries `defaultFormat` for Plan B: `rest=>openapi`, `soap=>wsdl`, `graphql=>graphql`, `grpc=>proto`, `dubbo=>proto`, `json-rpc=>json-rpc`, `xml-rpc=>xml-rpc`, `webhooks/mcp/a2a=>json-schema`, `odata=>edmx`, `hessian=>hessian`, `thrift=>thrift`; all others `''`.

- [ ] **Step 5: Implement `ParseProtocol::metadata()`**

- Read class and method attributes with `ParseAnnotation`.
- Normalize `Protocol`, `Channel`, `Debug` from string/list/assoc.
- `protocol` = catalog + annotation overrides (`version`,`transport`,`mode`).
- `channel.address = address ?: name`.
- `debug.driver = debug.driver ?: debug.name ?: catalog.defaultDriver` (positional `#[Debug('mqtt')]` lands in `name`); if `debug.driver === 'auto'` use catalog default.
- `debug.mode`: if the driver is `http`/`websocket`/`sse`, use the catalog default mode; otherwise `relay-unary` unless catalog default mode is `relay-stream`.
- `debug.defaults = ['target' => debug.target ?: channel.address ?: method url, 'encoding' => debug.encoding ?: 'text', 'timeout' => debug.timeout ?: 5]`.
- `debug.fields`: start with driver fields (Task 2 supplies; for this task use an empty array), then append method `param` items with `in='body'`, `header` with `in='header'`, `query` with `in='query'`, and a `target` field (`in='target'`, default from defaults). Normalize raw attribute values first: a string becomes `['name'=>string]`, a list of strings becomes a list of such arrays, and an associative array stays as one item. This matches `ParseApiDetail::handleMethodParams`.
- `debug.relayEnabled`: `protocols.debug.relay_enable === true` (config read from `$config`).
- `debug.debuggable`: browser mode -> `defaults.target` non-empty; relay mode -> relay enabled and target non-empty and driver exists; otherwise false with `debugReason`.
- `debug.responseEncoding = debug.meta['responseEncoding'] ?? 'text'`; `debug.hints = debug.meta['hints'] ?? ''`.
- Return `['protocol'=>$protocol,'channel'=>$channel,'debug'=>$debug]`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/annotation src/protocol/ProtocolCatalog.php src/parses/ParseProtocol.php tests/protocol.php tests/fixtures/protocol/AnnotationFixtures.php
git commit -m "feat(protocol): add protocol/debug annotations and debug contract"
```

---

### Task 2: Driver Interface, Registry, and Target Validation

**Files:**
- Create: `src/protocol/debug/DebugDriverInterface.php`
- Create: `src/protocol/debug/StreamingDriverInterface.php`
- Create: `src/protocol/debug/DebugRegistry.php`
- Create: `src/protocol/debug/DebugTarget.php`
- Create: `src/protocol/debug/Driver/RawDriver.php` (minimal stub in this task; Task 4 implements `send()`)
- Modify: `src/parses/ParseProtocol.php`
- Modify: `tests/protocol.php`

**Interfaces:**
- Produces:
  - `DebugDriverInterface::name(): string`, `mode(): string`, `fields(array $context): array`, `send(array $input, array $context): array`.
  - `StreamingDriverInterface extends DebugDriverInterface` adds `start(array $input, array $context): array`, `poll(string $sessionId, array $context): array`, `stop(string $sessionId, array $context): void`.
  - `DebugRegistry::driver(string $name): ?DebugDriverInterface`; `DebugRegistry::driverFields(string $name): array`.
  - `DebugTarget::parse(string $target): array` returning `['scheme','host','port','path','original']` or throwing `InvalidArgumentException`.

- [ ] **Step 1: Write the failing tests**

```php
check('target parses tcp', erikwang2013\apidoc\protocol\debug\DebugTarget::parse('tcp://broker:1883')['port'] === 1883);
check('target parses unix', erikwang2013\apidoc\protocol\debug\DebugTarget::parse('unix:///tmp/app.sock')['scheme'] === 'unix');
$bad = false;
try { erikwang2013\apidoc\protocol\debug\DebugTarget::parse('file:///etc/passwd'); } catch (Throwable $e) { $bad = true; }
check('target rejects file scheme', $bad);
check('registry raw driver', erikwang2013\apidoc\protocol\debug\DebugRegistry::driver('raw')?->name() === 'raw');
check('registry unknown driver', erikwang2013\apidoc\protocol\debug\DebugRegistry::driver('nope') === null);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement `DebugTarget`**

Allowed schemes: `tcp`, `udp`, `unix`, `http`, `https`, `ws`, `wss`. Parse `scheme://host:port/path`; a bare `host:port` is parsed as `scheme='tcp'`; `unix://` uses the path; default ports: `tcp`/`udp` must be explicit, `http=80`, `https=443`, `ws=80`, `wss=443`. Reject empty host for non-unix, port outside 1..65535, credentials in the URL, and any other scheme. Return the parsed array.

- [ ] **Step 4: Implement the interfaces and registry**

`DebugRegistry` maps driver names to classes; for this task create a minimal `RawDriver` stub (`name()='raw'`, `mode()='relay-unary'`, `fields()` returns the field list below, `send()` returns `['ok'=>false,'error'=>'raw driver not implemented yet']`). Task 4 replaces the stub's `send()`. `driverFields('raw')` returns:
```php
[
  ['name'=>'target','in'=>'target','type'=>'string','require'=>true,'desc'=>'tcp://host:port, udp://host:port, or unix:///path'],
  ['name'=>'payload','in'=>'body','type'=>'string','require'=>false,'desc'=>'Text/hex/base64 payload'],
  ['name'=>'encoding','in'=>'meta','type'=>'string','require'=>false,'default'=>'text'],
  ['name'=>'timeout','in'=>'meta','type'=>'number','require'=>false,'default'=>5],
]
```
For unknown driver, return `[]`.

- [ ] **Step 5: Wire driver fields into `ParseProtocol::metadata()`**

Replace the empty driver fields with `DebugRegistry::driverFields($driver)`; dedupe by `in|name`, keeping the annotation `param` item when both exist.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/protocol/debug src/parses/ParseProtocol.php tests/protocol.php
git commit -m "feat(debug): add driver registry and target validation"
```

---

### Task 3: Detail/Menu Integration and Existing HTTP Compatibility

**Files:**
- Modify: `src/parses/ParseApiDetail.php:84-90` and `:412-454`
- Modify: `src/parses/ParseApiMenus.php:217-270`
- Modify: `tests/protocol.php`
- Modify: `tests/fixtures/protocol/AnnotationFixtures.php` (add `PlainHttpFixture`)

**Interfaces:**
- Consumes: `ParseProtocol::metadata()`.
- Produces: detail gains optional `protocol`, `channel`, `debug`; menus gain optional `protocolKey`, `protocolLabel`, `channelAddress`; non-HTTP endpoints get `url = channel.address` when no explicit `Url`.

- [ ] **Step 1: Write the failing tests**

Add fixtures:
```php
class PlainHttpFixture {
    #[Method('GET')] #[Url('/plain')] #[Title('Plain')]
    public function plain() {}
}
```

Tests:
```php
$plainRef = new ReflectionClass(\Tests\Fixtures\Protocol\PlainHttpFixture::class);
$plainMeta = (new erikwang2013\apidoc\parses\ParseProtocol($config))->metadata($plainRef, $plainRef->getMethod('plain'));
check('plain http has no protocol metadata', $plainMeta === []);
$detail = (new erikwang2013\apidoc\parses\ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\MqttFixture@publishTemperature');
check('mqtt detail has protocol', ($detail['protocol']['name'] ?? '') === 'mqtt');
check('mqtt detail has debug', ($detail['debug']['driver'] ?? '') === 'mqtt');
check('mqtt detail url is channel', ($detail['url'] ?? '') === '/sensors/+/temp');
$menus = (new erikwang2013\apidoc\parses\ParseApiMenus($config));
$controller = $menus->parseController(\Tests\Fixtures\Protocol\MqttFixture::class, false, 'api');
check('menu has protocol key', ($controller['children'][0]['protocolKey'] ?? '') === 'mqtt');
check('menu has no internal fields', !array_key_exists('_definitionFingerprints', $controller['children'][0]));
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL on `mqtt detail has protocol`.

- [ ] **Step 3: Modify `handleApiBaseInfo`**

Add a sixth optional `$protocolMeta = []`. Add `protocolKey`, `protocolLabel`, `channelAddress` only when `$protocolMeta['protocol']` exists. When `url` is empty and `protocol.http === false` and a channel address exists, use it as `url` without prepending `/`; otherwise use `autoCreateUrl`.

- [ ] **Step 4: Wire `ParseApiDetail::parseApiMethod()`**

Compute `$protocolMeta` before `handleApiBaseInfo`, pass it, and merge `ParseProtocol::metadata()` output into the final array. Do not parse definition files here.
In `ParseApiMenus::parseApiMethod()`, compute metadata, pass it to `handleApiBaseInfo`, include the three new keys in `Helper::getArrayValuesByKeys` only when protocol metadata exists, and `unset($methodItem['_definitionFingerprints'])` after either branch in `parseController`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/protocol.php && php tests/smoke.php`
Expected: all `PASS`.

- [ ] **Step 6: Commit**

```bash
git add src/parses/ParseApiDetail.php src/parses/ParseApiMenus.php tests/protocol.php
git commit -m "feat(debug): expose protocol and debug metadata in menus and details"
```

---

### Task 4: Relay Endpoints, Security, and Raw/HTTP Drivers

**Files:**
- Create: `src/protocol/debug/DebugProxy.php`
- Modify: `src/protocol/debug/Driver/RawDriver.php` (stub from Task 2; implement `send()` here)
- Create: `src/protocol/debug/Driver/HttpRelayDriver.php`
- Create: `src/protocol/debug/Support/Hex.php`
- Create: `src/protocol/debug/Support/HttpClient.php`
- Modify: `src/providers/BaseService.php:13-30`
- Modify: `src/Controller.php` (add methods before the final `}`)
- Modify: `src/config.php` (add `protocols.debug` in Task 13; defaults here)
- Create: `tests/debug.php`, `tests/fixtures/debug/tcp_echo_server.php`
- Create: `tests/fixtures/debug/udp_echo_server.php`
- Create: `tests/fixtures/debug/http/echo.php` (served by `php -S` in the test)

**Interfaces:**
- Produces:
  - `DebugProxy::__construct(array $config)`, `send(string $appKey, string $apiKey, array $input): array`, `start(...)`, `poll(string $sessionId, string $appKey, string $apiKey): array`, `stop(...)`.
  - `RawDriver::name() === 'raw'`, `mode() === 'relay-unary'`, `send()`.
  - `HttpRelayDriver::name() === 'http-relay'`, `mode() === 'relay-unary'`, `send()`.
  - Controller routes `debugSend`, `debugStart`, `debugPoll`, `debugStop`.

- [ ] **Step 1: Write the failing tests**

`tests/debug.php` uses the same harness. Start the echo servers with `proc_open`:
```bash
php tests/fixtures/debug/tcp_echo_server.php 127.0.0.1 19001
php tests/fixtures/debug/udp_echo_server.php 127.0.0.1 19002
```
Fixtures listen on the given port and echo bytes back. Also start `php -S 127.0.0.1:19005 -t tests/fixtures/debug/http` for the HTTP-relay test. Tests:
```php
$config['protocols']['debug']['relay_enable'] = true;
$proxy = new erikwang2013\apidoc\protocol\debug\DebugProxy($config);
$res = $proxy->send('api', 'Tests\\Fixtures\\Protocol\\RawFixture@echo', ['payload' => 'hello', 'encoding' => 'text']);
check('raw tcp echo', ($res['body'] ?? '') === 'hello');
check('raw tcp ok', ($res['ok'] ?? false) === true);
$resHex = $proxy->send('api', 'Tests\\Fixtures\\Protocol\\RawFixture@echo', ['payload' => '68656c6c6f', 'encoding' => 'hex']);
check('raw tcp hex echo', strtolower($resHex['body'] ?? '') === '68656c6c6f');
$config['protocols']['debug']['relay_enable'] = false;
$disabled = (new erikwang2013\apidoc\protocol\debug\DebugProxy($config))->send('api', 'Tests\\Fixtures\\Protocol\\RawFixture@echo', ['payload' => 'x']);
check('relay disabled returns error', ($disabled['ok'] ?? true) === false);
$evil = $proxy->send('api', 'Tests\\Fixtures\\Protocol\\RawFixture@echo', ['target' => 'tcp://127.0.0.1:19999', 'payload' => 'x']);
check('client target ignored', ($evil['ok'] ?? true) === true && ($evil['body'] ?? '') === 'x');
$http = $proxy->send('api', 'Tests\\Fixtures\\Protocol\\HttpRelayFixture@echo', ['method' => 'POST', 'payload' => 'hello', 'encoding' => 'text']);
check('http relay ok', ($http['ok'] ?? false) === true);
check('http relay body', strpos((string)($http['body'] ?? ''), 'hello') !== false);
```
Add a `RawFixture` and `HttpRelayFixture` in `tests/fixtures/protocol/AnnotationFixtures.php`:
```php
#[Protocol('tcp')]
#[Debug(driver:'raw', target:'tcp://127.0.0.1:19001')]
class RawFixture {
    #[Channel('echo')] #[Title('Echo')]
    public function echo() {}
}
#[Protocol('http')]
#[Debug(driver:'http-relay', target:'http://127.0.0.1:19005/echo.php')]
class HttpRelayFixture {
    #[Channel('/echo.php')] #[Title('HTTP echo')]
    public function echo() {}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement `Hex` and `RawDriver`**

`Hex::decode(string $value, string $encoding): string`: `text` returns raw; `hex` strips whitespace and validates `[0-9a-fA-F]` with even length; `base64` uses `base64_decode(..., true)`. `Hex::encode(string $bytes, string $encoding): string`: inverse.
`RawDriver::send($input, $context)`:
- `$target = DebugTarget::parse($context['target'])`; reject if `scheme` not `tcp|udp|unix`.
- `$payload = Hex::decode($input['payload'] ?? '', $input['encoding'] ?? 'text')`.
- open `stream_socket_client($context['target'], $errno, $errstr, $timeout)`; for UDP use `STREAM_CLIENT_CONNECT` and `stream_socket_sendto` or `fwrite`; for TCP/UDS `fwrite` then read until EOF or timeout with `stream_set_timeout`.
- Read at most `max_bytes`; return `['ok'=>true,'body'=>Hex::encode($bytes,$encoding),'encoding'=>$encoding,'meta'=>['durationMs'=>...,'bytes'=>strlen($bytes)]]`.
- On connect/read failure return `['ok'=>false,'error'=>"$errstr ($errno)"...]`, never throw.

- [ ] **Step 4: Implement `HttpRelayDriver`**

Implement `HttpClient::request(array $options): array` with curl when `ext-curl` is available (status, headers, body, trailers, `CURLOPT_HTTP_VERSION`), falling back to `file_get_contents` + `stream_context_create` for simple HTTP. Enforce max bytes, timeout, and at most 3 redirects. Only `http`/`https` targets; no cookies. `GrpcDriver` requires the curl path with HTTP/2 and returns a clear error when unavailable.

- [ ] **Step 5: Implement `DebugProxy`**

Flow:
1. Load `ParseApiDetail($config)->renderApiDetail($appKey, $apiKey)`; abort if false.
2. `$debug = $detail['debug']`; abort if empty.
3. If relay disabled -> `['ok'=>false,'error'=>'relay disabled']`.
4. Resolve target from `$debug['defaults']['target']` (never `$input['target']`); parse with `DebugTarget`.
5. Merge input: `$fields = $input['fields'] ?? []`; if `$input['payload']` is set, `$fields['payload'] = $input['payload']`; `$encoding = $input['encoding'] ?? $debug['defaults']['encoding']`; `$timeout = min((float)($input['timeout'] ?? $debug['defaults']['timeout']), $config['protocols']['debug']['max_timeout'])`; pass `headers`/`query` through unchanged.
6. Require `$debug['mode'] === 'relay-unary'`; return `['ok'=>false,'error'=>'driver is not unary']` otherwise. `DebugRegistry::driver($debug['driver'])`; abort if null.
7. Call `send($input, $context)` where `$context` has `target`, `meta`, `timeout`, `maxBytes`.
8. Rate limit: `Cache` counter `debug/rate/{md5(token)}/{minute}`; abort over 60. Skip the check when `$rateKey === ''` (tests and read-only callers). `DebugProxy::send` signature is `send($appKey,$apiKey,$input,$rateKey='')`.

- [ ] **Step 6: Add Controller endpoints and routes**

In `BaseService::$routes` add:
```php
['rule'=>'debugSend','route'=>'debugSend'],
['rule'=>'debugStart','route'=>'debugStart'],
['rule'=>'debugPoll','route'=>'debugPoll'],
['rule'=>'debugStop','route'=>'debugStop'],
```
Controller:
```php
public function debugSend() {
    $this->init(true);
    $params = $this->requestParams;
    $this->checkAuth();
    $requireAuth = $this->config['protocols']['debug']['relay_require_auth'] ?? true;
    if ($requireAuth && empty($params['token']) && empty($params['shareKey'])) {
        return Helper::showJson(0, '', ['ok'=>false, 'error'=>'authentication required']);
    }
    $res = (new DebugProxy($this->config))->send(
        (string)($params['appKey'] ?? ''), (string)($params['path'] ?? ''),
        (array)($params['input'] ?? []), (string)($params['token'] ?? '')
    );
    return Helper::showJson(0, '', $res);
}
```
`debugStart`/`debugPoll`/`debugStop` delegate to `DebugProxy`; start returns `sessionId`; poll returns `messages`; stop returns `true`. Apply the same `relay_require_auth` check to all four methods. For P1, `send` is implemented; `start/poll/stop` return `['ok'=>false,'error'=>'streaming driver not implemented yet']` until Task 9.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php tests/debug.php && php tests/protocol.php`
Expected: all `PASS` (the echo servers are started/stopped by the test harness).

- [ ] **Step 8: Commit**

```bash
git add src/protocol/debug src/providers/BaseService.php src/Controller.php tests/debug.php tests/fixtures/debug
git commit -m "feat(debug): add controlled relay endpoint and raw/http drivers"
```

---

### Task 5: MQTT and STOMP Drivers

**Files:**
- Create: `src/protocol/debug/Driver/MqttDriver.php`
- Create: `src/protocol/debug/Driver/StompDriver.php`
- Modify: `src/protocol/debug/DebugRegistry.php`
- Modify: `tests/debug.php`
- Create: `tests/fixtures/debug/mqtt_broker.php`
- Create: `tests/fixtures/debug/stomp_server.php`

**Interfaces:**
- Produces: `MqttDriver::name() === 'mqtt'`, `mode() === 'relay-stream'`, fields `topic`,`qos`,`clientId`,`username`,`password`,`payload`; unary `send()` for publish; `StreamingDriverInterface` methods in Task 9.
- `StompDriver::name() === 'stomp'`, `mode() === 'relay-stream'`, fields `destination`,`command`,`payload`,`contentType`; unary `send()` for SEND; streaming in Task 9.

- [ ] **Step 1: Write the failing tests**

Use known byte vectors:
```php
$mqtt = new erikwang2013\apidoc\protocol\debug\Driver\MqttDriver();
check('mqtt connect packet starts with 0x10', bin2hex($mqtt->encodeConnect(['clientId'=>'apidoc','keepalive'=>60]))[0] . bin2hex($mqtt->encodeConnect(['clientId'=>'apidoc','keepalive'=>60]))[1] === '10');
check('mqtt publish topic encoded', strpos($mqtt->encodePublish('a/b', 0, 'hi'), 'a/b') !== false);
$stomp = new erikwang2013\apidoc\protocol\debug\Driver\StompDriver();
$frame = $stomp->encodeFrame('SEND', ['destination'=>'/queue/a','content-length'=>2], 'hi');
check('stomp frame ends with NUL', substr($frame, -1) === "\0");
check('stomp content-length', strpos($frame, 'content-length:2') !== false);
```
Driver `send()` tests use local fixture servers started with `proc_open`:
- `tests/fixtures/debug/mqtt_broker.php` listens on `127.0.0.1:19003`, reads CONNECT, replies CONNACK, reads PUBLISH, replies PUBACK.
- `tests/fixtures/debug/stomp_server.php` listens on `127.0.0.1:19004`, replies CONNECTED, reads SEND, replies RECEIPT.
Add fixtures with targets `tcp://127.0.0.1:19003` and `tcp://127.0.0.1:19004`. Assert `send()` returns `ok=true` and the ACK/receipt text.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement MQTT 3.1.1**

Implement `encodeConnect`, `encodePublish`, `encodeSubscribe`, `encodeDisconnect`, `decodePackets` (fixed header + remaining-length varint). Support QoS 0/1, topic wildcards for subscribe, packet identifiers, keepalive 60. `send()` for `action=publish`: CONNECT, PUBLISH, wait PUBACK for QoS 1, DISCONNECT. Return `['ok'=>true,'body'=>'PUBACK','meta'=>...]`. On CONNACK error return `ok=false` with the return code name.

- [ ] **Step 4: Implement STOMP 1.2**

Frames are `COMMAND\nheader:value\n...\n\nbody\0`. Implement `encodeFrame` and `decodeFrames` (split on NUL, parse headers, support `content-length`). `send()` for `action=send`: CONNECT, SEND, wait RECEIPT if `receipt` header, DISCONNECT. Return the receipt or `SENT`.

- [ ] **Step 5: Register the drivers and fields**

`DebugRegistry` adds `mqtt` and `stomp`. Field definitions:
- MQTT: `topic` (meta), `qos` (meta, default 1), `clientId` (meta, default `apidoc-debug`), `username` (meta), `password` (meta), `payload` (body).
- STOMP: `destination` (meta), `command` (meta, default `SEND`), `contentType` (meta, default `text/plain`), `payload` (body).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/debug.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/protocol/debug/Driver/MqttDriver.php src/protocol/debug/Driver/StompDriver.php src/protocol/debug/DebugRegistry.php tests/debug.php tests/fixtures/debug/mqtt_broker.php tests/fixtures/debug/stomp_server.php
git commit -m "feat(debug): add MQTT and STOMP drivers"
```

---

### Task 6: CoAP, Modbus TCP, and ISO 8583 Drivers

**Files:**
- Create: `src/protocol/debug/Driver/CoapDriver.php`
- Create: `src/protocol/debug/Driver/ModbusTcpDriver.php`
- Create: `src/protocol/debug/Driver/Iso8583Driver.php`
- Modify: `src/protocol/debug/DebugRegistry.php`
- Modify: `tests/debug.php`

**Interfaces:**
- Produces: drivers `coap`, `modbus-tcp`, `iso8583`, all `relay-unary`; each declares its own fields.

- [ ] **Step 1: Write the failing tests**

```php
$coap = new erikwang2013\apidoc\protocol\debug\Driver\CoapDriver();
check('coap header version 1', (ord($coap->encodeRequest(1, 1, 'coap://host/x', 'hi')[0]) >> 6) === 1);
$modbus = new erikwang2013\apidoc\protocol\debug\Driver\ModbusTcpDriver();
$req = $modbus->encodeRead(1, 3, 0, 2);
check('modbus mbap length', bin2hex(substr($req, 4, 2)) === '0006');
check('modbus function 3', ord($req[7]) === 3);
$iso = new erikwang2013\apidoc\protocol\debug\Driver\Iso8583Driver();
$wire = $iso->encodeFrame('0800', str_repeat('00', 8), 2);
check('iso length prefix', strlen($wire) === 2 + 4 + 8);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement CoAP**

RFC 7252 header: version 1, type CON (0), token length, code `0.01` GET / `0.02` POST, message ID random, options URI-Path (11) encoded from the target path, payload marker `0xFF` + payload. Parse response: code class/detail, options, payload. Fields: `method` (GET/POST), `path`, `payload`. UDP only; `send()` uses `RawDriver`-style UDP send/recv with timeout.

- [ ] **Step 4: Implement Modbus TCP**

MBAP (transaction id, protocol 0, length, unit id) + PDU. Functions: 1/2 read coils/discrete, 3/4 read registers, 5 write coil, 6 write register, 16 write registers. Fields: `unitId` (default 1), `function` (default 3), `address` (default 0), `quantity` (default 1), `values` (comma-separated for writes). Parse response: exception code if function | 0x80; otherwise values. Return a readable text body plus hex.

- [ ] **Step 5: Implement ISO 8583**

Fields: `lengthPrefix` (`2ascii|2binary|4ascii|4binary|none`, default `2binary`), `mti` (default `0800`), `bitmap` (hex, default 16 bytes), `fields` (hex blob appended). `encodeFrame` builds the length prefix + MTI + bitmap + fields. `send()` over TCP; read the length prefix then that many bytes (or until timeout); return hex + ASCII preview. No field decoding in P1; the binary layout DSL is P2.

- [ ] **Step 6: Register the drivers and fields**

Add `coap`, `modbus-tcp`, `iso8583` to `DebugRegistry` with the fields above.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php tests/debug.php`
Expected: all `PASS`.

- [ ] **Step 8: Commit**

```bash
git add src/protocol/debug/Driver/CoapDriver.php src/protocol/debug/Driver/ModbusTcpDriver.php src/protocol/debug/Driver/Iso8583Driver.php src/protocol/debug/DebugRegistry.php tests/debug.php
git commit -m "feat(debug): add CoAP, Modbus TCP, and ISO 8583 drivers"
```

---

### Task 7: RTSP, SIP, and HLS Drivers

**Files:**
- Create: `src/protocol/debug/Driver/RtspDriver.php`
- Create: `src/protocol/debug/Driver/SipDriver.php`
- Create: `src/protocol/debug/Driver/HlsDriver.php`
- Modify: `src/protocol/debug/DebugRegistry.php`
- Modify: `tests/debug.php`

**Interfaces:**
- Produces: drivers `rtsp`, `sip`, `hls`; `hls` is `browser-http` when the target is a URL, with a relay fallback via `http-relay`.

- [ ] **Step 1: Write the failing tests**

```php
$rtsp = new erikwang2013\apidoc\protocol\debug\Driver\RtspDriver();
$req = $rtsp->encodeRequest('DESCRIBE', 'rtsp://host/stream', 1);
check('rtsp start line', strpos($req, "DESCRIBE rtsp://host/stream RTSP/1.0\r\n") === 0);
check('rtsp cseq', strpos($req, "CSeq: 1\r\n") !== false);
$sip = new erikwang2013\apidoc\protocol\debug\Driver\SipDriver();
$msg = $sip->encodeRequest('OPTIONS', 'sip:host', 'from@test', 'to@test', 1);
check('sip start line', strpos($msg, "OPTIONS sip:host SIP/2.0\r\n") === 0);
$hls = new erikwang2013\apidoc\protocol\debug\Driver\HlsDriver();
$parsed = $hls->parsePlaylist("#EXTM3U\n#EXTINF:10,\nseg1.ts\n#EXT-X-ENDLIST\n");
check('hls segment parsed', ($parsed['segments'][0] ?? '') === 'seg1.ts');
check('hls is media playlist', ($parsed['master'] ?? true) === false);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement RTSP**

Fields: `method` (`OPTIONS|DESCRIBE|SETUP|PLAY|TEARDOWN`), `url`, `cseq` (default 1), `headers` (JSON). Build the request line + `CSeq` + `User-Agent: apidoc-debug` + extra headers. `send()` over TCP, read until timeout or `\r\n\r\n` (+ `Content-Length` body), return the raw response text. SETUP/PLAY are single request/response; no session continuation in P1.

- [ ] **Step 4: Implement SIP**

Fields: `method` (`OPTIONS|REGISTER|MESSAGE`), `uri`, `from`, `to`, `callId`, `cseq`, `headers`, `body`. Build a minimal SIP request with `Via`, `Max-Forwards: 70`, `From`, `To`, `Call-ID`, `CSeq`, `Content-Length`. Send over UDP by default, TCP if target scheme is `tcp`. Read one response; return text.

- [ ] **Step 5: Implement HLS**

`parsePlaylist(string $text): array` returns `master` (bool), `variants` (name/resolution/bandwidth/uri), `segments` (uri/duration), `endlist`. `send()` via `HttpClient` GETs the playlist; if the target is a master playlist, do not fetch variants; return the parsed JSON body. If `fetchSegment=true`, fetch the first segment and return its size/hash, not the full media.

- [ ] **Step 6: Register the drivers and fields**

Add `rtsp`, `sip`, `hls` to `DebugRegistry` with the fields above. For `hls`, `mode()` returns `browser-http` when the target starts with `http://`/`https://`, else `relay-unary`; the UI can use the browser for CORS-friendly targets.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php tests/debug.php`
Expected: all `PASS`.

- [ ] **Step 8: Commit**

```bash
git add src/protocol/debug/Driver/RtspDriver.php src/protocol/debug/Driver/SipDriver.php src/protocol/debug/Driver/HlsDriver.php src/protocol/debug/DebugRegistry.php tests/debug.php
git commit -m "feat(debug): add RTSP, SIP, and HLS drivers"
```

---

### Task 8: gRPC and Hessian Drivers

**Files:**
- Create: `src/protocol/debug/Support/Protobuf.php`
- Create: `src/protocol/debug/Driver/GrpcDriver.php`
- Create: `src/protocol/debug/Driver/HessianDriver.php`
- Modify: `src/protocol/debug/DebugRegistry.php`
- Modify: `tests/debug.php`

**Interfaces:**
- Produces: `Protobuf::encode(array $fields): string`, `Protobuf::decode(string $bytes, array $schema): array`; `GrpcDriver::name() === 'grpc'`; `HessianDriver::name() === 'hessian'`.

- [ ] **Step 1: Write the failing tests**

```php
$pb = erikwang2013\apidoc\protocol\debug\Support\Protobuf::encode([1 => ['type' => 'string', 'value' => 'hi']]);
check('protobuf string field', bin2hex($pb) === '0a026869');
$decoded = erikwang2013\apidoc\protocol\debug\Support\Protobuf::decode(hex2bin('0a026869'), [1 => ['name' => 'name', 'type' => 'string']]);
check('protobuf decode', ($decoded['name'] ?? '') === 'hi');
$hessian = new erikwang2013\apidoc\protocol\debug\Driver\HessianDriver();
check('hessian string encode', bin2hex($hessian->encodeString('hi')) === '026869');
check('hessian int encode', bin2hex($hessian->encodeInt(1)) === '91');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement `Protobuf`**

Support wire types 0 (varint), 1 (64-bit), 2 (length-delimited), 5 (32-bit). Encode from `fieldNumber => ['type'=>'string|bytes|bool|int|double|message|repeated','value'=>...]`. Decode with a schema map `fieldNumber => ['name','type']`. Unknown fields are returned as `field_<n>` hex. Handle nested messages and repeated values. Raise `InvalidArgumentException` on malformed varints/lengths.

- [ ] **Step 4: Implement `GrpcDriver`**

Fields: `service` (meta), `method` (meta), `metadata` (meta JSON), `payload` (body JSON), `schema` (meta, JSON describing request/response fields), `tls` (meta bool, default false). Target may be `host:port` or `http(s)://host:port`; default scheme is `http`, or `https` when `tls=true`. Build the gRPC frame: 1 byte compression flag 0 + 4-byte big-endian length + protobuf bytes. POST to `<scheme>://<host:port>/<service>/<method>` with headers `content-type: application/grpc`, `te: trailers`, optional metadata. Use `HttpClient` over curl with HTTP/2; if unavailable, return `ok=false` with `"curl HTTP/2 is required for gRPC"`. Parse the response frame and `grpc-status`/`grpc-message` from headers/trailers; decode protobuf into JSON using the schema. `schema` is optional; without it return hex.

- [ ] **Step 5: Implement `HessianDriver`**

Support Hessian 2.0 strings, ints, longs, doubles, booleans, null, lists, maps, and binary. `encodeCall(string $method, array $args)`. `send()` POSTs the call to the target URL with `Content-Type: x-application/hessian`, decodes the response. Fields: `method`, `args` (JSON array), `payload` (raw hex fallback).

- [ ] **Step 6: Register the drivers and fields**

Add `grpc` and `hessian` with the fields above. If curl lacks HTTP/2, `GrpcDriver::fields()` still returns fields but `send()` returns a clear error; `ParseProtocol` marks `debuggable=false` with the reason when `curl_version()['features'] & CURL_VERSION_HTTP2` is false.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php tests/debug.php`
Expected: all `PASS`.

- [ ] **Step 8: Commit**

```bash
git add src/protocol/debug/Support/Protobuf.php src/protocol/debug/Driver/GrpcDriver.php src/protocol/debug/Driver/HessianDriver.php src/protocol/debug/DebugRegistry.php tests/debug.php
git commit -m "feat(debug): add gRPC and Hessian drivers"
```

---

### Task 9: Streaming Sessions for MQTT/STOMP

**Files:**
- Create: `src/protocol/debug/DebugSession.php`
- Modify: `src/protocol/debug/DebugProxy.php`
- Modify: `src/protocol/debug/Driver/MqttDriver.php`
- Modify: `src/protocol/debug/Driver/StompDriver.php`
- Modify: `src/Controller.php` (`debugStart`/`debugPoll`/`debugStop`)
- Modify: `tests/debug.php`

**Interfaces:**
- Produces:
  - `DebugSession::create(array $data): string`, `get(string $id): ?array`, `touch(string $id): bool`, `delete(string $id): void`.
  - `DebugProxy::start(string $appKey,string $apiKey,array $input,string $rateKey=''): array`, `poll(string $sessionId,string $rateKey=''): array`, `stop(string $sessionId,string $rateKey=''): array`.
  - MQTT/STOMP implement `StreamingDriverInterface`.

- [ ] **Step 1: Write the failing tests**

```php
$config['protocols']['debug']['relay_enable'] = true;
$proxy = new erikwang2013\apidoc\protocol\debug\DebugProxy($config);
$start = $proxy->start('api', 'Tests\\Fixtures\\Protocol\\RawFixture@echo', ['fields'=>['topic'=>'a/b']]);
check('session start returns id', !empty($start['sessionId']));
$poll = $proxy->poll($start['sessionId']);
check('session poll returns messages array', is_array($poll['messages'] ?? null));
$stop = $proxy->stop($start['sessionId']);
check('session stop', ($stop['ok'] ?? false) === true);
$expired = $proxy->poll('nonexistent');
check('unknown session rejected', ($expired['ok'] ?? true) === false);
```

Extend `tests/fixtures/debug/mqtt_broker.php` and `stomp_server.php` to push one message after SUBSCRIBE; assert the poll returns that message text. Use `tcp://127.0.0.1:19003` / `19004` fixtures.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/debug.php`
Expected: FAIL with `DebugSession` not found.

- [ ] **Step 3: Implement `DebugSession`**

Use `Cache` with key `debug/session/{id}`. Value: `['id','appKey','apiKey','rateKey','driver','target','fields','createdAt','expiresAt']`. `create` uses `bin2hex(random_bytes(16))`, TTL 60s. `get` returns null if missing/expired. `touch` extends by 60s. `delete` removes. Never store tokens in the session; store a hash of the rate key.

- [ ] **Step 4: Implement MQTT/STOMP streaming**

`start()`: validate relay/target/driver; for MQTT, store `clientId='apidoc-'.substr($id,0,8)`, `cleanSession=false`; return session id. `poll()`: open the upstream connection, CONNECT, SUBSCRIBE (`topic`,`qos`), read packets for up to 25s or until `max_messages=100`, return decoded messages, DISCONNECT (MQTT persistent session keeps the subscription). For STOMP, CONNECT, SUBSCRIBE, read frames for up to 25s, return, DISCONNECT.
`stop()`: delete the session; MQTT optionally send a zero-length retained message? No; just delete.

- [ ] **Step 5: Wire Controller methods**

`debugStart` -> `DebugProxy::start(...)`; `debugPoll` -> `poll`; `debugStop` -> `stop`. All call `init(true)` + `checkAuth()`. Return `Helper::showJson(0,'',$res)`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/debug.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/protocol/debug/DebugSession.php src/protocol/debug/DebugProxy.php src/protocol/debug/Driver/MqttDriver.php src/protocol/debug/Driver/StompDriver.php src/Controller.php tests/debug.php
git commit -m "feat(debug): add stream sessions for MQTT and STOMP"
```

---

### Task 10: UI Types, API, Badge, and Menu

**Files:**
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/api/apidocApi/types.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/api/debugApi/types.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/api/debugApi/index.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/ProtocolBadge/ProtocolBadge.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/ProtocolBadge/index.ts`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/Menu/src/MenuItemContent.vue`

**Interfaces:**
- Consumes: backend `protocol`, `channel`, `debug` contract.
- Produces:
  - Types `ApiProtocolInfo`, `ApiChannelInfo`, `ApiDebugInfo`, `ApiDebugField`, `ApiDebugResult`.
  - `debugApi.send/start/poll/stop` functions.
  - `<ProtocolBadge>`.

- [ ] **Step 1: Add types**

```ts
export interface ApiDebugField extends ApiDetailParamItem {
  in?: 'target' | 'header' | 'query' | 'body' | 'meta'
  default?: unknown
}
export interface ApiDebugInfo {
  mode: 'browser-http' | 'browser-websocket' | 'browser-sse' | 'relay-unary' | 'relay-stream'
  driver: string
  relayEnabled: boolean
  debuggable: boolean
  debugReason?: string
  fields: ApiDebugField[]
  defaults: Record<string, unknown>
  responseEncoding: 'text' | 'json' | 'hex' | 'base64'
  hints?: string
}
export interface ApiDebugResult {
  ok: boolean
  status?: number | string
  headers?: Record<string, string>
  body?: string
  encoding?: 'text' | 'json' | 'hex' | 'base64'
  meta?: Record<string, unknown>
  error?: string
}
```
Add optional `protocol`, `channel`, `debug` to `ApiDetailResult`; add `protocolKey/protocolLabel/channelAddress` to `ApiMenuItem`.

- [ ] **Step 2: Add debug API functions**

`src/api/debugApi/index.ts`:
```ts
export default class debugApi {
  static send = (data: {appKey:string; path:string; input:Record<string,unknown>}) =>
    post<ApiDebugResult>({ url: '/debugSend', data })
  static start = (data: {appKey:string; path:string; input:Record<string,unknown>}) =>
    post<{sessionId:string}>({ url: '/debugStart', data })
  static poll = (data: {sessionId:string}) =>
    post<{messages:ApiDebugResult[]}>({ url: '/debugPoll', data })
  static stop = (data: {sessionId:string}) =>
    post<{ok:boolean}>({ url: '/debugStop', data })
}
```
The shared axios interceptor adds the auth token automatically.

- [ ] **Step 3: Create the badge and menu branch**

`ProtocolBadge.vue` props `{ protocol?: ApiProtocolInfo; protocolKey?: string; protocolLabel?: string }`; label = protocol.label || protocolLabel || uppercase protocolKey. In `MenuItemContent.vue` add a `protocolKey` branch before the HTTP method branches (non-HTTP items have `method='*'`).

- [ ] **Step 4: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: build passes.

- [ ] **Step 5: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/api src/components/ProtocolBadge src/components/Menu/src/MenuItemContent.vue
git commit -m "feat(ui): add protocol debug types, api, badge, and menu"
```

---

### Task 11: UI Browser Debug Panel (HTTP/WS/SSE)

**Files:**
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/ProtocolDebug.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/ProtocolFields.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/ResponseLog.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/drivers/browserHttp.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/drivers/browserWebSocket.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/drivers/browserSse.ts`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/Index.vue`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/public/config.js`

**Interfaces:**
- Consumes: `ApiDebugInfo`, `ApiDebugResult`, existing `useDebugApi`.
- Produces: protocol-aware debug tab.

- [ ] **Step 1: Add debug translations**

In every `LANG` entry: `apiPage.debug.protocolTarget`, `protocolSend`, `protocolConnect`, `protocolDisconnect`, `protocolMessages`, `protocolResponse`, `protocolRelayDisabled`, `protocolStatus`, `protocolDuration`, `protocolBytes`, `protocolEncoding`.

- [ ] **Step 2: Route the debug tab**

In `DebugTab/Index.vue`, keep the existing HTTP UI when `props.detail.debug?.mode === 'browser-http'` and the detail has no protocol-specific driver; otherwise render `<ProtocolDebug :detail="props.detail" />`. For `browser-http` with a protocol annotation (SOAP/GraphQL/JSON-RPC), reuse the existing Params + axios path; no new executor.

- [ ] **Step 3: Implement `ProtocolFields.vue`**

Render `detail.debug.fields` as a form:
- Group by `in` (`target`, `header`, `query`, `body`, `meta`).
- Text/number/boolean/select based on `type`; `default` prefill; `require` validation.
- `encoding` select (`text/json/hex/base64`) when the driver has a body payload.
- Emit `update:modelValue` with `{ target, headers, query, fields, payload, encoding, timeout }`.
  `fields` is a flat map keyed by field name (driver meta + body fields); `payload` is the raw body editor
  value when the driver exposes one.

- [ ] **Step 4: Implement `browserWebSocket.ts` and `browserSse.ts`**

WS: `connect(url)`, `send(text|hex)`, `close()`, callbacks `onMessage`, `onError`, `onClose`. Use native `WebSocket`; hex payload via `Uint8Array`.
SSE: `connect(url)`, `close()`, `onMessage`; use native `EventSource`; append `[event] data` lines.
Both return a log entry `{time, direction:'in'|'out', encoding, body}`.

- [ ] **Step 5: Implement `ProtocolDebug.vue`**

- Show badge, mode, target, relay status.
- `browser-websocket`: Connect/Disconnect buttons; message editor with text/hex; send; log list.
- `browser-sse`: Connect/Disconnect; log list.
- `browser-http`: render existing `Params` component (import from `./Params/Index.vue`) and call `useDebugApi().excuteDebug` with the current method.
- For `relay-*`: show a placeholder until Task 12.
- `ResponseLog.vue`: table/list of `{time, direction, encoding, body}`; text escaped, JSON pretty-printed, hex uppercase.

- [ ] **Step 6: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: build passes.

- [ ] **Step 7: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/pages/apiDetail/DebugTab public/config.js
git commit -m "feat(ui): add browser protocol debug panel for http/ws/sse"
```

---

### Task 12: UI Relay Debug Panel (Unary and Stream)

**Files:**
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/drivers/relay.ts`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/ProtocolDebug.vue`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/DebugTab/ResponseLog.vue`

**Interfaces:**
- Consumes: `debugApi.send/start/poll/stop`.
- Produces: relay-unary and relay-stream panels.

- [ ] **Step 1: Implement `relay.ts`**

```ts
export async function sendRelay(detail, input) { return debugApi.send({appKey, path, input}) }
export async function startStream(detail, input) { return debugApi.start(...) }
export async function pollStream(sessionId) { return debugApi.poll({sessionId}) }
export async function stopStream(sessionId) { return debugApi.stop({sessionId}) }
```
`path` is the current route `key` query. Include `shareKey`/`lang` if present? The axios layer already adds token; shareKey is in the route. Pass `appKey` from the detail.

- [ ] **Step 2: Implement relay-unary**

In `ProtocolDebug.vue`, when `mode === 'relay-unary'`: render fields, a Send button, and response log. Disable Send when `!debug.relayEnabled || !debug.debuggable`; show `debug.debugReason`/`protocolRelayDisabled`. On send, pass the `ProtocolFields` model (`target` ignored by the backend, `headers`, `query`, `fields`, `payload`, `encoding`, `timeout`) to `sendRelay`, append the result as one log entry (request + response).

- [ ] **Step 3: Implement relay-stream**

When `mode === 'relay-stream'`: Start/Stop buttons. Start calls `startStream`, stores `sessionId`, then loops `pollStream` while active; each poll appends messages; after each poll immediately poll again. Stop calls `stopStream`, clears the timer. Add a 60s session renewal: if poll returns an expired error, start again with the same input (one automatic retry).

- [ ] **Step 4: Show relay-disabled state**

If `relayEnabled=false`, render the fields and a disabled Send/Start button with the reason. No network call.

- [ ] **Step 5: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: build passes.

- [ ] **Step 6: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/pages/apiDetail/DebugTab
git commit -m "feat(ui): add relay unary and stream debug panels"
```

---

### Task 13: Config, Docs, and Full Regression

**Files:**
- Modify: `src/config.php`
- Modify: `composer.json`
- Modify: `docs/ATTRIBUTES.md`
- Modify: `README.md`
- Modify: `tests/debug.php` (only if a regression test is missing)

**Interfaces:**
- Produces: documented configuration, safe defaults, green full suite.

- [ ] **Step 1: Add the config block**

```php
'protocols' => [
    'debug' => [
        'relay_enable' => false,
        'relay_require_auth' => true,
        'timeout' => 5,
        'max_timeout' => 30,
        'max_bytes' => 1048576,
        'rate_limit_per_minute' => 60,
        'max_sessions' => 4,
        'session_ttl' => 60,
    ],
],
```

- [ ] **Step 2: Add composer suggestions**

Add a top-level `suggest` block (sibling of `require`, not inside it):

```json
"suggest": {
    "ext-dom": "Required for WSDL/XSD/EDMX definition parsing",
    "ext-curl": "Required for gRPC and HTTP relay in some drivers"
}
```

- [ ] **Step 3: Document the annotations**

`docs/ATTRIBUTES.md`: add `Protocol`, `Channel`, `Debug`, `Definition` sections with the MQTT and gRPC examples from the spec. Explain:
- relay is off by default and why;
- target comes from the annotation and cannot be changed by the client;
- raw fallback for protocols without a driver;
- browser vs relay modes.
`README.md`: add a short "任意协议调试" section and the config snippet.

- [ ] **Step 4: Run the full backend suite**

```bash
php tests/smoke.php
php tests/protocol.php
php tests/debug.php
```
Expected: all `PASS`.

- [ ] **Step 5: Run the UI build**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: build passes.

- [ ] **Step 6: Commit**

```bash
git add src/config.php composer.json docs/ATTRIBUTES.md README.md
git commit -m "docs(debug): document protocol debug panel and relay security"
```

---

## Self-Review Checklist

- Spec coverage: annotations/catalog/metadata (Tasks 1-3), debug contract/registry (Task 2), relay + security (Task 4), raw/http-relay (Task 4), MQTT/STOMP (Tasks 5, 9), CoAP/Modbus/ISO 8583 (Task 6), RTSP/SIP/HLS (Task 7), gRPC/Hessian (Task 8), UI types/badge/menu (Task 10), browser panel (Task 11), relay panel (Task 12), config/docs/regression (Task 13).
- Security: relay off by default, auth, annotation-only target, rate/size/timeout limits, session binding.
- Backward compatibility: Task 3 plain HTTP test; Task 11 routes browser-http to the existing UI.
- Deferred: AMQP/Kafka/Dubbo/Thrift/Socket.IO/XMPP/RTMP/DNP3/IEC104/BACnet/OPC UA/LwM2M/RTP/RTCP/WebRTC drivers (P2), definition parsers (separate plan).
- Known test gap: gRPC/Hessian `send()` is codec-tested; a real gRPC server integration test needs an HTTP/2 fixture and is manual in P1.
- Known relay risk: long-poll holds a PHP worker for up to 25s; deployment must raise `max_execution_time`/FPM timeouts and cap concurrency.
- Type consistency: `debug.mode/driver/fields/defaults/relayEnabled/debuggable/debugReason` used by both backend and UI; `debugSend/start/poll/stop` endpoint names match `debugApi`.
