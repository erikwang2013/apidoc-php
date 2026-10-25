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
├── docs.html                  # 旧链接跳转到 docs/index.html（noindex）
├── 404.html                   # 自定义 404（noindex）
├── robots.txt                 # 构建产物
├── sitemap.xml                # 构建产物，含中英 hreflang 与图片条目
├── content/{zh,en}/*.md       # 使用指南正文
├── assets/                    # CSS、JS、three.js、宠物与项目图片
├── assets/img/weixinpay.png   # 微信赞赏收款码
├── assets/img/alipay.png      # 支付宝赞赏收款码
├── bench.py                   # TTFB / 整页加载实测
├── test_build.py              # 构建产物契约测试
└── build.py                   # 生成全部页面
```

语言切换在页头；每个页面都有对应的另一语言页面。`hreflang` 与 `og:locale` 已同步。

## 打赏 / Donation

页脚上方有微信与支付宝赞赏入口，缩略码点击后用原生 `<dialog>` 放大；支持 Esc、点击遮罩和关闭按钮，关闭后焦点回到触发按钮。收款码固定放在 `assets/img/weixinpay.png` 与 `assets/img/alipay.png`，替换图片后重新构建即可。

## SEO

每个页面由 `build.py` 自动生成绝对 canonical、`og:url`、绝对 `og:image`（含 alt/type）、`hreflang`（含 `x-default`）与 robots meta。文档页使用单独维护的 SEO 标题/描述，H1 包含 “API 文档 / API Documentation”；文档配图自动补齐 `width`/`height`，首图使用 eager + high priority。结构化数据：首页 `WebSite` + `Organization` + `Person` + `SoftwareApplication`，文档中心 `CollectionPage` + `ItemList` + `FAQPage`，文档页 `TechArticle` + `BreadcrumbList`。根目录的 `robots.txt`、`sitemap.xml`（含图片条目）与 `404.html`（noindex）也是构建产物，旧 `docs.html` 带 `noindex` 跳转。

## 构建

```bash
cd site
python3 build.py
```

依赖 Python 3.9+、`markdown` 与 `Pygments`。修改 `content/zh/*.md` 或 `content/en/*.md` 后重新运行即可生成文档页。

`build.py` 顶部的 `SITE_URL` 是 canonical、`og:url`、hreflang 和 `sitemap.xml` 的唯一域名来源；部署域名变化时只改这一处。

构建产物契约测试：

```bash
cd site
python3 -m unittest test_build -v
```

性能实测（TTFB 逐请求检查；`--full` 额外用 Playwright 测热加载整页）：

```bash
python3 bench.py
python3 bench.py --full
```

## 部署

```nginx
root /path/to/apidoc-php/site;
index index.html;
location / {
    try_files $uri $uri/ =404;
}

error_page 404 /404.html;
location = /404.html {
    internal;
}
```

建议的 nginx 压缩与缓存配置：

```nginx
gzip on;
gzip_min_length 1024;
gzip_types text/html text/css application/javascript application/json application/xml image/svg+xml;

# 服务器装了 brotli 模块时再开启
brotli on;
brotli_types text/html text/css application/javascript application/json application/xml image/svg+xml;

location ~* \.(css|js|svg|png|jpg|jpeg|webp|woff2)$ {
    add_header Cache-Control "public, max-age=31536000, immutable";
}

location ~* \.html$ {
    add_header Cache-Control "public, max-age=3600, must-revalidate";
}
```

性能口径与实测：

- 验收口径：所有页面与静态资源的服务器响应（TTFB）< 50ms；本机热加载整页中位数 ≤ 1s。
- 空闲机器实测：58 个响应全部低于 50ms；整页热加载中位数约 200–380ms（`python3 bench.py --full`）。机器高负载时整页数字会明显波动，以实际输出为准。
- 站点全部为静态文件，不依赖后端。three.js 约 670 KB，busuanzi 统计和 three.js 都在 `load` 事件之后空闲加载，不进入整页加载关键路径。
- 以上数字随机器与网络变化；跨公网访问时延迟主要由 RTT、CDN 与压缩决定，`bench.py` 只对本机静态服务负责。

## 图片与第三方资源

- 所有页面图片均从 `assets/img/` 本地加载，包括项目宠物、12 种表情、Social Preview、任意协议调试架构图、Go/Rust 架构与功能图、apidoc-ui 预览图。
- `assets/img/php/protocol-debug.svg` 与根目录 `assets/protocol-debug.svg` 保持一致，用于 PHP 指南和 README 的协议调试示意图。
- Three.js 0.160.0，MIT License，位于 `assets/vendor/three.module.min.js`，许可证见 `assets/vendor/LICENSE-three.txt`。
- 唯一的外部资源是页脚 busuanzi 统计脚本；它在 `load` 事件之后空闲注入，不进入页面加载关键路径。

## 内容来源

- PHP：`docs/ATTRIBUTES.md` 与项目 README；上游参考项目 [HGthecode/apidoc-php](https://github.com/HGthecode/apidoc-php) / [hg/apidoc](https://packagist.org/packages/hg/apidoc)。
- Go：apidoc-go README、源码与文档图。
- Rust：apidoc-rust README、源码与文档图。
- UI：apidoc-ui README 与运行时配置。

## 视觉资产授权

项目宠物、表情、Social Preview 与相关插图属于项目视觉资产，版权归作者所有。未经书面许可不得复制、修改、再分发或用于商业用途；商业使用需取得商业授权，联系 erik@erik.xyz。详见仓库根目录 `ASSETS-LICENSE.md`。
