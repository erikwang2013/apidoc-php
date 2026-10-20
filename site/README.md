# Apidoc 静态站点 / Static site

Apidoc 的多语言项目站与使用文档，覆盖 PHP、Go、Rust 三种后端实现，以及共用的 apidoc-ui 文档前端。整站中英文双语，所有图片均为本站本地资源，无外部图片依赖。

## 页面结构

```text
site/
├── index.html                 # 中文首页
├── en/index.html              # English home
├── docs/index.html            # 中文文档中心
├── docs/{php,go,rust,ui}.html # 中文使用指南
├── en/docs/index.html         # English guides hub
├── en/docs/{php,go,rust,ui}.html
├── docs.html                  # 旧链接跳转到 docs/index.html
├── content/{zh,en}/*.md       # 使用指南正文
├── assets/                    # CSS、JS、three.js、宠物与项目图片
└── build.py                   # 生成全部页面
```

语言切换在页头；每个页面都有对应的另一语言页面。`hreflang` 与 `og:locale` 已同步。

## 构建

```bash
cd site
python3 build.py
```

依赖 Python 3、`markdown` 与 `Pygments`。修改 `content/zh/*.md` 或 `content/en/*.md` 后重新运行即可生成文档页。

## 部署

```nginx
root /path/to/apidoc-php/site;
index index.html;
location / {
    try_files $uri $uri/ =404;
}
```

建议：

- 开启 gzip 或 brotli。首页约 46 KB HTML，CSS 约 80 KB，首屏 three.js 通过 `requestIdleCallback` 延迟加载，不进入关键路径。
- 给 `assets/` 设置长期缓存；HTML 使用短缓存或协商缓存。
- 站点全部为静态文件，不依赖后端；本地静态响应在 50ms 以内。three.js 体积约 670 KB，开启压缩后可显著降低传输时间。

## 图片与第三方资源

- 所有页面图片均从 `assets/img/` 本地加载，包括项目宠物、12 种表情、Social Preview、Go/Rust 架构与功能图、apidoc-ui 预览图。
- Three.js 0.160.0，MIT License，位于 `assets/vendor/three.module.min.js`，许可证见 `assets/vendor/LICENSE-three.txt`。
- 唯一的外部资源是页脚 busuanzi 统计脚本；它异步加载，不阻塞页面。

## 内容来源

- PHP：`docs/ATTRIBUTES.md` 与项目 README；上游参考项目 [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php) / [hg/apidoc](https://packagist.org/packages/hg/apidoc)。
- Go：apidoc-go README、源码与文档图。
- Rust：apidoc-rust README、源码与文档图。
- UI：apidoc-ui README 与运行时配置。

## 视觉资产授权

项目宠物、表情、Social Preview 与相关插图属于项目视觉资产，版权归作者所有。未经书面许可不得复制、修改、再分发或用于商业用途；商业使用需取得商业授权，联系 erik@erik.xyz。详见仓库根目录 `ASSETS-LICENSE.md`。
