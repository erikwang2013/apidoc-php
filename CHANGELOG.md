# Changelog

本项目的所有重要变更记录在此。版本号以根目录 `VERSION` 为准。

## 1.2.0 - 2026-10-26

### Added

- UIC-1 `/ui/v1` 统一契约完整实现：`capabilities` / `config` / `menus` / `detail` / `docMenus` / `docDetail` / `verifyAuth`，调试入口 `debugSend` / `debugStart` / `debugPoll` / `debugStop`，分享 CRUD、缓存清理/预热、`exportAllData`。
- 新增 15 个协议调试驱动：`thrift`、`dubbo`、`http2`、`http3`、`kafka`、`amqp`、`xmpp`、`dnp3`、`iec104`、`bacnet`、`opcua`、`rtp`、`rtcp`、`rtmp`、`lwm2m`；调试驱动总数达到 27 个。
- 声明式生成器 `/ui/v1/generator`：只读取 `ui_generator` 配置在内存中渲染，不执行 middleware、不写文件、不建表；legacy `/generator` 保留原行为。
- 协议定义解析覆盖 9 种格式：proto、WSDL/XSD、GraphQL SDL、OpenAPI、AsyncAPI、JSON Schema、JSON-RPC、XML-RPC、OData EDMX。
- 新增 `VERSION` 与 `.github/workflows/release.yml`：push main 或手动触发时读取 `VERSION`，创建 `v1.2.0` tag 与 GitHub Release。
- 同一份 apidoc-ui Vue 构建可通过 UIC-1 服务 PHP / Go / Rust；文档补充部署、能力协商与生成器说明。

### Changed

- README 与多仓库站点文档更新为完整状态：Go 服务端执行器、Rust 定义解析与 Vue UI、三端共用 UI。
- `composer.json` 描述与关键词补充 UIC-1、协议驱动与定义格式。

### Fixed

- DNP3 修正 IIN 位解析与帧处理，并对解析出的点数量设置 10,000 上限。
- BACnet 修正前缀与帧边界处理。
- IEC 104 对序列 ASDU 解析行数设置 10,000 上限，避免小帧放大。
- 协议中继补齐 SSRF 守卫、TLS 下限、解析器资源上限与鉴权边界；`hls` 不再声明为服务端中继能力。
