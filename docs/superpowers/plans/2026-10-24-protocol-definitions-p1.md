# Protocol Definition Parsing P1 Implementation Plan (Plan B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real definition parsing/validation for the P1 protocol set on top of the debug-panel foundation, without new runtime dependencies.

**Architecture:** Each definition format has one adapter that returns normalized messages; a shared `ProtocolContext` owns path resolution, import limits, and diagnostics. `ParseProtocol::render()` merges annotations with parsed definitions, and `ParseApiDetail` adds the result to the existing detail payload. The UI renders the new optional fields only when present, so HTTP-only docs are unchanged.

**Tech Stack:** PHP 8.0-compatible code (no enum/readonly/never), DOM/libxml for XML, hand-written YAML/tokenizers, Vue 3 + TypeScript + ant-design-vue, existing `Cache` utility.

**Spec:** `docs/superpowers/specs/2026-10-24-multi-protocol-design.md`

**Prerequisite (Plan A):** Execute `docs/superpowers/plans/2026-10-24-protocol-debug-p1.md` first. Its Task 1 creates the attributes/catalog, Task 2 creates the debug contract, and Task 3 integrates metadata into menus/details. When Plan A is complete:
- **Skip Task 1** below (attributes/catalog already exist).
- **Skip Task 3** below (metadata/menu integration already exists).
- **Keep Task 2** (definition paths/context/registry; Plan A does not add these).
- **Keep Task 5** (definition cache/fingerprints; Plan A does not add these).
- Keep Tasks 4 and 6–19 (inline definitions, parsers, UI protocol tab/export, docs).
- **Merge UI/docs work:** Tasks 8 and 9 overlap with Plan A's ProtocolBadge/menu/header work, and Task 19
  overlaps with Plan A's config/docs task. Reuse Plan A's files and only add the definition-specific
  pieces (definition types, ProtocolTab, message tables, definition export). Do not duplicate the
  badge/menu/header/debug implementations.

If executing Plan B standalone, do all tasks as written and keep Plan A's debug plan for later.

## Global Constraints

- PHP `^8.0`; do not use enum/readonly/never or other 8.1+ syntax.
- No new `composer.json` runtime dependencies and no new npm dependencies.
- P0+P1 never fetches remote definitions; only local files under allowed roots.
- Existing HTTP endpoints without the new annotations must return the exact same JSON keys and UI behavior as before.
- New detail fields are optional; new menu fields are optional and only added when a protocol is declared.
- Backend code lives in `src/protocol/`, `src/parses/ParseProtocol.php`, `src/annotation/`; tests follow the `tests/smoke.php` style with a `check()` helper.
- UI work is committed in `/home/wwwroot/erikwang2013/apidoc-ui` separately from the backend commits.
- Backend verification: `php tests/smoke.php`, `php tests/protocol.php`, `php tests/protocol_adapters.php`.
- UI verification: `npm run build` (runs `vue-tsc --noEmit && vite build`).
- All new PHP files start with `declare(strict_types = 1);` and use the PSR-4 namespace prefix `erikwang2013\apidoc\`.

## File Map

Backend create:

- `src/annotation/Protocol.php`, `Channel.php`, `Definition.php`
- `src/protocol/ProtocolCatalog.php`
- `src/protocol/ProtocolPaths.php`
- `src/protocol/ProtocolContext.php`
- `src/protocol/ProtocolParseException.php`
- `src/protocol/AdapterInterface.php`
- `src/protocol/ProtocolRegistry.php`
- `src/protocol/Support/Yaml.php`
- `src/protocol/Support/SafeXml.php`
- `src/protocol/Support/TokenStream.php`
- `src/protocol/Support/SchemaMapper.php`
- `src/protocol/Support/JsonSchemaValidator.php`
- `src/protocol/Support/RefResolver.php`
- `src/protocol/Adapter/JsonSchemaAdapter.php`
- `src/protocol/Adapter/OpenApiAdapter.php`
- `src/protocol/Adapter/AsyncApiAdapter.php`
- `src/protocol/Adapter/GraphqlAdapter.php`
- `src/protocol/Adapter/ProtoAdapter.php`
- `src/protocol/Adapter/WsdlAdapter.php`
- `src/protocol/Adapter/EdmxAdapter.php`
- `src/protocol/Adapter/JsonRpcAdapter.php`
- `src/protocol/Adapter/XmlRpcAdapter.php`
- `src/parses/ParseProtocol.php`
- `tests/protocol.php`, `tests/protocol_adapters.php`, `tests/fixtures/protocol/**`

Backend modify:

- `src/parses/ParseApiDetail.php` (metadata merge, `handleApiBaseInfo` protocol URL fallback)
- `src/parses/ParseApiMenus.php` (protocol menu keys, strip internal fingerprints)
- `src/Controller.php` (cache keys, fingerprint freshness, strip internal field)
- `src/config.php` (`protocols` block)
- `composer.json` (`suggest` ext-dom)
- `docs/ATTRIBUTES.md`, `README.md`

UI create:

- `src/components/ProtocolBadge/ProtocolBadge.vue`, `src/components/ProtocolBadge/index.ts`
- `src/pages/apiDetail/ProtocolTab/Index.vue`

UI modify:

- `src/api/apidocApi/types.ts`
- `src/components/Menu/src/MenuItemContent.vue`
- `src/pages/apiDetail/index.vue`
- `src/utils/apidocExport/render.ts`
- `public/config.js`

## Review Focus

- A definition path containing `../`, an absolute path, or a symlink leaving the root must be rejected without reading the target; the error must be visible in `validation.errors`.
- A real-world `.proto` using an unsupported construct (`extend`, custom option, missing import) must produce a line-numbered error/warning, not a blank page or a PHP fatal.
- With `cache.enable=true`, editing a referenced `.proto`/WSDL/OpenAPI file must show new messages on the next detail request without manually clearing cache.
- An existing HTTP endpoint with no `Protocol`/`Channel`/`Definition` attribute must emit no new detail/menu keys and keep its debug tab.
- A class-level `Protocol` with a public method that has no method-level apidoc attribute must not make that method appear in menus.

---

### Task 1: Protocol Attributes and Catalog

**Files:**
- Create: `src/annotation/Protocol.php`
- Create: `src/annotation/Channel.php`
- Create: `src/annotation/Definition.php`
- Create: `src/protocol/ProtocolCatalog.php`
- Create: `tests/protocol.php`
- Create: `tests/fixtures/protocol/AnnotationFixtures.php`

**Interfaces:**
- Consumes: `erikwang2013\apidoc\utils\AbstractAnnotation`, `ParseAnnotation::getClassAnnotation()` / `getMethodAnnotation()`.
- Produces:
  - `#[Protocol(...)]`, `#[Channel(...)]`, `#[Definition(...)]` attributes with the public properties listed below.
  - `ProtocolCatalog::get(string $name): array` returning `name,label,transport,http,debuggable,debugReason,mode,defaultFormat`.
  - `ProtocolCatalog::has(string $name): bool`, `ProtocolCatalog::ids(): array`.

- [ ] **Step 1: Create the three attribute classes**

`Protocol` properties: `$name`, `$version = ''`, `$transport = ''`, `$mode = ''`; target class|method, repeatable, constructor `__construct(...$value)` calling `parent::__construct(...$value)`.
`Channel` properties: `$name`, `$address = ''`, `$action = ''`, `$meta = []`; same targets.
`Definition` properties: `$name`, `$file = ''`, `$format = 'auto'`, `$operation = ''`, `$message = ''`, `$direction = ''`, `$sample = ''`, `$sampleFile = ''`, `$sampleEncoding = 'hex'`, `$strict = true`; same targets, repeatable.
Positional usage maps to `name`: `#[Protocol('grpc')]`, `#[Channel('/topic')]`, `#[Definition('x.proto')]`. Normalization later reads `Protocol:name`, `Channel:address ?: name`, `Definition:file ?: name`.

- [ ] **Step 2: Write the failing tests**

Add to `tests/protocol.php` (copy the constants/autoloader/`check()` header from `tests/smoke.php`, plus `require __DIR__ . '/fixtures/protocol/AnnotationFixtures.php'`):

```php
$config = require __DIR__ . '/../src/config.php';
$ann = new erikwang2013\apidoc\parses\ParseAnnotation($config);
$class = new ReflectionClass(\Tests\Fixtures\Protocol\GrpcFixture::class);
$attrs = $ann->getClassAnnotation($class);
check('positional Protocol maps to name', ($attrs['protocol'] ?? null) === 'grpc');
$method = $class->getMethod('sayHello');
$mattrs = $ann->getMethodAnnotation($method);
check('named Channel keeps address', ($mattrs['channel']['address'] ?? '') === 'helloworld.Greeter/SayHello');
check('named Channel keeps action', ($mattrs['channel']['action'] ?? '') === 'call');
check('repeatable Definition becomes list', is_array($mattrs['definition']) && count($mattrs['definition']) === 2);
check('grpc catalog label', erikwang2013\apidoc\protocol\ProtocolCatalog::get('grpc')['label'] === 'gRPC');
check('grpc is channel-addressed', erikwang2013\apidoc\protocol\ProtocolCatalog::get('grpc')['http'] === false);
check('grpc is not debuggable', erikwang2013\apidoc\protocol\ProtocolCatalog::get('grpc')['debuggable'] === false);
check('grpc default format is proto', erikwang2013\apidoc\protocol\ProtocolCatalog::get('grpc')['defaultFormat'] === 'proto');
check('http2 is debuggable=false', erikwang2013\apidoc\protocol\ProtocolCatalog::get('http2')['debuggable'] === false);
check('unknown protocol falls back', erikwang2013\apidoc\protocol\ProtocolCatalog::get('nope')['label'] === 'NOPE');
```

Fixture:

```php
namespace Tests\Fixtures\Protocol;
use erikwang2013\apidoc\annotation\{Protocol, Channel, Definition};
#[Protocol('grpc')]
class GrpcFixture {
    #[Channel(address: 'helloworld.Greeter/SayHello', action: 'call')]
    #[Definition('a.proto')]
    #[Definition(file: 'b.proto', operation: 'S/M')]
    public function sayHello() {}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "erikwang2013\apidoc\annotation\Protocol" not found`.

- [ ] **Step 4: Implement the catalog**

Add one entry per canonical id. Required P1 ids and values:

```php
'http'    => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>''],
'http2'   => ['transport'=>'http2', 'http'=>true,  'debuggable'=>false, 'defaultFormat'=>''],
'http3'   => ['transport'=>'http3', 'http'=>true,  'debuggable'=>false, 'defaultFormat'=>''],
'rest'    => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'openapi'],
'soap'    => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'wsdl'],
'graphql' => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'graphql'],
'grpc'    => ['transport'=>'http2', 'http'=>false, 'debuggable'=>false, 'defaultFormat'=>'proto'],
'dubbo'   => ['transport'=>'http2', 'http'=>false, 'debuggable'=>false, 'defaultFormat'=>'proto'],
'json-rpc'=> ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'json-rpc'],
'xml-rpc' => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'xml-rpc'],
'webhooks'=> ['transport'=>'http',  'http'=>false, 'debuggable'=>false, 'defaultFormat'=>'json-schema'],
'mcp'     => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'json-schema'],
'a2a'     => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'json-schema'],
'odata'   => ['transport'=>'http',  'http'=>true,  'debuggable'=>true,  'defaultFormat'=>'edmx'],
```

Add the remaining ids from the spec with `http=false`, `debuggable=false`, `defaultFormat=''`: `websocket`, `sse`, `socket.io`, `thrift`, `hessian`, `amqp`, `mqtt`, `stomp`, `kafka`, `xmpp`, `webrtc`, `sip`, `rtsp`, `rtmp`, `hls`, `rtp`, `rtcp`, `coap`, `lwm2m`, `opc-ua`, `modbus-tcp`, `dnp3`, `iec-104`, `bacnet-ip`, `iso8583`, `tcp`, `udp`, `raw-socket`, `unix-socket`, `binary-body`, `websocket-binary`, `stomp-binary`.
Labels are the human names from the spec (for example `grpc` => `gRPC`, `iec-104` => `IEC 60870-5-104`). `ProtocolCatalog::get()` merges defaults `version=''`, `mode=''`, `debugReason=''`, and returns `label => strtoupper($name)` for unknown ids.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 6: Commit**

```bash
git add src/annotation/Protocol.php src/annotation/Channel.php src/annotation/Definition.php src/protocol/ProtocolCatalog.php tests/protocol.php tests/fixtures/protocol/AnnotationFixtures.php
git commit -m "feat(protocol): add protocol attributes and catalog"
```

---

### Task 2: Path Resolution, Context, and Registry

**Files:**
- Create: `src/protocol/ProtocolPaths.php`
- Create: `src/protocol/ProtocolParseException.php`
- Create: `src/protocol/ProtocolContext.php`
- Create: `src/protocol/AdapterInterface.php`
- Create: `src/protocol/ProtocolRegistry.php`
- Modify: `tests/protocol.php`

**Interfaces:**
- Consumes: `DirAndFile::resolvePathWithinRoot()`, `src/config.php` `protocols` block (created in Task 19; defaults here).
- Produces:
  - `ProtocolPaths::__construct(array $protocolConfig)`, `resolve(string $relativePath): ?array` returning `['realpath'=>string,'declared'=>string]`.
  - `ProtocolParseException::__construct(string $message, int $line = 0, int $column = 0)`, `line(): int`, `column(): int`.
  - `ProtocolContext::__construct(string $file, array $options, array $limits, array $roots)`, `file()`, `option($key,$default)`, `read(): ?string`, `resolve(string $importPath): ?string`, `push(string $realpath): bool`, `pop(): void`, `error()`, `warning()`, `unsupported()`, `errors()`, `warnings()`, `imports()`.
  - `AdapterInterface::format(): string`, `parse(ProtocolContext $ctx, string $content): array` returning `['operation'=>string,'messages'=>array,'meta'=>array]` (`meta` optional).
  - `ProtocolRegistry::detect(string $relativePath, string $content = ''): string`, `adapter(string $format): ?AdapterInterface`, `formats(): array`.

- [ ] **Step 1: Write the failing tests**

Append:

```php
$root = realpath(dirname(__DIR__));
$paths = new erikwang2013\apidoc\protocol\ProtocolPaths([
    'allowed_roots' => [$root],
    'max_file_bytes' => 16,
    'max_imports' => 2,
    'max_depth' => 2,
]);
check('relative path resolves', ($paths->resolve('tests/fixtures/protocol/AnnotationFixtures.php')['realpath'] ?? '') === $root . '/tests/fixtures/protocol/AnnotationFixtures.php');
check('traversal rejected', $paths->resolve('../../etc/passwd') === null);
check('absolute path rejected', $paths->resolve('/etc/passwd') === null);
check('missing file rejected', $paths->resolve('tests/fixtures/protocol/missing.proto') === null);

$tmp = tempnam(sys_get_temp_dir(), 'apidoc-proto-');
file_put_contents($tmp, 'abcdefghijklmnopq');
$ctx = new erikwang2013\apidoc\protocol\ProtocolContext($tmp, ['strict' => true], ['max_file_bytes' => 5, 'max_imports' => 2, 'max_depth' => 2], [$root]);
check('oversized read rejected', $ctx->read() === null);
check('oversized read records error', count($ctx->errors()) === 1);
check('registry detects proto', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.proto') === 'proto');
check('registry detects openapi yaml', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.yaml', "openapi: 3.0.3\n") === 'openapi');
check('registry detects asyncapi', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.yaml', "asyncapi: 2.6.0\n") === 'asyncapi');
check('registry detects edmx', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.xml', '<edmx:Edmx xmlns:edmx="x"></edmx:Edmx>') === 'edmx');
check('unknown format returns empty', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.bin') === '');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "erikwang2013\apidoc\protocol\ProtocolPaths" not found`.

- [ ] **Step 3: Implement `ProtocolPaths`**

Defaults: `allowed_roots => []` means `[APIDOC_ROOT_PATH]`; `max_file_bytes => 2097152`; `max_depth => 32`; `max_lines => 50000`; `max_imports => 64`.
`resolve()`: reject non-string, absolute (`strpos($path, '/') === 0`, `preg_match('#^[A-Za-z]:[\\\\/]#', $path)`), empty, NUL, `..` segments. For each root in order, call `DirAndFile::resolvePathWithinRoot($path, $root)`; first result that `is_file()` wins. Return `['realpath'=>realpath($file),'declared'=>ltrim(str_replace('\\','/',$path),'/')]`; otherwise `null`.

- [ ] **Step 4: Implement `ProtocolContext` and `ProtocolParseException`**

`read()`: current file `filesize`; if `> max_file_bytes`, record `error("file exceeds max_file_bytes", 0, 0)` and return `null`; else `file_get_contents`, record error on failure. Count lines with `substr_count($content, "\n") + 1`; if `> max_lines`, record `error("file exceeds max_lines")` and return `null`.
`resolve($importPath)`: reject absolute/NUL; `$base = dirname($this->file)`; build `$base.'/'.$importPath`; normalize; must be within one allowed root (use `DirAndFile::resolvePathWithinRoot` with root-relative path); must be a file; return `realpath`.
`push($realpath)`: if already visited -> `warning('import cycle skipped')`, return false; if `count($imports) >= max_imports` -> `error('max_imports exceeded')`, false; if `depth >= max_depth` -> `error('max_depth exceeded')`, false; add to visited/imports, set current, depth++, true. `pop()` restores previous file/depth.
`unsupported($message,$line,$column)`: `strict ? error() : warning()`.
Errors/warnings are arrays of `['path'=>'', 'line'=>int, 'column'=>int, 'severity'=>'error'|'warning', 'message'=>string]`.

- [ ] **Step 5: Implement `AdapterInterface` and `ProtocolRegistry`**

`AdapterInterface` exact methods as above. `ProtocolRegistry` static maps:
- extensions: `.proto`=>proto, `.thrift`=>thrift, `.graphql`/`.gql`=>graphql, `.wsdl`=>wsdl.
- `.json`/`.yaml`/`.yml`: use content keys `"openapi"`/`"swagger"`=>openapi, `"asyncapi"`=>asyncapi, `"methods"`=>json-rpc, else `$schema`/`"type"`/`"properties"`=>json-schema.
- `.xml`: content `<edmx:`=>edmx, `<definitions` + `wsdl`=>wsdl, `<methodCall`/`<methodResponse`=>xml-rpc; `<Envelope`/`<definitions`=>wsdl.
- Unknown => `''`.
`adapter($format)` returns a new instance for the P1 formats; unimplemented formats return null (P2/P3). Map `proto`, `openapi`, `asyncapi`, `graphql`, `wsdl`, `edmx`, `json-schema`, `json-rpc`, `xml-rpc` to their classes (classes may not exist yet; guard with `class_exists` so early tasks still run).

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/protocol/ProtocolPaths.php src/protocol/ProtocolParseException.php src/protocol/ProtocolContext.php src/protocol/AdapterInterface.php src/protocol/ProtocolRegistry.php tests/protocol.php
git commit -m "feat(protocol): add definition context, path guard, and registry"
```

---

### Task 3: Metadata Merge and Menu/Detail Integration

**Files:**
- Create: `src/parses/ParseProtocol.php`
- Modify: `src/parses/ParseApiDetail.php:84-90` and `:412-454`
- Modify: `src/parses/ParseApiMenus.php:217-270`
- Modify: `tests/fixtures/protocol/AnnotationFixtures.php`
- Modify: `tests/protocol.php`

**Interfaces:**
- Consumes: Task 1 attributes/catalog, Task 2 registry.
- Produces:
  - `ParseProtocol::__construct(array $config)`, `metadata(ReflectionClass $refClass, ReflectionMethod $refMethod): array` returning `[]` or `['protocol'=>array,'channel'=>array]`.
  - `ParseApiDetail::handleApiBaseInfo($methodInfo,$className,$methodName,$textAnnotations,$config,$protocolMeta=[])` (sixth optional parameter).
  - Menu items gain `protocolKey`, `protocolLabel`, `channelAddress` only when a protocol is declared.
  - Detail items gain `protocolKey`, `protocolLabel`, `channelAddress` scalars only when declared.

- [ ] **Step 1: Write the failing tests**

Add fixtures:

```php
#[Protocol(name: 'grpc', version: '1.0', transport: 'http2', mode: 'unary')]
class GrpcControllerFixture {
    #[Channel(address: 'helloworld.Greeter/SayHello', action: 'call')]
    #[Title('SayHello')]
    public function sayHello() {}
    public function notDocumented() {}
}
#[Protocol('mqtt')]
class MqttControllerFixture {
    #[Channel('/sensors/+/temp', action: 'subscribe')]
    #[Title('Temperature')]
    public function onTemperature() {}
}
class PlainHttpFixture {
    #[Method('GET')]
    #[Url('/plain')]
    #[Title('Plain')]
    public function plain() {}
}
```

Append tests:

```php
$parser = new erikwang2013\apidoc\parses\ParseProtocol($config);
$meta = $parser->metadata(new ReflectionClass(\Tests\Fixtures\Protocol\GrpcControllerFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\GrpcControllerFixture::class))->getMethod('sayHello'));
check('metadata merges class protocol', ($meta['protocol']['label'] ?? '') === 'gRPC');
check('metadata merges method channel', ($meta['channel']['address'] ?? '') === 'helloworld.Greeter/SayHello');
check('grpc renders channel header', ($meta['protocol']['http'] ?? true) === false);
$mqttMeta = $parser->metadata(new ReflectionClass(\Tests\Fixtures\Protocol\MqttControllerFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\MqttControllerFixture::class))->getMethod('onTemperature'));
$base = erikwang2013\apidoc\parses\ParseApiDetail::handleApiBaseInfo(['title'=>'T'], \Tests\Fixtures\Protocol\MqttControllerFixture::class, 'onTemperature', [], $config, $mqttMeta);
check('non-http url falls back to channel', ($base['url'] ?? '') === '/sensors/+/temp');
check('menu scalars are present', ($base['protocolKey'] ?? '') === 'mqtt' && ($base['channelAddress'] ?? '') === '/sensors/+/temp');
$plainMeta = $parser->metadata(new ReflectionClass(\Tests\Fixtures\Protocol\PlainHttpFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\PlainHttpFixture::class))->getMethod('plain'));
check('plain http has no protocol metadata', $plainMeta === []);
```

Menu test:

```php
$menus = new erikwang2013\apidoc\parses\ParseApiMenus($config);
$controller = $menus->parseController(\Tests\Fixtures\Protocol\GrpcControllerFixture::class, false, 'api');
$child = $controller['children'][0] ?? [];
check('menu has protocol key', ($child['protocolKey'] ?? '') === 'grpc');
check('undocumented method excluded', count($controller['children'] ?? []) === 1);
check('menu has no internal fingerprints', !array_key_exists('_definitionFingerprints', $child));
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "erikwang2013\apidoc\parses\ParseProtocol" not found`.

- [ ] **Step 3: Implement `ParseProtocol::metadata()`**

Read class and method attributes with `ParseAnnotation`. Normalize:
- `normalizeAssoc($value, string $fallbackKey)`: string => `[$fallbackKey => $value]`; list of strings => first; array => itself; empty => `[]`.
- `normalizeList($value, string $fallbackKey)`: string => `[[$fallbackKey=>$value]]`; list => map each string to `[$fallbackKey=>$string]`, keep arrays; associative => `[$value]`.
- `protocol = normalizeAssoc(class['protocol'] ?? [], 'name')` then field-by-field override from method; if `name` empty return `[]`.
- `channel = normalizeAssoc(class['channel'] ?? [], 'address')` then override; `address = address ?: name`.
- `catalog = ProtocolCatalog::get(name)`; merge annotation fields: `version`, `transport`, `mode`; `label` always from catalog; `debuggable` from catalog unless `mode` is set? Keep catalog. `debugReason` from catalog when false.
- Return `['protocol'=>$protocol,'channel'=>$channel]`.
- Add `public function render(ReflectionClass $refClass, ReflectionMethod $refMethod, array $methodAnnotations, array $protocolMeta = []): array { return []; }` as a stub; Task 4 fills it.

- [ ] **Step 4: Modify `handleApiBaseInfo`**

Add optional `$protocolMeta = []`. Before the auto-url branch:
```php
if (!empty($protocolMeta['protocol'])) {
    $methodInfo['protocolKey'] = $protocolMeta['protocol']['name'];
    $methodInfo['protocolLabel'] = $protocolMeta['protocol']['label'];
}
if (!empty($protocolMeta['channel']['address'])) {
    $methodInfo['channelAddress'] = $protocolMeta['channel']['address'];
}
```
When `url` is empty and `protocol.http === false` and `channelAddress` is non-empty, set `url = channelAddress`; otherwise keep `autoCreateUrl`. Do not prepend `/` to channel addresses.

- [ ] **Step 5: Wire the parsers**

In `ParseApiDetail::parseApiMethod()`:
- Before `$methodAnnotations = self::handleApiBaseInfo(...)`, create `$protocolMeta = (new ParseProtocol($config))->metadata($refClass,$refMethod);`.
- Pass `$protocolMeta` as the sixth argument.
- After the final response/event merges and before `return`, call `$protocolData = (new ParseProtocol($config))->render($refClass,$refMethod,$methodAnnotations,$protocolMeta);` (render returns `[]` in this task; implemented in Task 4) and merge with `array_merge`.
In `ParseApiMenus::parseApiMethod()`:
- Create `$protocolMeta = (new ParseProtocol($config))->metadata($refClass,$refMethod);`.
- Pass it to `handleApiBaseInfo`.
- Build the key list for `Helper::getArrayValuesByKeys` as the existing keys plus `protocolKey`, `protocolLabel`, `channelAddress` only when `$protocolMeta` is non-empty. This keeps HTTP menu JSON unchanged and preserves the protocol scalars otherwise.
In `ParseApiMenus::parseController()` after either `$methodItem` assignment: `unset($methodItem['_definitionFingerprints']);`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/protocol.php && php tests/smoke.php`
Expected: all `PASS`.

- [ ] **Step 7: Commit**

```bash
git add src/parses/ParseProtocol.php src/parses/ParseApiDetail.php src/parses/ParseApiMenus.php tests/fixtures/protocol/AnnotationFixtures.php tests/protocol.php
git commit -m "feat(protocol): merge protocol metadata into menus and details"
```

---

### Task 4: Inline Definitions, Schema Mapper, JSON Schema Adapter

**Files:**
- Create: `src/protocol/Support/SchemaMapper.php`
- Create: `src/protocol/Support/JsonSchemaValidator.php`
- Create: `src/protocol/Support/RefResolver.php`
- Create: `src/protocol/Adapter/JsonSchemaAdapter.php`
- Modify: `src/parses/ParseProtocol.php`
- Modify: `src/parses/ParseApiDetail.php` (call `ParseProtocol::render()` at the end of `parseApiMethod` and merge the result)
- Create: `tests/fixtures/protocol/json-schema/user.json`
- Create: `tests/fixtures/protocol/json-schema/invalid.json`
- Create: `tests/fixtures/protocol/json-schema/webhook.json`
- Create: `tests/fixtures/protocol/json-schema/user-sample.json`
- Modify: `tests/protocol.php`

**Interfaces:**
- Consumes: `ProtocolContext`, `ProtocolRegistry`, `AdapterInterface`.
- Produces:
  - `SchemaMapper::fields(array $schema, ProtocolContext $ctx, callable $resolveRef): array` where `$resolveRef(string $ref): ?array`.
  - `SchemaMapper::typeOf(array $schema): string`.
  - `JsonSchemaValidator::validate(array $schema, $data, callable $resolveRef): array` returning `[['path'=>'$.x','message'=>'...'], ...]`.
  - `RefResolver::resolve(string $ref, array $root, ProtocolContext $ctx, callable $load): ?array`; `$load(string $content): ?array` parses JSON/YAML. Handles `#/pointer` and `file#/pointer`, records imports via `$ctx->push()`/`pop()`, records an error on failure.
  - `ParseProtocol::render(ReflectionClass $refClass, ReflectionMethod $refMethod, array $methodAnnotations, array $protocolMeta = []): array` returning `[]` or `['protocol'=>..., 'channel'=>..., 'definitions'=>..., 'validation'=>..., '_definitionFingerprints'=>[...]]`.
  - `ParseProtocol::cacheFresh(array $detail): bool` (implemented in Task 5).

- [ ] **Step 1: Write the failing tests**

Fixtures:

`tests/fixtures/protocol/json-schema/user.json`
```json
{
  "title": "User",
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": {"type": "integer"},
    "name": {"type": "string"},
    "roles": {"type": "array", "items": {"type": "string"}}
  }
}
```

`tests/fixtures/protocol/json-schema/invalid.json`
```json
{"title": "Broken", "type": "object", "properties": {"id": {"type": "integer"}
```

`tests/fixtures/protocol/json-schema/webhook.json`
```json
{
  "title": "Hook",
  "type": "object",
  "properties": {"event": {"type": "string"}},
  "x-webhook-signature": {"header": "X-Signature", "algorithm": "hmac-sha256", "encoding": "hex"}
}
```

`tests/fixtures/protocol/json-schema/user-sample.json`
```json
{"id": 1, "name": "a"}
```

Add fixture methods:

```php
#[Protocol('json-rpc')]
class InlineFixture {
    #[Param(name: 'id', type: 'integer', require: true, desc: 'ID')]
    #[Returned(name: 'ok', type: 'boolean', desc: 'OK')]
    #[Title('Inline')]
    public function call() {}
}
#[Protocol('webhooks')]
class JsonSchemaFixture {
    #[Definition(file: 'tests/fixtures/protocol/json-schema/user.json', sample: '{"id":1,"name":"a"}')]
    #[Title('User')]
    public function user() {}
    #[Definition(file: 'tests/fixtures/protocol/json-schema/invalid.json')]
    #[Title('Broken')]
    public function broken() {}
    #[Definition(file: 'tests/fixtures/protocol/json-schema/webhook.json')]
    #[Title('Hook')]
    public function hook() {}
    #[Definition(file: 'tests/fixtures/protocol/json-schema/user.json', sampleFile: 'tests/fixtures/protocol/json-schema/user-sample.json')]
    #[Title('User from file')]
    public function userFile() {}
}
```

Append tests:

```php
$renderer = new erikwang2013\apidoc\parses\ParseProtocol($config);
$inline = $renderer->render(new ReflectionClass(\Tests\Fixtures\Protocol\InlineFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\InlineFixture::class))->getMethod('call'), (new erikwang2013\apidoc\parses\ParseApiDetail($config))->parseApiMethod(new ReflectionClass(\Tests\Fixtures\Protocol\InlineFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\InlineFixture::class))->getMethod('call')));
check('inline definition created', ($inline['definitions'][0]['format'] ?? '') === 'inline');
check('inline request message', ($inline['definitions'][0]['messages'][0]['direction'] ?? '') === 'request');
check('inline request field id', ($inline['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('inline response field ok', ($inline['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'ok');
$detail = (new erikwang2013\apidoc\parses\ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\JsonSchemaFixture@user');
check('json schema definition parses', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('json schema sample valid', ($detail['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
check('file definition has fingerprints', count($detail['_definitionFingerprints'] ?? []) === 1);
$broken = $renderer->render(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('broken'), (new erikwang2013\apidoc\parses\ParseApiDetail($config))->parseApiMethod(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('broken')));
check('malformed json is a validation error', ($broken['definitions'][0]['validation']['ok'] ?? true) === false);
check('malformed json error is visible', strpos(json_encode($broken['definitions'][0]['validation']['errors']), 'JSON') !== false);
$hook = $renderer->render(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('hook'), (new erikwang2013\apidoc\parses\ParseApiDetail($config))->parseApiMethod(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('hook')));
check('webhook signature metadata recorded', ($hook['definitions'][0]['meta']['header'] ?? '') === 'X-Signature');
$fromFile = $renderer->render(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('userFile'), (new erikwang2013\apidoc\parses\ParseApiDetail($config))->parseApiMethod(new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class), (new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class))->getMethod('userFile')));
check('sampleFile is validated', ($fromFile['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
```

Note: `renderApiDetail('api', ...)` needs an app config with key `api`; use `Helper::getCurrentAppConfig`? The test config `apps` has key `api`, but `ParseApiDetail::renderApiDetail` calls `Helper::getCurrentAppConfig` which requires `ConfigProvider::set($config)` first. Add `ConfigProvider::set($config);` at the top of `tests/protocol.php` after loading config.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "erikwang2013\apidoc\protocol\Support\SchemaMapper" not found`.

- [ ] **Step 3: Implement `SchemaMapper` and `JsonSchemaValidator`**

`SchemaMapper::typeOf`: map JSON Schema `type` (`string`, `integer`, `number`, `boolean`, `array`, `object`) to existing param types (`string`, `integer`, `number`, `boolean`, `array`, `object`); no type => `object` if `properties`, else `string`.
`fields`: resolve `$ref` first; handle `allOf` by merging properties/required; `oneOf`/`anyOf` by taking the first branch and adding a warning through `$ctx->warning()`; object properties => one field per property with `require` from `required`; array items => `type='array'`, `children` from item schema; `enum` => `enum` key; `nullable`/`type` array => `require=false` and warning; `description` => `desc`.
`JsonSchemaValidator::validate`: check type, required, properties, items, enum, const, oneOf/anyOf/allOf (allOf all must pass, oneOf exactly one, anyOf at least one), `$ref`; return errors with JSON-path-like `$.field` / `$.items[0]`. Unknown `format` => ignore.
`JsonSchemaAdapter::format() === 'json-schema'`; `parse()`: `json_decode($content, true)`; on `json_last_error()` throw `ProtocolParseException('JSON parse error: '.json_last_error_msg(), 0, 0)`; select schema by `$ctx->option('message')` from `$defs`/`definitions` if set, else root; message name = `$ctx->option('message') ?: ($schema['title'] ?? 'Body')`; direction = `$ctx->option('direction') ?: 'request'`; `messages = [['name'=>..., 'direction'=>..., 'fields'=>SchemaMapper::fields(...), 'sample'=>$ctx->option('sample',''), 'sampleValidation'=>...]]`; `meta = $schema['x-webhook-signature'] ?? []`. Ref resolver: `RefResolver::resolve($ref, $root, $ctx, fn($c) => json_decode($c, true))`; local `#/...` and external `file#/...` both supported.
Sample validation: if sample non-empty, `json_decode`; on invalid JSON add `sampleValidation.errors`; else `JsonSchemaValidator::validate`.

- [ ] **Step 4: Implement `ParseProtocol::render()`**

Steps:
1. If `$protocolMeta` empty, return `[]`.
2. Collect definitions: class-level `definition` list + method-level list; normalize; dedupe by `file|operation|message` with method winning.
3. If no definition entries, build one inline definition (format `inline`) from `$methodAnnotations['param']` as request and `$methodAnnotations['responseSuccess']`/`responseError` as response.
4. For each definition:
   - `format = def['format'] ?: 'auto'`; if `auto` and file non-empty, `ProtocolRegistry::detect($file, $firstBytes)`.
   - If file empty:
     - `format === 'inline'` or no catalog defaultFormat => build inline definition; if catalog defaultFormat is non-empty, return a definition with `format='pending'`, empty messages, validation warning `"definition file required for <protocol>"`.
   - If file non-empty: resolve via `ProtocolPaths`; on failure return definition `['format'=>$format,'source'=>$file,'operation'=>'','messages'=>[], 'validation'=>['ok'=>false,'errors'=>[['path'=>'','line'=>0,'column'=>0,'severity'=>'error','message'=>'definition file not found or outside allowed roots']],'warnings'=>[]]]`.
   - Parse directly in this task; Task 5 adds the definition cache and reuses this parse path:
     - context = new ProtocolContext(realpath, options, limits, roots); content = read; adapter = registry; try parse; catch `ProtocolParseException` / `Throwable` -> validation error.
     - `messages` from adapter; `sampleValidation` per message when `sample`/`sampleFile` set and adapter supports JSON (`json-schema`, `openapi`, `asyncapi`, `json-rpc`). For `sampleFile`, resolve it with `ProtocolPaths` and read it through a `ProtocolContext` so size/root rules apply; the file content becomes the sample.
     - `validation` from context errors/warnings; `imports` from context.
     - definition = `['format'=>$format,'source'=>$preferredSource,'operation'=>$parsed['operation'] ?? '','meta'=>$parsed['meta'] ?? [],'messages'=>$messages,'validation'=>$validation]`.
5. Top-level `validation`: aggregate definition errors/warnings (ok = no errors).
6. `_definitionFingerprints`: for each file-backed definition, `realpath => ['mtime'=>filemtime,'size'=>filesize]` for the root and all imports.
7. Return `array_merge($protocolMeta, ['definitions'=>..., 'validation'=>..., '_definitionFingerprints'=>...])`.

Source string: use `$def['file']` (declared relative) for `source`; never the realpath.

Wire it into `ParseApiDetail::parseApiMethod()` before the final return: `$protocolData = (new ParseProtocol($config))->render($refClass, $refMethod, $methodAnnotations, $protocolMeta);` and `array_merge` the result. Plan A already computes `$protocolMeta` for `handleApiBaseInfo`; reuse that variable. If Plan B Task 3 was skipped, add this wiring now.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 6: Commit**

```bash
git add src/protocol/Support/SchemaMapper.php src/protocol/Support/JsonSchemaValidator.php src/protocol/Support/RefResolver.php src/protocol/Adapter/JsonSchemaAdapter.php src/parses/ParseProtocol.php src/parses/ParseApiDetail.php tests/fixtures/protocol/json-schema tests/protocol.php
git commit -m "feat(protocol): add inline definitions and JSON Schema adapter"
```

---

### Task 5: Cache Fingerprints and Controller Integration

**Files:**
- Modify: `src/parses/ParseProtocol.php`
- Modify: `src/Controller.php:170-220` and `:299-334`
- Modify: `tests/protocol.php`

**Interfaces:**
- Consumes: `ParseProtocol::render()` output with `_definitionFingerprints`.
- Produces:
  - `ParseProtocol::cacheFresh(array $detail): bool`.
  - `Controller::getApiDetail()` uses `apiDetail_v2`, checks `cacheFresh`, strips `_definitionFingerprints` before response.
  - `apiMenu` cache key becomes `apiMenu_v2`.

- [ ] **Step 1: Write the failing tests**

```php
$file = sys_get_temp_dir() . '/apidoc-proto-fp-' . getmypid() . '.json';
file_put_contents($file, '{"type":"object","properties":{"a":{"type":"string"}}}');
$fpDetail = ['definitions' => [['format' => 'json-schema', 'source' => 'x', 'messages' => [], 'validation' => ['ok'=>true,'errors'=>[],'warnings'=>[]]]], '_definitionFingerprints' => [$file => ['mtime' => filemtime($file), 'size' => filesize($file)]]];
check('fingerprint fresh', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === true);
touch($file, time() + 5);
clearstatcache();
check('fingerprint detects mtime change', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === false);
unlink($file);
check('fingerprint detects missing file', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === false);
check('inline-only detail is fresh', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh(['definitions' => [['format' => 'inline']]]) === true);
check('file detail without fingerprints is stale', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh(['definitions' => [['format' => 'json-schema']]]) === false);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Call to undefined method ...::cacheFresh()`.

- [ ] **Step 3: Implement `cacheFresh()`**

If `definitions` empty -> true. If every definition format is `inline` or `pending` -> true. If any file-backed definition and `_definitionFingerprints` empty -> false. For each `realpath => ['mtime'=>int,'size'=>int]`: `is_file`, `filemtime`, `filesize` must match exactly; any mismatch/missing -> false. Return true.

- [ ] **Step 4: Cache parsed definitions**

In `ParseProtocol`, add a private `parseFileDefinition()` cache:
- Key: `protocol/{format}/{md5(declared)}_{md5(realpath)}_{md5(json_encode($options))}`.
- Read with `new Cache()`; if the cached value has `result` and its `fingerprints` pass the same check as `cacheFresh`, return `result`.
- Otherwise parse, build `['result'=>$result,'fingerprints'=>$fingerprints]`, write with `Cache::set()`, return `result`.
- Add a private `fingerprintsFor(array $files): array` used by both the adapter cache and `_definitionFingerprints`; it skips missing files (the caller records the error).
- Include `strict`, `operation`, `message`, `direction`, `sample`, and `sampleEncoding` in `$options` before hashing; `sampleFile` content is resolved to `sample` first so file edits also invalidate the result.

Add a test fixture `CacheFixture` with `#[Definition(file: 'tests/fixtures/protocol/json-schema/cache.json')]`. The test writes the file with property `a`, renders, rewrites it with property `b`, renders again, and asserts the second render contains `b`. Delete the file at the end.

- [ ] **Step 5: Wire Controller cache**

In `getApiDetail()`:
```php
$cacheKey = Helper::getCacheKey('apiDetail_v2', $appKey, $this->lang, $cachePath);
$cacheData = (new Cache())->get($cacheKey);
if ($cacheData && empty($params['reload']) && ParseProtocol::cacheFresh($cacheData)) {
    $res = $cacheData;
} else {
    $res = (new ParseApiDetail($config))->renderApiDetail($appKey, $apiKey);
    (new Cache())->set($cacheKey, $res);
}
unset($res['_definitionFingerprints']);
```
Add `use erikwang2013\apidoc\parses\ParseProtocol;`.
In `getApiMenusByAppKey()` change the key to `apiMenu_v2`.
In `createAllCache()` change both keys to `apiDetail_v2` and `apiMenu_v2`.
Do not strip `_definitionFingerprints` in `createAllCache` (the cache needs it).

- [ ] **Step 6: Verify controller wiring**

Run:
```bash
php tests/protocol.php
php tests/smoke.php
grep -n "apiDetail_v2\|apiMenu_v2\|cacheFresh\|unset(\$res\['_definitionFingerprints'\])" src/Controller.php
```
Expected: tests PASS; grep shows all four markers.

- [ ] **Step 7: Commit**

```bash
git add src/parses/ParseProtocol.php src/Controller.php tests/protocol.php
git commit -m "feat(protocol): cache parsed definitions with file fingerprints"
```

---

### Task 6: YAML Subset Parser

**Files:**
- Create: `src/protocol/Support/Yaml.php`
- Modify: `tests/protocol.php`

**Interfaces:**
- Produces: `Yaml::parse(string $source): array`, throwing `ProtocolParseException` with the failing line.

- [ ] **Step 1: Write the failing tests**

```php
$yaml = erikwang2013\apidoc\protocol\Support\Yaml::parse("title: T\nservers:\n  - url: a\n  - url: b\ninline: [a, b]\n");
check('yaml mapping', ($yaml['title'] ?? '') === 'T');
check('yaml sequence of maps', ($yaml['servers'][1]['url'] ?? '') === 'b');
check('yaml inline list', ($yaml['inline'] ?? []) === ['a', 'b']);
$threw = false;
try { erikwang2013\apidoc\protocol\Support\Yaml::parse("a: &x 1\nb: *x\n"); } catch (erikwang2013\apidoc\protocol\ProtocolParseException $e) { $threw = true; check('yaml anchor error line', $e->line() > 0); }
check('yaml rejects anchors', $threw === true);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "...Support\Yaml" not found`.

- [ ] **Step 3: Implement the subset**

Support: mappings `key: value`; nested by indentation; sequences `- item` and `- key: value`; scalars (quoted/unquoted, int/float/bool/null); inline lists `[a, b]` and inline maps `{a: b}` of scalars; comments `#` outside quotes; block scalars `|`/`>`; blank lines. Reject: tabs in indentation, anchors `&`/aliases `*`, multi-document `---`, tags `!`. Throw `ProtocolParseException("YAML ...", $line, 0)`. Use a line stack, not regex backtracking.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Support/Yaml.php tests/protocol.php
git commit -m "feat(protocol): add YAML subset parser"
```

---

### Task 7: Safe XML and Token Stream

**Files:**
- Create: `src/protocol/Support/SafeXml.php`
- Create: `src/protocol/Support/TokenStream.php`
- Modify: `tests/protocol.php`

**Interfaces:**
- Produces:
  - `SafeXml::load(string $xml): \DOMDocument`, `first(\DOMNode $node, string $localName): ?\DOMElement`, `all(\DOMNode $node, string $localName): array`, `attr(\DOMElement $el, string $name): string`.
  - `TokenStream::__construct(string $source)`, `eof(): bool`, `peek(int $offset=0): string`, `consume(string $chars): ?string`, `expect(string $char): void`, `identifier(): string`, `number(): string`, `string(): string`, `skipTrivia(): void`, `line(): int`, `column(): int`, `error(string $message): void`.

- [ ] **Step 1: Write the failing tests**

```php
$doc = erikwang2013\apidoc\protocol\Support\SafeXml::load('<root><a x="1">v</a></root>');
check('xml first finds element', erikwang2013\apidoc\protocol\Support\SafeXml::attr(erikwang2013\apidoc\protocol\Support\SafeXml::first($doc->documentElement, 'a'), 'x') === '1');
$threw = false;
try { erikwang2013\apidoc\protocol\Support\SafeXml::load('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'); } catch (erikwang2013\apidoc\protocol\ProtocolParseException $e) { $threw = true; }
check('xml rejects doctype', $threw === true);
$ts = new erikwang2013\apidoc\protocol\Support\TokenStream("// c\nmessage A { string name = 1; }");
$ts->skipTrivia();
check('token identifier', $ts->identifier() === 'message');
check('token line tracking', $ts->line() === 2);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol.php`
Expected: FAIL with `Class "...Support\SafeXml" not found`.

- [ ] **Step 3: Implement `SafeXml`**

Reject `stripos($xml, '<!DOCTYPE') !== false` with `ProtocolParseException('DOCTYPE is not allowed', 0, 0)`. Use `libxml_use_internal_errors(true)`, `$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)`, collect `libxml_get_errors()` and throw with the first error's line. Never call `libxml_disable_entity_loader` (removed in PHP 8); `LIBXML_NONET` plus DOCTYPE rejection is the guard. `first`/`all` match by `localName` (namespace-agnostic).

- [ ] **Step 4: Implement `TokenStream`**

Skip whitespace and comments: `//...\n`, `/*...*/`, `#...\n`. `peek` returns the next significant char without consuming. `identifier` accepts `[A-Za-z_][A-Za-z0-9_.]*`. `number` accepts integer/float/exponent. `string` supports `"..."` and `'...'` with `\\` escapes. `expect` throws `ProtocolParseException("expected <char>", line, column)`. Track line/column on every consumed char.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 6: Commit**

```bash
git add src/protocol/Support/SafeXml.php src/protocol/Support/TokenStream.php tests/protocol.php
git commit -m "feat(protocol): add safe XML loader and token stream"
```

---

### Task 8: UI Types, Protocol Badge, and Menu Badge

**Files:**
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/api/apidocApi/types.ts`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/ProtocolBadge/ProtocolBadge.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/ProtocolBadge/index.ts`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/components/Menu/src/MenuItemContent.vue`

**Interfaces:**
- Consumes: backend contract from Task 3/4.
- Produces:
  - Types: `ApiProtocolInfo`, `ApiChannelInfo`, `ApiValidationError`, `ApiValidationInfo`, `ApiMessageInfo`, `ApiDefinitionInfo`.
  - `ApiMenuItem` optional `protocolKey/protocolLabel/channelAddress`.
  - `ApiDetailResult` optional `protocol/channel/definitions/validation`.
  - `<ProtocolBadge :protocol? :protocol-key? :protocol-label? />`.

- [ ] **Step 1: Extend the TypeScript contract**

Add the interfaces exactly as named above. `ApiProtocolInfo` fields: `name`, `label`, `version?`, `transport?`, `mode?`, `http: boolean`, `debuggable: boolean`, `debugReason?`. `ApiDefinitionInfo`: `format`, `source`, `operation?`, `meta?: Record<string, unknown>`, `messages: ApiMessageInfo[]`, `validation: ApiValidationInfo`. `ApiMessageInfo`: `name`, `direction: 'request'|'response'|'event'|'notification'`, `fields: ApiDetailParamItem[]`, `sample?`, `sampleValidation?`. `ApiValidationError`: `path?`, `line?`, `column?`, `severity: 'error'|'warning'`, `message`.

- [ ] **Step 2: Create the badge**

`ProtocolBadge.vue` with props:
```ts
const props = withDefaults(defineProps<{
  protocol?: ApiProtocolInfo
  protocolKey?: string
  protocolLabel?: string
}>(), {})
const label = computed(() => props.protocol?.label || props.protocolLabel || (props.protocolKey || '').toUpperCase())
const transport = computed(() => props.protocol?.transport || '')
```
Template: `<span class="protocol-badge"><span class="protocol-badge__label">{{ label }}</span><span v-if="transport" class="protocol-badge__transport">{{ transport }}</span></span>`. Style: inline-flex, 11px, uppercase label, muted transport, border, 3px radius. Export from `index.ts`.

- [ ] **Step 3: Update the menu item**

Import `ProtocolBadge` and insert a branch before the method badges:
```vue
<span v-if="item.type == 'controller'"><CopyrightCircleOutlined /></span>
<ProtocolBadge
  v-else-if="item.protocolKey"
  :protocol-key="item.protocolKey"
  :protocol-label="item.protocolLabel"
/>
<span v-else-if="item.method && typeof item.method == 'object' && item.method.length">...</span>
<span v-else-if="item.method">...</span>
<FileMarkdownOutlined v-else-if="item.path && item.type === 'md'" />
<FolderOutlined v-else />
```
This ensures non-HTTP items (which have `method='*'`) show the protocol badge, not `*`.

- [ ] **Step 4: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: `vue-tsc` passes and Vite build completes.

- [ ] **Step 5: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/api/apidocApi/types.ts src/components/ProtocolBadge src/components/Menu/src/MenuItemContent.vue
git commit -m "feat(ui): add protocol types, badge, and menu badge"
```

---

### Task 9: UI Detail Header, Protocol Tab, Debug Gating

**Files:**
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/index.vue`
- Create: `/home/wwwroot/erikwang2013/apidoc-ui/src/pages/apiDetail/ProtocolTab/Index.vue`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/public/config.js`

**Interfaces:**
- Consumes: Task 8 types/badge; backend detail `protocol`, `channel`, `definitions`, `validation`.
- Produces: a `protocol` tab, a channel-style header for `protocol.http === false`, and debug-tab gating by `protocol.debuggable`.

- [ ] **Step 1: Add i18n keys and tab config**

In `public/config.js`:
- Change `API_DETAIL_TABS` to `["table", "json", "ts", "protocol", "debug"]`.
- Add to every `LANG` entry under `apiPage`:
  - `"tabs.protocol": "协议"` / `"Protocol"`
  - `"protocol.definition": "定义"` / `"Definition"`
  - `"protocol.source": "来源"` / `"Source"`
  - `"protocol.format": "格式"` / `"Format"`
  - `"protocol.operation": "操作"` / `"Operation"`
  - `"protocol.messages": "消息"` / `"Messages"`
  - `"protocol.validation": "校验"` / `"Validation"`
  - `"protocol.request": "请求"` / `"Request"`
  - `"protocol.response": "响应"` / `"Response"`
  - `"protocol.event": "事件"` / `"Event"`
  - `"protocol.notification": "通知"` / `"Notification"`
  - `"protocol.noDefinitions": "该接口尚未配置定义文件"` / `"No definition configured"`
  - `"protocol.debugDisabled": "该协议不支持在线调试"` / `"Online debugging is not supported"`

- [ ] **Step 2: Update the detail page header**

Import `ProtocolBadge`. In the template:
- Show `<protocol-badge v-if="state.detail.protocol" :protocol="state.detail.protocol" />` next to the title (inside the `text-grid` or directly after `<h1>`).
- When `state.detail.protocol && !state.detail.protocol.debuggable`, show a muted `<span class="protocol-debug-reason">{{ state.detail.protocol.debugReason || t('apiPage.protocol.debugDisabled') }}</span>` next to the badge.
- Wrap the existing `.api-url` block in `v-if="!state.detail.protocol || state.detail.protocol.http"`.
- Add an `v-else` block:
```vue
<div v-else class="api-url">
  <div :class="['api-url-method', 'protocol-action']">{{ state.detail.channel?.action || state.detail.protocol?.mode || 'CALL' }}</div>
  <div class="api-url-input">
    <input :value="state.detail.channel?.address || ''" readonly />
  </div>
  <div class="api-url-copy" @click="onCopyChannel"><CopyOutlined /></div>
</div>
```
`onCopyChannel` copies `state.detail.channel?.address`.

- [ ] **Step 3: Update tab computation**

In `fetchApiDetail`, after setting `state.detail`:
```ts
const configured = appStore.feConfig.API_DETAIL_TABS || ['table', 'json', 'ts', 'debug']
let nextTabs = configured.includes('protocol') ? [...configured] : [...configured, 'protocol']
if (!data.protocol) nextTabs = nextTabs.filter((p) => p !== 'protocol')
if (data.notDebug || (data.protocol && !data.protocol.debuggable)) nextTabs = nextTabs.filter((p) => p !== 'debug')
state.tabs = nextTabs
```
Remove the old `if (state.detail.notDebug) ... else ...` block.

- [ ] **Step 4: Create the ProtocolTab component**

`ProtocolTab/Index.vue`:
- Props `{ detail: ApiDetailResult }`.
- If `!detail.definitions?.length`, render `a-empty` with `t('apiPage.protocol.noDefinitions')`.
- For each definition, render `a-card`:
  - Title: `${def.format} · ${def.source}`.
  - `a-descriptions` with format/source/operation.
  - If `def.meta` has keys, render one `a-descriptions-item` per key using `String(value)`.
  - `a-alert v-if="!def.validation.ok"` type `error`, listing `err.line ? `${err.line}:${err.column} ` : ''` + `err.message`.
  - Message directions: computed unique `def.messages.map(m => m.direction)`; `a-tabs` with `a-tab-pane` per direction and label from `t('apiPage.protocol.'+direction)`.
  - For each message: `<h4>{{ msg.name }}</h4>`, `<table-card :title="msg.name" :columns="columns" :data="msg.fields" />`, and `a-alert` if `msg.sampleValidation && !msg.sampleValidation.ok`.
- `columns` = field/type/require/desc using existing i18n keys `apiPage.common.field`, `apiPage.common.method`, `apiPage.common.require`, `apiPage.common.desc`.
- Import `TableCard` from `../tableTab/TableCard.vue`.
- In `apiDetail/index.vue` add `<protocol-tab v-else-if="key == 'protocol'" :detail="state.detail" />` and an icon (`<ApiOutlined />`).

- [ ] **Step 5: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: `vue-tsc` passes and Vite build completes.

- [ ] **Step 6: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/pages/apiDetail/index.vue src/pages/apiDetail/ProtocolTab public/config.js
git commit -m "feat(ui): add protocol header, tab, and debug gating"
```

---

### Task 10: OpenAPI Adapter

**Files:**
- Create: `src/protocol/Adapter/OpenApiAdapter.php`
- Create: `tests/fixtures/protocol/AdapterFixtures.php` (all adapter fixture classes; later adapter tasks append to it)
- Create: `tests/fixtures/protocol/openapi/api.yaml`
- Create: `tests/fixtures/protocol/openapi/user.yaml`
- Modify: `tests/protocol_adapters.php` (create the file in this task with the same harness header as `tests/protocol.php`)

**Interfaces:**
- Consumes: `Yaml::parse`, `SchemaMapper`, `JsonSchemaValidator`, `ProtocolContext`.
- Produces: `OpenApiAdapter::format() === 'openapi'`; `parse()` returns `['operation'=>string,'messages'=>array]`.

- [ ] **Step 1: Write the failing tests**

Fixture `api.yaml`:
```yaml
openapi: 3.0.3
info: {title: Users, version: "1"}
paths:
  /users/{id}:
    get:
      operationId: getUser
      parameters:
        - {name: id, in: path, required: true, schema: {type: integer}}
      responses:
        "200":
          content:
            application/json:
              schema: {$ref: './user.yaml#/User'}
```

Fixture `user.yaml`:
```yaml
User:
  type: object
  required: [id]
  properties:
    id: {type: integer}
    name: {type: string}
```

Tests:
```php
$adapter = new erikwang2013\apidoc\protocol\Adapter\OpenApiAdapter();
check('openapi format', $adapter->format() === 'openapi');
$detail = renderFixture('api', \Tests\Fixtures\Protocol\OpenApiFixture::class, 'getUser');
check('openapi operation', ($detail['definitions'][0]['operation'] ?? '') === 'getUser');
check('openapi request id', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('openapi response id', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'id');
check('openapi external ref resolved', ($detail['definitions'][0]['messages'][1]['fields'][1]['name'] ?? '') === 'name');
check('openapi external import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('openapi valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
```

Add a fixture class to `tests/fixtures/protocol/AdapterFixtures.php` (namespace `Tests\Fixtures\Protocol`, require it from the adapter test harness):
```php
#[Protocol('rest')]
class OpenApiFixture {
    #[Definition(file: 'tests/fixtures/protocol/openapi/api.yaml', operation: 'getUser')]
    #[Title('Get user')]
    public function getUser() {}
}
```

Helper `renderFixture($appKey, $class, $method)` at the top of `tests/protocol_adapters.php`: `return (new ParseApiDetail($config))->renderApiDetail($appKey, $class . '@' . $method);`. The harness must call `ConfigProvider::set($config)` once after loading config.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with `Class "...Adapter\OpenApiAdapter" not found`.

- [ ] **Step 3: Implement the adapter**

- Load content: JSON if first non-space char is `{` or `[`, else `Yaml::parse`; catch and rethrow as `ProtocolParseException`.
- Require `openapi` starting with `3.` or `swagger` `2.`; otherwise error `"unsupported OpenAPI version"`.
- Select operation:
  - `operation` option matches `operationId` first, then `METHOD /path`.
  - If empty and exactly one operation exists, use it; if multiple, `$ctx->error("operation required; candidates: ...")` and use the first.
- Request fields: parameters (`name`, `in`, `required`, `schema`) + `requestBody.content['application/json'].schema` properties; mark parameter `in` as extra key `in`.
- Response: first status key starting with `2`; schema from `content['application/json'].schema` or `content['*/*'].schema`.
- `$ref` resolver: use `RefResolver::resolve($ref, $root, $ctx, $load)` with `$load` = JSON-or-YAML parse. This handles `#/components/schemas/X` and `./file.yaml#/X` and records imports.
- Messages: `Request` (direction request), `Response <status>` (direction response). Fields via `SchemaMapper::fields`.
- Sample validation: if `$ctx->option('sample')` non-empty, validate the JSON sample against the response schema (or request if `direction=request`).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/protocol_adapters.php && php tests/protocol.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/OpenApiAdapter.php tests/fixtures/protocol/openapi tests/protocol_adapters.php
git commit -m "feat(protocol): add OpenAPI adapter"
```

---

### Task 11: AsyncAPI Adapter

**Files:**
- Create: `src/protocol/Adapter/AsyncApiAdapter.php`
- Create: `tests/fixtures/protocol/asyncapi/asyncapi.yaml`
- Create: `tests/fixtures/protocol/asyncapi/messages.yaml`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `Yaml::parse`, `SchemaMapper`, `JsonSchemaValidator`, `ProtocolContext`.
- Produces: `AsyncApiAdapter::format() === 'asyncapi'`; operation selector `<channel>:<publish|subscribe>`.

- [ ] **Step 1: Write the failing tests**

Fixture:
```yaml
asyncapi: "2.6.0"
info: {title: Sensors, version: "1"}
channels:
  sensors/+/temp:
    publish:
      message:
        $ref: './messages.yaml#/Temperature'
```

`messages.yaml`:
```yaml
Temperature:
  name: Temperature
  payload:
    type: object
    required: [value]
    properties:
      value: {type: number}
      unit: {type: string}
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\AsyncApiFixture::class, 'onTemp');
check('asyncapi operation', ($detail['definitions'][0]['operation'] ?? '') === 'sensors/+/temp:publish');
check('asyncapi message name', ($detail['definitions'][0]['messages'][0]['name'] ?? '') === 'Temperature');
check('asyncapi direction event', ($detail['definitions'][0]['messages'][0]['direction'] ?? '') === 'event');
check('asyncapi field value', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'value');
check('asyncapi external import', count($detail['_definitionFingerprints'] ?? []) === 2);
```

Fixture class:
```php
#[Protocol('mqtt')]
class AsyncApiFixture {
    #[Channel('/sensors/+/temp', action: 'subscribe')]
    #[Definition(file: 'tests/fixtures/protocol/asyncapi/asyncapi.yaml', operation: 'sensors/+/temp:publish')]
    #[Title('Temperature')]
    public function onTemp() {}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- JSON/YAML detection as in Task 10. Require `asyncapi` `2.` or `3.`.
- 2.x: operation selector `<channel>:<publish|subscribe>`; look up `channels[channel][action].message`.
- 3.x: `operations[operationId]` -> `channel` + `messages`; support selector `operationId` or `<channel>:<action>`.
- Resolve message `$ref` and payload schema `$ref` with `RefResolver::resolve()` (local `#/components/messages/X` and external `file#/X`).
- Message name from `message.name` or `payload.title` or `Message`; direction from `$ctx->option('direction') ?: 'event'`.
- Fields via `SchemaMapper`.
- Sample validation: JSON sample against payload schema.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/AsyncApiAdapter.php tests/fixtures/protocol/asyncapi tests/protocol_adapters.php
git commit -m "feat(protocol): add AsyncAPI adapter"
```

---

### Task 12: GraphQL Adapter

**Files:**
- Create: `src/protocol/Adapter/GraphqlAdapter.php`
- Create: `tests/fixtures/protocol/graphql/schema.graphql`
- Create: `tests/fixtures/protocol/graphql/user.graphql`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `TokenStream`.
- Produces: `GraphqlAdapter::format() === 'graphql'`; operation selector `Type.field`.

- [ ] **Step 1: Write the failing tests**

`schema.graphql`:
```graphql
#import "user.graphql"
type Query {
  user(id: ID!): User
}
```

`user.graphql`:
```graphql
type User {
  id: ID!
  name: String
  tags: [String!]!
}
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\GraphqlFixture::class, 'user');
check('graphql operation', ($detail['definitions'][0]['operation'] ?? '') === 'Query.user');
check('graphql request id required', ($detail['definitions'][0]['messages'][0]['fields'][0]['require'] ?? false) === true);
check('graphql response id', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'id');
check('graphql list type', ($detail['definitions'][0]['messages'][1]['fields'][2]['type'] ?? '') === 'array');
check('graphql import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
```

Fixture class:
```php
#[Protocol('graphql')]
class GraphqlFixture {
    #[Definition(file: 'tests/fixtures/protocol/graphql/schema.graphql', operation: 'Query.user')]
    #[Title('User')]
    public function user() {}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- Tokenize with `TokenStream`: skip descriptions (`"""..."""` and `"..."`), parse `type`, `input`, `interface`, `enum`, `scalar`, `union`, `schema`, `extend`, `directive`.
- Type syntax: `Name`, `[Type]`, `Type!`, arguments `name: Type = default`.
- `#import "file"` lines at the top: resolve via `$ctx->resolve`, push/pop, merge type maps.
- Operation selector: `Type.field` (e.g., `Query.user`) or just field if unique. If empty and exactly one field on the `schema { query: ... }` type (or `Query`), use it; else error with candidates.
- Request fields from arguments; response fields from the return type: scalar -> single field named `value`? Better: if return type is an object, fields are the object's fields; if scalar, one field named `result`. For `User`, fields id/name/tags.
- Type mapping: `String`/`ID`->string, `Int`->integer, `Float`->number, `Boolean`->boolean, `[T]`->array with `childrenType`/`children`, `T!`->require true, enum->string + `enum` values.
- Resolve nested object fields recursively with a visited set to break cycles.
- Sample validation: JSON sample against the response fields only if `sample` provided; validate required keys and scalar types (no GraphQL execution).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/GraphqlAdapter.php tests/fixtures/protocol/graphql tests/protocol_adapters.php
git commit -m "feat(protocol): add GraphQL SDL adapter"
```

---

### Task 13: Proto Adapter

**Files:**
- Create: `src/protocol/Adapter/ProtoAdapter.php`
- Create: `tests/fixtures/protocol/proto/helloworld.proto`
- Create: `tests/fixtures/protocol/proto/common.proto`
- Create: `tests/fixtures/protocol/proto/unsupported.proto`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `TokenStream`.
- Produces: `ProtoAdapter::format() === 'proto'`; operation selector `Service/Method`, `package.Service/Method`, or a unique method name.

- [ ] **Step 1: Write the failing tests**

`helloworld.proto`:
```proto
syntax = "proto3";
package helloworld;
import "common.proto";
message HelloRequest { string name = 1; common.Meta meta = 2; repeated string tags = 3; }
message HelloReply { string message = 1; }
service Greeter {
  rpc SayHello (HelloRequest) returns (HelloReply);
}
```

`common.proto`:
```proto
syntax = "proto3";
package common;
message Meta { string trace_id = 1; }
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\ProtoFixture::class, 'sayHello');
check('proto operation', ($detail['definitions'][0]['operation'] ?? '') === 'helloworld.Greeter/SayHello');
$req = $detail['definitions'][0]['messages'][0];
$res = $detail['definitions'][0]['messages'][1];
check('proto request name field', ($req['fields'][0]['name'] ?? '') === 'name');
check('proto request field number', ($req['fields'][0]['fieldNumber'] ?? 0) === 1);
check('proto repeated is array', ($req['fields'][2]['type'] ?? '') === 'array');
check('proto imported message resolves', ($req['fields'][1]['children'][0]['name'] ?? '') === 'trace_id');
check('proto import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('proto response message field', ($res['fields'][0]['name'] ?? '') === 'message');
$bad = renderFixture('api', \Tests\Fixtures\Protocol\ProtoBadFixture::class, 'bad');
check('proto unsupported construct reported', ($bad['definitions'][0]['validation']['ok'] ?? true) === false);
check('proto error has line', ($bad['definitions'][0]['validation']['errors'][0]['line'] ?? 0) > 0);
```

Fixture classes:
```php
#[Protocol('grpc')]
class ProtoFixture {
    #[Definition(file: 'tests/fixtures/protocol/proto/helloworld.proto', operation: 'helloworld.Greeter/SayHello')]
    #[Title('SayHello')]
    public function sayHello() {}
}
#[Protocol('grpc')]
class ProtoBadFixture {
    #[Definition(file: 'tests/fixtures/protocol/proto/unsupported.proto')]
    #[Title('Bad')]
    public function bad() {}
}
```

`unsupported.proto`:
```proto
syntax = "proto3";
extend Foo { optional string x = 100; }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- Parse top-level: `syntax`, `package`, `import` (skip `public`/`weak`), `option` (skip to `;`), `message`, `enum`, `service`.
- Message fields: `[repeated|optional|required] Type name = number [options];`, `map<K,V> name = number;`, `oneof Name { ... }` (flatten fields with `oneof` key), nested `message`/`enum`.
- Service: `rpc Name (Req) returns (Res);` and `(stream Req)` / `returns (stream Res)` -> mode notes in message extra keys `stream=true`.
- Imports: resolve via `$ctx->resolve`, push/pop, parse, merge symbol tables. A missing import is an error with the import line. `google/protobuf/*.proto` is not built in; if present locally under roots, parse normally; otherwise error.
- Operation selection: exact `Service/Method` or `package.Service/Method`; if empty and exactly one rpc, use it; else `$ctx->error("operation required; candidates: ...")`.
- Field mapping: scalar map (`string`->string, `bool`->boolean, integer types->integer, `float/double`->number, `bytes`->string with `format:'bytes'`); message type -> `object` with `children` from the referenced message (cycle guard returns `children=[]` + warning); enum -> `string` with `enum` values; `repeated` -> `array` with `repeated:true`; `map` -> `object` with `map:true` and key/value children.
- Unsupported top-level `extend`/`group`/`edition`: `$ctx->unsupported("unsupported construct: extend", $line, $col)`.
- Messages: request direction request, response direction response; names from rpc types.
- Sample validation: not supported in P1 (binary protobuf); if `sample` non-empty, add warning `"protobuf sample validation is planned for P3"`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/ProtoAdapter.php tests/fixtures/protocol/proto tests/protocol_adapters.php
git commit -m "feat(protocol): add protobuf IDL adapter"
```

---

### Task 14: WSDL Adapter

**Files:**
- Create: `src/protocol/Adapter/WsdlAdapter.php`
- Create: `tests/fixtures/protocol/wsdl/calculator.wsdl`
- Create: `tests/fixtures/protocol/wsdl/types.xsd`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `SafeXml`.
- Produces: `WsdlAdapter::format() === 'wsdl'`; operation selector `Service/Port/Operation`, `PortType/Operation`, or a unique operation name.

- [ ] **Step 1: Write the failing tests**

Fixture `calculator.wsdl`:
```xml
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:calc">
  <types><xsd:schema><xsd:include schemaLocation="types.xsd"/></xsd:schema></types>
  <message name="AddRequest"><part name="parameters" element="tns:Add"/></message>
  <message name="AddResponse"><part name="parameters" element="tns:AddResponse"/></message>
  <portType name="CalculatorPortType"><operation name="Add"><input message="tns:AddRequest"/><output message="tns:AddResponse"/></operation></portType>
  <binding name="CalculatorBinding" type="tns:CalculatorPortType"><soap:binding style="document"/><operation name="Add"><soap:operation soapAction="urn:add"/></operation></binding>
  <service name="CalculatorService"><port name="CalculatorPort" binding="tns:CalculatorBinding"><soap:address location="http://example.test/calc"/></port></service>
</definitions>
```

`types.xsd`:
```xml
<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:calc">
  <xsd:element name="Add"><xsd:complexType><xsd:sequence>
    <xsd:element name="a" type="xsd:int"/>
    <xsd:element name="b" type="xsd:int"/>
  </xsd:sequence></xsd:complexType></xsd:element>
  <xsd:element name="AddResponse"><xsd:complexType><xsd:sequence>
    <xsd:element name="result" type="xsd:int"/>
  </xsd:sequence></xsd:complexType></xsd:element>
</xsd:schema>
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\WsdlFixture::class, 'add');
check('wsdl operation', ($detail['definitions'][0]['operation'] ?? '') === 'CalculatorService/CalculatorPort/Add');
check('wsdl request a', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'a');
check('wsdl request b', ($detail['definitions'][0]['messages'][0]['fields'][1]['name'] ?? '') === 'b');
check('wsdl response result', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'result');
check('wsdl xsd import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('wsdl valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- `SafeXml::load`; if `!class_exists('DOMDocument')` -> `$ctx->error("ext-dom is required for WSDL")`.
- Parse `definitions`, `types/xsd:schema`, `message`, `portType/operation`, `binding`, `service/port`.
- Build maps by `name` attributes; resolve `tns:`/namespace prefixes by local name.
- Operation selection as above; if empty and one operation, use it; else error candidates.
- XSD subset: `element` (`name`, `type`, `minOccurs`, `maxOccurs`), inline `complexType`/`simpleType`, `sequence`, `attribute`, `enumeration`, `import`/`include` (`schemaLocation`).
- Imports: `<xsd:import>`/`<xsd:include>` and `<wsdl:import location>` -> `$ctx->resolve`, push/pop, parse; merge element/type maps.
- Field mapping: `xsd:string`->string, `xsd:int/integer/long/short`->integer, `xsd:decimal/float/double`->number, `xsd:boolean`->boolean, complex type -> object with children, `maxOccurs>1` or `unbounded` -> array.
- Messages: request from input message parts/elements, response from output. Element names become message names; fields from XSD.
- Sample validation: if `sample` non-empty, check XML well-formed via `SafeXml::load` and, when the root is `Envelope`, that a `Body` child local name equals the operation input element; full XSD validation is deferred with a warning.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/WsdlAdapter.php tests/fixtures/protocol/wsdl tests/protocol_adapters.php
git commit -m "feat(protocol): add WSDL/XSD adapter"
```

---

### Task 15: EDMX Adapter

**Files:**
- Create: `src/protocol/Adapter/EdmxAdapter.php`
- Create: `tests/fixtures/protocol/edmx/metadata.xml`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `SafeXml`, `JsonSchemaValidator`.
- Produces: `EdmxAdapter::format() === 'edmx'`; operation selector entity set name or function import name.

- [ ] **Step 1: Write the failing tests**

Fixture:
```xml
<edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx" Version="4.0">
  <edmx:DataServices>
    <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="Demo">
      <EntityType Name="Product"><Key><PropertyRef Name="ID"/></Key>
        <Property Name="ID" Type="Edm.Int32" Nullable="false"/>
        <Property Name="Name" Type="Edm.String"/>
        <Property Name="Price" Type="Edm.Decimal"/>
      </EntityType>
      <EntityContainer Name="Container"><EntitySet Name="Products" EntityType="Demo.Product"/></EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\EdmxFixture::class, 'products');
check('edmx operation', ($detail['definitions'][0]['operation'] ?? '') === 'Products');
check('edmx id required', ($detail['definitions'][0]['messages'][0]['fields'][0]['require'] ?? false) === true);
check('edmx price type', ($detail['definitions'][0]['messages'][0]['fields'][2]['type'] ?? '') === 'number');
check('edmx valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- Parse `EntityType`/`ComplexType`/`EntitySet`/`FunctionImport` by local name.
- Operation: entity set name or function import name; if empty and one candidate, use it; else error candidates.
- Messages: one message direction `response` (entity set) or `request` (function import) named after the operation; fields from properties.
- Type map: `Edm.Int32/Int64/Int16/Byte`->integer, `Edm.String`->string, `Edm.Boolean`->boolean, `Edm.Decimal/Double/Single`->number, `Edm.DateTimeOffset/Guid`->string, `Edm.Collection`->array.
- `Nullable="false"` -> require true.
- Navigation properties -> object field with `ref` to target entity type.
- Sample validation: JSON sample against the field list (required + scalar types) using `JsonSchemaValidator` with a synthesized schema.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/EdmxAdapter.php tests/fixtures/protocol/edmx tests/protocol_adapters.php
git commit -m "feat(protocol): add OData EDMX adapter"
```

---

### Task 16: JSON-RPC Adapter

**Files:**
- Create: `src/protocol/Adapter/JsonRpcAdapter.php`
- Create: `tests/fixtures/protocol/json-rpc/methods.json`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `SchemaMapper`, `JsonSchemaValidator`.
- Produces: `JsonRpcAdapter::format() === 'json-rpc'`; operation selector method name.

- [ ] **Step 1: Write the failing tests**

Fixture:
```json
{
  "methods": {
    "user.get": {
      "params": {"type": "object", "required": ["id"], "properties": {"id": {"type": "integer"}}},
      "result": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}}
    }
  }
}
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\JsonRpcFixture::class, 'getUser');
check('json-rpc operation', ($detail['definitions'][0]['operation'] ?? '') === 'user.get');
check('json-rpc params id', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('json-rpc result name', ($detail['definitions'][0]['messages'][1]['fields'][1]['name'] ?? '') === 'name');
check('json-rpc valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
```

Sample test: `sample: '{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}'` -> sampleValidation ok; `params:{}` -> not ok (missing id).

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- JSON only; root `methods` map required.
- Operation selection: exact method name; if empty and one method, use it; else error candidates.
- Messages: `Params` direction request from `params`; `Result` direction response from `result`.
- Sample validation: parse JSON; require `jsonrpc === '2.0'`, `method` equals selected method, `params` validates against schema; response sample requires `result`.
- Unresolved `$ref` -> `$ctx->error`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/JsonRpcAdapter.php tests/fixtures/protocol/json-rpc tests/protocol_adapters.php
git commit -m "feat(protocol): add JSON-RPC 2.0 adapter"
```

---

### Task 17: XML-RPC Adapter

**Files:**
- Create: `src/protocol/Adapter/XmlRpcAdapter.php`
- Create: `tests/fixtures/protocol/xml-rpc/methods.xml`
- Modify: `tests/protocol_adapters.php`

**Interfaces:**
- Consumes: `SafeXml`.
- Produces: `XmlRpcAdapter::format() === 'xml-rpc'`; operation selector method name.

- [ ] **Step 1: Write the failing tests**

Fixture:
```xml
<xmlrpc>
  <method name="add">
    <params>
      <param name="a" type="int"/>
      <param name="b" type="int"/>
    </params>
    <result type="int"/>
  </method>
</xmlrpc>
```

Test:
```php
$detail = renderFixture('api', \Tests\Fixtures\Protocol\XmlRpcFixture::class, 'add');
check('xml-rpc operation', ($detail['definitions'][0]['operation'] ?? '') === 'add');
check('xml-rpc param a', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'a');
check('xml-rpc result type', ($detail['definitions'][0]['messages'][1]['fields'][0]['type'] ?? '') === 'integer');
check('xml-rpc valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/protocol_adapters.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the adapter**

- Parse `<xmlrpc><method name=...>`; params with `name`/`type`; result `type` (single field named `result`).
- Operation selector: method name; if empty and one method, use it; else error candidates.
- Type mapping: `int/i4`->integer, `double`->number, `boolean`->boolean, `string`->string, `dateTime.iso8601`->string, `base64`->string, `struct`->object (children from nested `<member>` if present), `array`->array.
- Sample validation: if sample non-empty, `SafeXml::load`; root must be `methodCall` or `methodResponse`; for `methodCall`, `methodName` text must equal the operation and `params/param` count must match; for `methodResponse`, require a `params` or `fault` child.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/protocol_adapters.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add src/protocol/Adapter/XmlRpcAdapter.php tests/fixtures/protocol/xml-rpc tests/protocol_adapters.php
git commit -m "feat(protocol): add XML-RPC adapter"
```

---

### Task 18: UI Export and Markdown Protocol Sections

**Files:**
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/utils/apidocExport/render.ts`
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/src/utils/apidocExport/collect.ts` (only if type errors appear)
- Modify: `/home/wwwroot/erikwang2013/apidoc-ui/public/config.js` (if labels are missing)

**Interfaces:**
- Consumes: Task 8 types.
- Produces: Markdown export includes protocol, channel, definitions, messages, and validation errors.

- [ ] **Step 1: Extend `apiSections()`**

In `render.ts`, after the intro paragraph:
```ts
if (d.protocol) {
  const channel = d.channel ? ` · ${d.channel.action || ''} \`${d.channel.address}\`` : ''
  parts.push(`**协议：** ${d.protocol.label}${channel}`)
}
for (const def of d.definitions || []) {
  const title = `#### ${def.format} · ${def.source}${def.operation ? ` · ${def.operation}` : ''}`
  const body: string[] = []
  for (const msg of def.messages || []) {
    const table = paramsSection(`${msg.direction}: ${msg.name}`, msg.fields, false)
    if (table) body.push(table)
  }
  if (!def.validation.ok && def.validation.errors.length) {
    body.push(`**校验错误：**\n\n${def.validation.errors.map((e) => `- ${e.line ? `${e.line}:${e.column || 0} ` : ''}${e.message}`).join('\n')}`)
  }
  parts.push([title, ...body].join('\n\n'))
}
```
Keep the existing HTTP parameter sections unchanged; they will be empty for definition-only endpoints.

- [ ] **Step 2: Verify the export fallback filter**

Check `collect.ts` `collectLeaves` still accepts non-HTTP leaves because `url = channelAddress` and `method = '*'`. No code change unless TypeScript complains about the new optional fields.

- [ ] **Step 3: Verify the build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: `vue-tsc` passes and Vite build completes.

- [ ] **Step 4: Commit in the UI repo**

```bash
cd /home/wwwroot/erikwang2013/apidoc-ui
git add src/utils/apidocExport/render.ts src/utils/apidocExport/collect.ts public/config.js
git commit -m "feat(ui): include protocol definitions in markdown export"
```

---

### Task 19: Config, Docs, and Full Regression

**Files:**
- Modify: `src/config.php`
- Modify: `composer.json`
- Modify: `docs/ATTRIBUTES.md`
- Modify: `README.md`
- Modify: `tests/protocol.php`, `tests/protocol_adapters.php` (only if a regression test is missing)

**Interfaces:**
- Produces: documented configuration and annotations; a green full test run.

- [ ] **Step 1: Add the config block**

Append to `src/config.php`:
```php
'protocols' => [
    'allowed_roots' => [],
    'max_file_bytes' => 2 * 1024 * 1024,
    'max_depth' => 32,
    'max_lines' => 50000,
    'max_imports' => 64,
],
```
`allowed_roots => []` means `[APIDOC_ROOT_PATH]`.

- [ ] **Step 2: Add the composer suggestion**

In `composer.json`, add under `require`:
```json
"suggest": {
    "ext-dom": "Required for WSDL, XSD, EDMX, and XMPP definition parsing"
}
```

- [ ] **Step 3: Document the annotations**

In `docs/ATTRIBUTES.md`, add sections for `Protocol`, `Channel`, `Definition` with the exact examples from the spec (gRPC and MQTT). State the P1 supported formats and the local-only rule.
In `README.md`, add a short "非 HTTP 协议" section with one gRPC example and one MQTT example, and note that P1 supports OpenAPI, WSDL, GraphQL, proto, AsyncAPI, JSON Schema, JSON-RPC, XML-RPC, EDMX.

- [ ] **Step 4: Run the full backend suite**

Run:
```bash
php tests/smoke.php
php tests/protocol.php
php tests/protocol_adapters.php
```
Expected: every line is `PASS`; exit code 0.

- [ ] **Step 5: Run the UI build**

Run:
```bash
cd /home/wwwroot/erikwang2013/apidoc-ui && npm run build
```
Expected: `vue-tsc` passes and Vite build completes.

- [ ] **Step 6: Commit**

```bash
git add src/config.php composer.json docs/ATTRIBUTES.md README.md
git commit -m "docs(protocol): document P1 protocol support and limits"
```

---

## Self-Review Checklist

- Spec coverage: attributes/catalog (Task 1), path/context/registry (Task 2), metadata/menu/detail (Task 3), inline + JSON Schema (Task 4), cache (Task 5), YAML/XML/tokenizer (Tasks 6-7), UI contract/badge/menu (Task 8), UI detail/protocol tab/debug (Task 9), nine adapters (Tasks 4 and 10-17), export (Task 18), config/docs/regression (Task 19).
- Type consistency: `protocolKey/protocolLabel/channelAddress` in menus; `protocol/channel/definitions/validation` in details; `_definitionFingerprints` is internal only.
- Security: path traversal (Task 2), XXE/DOCTYPE (Task 7), size/import limits (Tasks 2-4), no remote fetch.
- Backward compatibility: Task 3 plain HTTP test; Task 9 tab gating; Task 19 full suite.
- Deferred: P2/P3 binary/frame adapters, WebSocket/SSE debug, HMAC webhook verification, code snippet generation, TypeScript type generation from definitions.
