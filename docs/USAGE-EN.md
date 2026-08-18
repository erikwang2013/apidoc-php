# Apidoc Usage Guide

Apidoc is a PHP composer extension that parses PHP 8 attributes to generate API documentation. It is compatible with Laravel, ThinkPHP, Hyperf, Webman, Yii2, Yii3 and other frameworks.

> This project is a fork of [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php): the old `doctrine/annotations` dependency is removed, only PHP 8 attributes are supported (PHP >= 8.0). Package name `erik/apidoc`, namespace `erikwang2013\apidoc`. Annotation classes and config keys are basically the same as the official version.

---

## 1. Introduction

- **Out of the box**: no complex configuration. Install it, write attributes per this doc, and the API documentation is generated automatically.
- **Easy to write**: supports generic comment references, business-logic-layer references and database-table-field references. A few comments are enough.
- **Online debugging**: debug interfaces directly in the docs, with global request params, mock params and event handling.
- **Safe & efficient**: access-password verification, per-app/per-version passwords, and document caching.
- **Multi-app / multi-version**: adapts to single-app, multi-app and multi-version projects.
- **Group / Tag**: multi-level grouping or Tag definition for controllers/interfaces.
- **Markdown docs**: display `.md` files in the documentation.
- **Json / TypeScript generation**: auto-generate interface Json and TypeScript definitions.
- **Code generator**: config + template to quickly generate code and database tables.
- **Interface sharing**: share links for apps/interfaces, export `swagger.json`.

## 2. Installation

```bash
composer require erik/apidoc
```

## 3. Framework Integration

### 3.1 Laravel

1. Publish config and static resources:

```bash
php artisan vendor:publish --provider="erikwang2013\apidoc\providers\LaravelServiceProvider"
```

2. Add the route in `routes/web.php` (example):

```php
Route::any('apidoc', 'erikwang2013\apidoc\ApiDoc@index');
Route::any('apidoc/api', 'erikwang2013\apidoc\ApiDoc@api');
```

### 3.2 ThinkPHP 5.x

Add to `route/route.php`:

```php
Route::any('apidoc', 'erikwang2013\apidoc\ApiDoc@index');
Route::any('apidoc/api', 'erikwang2013\apidoc\ApiDoc@api');
```

### 3.3 ThinkPHP 6.x / 8.x

In `route/app.php`:

```php
Route::any('apidoc', 'erikwang2013\apidoc\ApiDoc@index');
Route::any('apidoc/api', 'erikwang2013\apidoc\ApiDoc@api');
```

### 3.4 Hyperf

In `config/routes.php`:

```php
Router::addRoute(['GET', 'POST', 'HEAD'], '/apidoc', 'erikwang2013\apidoc\ApiDoc@index');
Router::addRoute(['GET', 'POST', 'HEAD'], '/apidoc/api', 'erikwang2013\apidoc\ApiDoc@api');
```

### 3.5 Webman

In `config/route.php`:

```php
Route::any('/apidoc', ['erikwang2013\apidoc\ApiDoc', 'index']);
Route::any('/apidoc/api', ['erikwang2013\apidoc\ApiDoc', 'api']);
```

### 3.6 Yii2

Requirements: `urlManager` must have `enablePrettyUrl` enabled, and the apidoc module registered with the application.

In your application bootstrap / config:

```php
// Enable pretty URLs first (in config):
// 'urlManager' => [
//     'enablePrettyUrl' => true,
//     'showScriptName' => false,
//     ...
// ],

\erikwang2013\apidoc\providers\Yii2Service::register();
```

Then add the module routes (example, in your controller or route config):

```php
Yii::$app->controllerMap = array_merge(Yii::$app->controllerMap, [
    'apidoc' => 'erikwang2013\apidoc\ApiDoc',
]);
```

Visit `/index.php?r=apidoc` (or the pretty URL after your rules).

### 3.7 Yii3

Register the service with your container:

```php
\erikwang2013\apidoc\providers\Yii3Service::register($container, $config);
```

Where `$container` is your PSR-11 container and `$config` is the apidoc config array. Then add routes to your router.

## 4. Configuration

Create `config/apidoc.php` (or merge into your framework config) and return an array:

```php
<?php

return [
    // Access password (empty = no password)
    'password' => '',
    // Whether to enable cache (development: false, production: true)
    'cache' => false,
    // Cache directory
    'cache_path' => runtime_path() . '/apidoc/',
    // Whether to always return the latest configuration in the browser (dynamic config switch)
    'always_show_config' => true,
    // Whether to enable interface sharing links
    'share_link' => true,
    // Whether to enable code generation
    'code_generation' => true,
    // Whether to enable mock debugging
    'mock' => true,
    // Whether to enable interface debugging
    'debug' => true,
    // Default request method when the interface has no method specified
    'default_method' => '*',
    // Response code used when an exception occurs
    'response_code' => 400,
    // Exception handling function: function($e) {}
    'exception_handle' => null,
    // Database query function used to read table field comments: function($sql) {}
    'database_query_function' => null,
    // Number of generated template files per run
    'generator_generate_num' => 1000,
    // Apps configuration
    'apps' => [
        'app' => [
            // App name
            'name' => 'App',
            // Controller directory (relative to the project root)
            'path' => 'app/controller',
            // Static resource path of the docs (relative to the project root)
            'static_path' => 'public/static/apidoc',
            // The directory where the app documentation is generated (relative to the project root)
            'out_path' => 'public/apidoc',
            // Controller namespace prefix, e.g. app\controller
            'controller_layer' => 'app\controller',
            // App password
            'password' => '',
            // App logo
            'logo' => '',
            // Controllers to include (empty = read all in path)
            'controllers' => [],
            // Multi-level grouping: see section 5.8
            'groups' => [],
        ],
    ],
    // Definitions: referenced classes (data model, business logic), see section 5.3
    'definitions' => [
        'App\Models\User' => 'user',
    ],
    // Global request params for debugging
    'global' => [
        // Common request headers
        'header' => [
            [
                'name' => 'token',
                'desc' => 'User token',
                'require' => true,
                'value' => '',
            ],
        ],
        // Common request params
        'param' => [
            [
                'name' => 'page',
                'desc' => 'Page number',
                'require' => false,
                'value' => '1',
            ],
        ],
    ],
    // Error code list
    'errorCode' => [
        '0' => 'success',
        '400' => 'parameter error',
    ],
];
```

For the full configuration reference see the [Appendix](#appendix-full-configuration).

## 5. Usage

### 5.1 Controller & Interface Attributes

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation\ApiController;
use erikwang2013\apidoc\annotation\Api;
use erikwang2013\apidoc\annotation\Method;
use erikwang2013\apidoc\annotation\Url;
use erikwang2013\apidoc\annotation\Param;
use erikwang2013\apidoc\annotation\Returned;
use erikwang2013\apidoc\annotation\Tag;
use erikwang2013\apidoc\annotation\Author;
use erikwang2013\apidoc\annotation\Group;
use erikwang2013\apidoc\annotation\Title;

#[ApiController('User')]
class UserController
{
    #[Api('User list')]
    #[Method('GET')]
    #[Url('/user/list')]
    #[Tag('user')]
    #[Author('erik')]
    #[Param(name: 'page', type: 'int', desc: 'Page number', require: false, value: '1')]
    #[Returned(name: 'list', type: 'array', desc: 'User list')]
    public function list()
    {
        // ...
    }
}
```

- `ApiController(title)` — marks the class as an API controller.
- `Api(title)` — marks a method as an API interface.
- `Method('GET')` — request method: GET / POST / PUT / DELETE / HEAD / * etc.
- `Url('/user/list')` — request URL. If empty, it is auto-generated from the controller/method name.
- `Tag`, `Author`, `Group`, `Title`, `Param`, `Returned` — see below.

### 5.2 Common Attributes

| Attribute | Description |
| --------- | ----------- |
| `ApiController` | Controller mark: `ApiController('title')`, `ApiController(title: '...', group: '...', description: '...')` |
| `Api` | Interface mark: `Api('title')`, `Api(title: '...', description: '...')` |
| `Method` | Request method |
| `Url` | Request URL |
| `Tag` | Tag of the interface (multiple allowed) |
| `Author` | Author of the interface |
| `Group` | Group of the controller (string or array, see 5.8) |
| `Title` | Title |
| `Description` | Detailed description, supports markdown |
| `Param` | Request param: `Param(name, type, desc, require, value, default, mock, md, ref, children)` |
| `Returned` | Return field: same fields as `Param` |
| `Header` | Request header: `Header(name, type, desc, require, value)` |
| `Cookie` | Request cookie |
| `Before` | Global before event: `Before(type, value)` — type: `header`/`param`/`response` |
| `After` | Global after event: `After(type, value)` |
| `Field` | Model field reference (see 5.4) |
| `WithoutField` | Exclude fields from the model reference (see 5.4) |
| `AddField` | Add fields on top of the model reference (see 5.4) |
| `NotParse` | Do not parse this controller/interface |
| `NotDebug` | Do not allow debugging this controller/interface |
| `NotLogin` | Mark interface as not requiring login (shown in docs) |
| `RouteMiddleware` | Route middleware for this interface/controller |
| `Ref` | Reference another definition (see 5.3) |
| `Md` | Load content from a markdown file |

### 5.3 Reference (`ref`)

The `ref` attribute allows you to reuse a class (data model, business logic, another interface) as the param/return fields:

```php
// In a model or business class
#[Param(name: 'id', type: 'int', desc: 'User id', require: true)]
#[Param(name: 'name', type: 'string', desc: 'User name', require: true)]
class User
{
}

// In the interface:
#[Param(ref: 'App\Models\User', field: 'param')]
public function add()
{
}
```

- `ref` — the class name (must be in the `definitions` config, or directly the class name).
- `field` — which group of fields to reference: `param` (request) or `return` (response). Default is both.

Referencing another interface's request/response:

```php
#[Returned(ref: 'app\controller\UserController@list')]
```

Business logic layer methods: put `Param`/`Returned` attributes on a method, then reference it:

```php
class UserLogic
{
    #[Returned(name: 'id', type: 'int', desc: 'id')]
    public function getList()
    {
    }
}

// In the interface:
#[Returned(ref: 'App\Logic\UserLogic@getList')]
```

### 5.4 Model Table Reference (`field`)

If a model's `getTable()` returns the table name and you configured `database_query_function`, you can reference the table fields directly:

```php
use erikwang2013\apidoc\annotation\Api;
use erikwang2013\apidoc\annotation\Field;

#[Api('User info')]
#[Field('App\Models\User')]
public function info()
{
}
```

- `Field` — reads all fields of the model's table as the return fields.
- `WithoutField('App\Models\User', ['password'])` — excludes fields.
- `AddField` — adds fields on top of the model reference: `AddField(ref: 'App\Models\Other', field: 'return')`, or directly `AddField(name: 'extra', type: 'string', desc: 'Extra')`.

### 5.5 Multi-level Params & Return Fields

```php
#[Param(name: 'user', type: 'object', desc: 'User info')]
#[Param(name: 'user.id', type: 'int', desc: 'User id')]   // dot notation for children
#[Param(name: 'user.name', type: 'string', desc: 'User name')]
#[Param(name: 'list', type: 'object[]', desc: 'List')]
#[Param(name: 'list[].id', type: 'int', desc: 'Id')]
```

Or use the `children` field:

```php
#[Param(
    name: 'user',
    type: 'object',
    desc: 'User info',
    children: [
        ['name' => 'id', 'type' => 'int', 'desc' => 'User id'],
        ['name' => 'name', 'type' => 'string', 'desc' => 'User name'],
    ]
)]
```

### 5.6 Global Events (`Before` / `After`)

Before events run before an interface debug request, after events run after it:

```php
#[Before(type: 'param', value: [
    ['name' => 'token', 'type' => 'string', 'desc' => 'Token', 'require' => true, 'value' => ''],
])]
#[After(type: 'response', value: [
    ['name' => 'code', 'type' => 'int', 'desc' => 'Code', 'require' => true, 'value' => '0'],
])]
```

- `type` — `header` / `param` / `response`.
- `value` — array of `Param`-like fields.

### 5.7 Interface Debugging

In the generated docs:

1. Open the app docs, select an interface, click "Debug".
2. The request params come from `Param` attributes; fill in values and send.
3. Mock data: enabled when `mock` is on. `Param(mock: '@string')` generates mock values using [faker](https://fakerphp.github.io/) — for example `mock: '@name'`, `mock: '@email'`, `mock: '@phone'`. Custom mock can be a fixed value: `mock: 'test'`.
4. Global request params (`global` config) are appended to every debug request.

### 5.8 Multi-level Grouping

```php
// controllers config in the app
'controllers' => [
    'index' => ['IndexController'],
    'user' => ['UserController'],
],
```

```php
'groups' => [
    [
        'name' => 'index',
        'title' => 'Home',
    ],
    [
        'name' => 'user',
        'title' => 'User',
        'children' => [
            [
                'name' => 'user.info',
                'title' => 'User Info',
            ],
        ],
    ],
],
```

Controller group via attribute:

```php
#[Group(['user', 'user.info'])]
#[ApiController('User')]
class UserController
{
}
```

### 5.9 Code Generator

With `code_generation` enabled, the docs page provides a code generator: configure the table fields in the UI, generate the model/controller/migration code and the table SQL.

```php
// config
'code_generation' => true,
// template directory (relative to the project root, default is the built-in template)
'generator_templates' => 'public/apidoc/templates',
```

### 5.10 Markdown Docs

```php
#[Api('User list')]
#[Md('docs/user.md')]  // load the markdown file as the interface description
```

### 5.11 Auto Route Registration (optional)

```php
use erikwang2013\apidoc\utils\AutoRegisterRouts;

// Register all configured apps' routes, e.g. in a webman route file:
$config = require config_path() . '/apidoc.php';
$routes = (new AutoRegisterRouts($config))->getAppsApis();
foreach ($routes as $controllerData) {
    foreach ($controllerData['methods'] as $method) {
        Route::any($method['url'], [$controllerData['name'], $method['name']]);
    }
}
```

## 6. Features

- **Document cache**: set `cache => true` in production. The docs regenerate automatically when controllers change (md5-based cache key).
- **Share links**: with `share_link => true`, you can generate a shareable link for a single app or interface.
- **Swagger export**: export the app documentation as `swagger.json`.
- **Json / TypeScript generation**: the docs page generates interface Json examples and TypeScript definitions with one click.
- **Custom annotations**: `parsesAnnotation` config lets you customize how attribute data is parsed:

```php
'parsesAnnotation' => function ($data) {
    // $data is the attribute data array
    return $data;
},
```

- **Access control**: app-level and version-level passwords, plus a global password.

## 7. FAQ

### 7.1 Composer reports "could not be found" for `erik/apidoc`

Run `composer clear-cache` and `composer update erik/apidoc`. If you are in China, consider using the Aliyun mirror: `composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/`.

### 7.2 Nothing shows in the docs, controllers not found

1. Check the `path` config: the controller directory is relative to the project root.
2. Check `controller_layer`: the namespace prefix of your controllers.
3. Clear the cache: delete the `cache_path` directory, or set `cache => false` while developing.

### 7.3 Table field comments not showing

1. Configure `database_query_function` (framework-specific database query).
2. The model must implement `getTable()` (Eloquent / ThinkPHP models do).
3. Table fields have comments in the database.

### 7.4 How do I exclude a controller or interface?

- Class level: `#[NotParse]` on the controller, or add it to `filter_controllers`.
- Method level: `#[NotParse]` on the interface.

### 7.5 Which frameworks are supported?

Laravel (>= 8), ThinkPHP (>= 5.1), Hyperf (>= 2), Webman (>= 1), Yii2 (>= 2.0, requires `enablePrettyUrl` and manual `Yii2Service::register()`), Yii3 (>= 3.0, manual `Yii3Service::register($container, $config)`).

### 7.6 Can I use annotations without PHP 8 attributes?

No. This fork removed `doctrine/annotations` — PHP 8 attributes only (PHP >= 8.0).

## Appendix: Full Configuration

```php
<?php
return [
    // Access password (empty = no password)
    'password' => '',
    // Whether to enable cache
    'cache' => false,
    // Cache directory
    'cache_path' => runtime_path() . '/apidoc/',
    // Always return the latest config in the browser
    'always_show_config' => true,
    // Enable share links
    'share_link' => true,
    // Enable code generation
    'code_generation' => true,
    // Enable mock debugging
    'mock' => true,
    // Enable interface debugging
    'debug' => true,
    // Enable interface document generation
    'api_doc' => true,
    // Default request method
    'default_method' => '*',
    // Exception response code
    'response_code' => 400,
    // Exception handler: function($e) {}
    'exception_handle' => null,
    // Database query function: function($sql) {}
    'database_query_function' => null,
    // Custom attribute parse callback: function($data) {}
    'parsesAnnotation' => null,
    // Generated files per code-generation run
    'generator_generate_num' => 1000,
    // Code generation template directory
    'generator_templates' => '',
    // Auto route registration
    'auto_register_routs' => false,
    // Apps
    'apps' => [
        'app' => [
            'name' => 'App',
            'path' => 'app/controller',
            'static_path' => 'public/static/apidoc',
            'out_path' => 'public/apidoc',
            'controller_layer' => 'app\controller',
            'password' => '',
            'logo' => '',
            'controllers' => [],
            'groups' => [],
        ],
    ],
    // Definitions (referenced classes)
    'definitions' => [],
    // Global debug params
    'global' => [
        'header' => [],
        'param' => [],
    ],
    // Error codes
    'errorCode' => [],
    // Filters
    'filter_controllers' => [],
    'filter_dirs' => [],
];
```
