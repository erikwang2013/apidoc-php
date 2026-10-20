The PHP edition puts API documentation in PHP 8 attributes next to your controllers and endpoint methods. Install it, open `/apidoc`, and you get browsable, debuggable and exportable docs that stay next to the code. It fits projects that already have a controller structure and want documentation maintained with the code.

<figure class="doc-figure"><img src="{{root}}assets/img/php/social-preview-en.svg" alt="Apidoc PHP social preview"><figcaption>Apidoc PHP: the pet and annotation code share one document model with the Go and Rust editions.</figcaption></figure>

## When to use it

- PHP services built with Laravel, ThinkPHP, Hyperf, Webman, Yii2 or Yii3.
- Teams that already describe params and responses in controller methods and want to stop maintaining separate Markdown or Postman collections.
- Projects that need live debugging, Mock data, apps/versions, sharing, Swagger or TypeScript exports.
- Projects running PHP 8.0 or newer that accept the attributes-only syntax.

> This project only parses PHP 8 attributes. The old doctrine/annotations `@annotation` syntax is not supported. PHP 7 projects must upgrade to PHP 8 first.

## Requirements and installation

```bash
composer require erikwang2013/apidoc-php
```

PHP 8.0+ is required. The docs are mounted at `/apidoc` by default; change `route_prefix` to use another path.

## Minimal setup

A ThinkPHP example, in `config/apidoc.php`:

```php
return [
    'apps' => [
        ['title' => 'API', 'path' => 'app\controller', 'key' => 'api'],
    ],
    'definitions' => "app\common\controller\Definitions",
];
```

Import the annotation alias and declare the controller and endpoint:

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("User")]
#[Apidoc\Desc("User endpoints")]
class User
{
    #[
        Apidoc\Title("Get user"),
        Apidoc\Method("GET"),
        Apidoc\Url("/user/info"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "User ID"),
        Apidoc\Returned(name: "nickname", type: "string", desc: "Nickname"),
    ]
    public function info()
    {
        // ...
    }
}
```

Open `http://your-domain/apidoc`. If no endpoints appear, check that `apps[].path` points at the real controller directory and that the annotation alias is imported.

## Framework setup

| Framework | Version | Setup |
| --- | --- | --- |
| Laravel | 8+ | Auto-registered through `extra.laravel.providers`; publish config with `php artisan vendor:publish --provider="erikwang2013\apidoc\providers\LaravelService"`. |
| ThinkPHP | 5.1+ | Auto-registered through `extra.think.services`; the package `src/config.php` becomes the `apidoc` app config key. |
| Hyperf | 2+ | `extra.hyperf.config` points at `erikwang2013\apidoc\ConfigProvider`; copy the package config to `config/autoload/apidoc.php`. |
| Webman | 1+ | Run `php webman install`; config is published to `config/plugin/erikwang2013/apidoc/`. |
| Yii2 | 2.0+ | Enable `urlManager.enablePrettyUrl` and call `Yii2Service::register()` in the entry script. |
| Yii3 | 3.0+ | Call `Yii3Service::register($container, $config)`; provide config through `params['apidoc']`. |

Other useful keys:

```php
return [
    'title' => 'Apidoc',
    'route_prefix' => '/apidoc',
    'default_author' => 'erik',
    'default_method' => 'GET',
    'allowCrossDomain' => false,
    'cors_origins' => [],
    'ignored_methods' => [],
];
```

## Parsing rules

- Only controllers under `apps[].path` are scanned. Each public method that matches the rules becomes an endpoint.
- Use `use erikwang2013\apidoc\annotation as Apidoc;` and write `#[Apidoc\Title(...)]`. Per-class imports of short names also work, but the alias is recommended.
- `NotParse` skips a controller or method. `NotDebug` disables live debugging for a controller or endpoint.
- `Title`, `Desc`, `Group` and `Sort` set the display title, description, group and order.
- If `Url` is omitted, it is generated from the controller path and `auto_url`. If `Method` is omitted, `default_method` is used.
- `RouteParam` describes `{name}` / `{phone}` path parameters. Use `Query` for URL query fields and `Param` for body fields.
- Every parameter annotation can use `ref` to reuse definitions, service methods, models or entity classes.

## Controller and endpoint annotations

Controller-level annotations:

| Annotation | Purpose |
| --- | --- |
| `Title` | Controller title |
| `Group` | Group name from the `groups` config |
| `Sort` | Controller sort order |
| `NotParse` | Skip this controller |
| `NotDebug` | Disable debugging for all endpoints in the controller |

Endpoint-level annotations:

| Annotation | Purpose |
| --- | --- |
| `Title` / `Desc` | Endpoint name and description |
| `Method` / `Url` | HTTP method and real URL |
| `Header` / `Query` / `Param` | Request headers, query fields and body fields |
| `RouteParam` | Path parameters such as `/user/{id}` |
| `Returned` | Response fields |
| `Tag` / `Author` / `Sort` | Tags, author and order |
| `ParamType` | Request body type: `json` or `formdata` |
| `ContentType` | Content-Type used by the debugger |
| `Md` | Extra Markdown; `ref` can point to a Markdown file |
| `ResponseSuccess` / `ResponseError` | Success and error envelopes; `main: true` marks where `Returned` is mounted |
| `ResponseStatus` | Documented response status codes |
| `Before` / `After` | Debug events before and after the request |
| `RouteMiddleware` | Middleware used when auto-registering routes |

A request with headers, path params, body fields and a response:

```php
#[
    Apidoc\Title("Update user"),
    Apidoc\Method("POST"),
    Apidoc\Url("/user/{id}"),
    Apidoc\Header(name: "Authorization", type: "string", require: true, desc: "Token"),
    Apidoc\RouteParam(name: "id", type: "int", require: true, desc: "User ID"),
    Apidoc\Param(name: "nickname", type: "string", require: true, desc: "Nickname"),
    Apidoc\Param(name: "avatar", type: "file", desc: "Avatar file"),
    Apidoc\Returned(name: "id", type: "int", desc: "User ID"),
    Apidoc\Returned(name: "nickname", type: "string", desc: "Nickname"),
]
public function update(Request $request, int $id)
{
    // ...
}
```

## Request parameters

`Header`, `Query`, `Param`, `RouteParam` and `Returned` share the same field arguments:

| Argument | Description |
| --- | --- |
| `name` | Field name; optional when using `ref` |
| `type` | `string`, `int`, `boolean`, `array`, `object`, `tree`, `file`, `float`, `date`, `time`, `datetime` |
| `require` | Whether the field is required |
| `default` | Default value |
| `desc` | Field description |
| `mock` | Value generated for debugging |
| `ref` | Reuse a definition, service method, model or entity class |
| `field` | Keep only listed fields when using `ref`, such as `id,username` |
| `withoutField` | Exclude listed fields when using `ref` |
| `children` | Nested child fields |
| `childrenField` / `childrenDesc` / `childrenType` | Child configuration for `tree` / `array` |

`Param` is a body field, `Query` is a URL query field, `Header` is a request header and `RouteParam` is a path field. The debugger builds the body according to `ParamType` (`json` by default).

## Responses and nesting

Use `Returned` for response fields and `children` for nested structures:

```php
#[
    Apidoc\Method("POST"),
    Apidoc\Param(name: "info", type: "object", desc: "Info", children: [
        ['name' => 'name', 'type' => 'string', 'desc' => 'Name'],
        ['name' => 'sex', 'type' => 'string', 'desc' => 'Sex'],
        ['name' => 'group', 'type' => 'object', 'desc' => 'Group', 'children' => [
            ['name' => 'group_id', 'type' => 'int', 'desc' => 'Group ID'],
            ['name' => 'group_name', 'type' => 'string', 'desc' => 'Group name'],
        ]],
    ]),
]
public function nested()
{
    // ...
}
```

Define the global success/error envelope in config and override it per endpoint with `ResponseSuccess` / `ResponseError`. The field marked `main: true` is where endpoint `Returned` fields are mounted:

```php
'responses' => [
    'success' => [
        ['name' => 'code', 'type' => 'int', 'require' => 1],
        ['name' => 'message', 'type' => 'string', 'require' => 1],
        ['name' => 'data', 'type' => 'object', 'main' => true, 'require' => 1],
    ],
    'error' => [
        ['name' => 'code', 'type' => 'int', 'require' => 1],
        ['name' => 'message', 'type' => 'string', 'require' => 1],
    ],
],
```

## Reuse: definitions, ref, models and entities

Point the config at a definitions class:

```php
'definitions' => "app\controller\Definitions",
```

Define reusable fields:

```php
class Definitions
{
    #[
        Apidoc\Query(name: "pageIndex", type: "int", require: true, default: 1, desc: "Page"),
        Apidoc\Query(name: "pageSize", type: "int", require: true, default: 20, desc: "Page size"),
        Apidoc\Returned(name: "total", type: "int", desc: "Total"),
    ]
    public function pagingParam() {}
}
```

Reference them from an endpoint:

```php
#[
    Apidoc\Query(ref: [Definitions::class, "pagingParam"]),
    Apidoc\Returned(name: "data", type: "array", ref: "dictionary", desc: "Dictionary list"),
]
```

`ref` accepts definitions, service methods, model methods and entity classes, for example `ref: "pagingParam"`, `ref: "app\services\ApiDocService@getUserInfo"` and `ref: "app\model\User@getList"`. Use `field` / `withoutField` to trim imported fields.

## Apps, versions and groups

Each entry in `apps` is an app with a unique `key` and a controller `path`. A multi-version project can use multiple apps, or split by controller directories and use groups:

```php
'apps' => [
    ['title' => 'Public API', 'path' => 'app\api\controller', 'key' => 'api'],
    ['title' => 'Admin API', 'path' => 'app\admin\controller', 'key' => 'admin'],
],
```

Use `Group` for the group, `Tag` for tags and `Sort` for ordering. The UI app/version selector follows the configured tree.

## Mock and live debugging

The `mock` argument generates values in the debugger:

```php
#[
    Apidoc\Query(name: "name", type: "string", mock: "@name"),
    Apidoc\Query(name: "age", type: "int", mock: "@integer(1, 100)"),
    Apidoc\Query(name: "phone", type: "string", mock: "@phone"),
    Apidoc\Query(name: "fixed", type: "string", mock: "fixed text"),
]
```

The debug panel supports global Header/Query/Body params, Mock prefill, file upload and `Before` / `After` events. Cross-origin debugging needs `allowCrossDomain` and `cors_origins`; do not leave the allowlist open in production.

## Auth, caching and security

```php
'auth' => [
    'enable' => true,
    'password' => 'your-password',
    'secret_key' => 'your-secret-key',
    'expire' => 86400,
],
'cache' => ['enable' => true],
```

- Global and per-app/version passwords can be used together; the app password takes precedence.
- Passwords are checked at login time and tokens expire. Never commit `secret_key` to a public repository.
- Caching reuses parsed documentation. Clear the cache when annotations do not appear after an edit.
- Security releases add identifier allowlists, path checks and constant-time comparison.

## Exports, sharing and code generation

- Every endpoint can produce JSON request/response examples and TypeScript type definitions.
- `swagger.json` export can be imported into Swagger UI or code generators.
- Sharing creates a link for an app or endpoint. Enable it with `share.enable`.
- The code generator and templates turn documented fields into business code, table definitions and front-end API files. See `generator` / `code_template`.

## Common scenarios

### Lists and pagination

Define pagination once and reference it:

```php
#[
    Apidoc\Title("User list"),
    Apidoc\Method("GET"),
    Apidoc\Query(ref: [Definitions::class, "pagingParam"]),
    Apidoc\Returned(name: "list", type: "array", children: [
        ['name' => 'id', 'type' => 'int'],
        ['name' => 'nickname', 'type' => 'string'],
    ]),
]
public function list()
{
    // ...
}
```

### Login and tokens

Declare `Authorization` as a Header and reuse the definition across protected endpoints. In the debugger, add it through global headers or a `Before` event.

### File upload

Use `type: "file"` and set `ParamType` to `formdata`. The docs page renders a file picker in the debug panel.

### Multiple versions

Configure multiple apps or controller directories, then switch apps and versions inside the same documentation UI.

### Front-end handoff

Open the JSON or TypeScript view for an endpoint and copy the types into the front-end, or share a link that exposes only the selected endpoint.

## Rules and troubleshooting

| Problem | Check |
| --- | --- |
| `/apidoc` returns 404 | Check `route_prefix`, framework route registration, and whether the Webman plugin was installed. |
| No endpoints appear | Check `apps[].path`, public visibility, `NotParse`, and the imported annotation namespace. |
| Annotations do not work | Only PHP 8 attributes are parsed; the old `@annotation` syntax is ignored. |
| Wrong URL | Check `auto_url`, `prefix` and `Apidoc\Url`; use `RouteParam` for path fields. |
| `ref` target missing | Confirm the target method and namespace; class references can use `Class::class`. |
| Page does not update | Clear the document cache; confirm the config file was published where the framework reads it. |
| Debug CORS fails | Configure `allowCrossDomain` / `cors_origins` and only allow the origins you need. |
| Auth fails | Check the password, `secret_key`, token expiry and app-password precedence. |

## Reference

- Full annotation reference and section-by-section examples: [docs/ATTRIBUTES.md](https://github.com/erikwang2013/apidoc-php/blob/main/docs/ATTRIBUTES.md)
- Upstream project: [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php) / [hg/apidoc](https://packagist.org/packages/hg/apidoc)
- Project README: [apidoc-php](https://github.com/erikwang2013/apidoc-php)
- Releases: [Releases](https://github.com/erikwang2013/apidoc-php/releases)
