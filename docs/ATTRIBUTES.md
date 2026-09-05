# Attributes 使用说明（PHP 8 原生注解）

本文档为基于 **PHP 8 attributes**（`#[注解(...)]`）的使用说明，不含 doctrine 旧注解（`@注解`）写法。示例均使用本项目命名空间 `erikwang2013\apidoc`。

## 〇、安装与框架接入

```bash
composer require erikwang2013/apidoc-php
```

要求 PHP >= 8.0（attributes 语法）。安装后文档默认挂在 `/apidoc` 路由下（可通过配置键 `route_prefix` 修改）。

| 框架 | 接入方式 |
| --- | --- |
| Laravel >= 8 | 经 `extra.laravel.providers` 自动注册，无需手动添加 Provider；执行 `php artisan vendor:publish --provider="erikwang2013\apidoc\providers\LaravelService"` 发布配置到 `config/apidoc.php` |
| ThinkPHP >= 5.1 | 经 `extra.think.services` 自动注册（TP5 使用 `ThinkPHP5Service`）；`extra.think.config` 将包内 `src/config.php` 合并为应用配置键 `apidoc`（`config/apidoc.php`） |
| Hyperf >= 2 | `extra.hyperf.config` 指向 `erikwang2013\apidoc\ConfigProvider`，自动注册；将包内 `src/config.php` 复制到 `config/autoload/apidoc.php` |
| Webman >= 1 | 插件机制：安装后在项目根目录执行 `php webman install`，配置发布到 `config/plugin/erikwang2013/apidoc/`（`app.php` 为主配置，`route.php` 自动注册 `/apidoc` 路由） |
| Yii2 >= 2.0 | 入口脚本（`web/index.php`）中在应用创建后、`$app->run()` 前手动调用 `Yii2Service::register()`（需开启 urlManager 的 `enablePrettyUrl`）；配置通过 `params['apidoc']` 提供 |
| Yii3 >= 3.0 | 手动调用 `Yii3Service::register($container[, $config])`；配置通过 `params['apidoc']` 提供 |

以 ThinkPHP 为例的最小配置（`config/apidoc.php`）：

```php
return [
    'apps' => [
        ['title' => 'Api接口', 'path' => 'app\controller', 'key' => 'api'],
    ],
    'definitions' => "app\common\controller\Definitions",
];
```

## 一、引入 Apidoc 注解

编写注解前必须先引入注解类，否则不会被解析。

### 引入方式一（推荐）

用一个别名引入全部注解：

```php
<?php
namespace app\controller;

// 添加这句
use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[Apidoc\Title("测试接口")]
    public function index()
    {
        //...
    }
}
```

### 引入方式二

分别引入需要用到的注解类，书写时使用短类名：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation\Title;
use erikwang2013\apidoc\annotation\Group;
use erikwang2013\apidoc\annotation\Desc;
use erikwang2013\apidoc\annotation\Author;
use erikwang2013\apidoc\annotation\Url;
use erikwang2013\apidoc\annotation\Tag;
use erikwang2013\apidoc\annotation\Param;
use erikwang2013\apidoc\annotation\Returned;
// ...按需引入

#[Title("基础示例")]
#[Group("base")]
class ApiDocTest
{
    #[Title("测试接口")]
    public function index()
    {
        //...
    }
}
```

## 二、控制器注解

为控制器加上注解，可让文档可读性更高（不是必须的）：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
#[Apidoc\Group("base")]
#[Apidoc\Sort(1)]
#[Apidoc\NotParse()]
#[Apidoc\NotDebug()]
class ApiDocTest
{
    //...
}
```

### 注释参数

| 参数名 | 参数值 | 说明 |
| - | - | - |
| `Title` | | 控制器标题 |
| `Group` | 定义在配置文件 `groups` 中分组的 name | 所属分组 |
| `Sort` | number | 控制器排序 |

### 特殊参数

| 参数名 | 说明 |
| - | - |
| `NotParse` | 不需要解析 API 文档的控制器 |
| `NotDebug` | 关闭该控制器下所有接口的调试 |

## 三、接口注解

控制器中的每一个符合注释规则的方法都会被解析成一个 API 接口。

### 1、Header 参数

通过 `Header` 注解定义 HTTP 请求的 Header 参数：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[
        Apidoc\Title("请求头参数"),
        Apidoc\Method("GET"),
        Apidoc\Header(name: "token", type: "string", require: true, desc: "Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "信息id"),
        Apidoc\Returned(name: "id", type: "int", desc: "信息id"),
    ]
    public function header()
    {
        //...
    }
}
```

### 2、Query 参数

通过 `Query` 注解定义 HTTP 请求的 Query（URL 查询）参数：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[
        Apidoc\Title("请求Query参数"),
        Apidoc\Method("GET"),
        Apidoc\Query(name: "name", type: "string", require: true, desc: "姓名"),
        Apidoc\Query(name: "phone", type: "string", require: true, desc: "手机号"),
        Apidoc\Returned(name: "id", type: "int", desc: "用户id"),
    ]
    public function query()
    {
        //...
    }
}
```

### 3、Body 参数

通过 `Param` 注解定义 HTTP 请求的 body 参数：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[
        Apidoc\Title("请求Body参数"),
        Apidoc\Method("POST"),
        Apidoc\Param(name: "name", type: "string", require: true, desc: "姓名"),
        Apidoc\Param(name: "phone", type: "string", require: true, desc: "手机号"),
        Apidoc\Returned(name: "id", type: "int", desc: "用户id"),
    ]
    public function body()
    {
        //...
    }
}
```

### 4、路由参数

通过 `RouteParam` 注解定义路由传参，如 `url/{name}/{phone}`：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[
        Apidoc\Title("路由参数"),
        Apidoc\Method("POST"),
        Apidoc\RouteParam(name: "name", type: "string", require: true, desc: "姓名"),
        Apidoc\RouteParam(name: "phone", type: "string", require: true, desc: "手机号"),
        Apidoc\Returned(name: "id", type: "int", desc: "用户id"),
    ]
    public function route(Request $request, $name, $phone)
    {
        //...
    }
}
```

### 5、响应参数

通过 `Returned` 注解定义 HTTP 请求的响应参数：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("基础示例")]
class ApiDocTest
{
    #[
        Apidoc\Title("响应参数"),
        Apidoc\Method("GET"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "用户id"),
        Apidoc\Returned(name: "id", type: "int", desc: "用户id"),
        Apidoc\Returned(name: "name", type: "string", desc: "姓名"),
        Apidoc\Returned(name: "phone", type: "string", desc: "电话"),
    ]
    public function returned()
    {
        //...
    }
}
```

## 四、通用注解（definitions）

通过定义通用的公共注释来实现复用，避免每个接口都定义一大堆同样的参数。

### 1、增加配置

首先在配置文件中，指定一个文件为定义公共注释的类：

```php
// apidoc.php
// 指定公共注释定义的文件地址
'definitions' => "app\controller\Definitions",
```

### 2、定义通用注解

添加一些通用的方法及注解（`Header`、`Query`、`Param`、`Returned` 参数与接口注解书写规则一致）：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

class Definitions
{
    #[
        Apidoc\Query(name: "pageIndex", type: "int", require: true, default: 1, desc: "查询页数"),
        Apidoc\Query(name: "pageSize", type: "int", require: true, default: 20, desc: "查询条数"),
        Apidoc\Returned(name: "total", type: "int", desc: "总条数"),
    ]
    public function pagingParam() {}

    #[
        Apidoc\Returned(name: "id", type: "int", desc: "唯一id"),
        Apidoc\Returned(name: "name", type: "string", desc: "字典名"),
        Apidoc\Returned(name: "value", type: "string", desc: "字典值"),
    ]
    public function dictionary() {}

    #[Apidoc\Header(name: "shopid", type: "string", require: true, desc: "店铺id")]
    public function shopHeader() {}
}
```

### 3、使用定义

在接口注解的 `Header`、`Query`、`Param`、`RouteParam`、`Returned` 中可通过 `ref` 引用通用注解：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;
use app\controller\Definitions;

class ApiDocTest
{
    #[
        Apidoc\Title("引用公共注解"),
        Apidoc\Method("GET"),
        Apidoc\Header(ref: "shopHeader"),
        Apidoc\Query(ref: [Definitions::class, "pagingParam"]),
        Apidoc\Returned(ref: [Definitions::class, "pagingParam"]),
        Apidoc\Returned(name: "data", type: "array", ref: "dictionary", desc: "字典列表"),
    ]
    public function definitions()
    {
        //...
    }
}
```

> 以上 `Returned` 用了两种方式引入：指定字段名与 type 时，引入的参数挂在该字段下；不指定字段名时，直接引入所有参数。

## 五、逻辑层注解

实际开发中业务逻辑会分层处理（service 层），可直接引入业务逻辑层的注解来实现接口参数的定义。

### 1、定义逻辑层注解

在项目 app 目录下新建 `services` 文件夹，新建 `ApiDocService.php`：

```php
<?php
namespace app\services;

use erikwang2013\apidoc\annotation as Apidoc;

class ApiDocService
{
    #[
        Apidoc\Param(name: "id", type: "int", require: true, desc: "唯一id"),
        Apidoc\Param(name: "sex", type: "int", require: true, desc: "性别"),
        Apidoc\Param(name: "age", type: "int", require: true, desc: "年龄"),
        Apidoc\Returned(name: "id", type: "int", desc: "唯一id"),
        Apidoc\Returned(name: "name", type: "string", desc: "姓名"),
        Apidoc\Returned(name: "phone", type: "string", desc: "电话"),
    ]
    public function getUserInfo() {}
}
```

### 2、引用逻辑层注解

在控制器的接口注解中通过 `ref` 指定引入逻辑层的注解，以下三种 `ref` 写法等价：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;
use app\services\ApiDocService;

class ApiDocTest
{
    #[
        Apidoc\Title("引入逻辑层注解"),
        Apidoc\Method("POST"),
        Apidoc\Param(ref: "app\services\ApiDocService@getUserInfo"),
        Apidoc\Param(ref: "app\services\ApiDocService\getUserInfo"),
        Apidoc\Param(ref: [ApiDocService::class, "getUserInfo"]),
        Apidoc\Returned(ref: [ApiDocService::class, "getUserInfo"]),
    ]
    public function service()
    {
        //...
    }
}
```

## 六、模型注解

接口参数往往与数据表息息相关，很多接口参数来自数据表字段。可以直接引入指定模型的数据表字段来生成参数说明，省去大量注解与维护工作。

### 1、给数据表字段添加注释

建议为数据表字段添加注释，可读性更高（示例 SQL 供参考）：

```sql
CREATE TABLE `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '用户id',
  `username` varchar(64) NOT NULL COMMENT '用户名',
  `nickname` varchar(64) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `name` varchar(64) DEFAULT NULL COMMENT '姓名',
  `phone` varchar(11) DEFAULT NULL COMMENT '联系电话',
  `role` varchar(255) DEFAULT NULL COMMENT '角色',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=23 DEFAULT CHARSET=utf8;
```

### 2、直接引用数据表字段

某些表没有模型文件时，可直接使用 `table` 参数指定要解析的表：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

class ApiDocTest
{
    #[
        Apidoc\Title("直接引用数据表"),
        Apidoc\Method("POST"),
        Apidoc\Param(name: "userList", type: "array", desc: "用户列表", table: "users"),
    ]
    public function table()
    {
        //...
    }
}
```

> 使用 `table` 引用前，需在配置文件的 `database` 配置项中配置数据库连接。

### 3、模型方法的注解

可为模型方法添加注解，实现 `Field`（返回指定字段）、`WithoutField`（排除指定字段）、`AddField`（添加/覆盖指定字段）：

| 参数 | 说明 | 书写规范 |
| - | - | - |
| `Field` | 返回指定字段 | 数组或英文逗号分隔的字段名 |
| `WithoutField` | 排除指定字段 | 数组或英文逗号分隔的字段名 |
| `AddField` | 添加指定字段 | 可定义多个，每行一个参数；可嵌套 `children` 定义复杂层级 |
| ├─ 字段名 | | 如 `Apidoc\AddField(name: "name", ...)` |
| ├─ `type` | 字段类型 | |
| ├─ `require` | 是否必填 | |
| ├─ `default` | 默认值 | |
| ├─ `desc` | 字段说明文字 | |
| └─ `children` | 子参数 | |

```php
<?php
namespace app\model;

use erikwang2013\apidoc\annotation as Apidoc;

class User extends BaseModel
{
    #[
        Apidoc\Field(["id", "username", "nickname", "role"]),
        Apidoc\AddField(name: "openid", type: "string", desc: "OpenId"),
        Apidoc\AddField(name: "role", type: "array", desc: "重写 role，由于数据表中存在该字段，此处定义会覆盖数据表中的字段", children: [
            ['name' => 'name', 'type' => 'string', 'desc' => '角色名称'],
            ['name' => 'id', 'type' => 'int', 'desc' => '角色id'],
        ]),
    ]
    public function getInfo($id)
    {
        //...
    }
}
```

### 4、引用模型注解

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;
use app\model\User as UserModel;

class ApiDocTest
{
    #[
        Apidoc\Title("引入模型注解"),
        Apidoc\Method("POST"),
        Apidoc\Param(ref: "app\model\User@getInfo", desc: "引入指定模型的 getInfo 方法，会通过 getInfo 的注解处理数据表字段"),
        Apidoc\Param(ref: [UserModel::class, "getUserInfo"], desc: "同上"),
        Apidoc\Param(ref: "app\model\User", desc: "直接引入该模型数据表字段"),
        Apidoc\Param(ref: UserModel::class, desc: "同上"),
        Apidoc\Returned(ref: UserModel::class),
    ]
    public function model()
    {
        //...
    }
}
```

## 七、实体类引用

### 1、实体类参数注解

实体类的属性使用 `Property` 注解：

```php
<?php
namespace app\entity;

use erikwang2013\apidoc\annotation as Apidoc;

class User
{
    /**
     * 用户id
     * @var int
     */
    public $id;

    /**
     * 用户姓名
     * @var string
     */
    #[Apidoc\Property(name: "name", type: "string", require: true, desc: "用户的姓名")]
    public $name;

    #[Apidoc\Property(name: "arrData", type: "array", require: true, desc: "嵌套数组", children: [
        ['name' => 'id', 'type' => 'int', 'require' => true, 'desc' => 'Id'],
        ['name' => 'name', 'type' => 'string', 'desc' => 'Name'],
    ])]
    public $arrData;

    #[Apidoc\Property(name: "refData", type: "array", require: true, ref: "app\model\User", desc: "嵌套数组")]
    public $refData;
}
```

### 2、接口引入实体类

接口参数 `Param`、`Query`、`Returned` 注解中使用 `ref` 引入实体类即可：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;
use app\entity\User;

class ApiDocTest
{
    #[
        Apidoc\Title("引入实体类"),
        Apidoc\Method("POST"),
        Apidoc\Param(ref: "app\entity\User", desc: "引入实体类"),
        Apidoc\Returned(name: "user", type: "array", ref: User::class, desc: "用户信息"),
    ]
    public function refEntity()
    {
        //...
    }
}
```

## 八、复杂注解（children 嵌套）

虽然 Apidoc 拥有强大的 `ref` 引用能力，但某些场景需要在一个方法内完成多层数据结构的注解，此时可将 `Header`、`Query`、`Param`、`Returned` 通过 `children` 嵌套使用：

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

class ApiDocTest
{
    #[
        Apidoc\Method("POST"),
        Apidoc\Param(name: "info", type: "object", desc: "信息", children: [
            ['name' => 'name', 'type' => 'string', 'desc' => '姓名'],
            ['name' => 'sex', 'type' => 'string', 'desc' => '性别'],
            ['name' => 'group', 'type' => 'object', 'desc' => '所属分组', 'children' => [
                ['name' => 'group_id', 'type' => 'int', 'desc' => '组id'],
                ['name' => 'group_name', 'type' => 'string', 'desc' => '组名'],
            ]],
        ]),
    ]
    public function test()
    {
        //...
    }
}
```

## 九、接口通用注解参数说明

| 参数名 | 参数值 | 说明 |
| - | - | - |
| `Title` | | 接口名称 |
| `Desc` | | 接口描述 |
| `Md` | | Markdown 描述，子参数 `ref` 引用一个 md 文件内容 |
| `Author` | | 作者，默认取配置文件 `default_author` |
| `Url` | | 真实的接口 URL，不配置时根据控制器目录自动生成 |
| `Method` | | 请求类型，默认取配置文件 `default_method`，多个类型用数组或逗号隔开（`GET` `POST` 等） |
| `RouteMiddleware` | | 开启自动注册路由时，定义路由中间件 |
| `ContentType` | | 指定调试时请求的 ContentType |
| `Tag` | | 接口 Tag 标签，多个标签用数组或逗号隔开 |
| `Header` | 见下方接口参数 | 请求 Headers 参数，可定义多个 |
| `Query` | 见下方接口参数 | 请求 Query 参数，可定义多个 |
| `Param` | 见下方接口参数 | 请求 Body 参数，可定义多个 |
| `ParamType` | `json` `formdata` | 请求参数类型，默认 json |
| `Returned` | 见下方接口参数 | 响应结果，可定义多个 |
| `ResponseSuccess` | | 当前接口的成功响应体，通过 `main: true` 指定业务数据挂载节点 |
| `ResponseError` | | 当前接口的异常响应体 |
| `ResponseSuccessMd` | | 使用 Markdown 写成功响应体，支持 `ref` 引入 md 文件 |
| `ResponseErrorMd` | | 使用 Markdown 写异常响应体 |
| `Before` | | 调试时请求发起前执行的事件，可定义多个 |
| `After` | | 调试时请求返回后执行的事件，可定义多个 |

### 接口参数

适用于 `Header`、`Query`、`Param`、`Returned` 注解：

| 参数名 | 说明 | 书写规范 |
| - | - | - |
| 字段名 | 参数的字段名 | 如 `Apidoc\Param(name: "name", ...)`；使用 `ref` 引入定义时可不配置 |
| `type` | 字段类型 | `string` `int` `boolean` `array` `object` `tree` `file` `float` `date` `time` `datetime` |
| `require` | 是否必填 | |
| `default` | 默认值 | |
| `desc` | 字段描述 | |
| `md` | 引用 Markdown 描述内容 | |
| `mdRef` | 引用 Markdown 描述内容 | 如 `/docs/xxx.md` |
| `ref` | 引入定义的路径：通用定义、逻辑层方法、模型方法、实体类 | 如 `ref: "pagingParam"`、`ref: "app\services\ApiDocService@getUserInfo"`、`ref: "app\model\User@getList"`、`ref: User::class` |
| `mock` | 接口调试时自动生成该字段的值 | 语法见下方 Mock |
| `field` | 配置 `ref` 引入时有效，指定引入的字段 | 如 `field: "id,username"`，只引入 id、username |
| `withoutField` | 配置 `ref` 引入时有效，指定过滤掉的字段 | 如 `withoutField: "id,username"`，引入除这两个外的所有字段 |
| `childrenField` | 字段类型为 `tree` 时，指定子节点字段名 | 默认为 `children` |
| `childrenDesc` | 字段类型为 `tree` 时，子节点字段名的备注 | |
| `childrenType` | 字段类型为 `array` 时，为子参数定义类型 | 可选 `string` `int` `boolean` `array` `object` |
| `children` | 子参数，多层参数时嵌套使用 | |

### Mock

`mock` 参数在接口调试时自动生成字段值，支持多种规则：

```php
#[
    Apidoc\Query(name: "name", type: "string", require: true, desc: "姓名", mock: "@name"),
    Apidoc\Query(name: "age", type: "int", desc: "年龄", mock: "@integer(1, 100)"),
    Apidoc\Query(name: "phone", type: "string", desc: "电话", mock: "@phone"),
    Apidoc\Query(name: "fixed", type: "string", desc: "固定值", mock: "固定文本"),
]
```

## 十、特殊参数（Not\* 系列）

直接写在类或方法注解中即可：

| 参数名 | 说明 |
| - | - |
| `NotParse` | 不解析该接口/控制器 |
| `NotHeaders` | 不使用配置中的全局请求 Headers 参数 |
| `NotQuerys` | 不使用配置中的全局请求 Query 参数 |
| `NotParams` | 不使用配置中的全局请求 Body 参数 |
| `NotResponses` | 不使用统一响应体返回数据 |
| `NotResponseSuccess` | 不使用成功响应体返回数据 |
| `NotResponseError` | 不使用异常响应体返回数据 |
| `NotDefaultAuthor` | 不使用默认作者 |
| `NotDebug` | 关闭接口调试 |

```php
<?php
namespace app\controller;

use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\NotParse()]
class ApiDocTest
{
    #[Apidoc\NotParse()]
    #[Apidoc\NotResponses()]
    public function model()
    {
        //...
    }
}
```
