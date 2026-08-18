<h1 align="center" style="margin-top: 0;padding-top: 0;">
  Apidoc
</h1>

<div align="center">
 基于PHP 8 attributes生成API文档及Api接口开发工具
</div>

<div align="center" style="margin-top:10px;margin-bottom:50px;">
<a href="https://packagist.org/packages/erik/apidoc"><img src="https://img.shields.io/packagist/v/erik/apidoc"></a>
<a href="https://packagist.org/packages/erik/apidoc"><img src="https://img.shields.io/packagist/dt/erik/apidoc"></a>
<a href="https://packagist.org/packages/erik/apidoc"><img src="https://img.shields.io/packagist/dm/erik/apidoc"></a>
<a href="https://packagist.org/packages/erik/apidoc"><img src="https://img.shields.io/packagist/l/erik/apidoc"></a>
<a href="https://github.com/erikwang2013/apidoc-php"><img src="https://img.shields.io/github/issues/erikwang2013/apidoc-php"></a>
<a href="https://github.com/erikwang2013/apidoc-php"><img src="https://img.shields.io/github/forks/erikwang2013/apidoc-php"></a>
</div>


## 🤷‍♀️ Apidoc是什么？

Apidoc是一个通过解析PHP 8 attributes生成Api接口文档的PHP composer扩展，兼容Laravel、ThinkPHP、Hyperf、Webman、Yii2、Yii3等框架；
全面的attributes引用、数据表字段引用，简单的attributes即可生成Api文档，而Apidoc不仅于接口文档，在线接口调试、Mock调试数据、调试事件处理、Json/TypeScript生成、接口生成器、代码生成器等诸多实用功能，致力于提高Api接口开发效率。

> 本项目是 [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php) 的 fork：移除了 doctrine/annotations 旧注解依赖，仅支持 PHP 8 attributes（PHP >= 8.0），包名 `erik/apidoc`，命名空间 `erikwang2013\apidoc`，其余注解类、配置键与官方基本一致。


## ✨特性

- 开箱即用：无繁杂的配置、安装后按文档编写attributes即可自动生成API文档。
- 轻松编写：支持通用注释引用、业务逻辑层、数据表字段的引用，几句注释即可完成。
- 在线调试：在线文档可直接调试，并支持全局请求/Mock参数/事件处理，接口调试省时省力。
- 安全高效：支持访问密码验证、应用/版本独立密码；支持文档缓存。
- 多应用/多版本：可适应各种单应用、多应用、多版本的项目的Api管理。
- 分组/Tag：可对控制器/接口进行多级分组或定义Tag。
- Markdown文档：支持.md文件的文档展示。
- Json/TypeScript生成：文档自动生成接口的Json及TypeScript。
- 代码生成器：配置+模板即可快速生成代码及数据表的创建，大大提高工作效率。
- 接口分享：支持自由指定应用/接口生成分享链接、导出swagger.json文件。


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

[使用说明（中文）](docs/USAGE.md) · [Usage Guide (English)](docs/USAGE-EN.md)

（安装、框架接入、配置参数、attributes 写法、常用功能、常见问题）


## 🏆支持我们

如果本项目对您有所帮助，请点个Star支持我们

- [Github](https://github.com/erikwang2013/apidoc-php) -> <a href="https://github.com/erikwang2013/apidoc-php" target="_blank">
  <img height="22" src="https://img.shields.io/github/stars/erikwang2013/apidoc-php?style=social" class="attachment-full size-full" alt="Star me on GitHub" data-recalc-dims="1" /></a>

