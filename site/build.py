#!/usr/bin/env python3
"""Build the bilingual Apidoc static site.

Sources:
  content/{zh,en}/{php,go,rust,ui}.md
  assets/ (all images are local)

Outputs:
  index.html, en/index.html
  docs/index.html, docs/{php,go,rust,ui}.html
  en/docs/index.html, en/docs/{php,go,rust,ui}.html
  docs.html (redirect kept for old links)
"""
from __future__ import annotations

import html
import json
import posixpath
import re
from pathlib import Path
from string import Template

import markdown
from pygments import highlight
from pygments.formatters import HtmlFormatter
from pygments.lexers import TextLexer, get_lexer_by_name
from pygments.util import ClassNotFound

ROOT = Path(__file__).resolve().parent
LANGS = ("zh", "en")
SLUGS = ("php", "go", "rust", "ui")
HTML_LANG = {"zh": "zh-CN", "en": "en"}
OTHER = {"zh": "en", "en": "zh"}
COPY = {"zh": "复制", "en": "Copy"}
COPIED = {"zh": "已复制", "en": "Copied"}
MENU_OPEN = {"zh": "打开菜单", "en": "Open menu"}
MENU_CLOSE = {"zh": "关闭菜单", "en": "Close menu"}
BACK_TOP = {"zh": "回到顶部", "en": "Back to top"}
PAGE_PREFIX = {"zh": "", "en": "en/"}
DOCS_PREFIX = {"zh": "docs/", "en": "en/docs/"}
IMAGE_LANG = {"zh": "zh", "en": "en"}

DOCS = {
    "php": {
        "label": "PHP",
        "version": "v1.0.6",
        "repo": "https://github.com/erikwang2013/apidoc-php",
        "release_url": "https://github.com/erikwang2013/apidoc-php/releases",
        "source_url": "https://github.com/erikwang2013/apidoc-php/blob/main/docs/ATTRIBUTES.md",
        "source_label": {"zh": "完整注解参考：docs/ATTRIBUTES.md", "en": "Full reference: docs/ATTRIBUTES.md"},
        "install": "composer require erikwang2013/apidoc-php",
        "install_lang": "bash",
        "pet": "smile.svg",
        "accent": "php",
        "image": "assets/img/social-preview.svg",
        "image_alt": {"zh": "Apidoc 项目 Social Preview，宠物与注解代码", "en": "Apidoc social preview with the pet and annotation code"},
        "title": {"zh": "PHP 使用指南", "en": "PHP Guide"},
        "tagline": {"zh": "PHP 8 attributes 驱动的 API 文档", "en": "Attribute-driven API docs for PHP 8"},
        "summary": {
            "zh": "在 Laravel、ThinkPHP、Hyperf、Webman、Yii2、Yii3 中写 PHP 8 attributes，自动生成可浏览、可调试、可导出的接口文档。",
            "en": "Write PHP 8 attributes in Laravel, ThinkPHP, Hyperf, Webman, Yii2 or Yii3 and get a browsable, debuggable, exportable API portal.",
        },
        "sections": {
            "zh": ["安装与框架接入", "注解、参数与响应规则", "definitions / ref / children 复用", "鉴权、导出、场景与排错"],
            "en": ["Install and framework setup", "Annotations, params and responses", "definitions / ref / children reuse", "Auth, exports, scenarios and troubleshooting"],
        },
        "facts": {
            "zh": ["PHP 8 attributes", "6 个框架适配", "definitions / ref / children", "在线调试、Mock、JSON/TS"],
            "en": ["PHP 8 attributes", "6 framework adapters", "definitions / ref / children", "Debug, Mock, JSON/TS"],
        },
        "frameworks": "Laravel · ThinkPHP · Hyperf · Webman · Yii2 · Yii3",
    },
    "go": {
        "label": "Go",
        "version": "v1.0.7",
        "repo": "https://github.com/erikwang2013/apidoc-go",
        "release_url": "https://github.com/erikwang2013/apidoc-go/releases",
        "source_url": "https://github.com/erikwang2013/apidoc-go/blob/main/README.md",
        "source_label": {"zh": "完整参考：Go README", "en": "Full reference: Go README"},
        "install": "go get github.com/erikwang2013/apidoc-go",
        "install_lang": "bash",
        "pet": "tongue.svg",
        "accent": "go",
        "image": "assets/img/go/architecture.svg",
        "image_alt": {"zh": "apidoc-go 架构图", "en": "apidoc-go architecture"},
        "title": {"zh": "Go 使用指南", "en": "Go Guide"},
        "tagline": {"zh": "类型化结构体随路由注册", "en": "Typed docs registered with routes"},
        "summary": {
            "zh": "接口文档以类型化结构体随路由注册，内嵌 Web UI 提供浏览与在线调试；兼容 net/http、Gin、Echo、Chi、Fiber、beego、Kratos、go-zero。",
            "en": "Declare docs as typed structs next to route registration. A built-in web UI provides browsing and live debugging for net/http, Gin, Echo, Chi, Fiber, beego, Kratos and go-zero.",
        },
        "sections": {
            "zh": ["安装、Config 与 Router", "Route / Doc / Param / Response", "8 个框架适配器", "鉴权、缓存、导出、场景与排错"],
            "en": ["Install, Config and Router", "Route / Doc / Param / Response", "8 framework adapters", "Auth, caching, exports, scenarios and troubleshooting"],
        },
        "facts": {
            "zh": ["类型化 Doc 结构体", "8 个框架适配", "reflect + go/ast 解析", "ETag、JSON/TS 导出"],
            "en": ["Typed Doc structs", "8 framework adapters", "reflect + go/ast parsing", "ETag, JSON/TS export"],
        },
        "frameworks": "net/http · Gin · Echo · Chi · Fiber · beego · Kratos · go-zero",
    },
    "rust": {
        "label": "Rust",
        "version": "v2.0.0",
        "repo": "https://github.com/erikwang2013/apidoc-rust",
        "release_url": "https://github.com/erikwang2013/apidoc-rust/releases",
        "source_url": "https://github.com/erikwang2013/apidoc-rust/blob/HEAD/README.md",
        "source_label": {"zh": "完整参考：Rust README", "en": "Full reference: Rust README"},
        "install": 'apidoc-rust = "2.0"',
        "install_lang": "toml",
        "pet": "knocking.svg",
        "accent": "rust",
        "image": "assets/img/rust/social-preview.svg",
        "image_alt": {"zh": "apidoc-rust 架构图", "en": "apidoc-rust architecture"},
        "title": {"zh": "Rust 使用指南", "en": "Rust Guide"},
        "tagline": {"zh": "过程宏在编译期生成文档", "en": "Proc-macro docs generated at compile time"},
        "summary": {
            "zh": "用过程宏在 handler 上声明文档，编译期静态注册，运行期零成本聚合；兼容 axum 与 actix-web，内置鉴权、Mock、导出、分享与代码生成。",
            "en": "Declare docs with proc macros on handlers, register them statically at compile time and aggregate at zero runtime cost. Works with axum and actix-web, with auth, Mock, exports, sharing and codegen built in.",
        },
        "sections": {
            "zh": ["依赖、宏与编译期规则", "axum / actix-web 接入", "鉴权、Mock、多应用版本", "导出、代码生成、场景与排错"],
            "en": ["Dependencies, macros and compile-time rules", "axum / actix-web setup", "Auth, Mock, apps and versions", "Exports, codegen, scenarios and troubleshooting"],
        },
        "facts": {
            "zh": ["20+ 属性宏", "axum / actix-web", "linkme 编译期注册", "HMAC 鉴权、三种导出"],
            "en": ["20+ attribute macros", "axum / actix-web", "linkme compile-time registry", "HMAC auth, three exports"],
        },
        "frameworks": "axum · actix-web",
    },
    "ui": {
        "label": "UI",
        "version": "Vue 3.5",
        "repo": "https://github.com/erikwang2013/apidoc-ui",
        "release_url": "https://github.com/erikwang2013/apidoc-ui",
        "source_url": "https://github.com/erikwang2013/apidoc-ui/blob/main/README.md",
        "source_label": {"zh": "完整参考：apidoc-ui README", "en": "Full reference: apidoc-ui README"},
        "install": "npm ci && npm run build",
        "install_lang": "bash",
        "pet": "cute.svg",
        "accent": "ui",
        "image": "assets/img/ui/social-preview.svg",
        "image_alt": {"zh": "apidoc-ui 文档界面", "en": "apidoc-ui documentation front-end"},
        "title": {"zh": "apidoc-ui 使用指南", "en": "apidoc-ui Guide"},
        "tagline": {"zh": "三套后端共用的静态文档前端", "en": "One static front-end for all three backends"},
        "summary": {
            "zh": "Vue 3 + TypeScript 的文档加载与展示页面，支持应用/版本、在线调试、接口分享、Swagger 导出、缓存管理与多语言。构建产物为纯静态文件。",
            "en": "A Vue 3 + TypeScript documentation front-end with apps/versions, live debugging, sharing, Swagger export, cache management and i18n. The build output is fully static.",
        },
        "sections": {
            "zh": ["构建与部署", "config.js 运行时配置", "多 Host / appKey / 鉴权", "调试事件、Mock 扩展与排错"],
            "en": ["Build and deploy", "config.js runtime config", "Multi-host / appKey / auth", "Debug events, Mock extensions and troubleshooting"],
        },
        "facts": {
            "zh": ["Vue 3.5 + TypeScript", "纯静态构建产物", "多 Host / 多应用", "在线调试与 Swagger 导出"],
            "en": ["Vue 3.5 + TypeScript", "Static build output", "Multi-host / multi-app", "Live debug and Swagger export"],
        },
        "frameworks": "Vue 3.5 · Vite 6 · Pinia · Monaco",
    },
}

ADAPTER_GROUPS = [
    {"label": "PHP", "count": 6, "items": ["Laravel", "ThinkPHP", "Hyperf", "Webman", "Yii2", "Yii3"]},
    {"label": "Go", "count": 8, "items": ["net/http", "Gin", "Echo", "Chi", "Fiber", "beego", "Kratos", "go-zero"]},
    {"label": "Rust", "count": 2, "items": ["axum", "actix-web"]},
]

PETS = [
    ("smile.svg", "笑", "Smile", ":emoji-smile:"),
    ("joy.svg", "欢乐", "Joy", ":emoji-joy:"),
    ("cry.svg", "哭", "Cry", ":emoji-cry:"),
    ("depressed.svg", "沮丧", "Depressed", ":emoji-depressed:"),
    ("terrified.svg", "惊恐", "Terrified", ":emoji-terrified:"),
    ("angry.svg", "愤怒", "Angry", ":emoji-angry:"),
    ("coquettish.svg", "撒娇", "Coquettish", ":emoji-coquettish:"),
    ("cute.svg", "卖萌", "Cute", ":emoji-cute:"),
    ("pleasing.svg", "讨好", "Pleasing", ":emoji-pleasing:"),
    ("knocking.svg", "敲打", "Knocking", ":emoji-knocking:"),
    ("facepalm.svg", "捂脸", "Facepalm", ":emoji-facepalm:"),
    ("tongue.svg", "吐舌头", "Tongue", ":emoji-tongue:"),
]

VERSIONS = [
    {
        "key": "php",
        "version": "v1.0.6",
        "title": {"zh": "安全与稳定性修复", "en": "Security and stability"},
        "points": {
            "zh": ["模板/生成器去 eval、标识符白名单与路径校验", "分享列表、多级应用树与多注解 TypeError 修复", "缓存原子写、Auth 常量时间比较、冒烟测试"],
            "en": ["Removed eval from templates/generators, added identifier allowlists and path checks", "Fixed share lists, multi-level app trees and multi-annotation TypeError", "Atomic cache writes, constant-time auth and smoke tests"],
        },
    },
    {
        "key": "go",
        "version": "v1.0.7",
        "title": {"zh": "适配器文档与 UI 资源", "en": "Adapter docs and UI assets"},
        "points": {
            "zh": ["补齐 beego、Kratos、go-zero 适配器文档", "内置 UI favicon 与侧栏宠物图标", "HTTP 缓存、reflect 参数与 UI 树能力"],
            "en": ["Documented beego, Kratos and go-zero adapters", "Bundled UI favicon and sidebar mascot", "HTTP caching, reflect params and UI tree"],
        },
    },
    {
        "key": "rust",
        "version": "v2.0.0",
        "title": {"zh": "Rust 原生鉴权", "en": "Native Rust auth"},
        "points": {
            "zh": ["鉴权改为 Rust 原生 POST + HMAC-SHA256", "移除 PHP 时代实现，全库统一", "保留 v2 五项能力：table、缓存、分享、代码生成、调试事件"],
            "en": ["Auth rewritten in native Rust with POST + HMAC-SHA256", "Removed the PHP-era implementation", "Keeps all five v2 features: tables, cache, sharing, codegen, debug events"],
        },
    },
]

UI = {
    "zh": {
        "copy": COPY["zh"], "copied": COPIED["zh"], "menu_open": MENU_OPEN["zh"], "menu_close": MENU_CLOSE["zh"], "back_top": BACK_TOP["zh"],
        "nav_languages": "语言实现", "nav_features": "核心能力", "nav_docs": "使用文档", "nav_install": "安装", "nav_versions": "版本", "nav_download": "下载",
        "github": "GitHub", "home": "首页", "docs": "使用文档", "read_guide": "阅读使用指南", "open_docs": "打开文档中心",
        "hero_kicker": "Apidoc / PHP · Go · Rust",
        "hero_title": '一套文档体系，<br><span class="mark">三种开发语言</span>。',
        "hero_sub": "在处理器旁声明文档，自动得到可浏览、可调试、可导出的 API 门户。PHP、Go、Rust 共用同一套文档模型与静态 UI。",
        "choose_language": "选择语言", "read_guides": "阅读使用文档",
        "stats_languages": "后端语言", "stats_adapters": "框架适配器", "stats_ui": "共享文档 UI", "stats_pets": "宠物表情",
        "intro_title": "一个项目家族，一套文档体验。",
        "intro_p1": "Apidoc 把接口文档从手写 Markdown 变成代码旁的声明。PHP 版使用 PHP 8 attributes，Go 版使用类型化结构体，Rust 版使用过程宏；三者共享同一套文档模型、在线调试与静态前端。",
        "intro_p2": "PHP 版源自 <a href='https://github.com/HGthecode/apidoc-php' target='_blank' rel='noopener'>HGthecode/apidoc-php</a>（<a href='https://packagist.org/packages/hg/apidoc' target='_blank' rel='noopener'>hg/apidoc</a>）；Go、Rust 与 apidoc-ui 由 erikwang2013 维护。每种语言保留自己的安装与接入方式，文档体验保持一致。",
        "intro_source": "从声明到交付，三种语言走同一条文档流水线。",
        "intro_caption": "项目家族 Social Preview。宠物与注解代码。",
        "languages_title": "先选语言，再看文档。",
        "languages_sub": "同一套文档工作流，不同的注解机制、框架适配与构建时机。每种语言都有独立的使用指南。",
        "ui_title": "apidoc-ui · 共享文档前端",
        "ui_sub": "PHP、Go、Rust 三套后端共用同一个静态文档 UI。Vue 3 + TypeScript，构建产物可直接部署。",
        "ui_note": "后端地址由同目录 config.js 配置；支持多应用、在线调试、分享、Swagger 导出与缓存管理。",
        "compare_title": "同一个接口，三种写法。",
        "compare_sub": "不需要迁移技术栈。用你现有的语言声明路由与文档，Apidoc 负责生成、调试与交付。",
        "features_title": "同一套文档体验，各自原生。",
        "features_sub": "从在线调试到鉴权、导出与共享 UI，核心工作流一致；具体能力以各语言指南为准。",
        "docs_title": "使用文档是本站的主体。",
        "docs_sub": "每种语言都包含安装、规则、完整示例、典型场景与排错。先选你的技术栈，再按章节深入。",
        "adapters_title": "16 个框架适配器，覆盖主流服务端。",
        "adapters_sub": "PHP 6 个、Go 8 个、Rust 2 个；同一套文档模型，不同的薄适配层。",
        "install_title": "选一种语言，直接开始。",
        "install_sub": "下面是各语言的最小安装命令；框架接入、配置与完整示例在对应指南中。",
        "install_note": "每种指南都从最小可运行示例开始，再展开注解规则、场景与排错。",
        "versions_title": "当前版本",
        "versions_sub": "版本日志与完整变更以各仓库 Releases 为准。",
        "release_notes": "查看 Releases",
        "pets_title": "项目宠物，十二种情绪。",
        "pets_sub": "它是一页会走路的文档。每个表情都是独立 SVG，自带动效，并遵循 prefers-reduced-motion。",
        "pets_license": "项目宠物与表情属于项目视觉资产，版权归作者所有，商业使用需取得授权。详见 ASSETS-LICENSE.md。",
        "download_title": '把 Apidoc 带到<br class="br-mobile">你的技术栈。',
        "download_sub": "从 Packagist、Go modules、crates.io 或 GitHub 获取；三套后端共用同一个文档 UI。",
        "download_docs": "打开使用文档", "download_github": "GitHub",
        "footer_tagline": "PHP、Go、Rust 通用 API 文档体系。",
        "footer_guides": "使用指南", "footer_projects": "项目", "footer_about": "关于",
        "footer_assets": "视觉资产版权归作者所有，商业使用需取得授权。",
        "footer_license": "MIT License", "footer_made": "由 erik 维护", "reference_label": "参考项目",
        "docs_hub_title": "使用文档", "docs_hub_sub": "四种实现，一套文档工作流。选择语言，从安装、规则到场景与排错。",
        "docs_hub_meta": "PHP · Go · Rust · UI",
        "workflow_title": "从声明到交付，五步。", "workflow_sub": "无论使用哪种语言，文档都沿着同一条路径进入生产。",
        "workflow_steps": ["声明", "浏览", "调试", "导出", "部署"],
        "compare_table_title": "核心概念对照", "compare_table_sub": "同一件事在不同语言里的实现方式。",
        "compare_headers": ["概念", "PHP", "Go", "Rust"],
        "compare_rows": [
            ["声明方式", "PHP 8 attributes", "类型化 Doc 结构体", "过程宏属性"],
            ["文档生成时机", "运行时解析控制器", "路由注册时写入", "编译期静态注册"],
            ["框架适配", "6 个", "8 个", "axum / actix-web"],
            ["在线调试 / Mock", "支持 / 规则表达式", "支持 / 按类型生成", "支持 / 15 条 fake 规则"],
            ["鉴权", "全局与应用密码", "Cookie session + 应用 token", "HMAC-SHA256 token"],
            ["导出", "JSON / TS / Swagger / 分享", "JSON / TS", "Markdown / TS / Swagger / 分享"],
        ],
        "docs_next": "继续阅读", "docs_source": "查看源文档", "docs_version": "版本", "docs_install": "安装",
        "docs_back": "返回文档中心", "docs_help": "需要帮助？", "docs_help_text": "到 GitHub Issues 提问，或先看仓库 README。", "docs_issue": "提交 Issue",
        "docs_toc": "本页目录",
        "docs_next_title": "其他语言指南",
        "footer_language": "语言",
    },
    "en": {
        "copy": COPY["en"], "copied": COPIED["en"], "menu_open": MENU_OPEN["en"], "menu_close": MENU_CLOSE["en"], "back_top": BACK_TOP["en"],
        "nav_languages": "Languages", "nav_features": "Features", "nav_docs": "Guides", "nav_install": "Install", "nav_versions": "Versions", "nav_download": "Downloads",
        "github": "GitHub", "home": "Home", "docs": "Usage guides", "read_guide": "Read the guide", "open_docs": "Open the guides",
        "hero_kicker": "Apidoc / PHP · Go · Rust",
        "hero_title": 'One API docs layer.<br><span class="mark">Three languages</span>.',
        "hero_sub": "Declare docs next to your handlers and get a browsable, debuggable, exportable API portal. PHP, Go and Rust share one document model and one static UI.",
        "choose_language": "Choose a language", "read_guides": "Read the guides",
        "stats_languages": "backend languages", "stats_adapters": "framework adapters", "stats_ui": "shared docs UI", "stats_pets": "pet moods",
        "intro_title": "One project family, one documentation experience.",
        "intro_p1": "Apidoc turns API docs from hand-written Markdown into declarations next to your code. PHP uses PHP 8 attributes, Go uses typed structs, and Rust uses proc macros. All three share one document model, live debugging and the same static front-end.",
        "intro_p2": "The PHP edition started from <a href='https://github.com/HGthecode/apidoc-php' target='_blank' rel='noopener'>HGthecode/apidoc-php</a> (<a href='https://packagist.org/packages/hg/apidoc' target='_blank' rel='noopener'>hg/apidoc</a>). Go, Rust and apidoc-ui are maintained by erikwang2013. Each language keeps its native setup while the documentation experience stays consistent.",
        "intro_source": "From declaration to delivery, all three languages follow the same documentation pipeline.",
        "intro_caption": "Project family social preview. The pet and annotation code.",
        "languages_title": "Pick your language first.",
        "languages_sub": "One documentation workflow, different declaration mechanisms, framework adapters and build times. Every language has its own guide.",
        "ui_title": "apidoc-ui · shared documentation front-end",
        "ui_sub": "One static documentation UI for PHP, Go and Rust backends. Vue 3 + TypeScript with a deployable static build.",
        "ui_note": "The backend URL is set in config.js next to index.html. Multi-app, live debug, sharing, Swagger export and cache management are built in.",
        "compare_title": "One endpoint, three ways.",
        "compare_sub": "Keep your stack. Declare routes and docs in the language you already use; Apidoc handles generation, debugging and delivery.",
        "features_title": "One documentation experience, native in each.",
        "features_sub": "Live debug, auth, exports and the shared UI follow one workflow; see each guide for exact capabilities.",
        "docs_title": "The usage guides are the core of this site.",
        "docs_sub": "Each guide covers installation, rules, complete examples, common scenarios and troubleshooting. Pick your stack, then go deep.",
        "adapters_title": "16 framework adapters across mainstream servers.",
        "adapters_sub": "6 for PHP, 8 for Go and 2 for Rust. One document model, thin native adapters.",
        "install_title": "Pick a language and start.",
        "install_sub": "The minimum install command for each stack. Framework setup, config and complete examples live in the guides.",
        "install_note": "Every guide starts from a minimal runnable example, then covers annotation rules, scenarios and troubleshooting.",
        "versions_title": "Current versions",
        "versions_sub": "Full changelogs and release assets live on each repository's Releases page.",
        "release_notes": "View Releases",
        "pets_title": "The project pet, twelve moods.",
        "pets_sub": "It is a walking documentation page. Every expression is a standalone SVG with its own motion, honoring prefers-reduced-motion.",
        "pets_license": "The project pet and expressions are visual assets owned by the author. Commercial use requires permission. See ASSETS-LICENSE.md.",
        "download_title": 'Bring Apidoc to<br class="br-mobile"> your stack.',
        "download_sub": "Get it from Packagist, Go modules, crates.io or GitHub. All three backends share one documentation UI.",
        "download_docs": "Open the guides", "download_github": "GitHub",
        "footer_tagline": "API documentation for PHP, Go and Rust.",
        "footer_guides": "Guides", "footer_projects": "Projects", "footer_about": "About",
        "footer_assets": "Visual assets are owned by the author. Commercial use requires permission.",
        "footer_license": "MIT License", "footer_made": "Maintained by erik", "reference_label": "Upstream",
        "docs_hub_title": "Usage guides", "docs_hub_sub": "Four implementations, one documentation workflow. Choose a language and go from install to rules, scenarios and troubleshooting.",
        "docs_hub_meta": "PHP · Go · Rust · UI",
        "workflow_title": "From declaration to delivery, in five steps.", "workflow_sub": "Whichever language you use, docs follow the same path into production.",
        "workflow_steps": ["Declare", "Browse", "Debug", "Export", "Deploy"],
        "compare_table_title": "Core concepts at a glance", "compare_table_sub": "How the same job is implemented in each language.",
        "compare_headers": ["Concept", "PHP", "Go", "Rust"],
        "compare_rows": [
            ["Declaration", "PHP 8 attributes", "Typed Doc structs", "Attribute macros"],
            ["Docs built at", "Runtime controller scan", "Route registration", "Compile time, static registry"],
            ["Framework adapters", "6", "8", "axum / actix-web"],
            ["Debug / Mock", "Yes / rule expressions", "Yes / generated by type", "Yes / 15 fake rules"],
            ["Auth", "Global and app passwords", "Cookie session + app token", "HMAC-SHA256 token"],
            ["Exports", "JSON / TS / Swagger / share", "JSON / TS", "Markdown / TS / Swagger / share"],
        ],
        "docs_next": "Continue", "docs_source": "View source", "docs_version": "Version", "docs_install": "Install",
        "docs_back": "Back to guides", "docs_help": "Need help?", "docs_help_text": "Open a GitHub Issue, or read the repository README first.", "docs_issue": "Open an Issue",
        "docs_toc": "On this page",
        "docs_next_title": "Other language guides",
        "footer_language": "Language",
    },
}

FEATURE_CARDS = [
    {"span": "card-wide", "icon": "terminal", "visual": "response", "pet": "smile.svg"},
    {"span": "card-debug", "icon": "spark", "visual": "mock"},
    {"span": "card-mock", "icon": "layers", "visual": "versions"},
    {"span": "card-versions", "icon": "shield", "visual": "auth"},
    {"span": "card-ts", "icon": "file-code", "visual": "formats"},
    {"span": "card-md", "icon": "wand", "visual": "codegen"},
    {"span": "card-gen", "icon": "layout", "visual": "ui"},
    {"span": "card-security", "icon": "shield", "visual": "security"},
    {"span": "card-share", "icon": "share", "visual": "pipeline", "pet": "knocking.svg"},
]

FEATURE_TEXT = {
    "zh": [
        ("在线调试", "在文档页直接请求真实接口，支持全局参数、Mock 预填与请求前后事件。三种语言的文档都提供调试面板。"),
        ("Mock 数据", "字段级 Mock 示例。PHP 支持规则表达式，Go 按类型生成，Rust 提供 15 条 fake 规则。"),
        ("多应用 / 多版本", "一套文档管理多个应用与版本，按应用树切换；接口归属与访问密码可以独立配置。"),
        ("鉴权与缓存", "全局密码与应用独立密码；文档缓存、ETag/304 与常量时间比较，兼顾速度与安全。"),
        ("统一导出", "JSON、TypeScript、Markdown、Swagger/OpenAPI 等格式按语言提供；请求与响应可直接交给前端和测试。"),
        ("代码生成", "PHP 与 Rust 提供配置/模板驱动的代码生成；Go 侧重文档导出与注释解析。"),
        ("共享文档 UI", "apidoc-ui 同时服务 PHP、Go、Rust 后端；Vue 3 + TypeScript，构建产物为纯静态文件。"),
        ("安全边界", "HMAC 令牌、CORS 白名单、路径与标识符校验；把文档服务当作生产接口来保护。"),
        ("从声明到交付", "声明、浏览、调试、导出、部署沿同一条路径完成，三种语言共享这套工作流。"),
    ],
    "en": [
        ("Live debugging", "Call real endpoints from the docs page with global params, Mock prefill and request/response events. Every language guide includes a debug panel."),
        ("Mock data", "Field-level Mock examples. PHP supports rule expressions, Go generates values by type, and Rust ships 15 fake rules."),
        ("Apps and versions", "Manage multiple apps and versions in one portal, switch by the app tree, and scope access passwords per app."),
        ("Auth and caching", "Global and per-app passwords, document caching, ETag/304 and constant-time comparison for speed with a security boundary."),
        ("Unified exports", "JSON, TypeScript, Markdown and Swagger/OpenAPI are available per language. Hand request and response shapes to front-end and QA."),
        ("Code generation", "PHP and Rust provide template-driven code generation; Go focuses on exports and comment parsing."),
        ("Shared docs UI", "apidoc-ui serves PHP, Go and Rust backends. Vue 3 + TypeScript with a fully static build."),
        ("Security boundary", "HMAC tokens, CORS allowlists and path/identifier validation. Treat the docs server like a production API."),
        ("From declaration to delivery", "Declare, browse, debug, export and deploy on one path, shared by all three languages."),
    ],
}

PIPELINE = {
    "zh": ["声明", "浏览", "调试", "导出", "部署"],
    "en": ["Declare", "Browse", "Debug", "Export", "Deploy"],
}


ITERATION = {
    "zh": [("声明", "在代码旁写注解"), ("浏览", "文档自动生成"), ("调试", "在线请求与 Mock"), ("导出", "JSON / TS / Swagger")],
    "en": [("Declare", "annotations next to code"), ("Browse", "docs generated automatically"), ("Debug", "live requests and Mock"), ("Export", "JSON / TS / Swagger")],
}

HEADER_ICONS = {
    "terminal": "i-terminal", "spark": "i-spark", "layers": "i-layers", "shield": "i-shield",
    "formats": "i-file-code", "wand": "i-wand", "ui": "i-layout", "pipeline": "i-share",
}

SPRITE = """<svg class="icon-sprite" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-arrow" viewBox="0 0 20 20"><path d="M3 10h13M11 5l5 5-5 5"/></symbol>
  <symbol id="i-external" viewBox="0 0 20 20"><path d="M8 4h8v8M16 4L4 16"/></symbol>
  <symbol id="i-code" viewBox="0 0 24 24"><path d="M8 6l-5 6 5 6M16 6l5 6-5 6"/></symbol>
  <symbol id="i-terminal" viewBox="0 0 24 24"><path d="M3 5h18v14H3zM6 9l3 3-3 3M12 15h5"/></symbol>
  <symbol id="i-spark" viewBox="0 0 24 24"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M18 6l-2.5 2.5M8.5 15.5L6 18"/></symbol>
  <symbol id="i-layers" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/></symbol>
  <symbol id="i-file-code" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6zM14 3v5h4M10 12l-2 2 2 2M14 12l2 2-2 2"/></symbol>
  <symbol id="i-file-text" viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><path d="M4 6h16M4 12h10M4 18h7"/></symbol>
  <symbol id="i-wand" viewBox="0 0 24 24"><path d="M15 4l5 5M13 6l5 5M4 20L14 10M6 4l1.5 3L11 8.5 7.5 10 6 13l-1.5-3L1 8.5 4.5 7 6 4Z"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3ZM9 12l2 2 4-4"/></symbol>
  <symbol id="i-share" viewBox="0 0 24 24"><path d="M4 12v7h16v-7M12 3v13M8 7l4-4 4 4"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M4 20h16"/></symbol>
  <symbol id="i-check" viewBox="0 0 20 20"><path d="M4 10l4 4 8-8"/></symbol>
  <symbol id="i-globe" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18ZM3 12h18M12 3c2.5 2.5 4 5.5 4 9s-1.5 6.5-4 9c-2.5-2.5-4-5.5-4-9s1.5-6.5 4-9Z"/></symbol>
  <symbol id="i-book" viewBox="0 0 24 24"><path d="M4 5a3 3 0 0 1 3-3h13v18H7a3 3 0 0 0-3 3V5ZM4 20a3 3 0 0 1 3-3h13"/></symbol>
  <symbol id="i-layout" viewBox="0 0 24 24"><path d="M3 4h18v16H3zM3 9h18M9 9v11"/></symbol>
  <symbol id="i-rocket" viewBox="0 0 24 24"><path d="M14 4c3-2 6-1 6-1s1 3-1 6l-6 6-4-4 5-7ZM8 15l-3 3M10 17l-2 3M7 14l-3 2"/></symbol>
</svg>"""


def link(current: str, target: str) -> str:
    return posixpath.relpath(target, posixpath.dirname(current) or ".")


def root_prefix(current: str) -> str:
    depth = len([part for part in posixpath.dirname(current).split("/") if part])
    return "" if depth == 0 else "../" * depth


def lang_alt(current: str, lang: str) -> str:
    if current.startswith("en/"):
        return current[3:]
    return "en/" + current


def lang_urls(current: str) -> tuple[str, str]:
    base = current[3:] if current.startswith("en/") else current
    return base, "en/" + base


def asset(current: str, path: str) -> str:
    return link(current, "assets/" + path)


def lexer_for(language: str):
    try:
        options = {"stripall": True}
        if language == "php":
            options["startinline"] = True
        return get_lexer_by_name(language, **options)
    except ClassNotFound:
        return TextLexer(stripall=True)


def code_window(code: str, language: str, filename: str, cid: str, lang: str, extra: str = "") -> str:
    inner = highlight(code.strip("\n"), lexer_for(language), HtmlFormatter(nowrap=True))
    return (
        f'<div class="code-window {extra}">'
        '<div class="code-bar"><span class="code-dots" aria-hidden="true"><i></i><i></i><i></i></span>'
        f'<span>{html.escape(filename)}</span>'
        f'<button class="copy-btn" type="button" data-copy="{cid}">{UI[lang]["copy"]}</button></div>'
        f'<pre><code id="{cid}">{inner}</code></pre></div>'
    )


def command_box(cid: str, command: str, lang: str) -> str:
    return (
        f'<div class="command-box"><code id="{cid}">{html.escape(command)}</code>'
        f'<button class="copy-btn copy-btn--light" type="button" data-copy="{cid}">{UI[lang]["copy"]}</button></div>'
    )


def icon(name: str, extra: str = "") -> str:
    return f'<svg class="icon {extra}" aria-hidden="true"><use href="#{name}"></use></svg>'


def docs_url(current: str, lang: str, slug: str) -> str:
    return link(current, f"{DOCS_PREFIX[lang]}{slug}.html")


def docs_hub_url(current: str, lang: str) -> str:
    return link(current, f"{DOCS_PREFIX[lang]}index.html")


def home_url(current: str, lang: str) -> str:
    return link(current, "index.html" if lang == "zh" else "en/index.html")


def alternate_url(current: str, lang: str) -> str:
    return link(current, lang_alt(current, lang))


def render_header(lang: str, current: str, active: str) -> str:
    home = home_url(current, lang)
    docs = docs_hub_url(current, lang)
    nav = [
        ("languages", f"{home}#languages", UI[lang]["nav_languages"]),
        ("features", f"{home}#features", UI[lang]["nav_features"]),
        ("docs", docs, UI[lang]["nav_docs"]),
        ("install", f"{home}#install", UI[lang]["nav_install"]),
        ("versions", f"{home}#versions", UI[lang]["nav_versions"]),
        ("download", f"{home}#download", UI[lang]["nav_download"]),
    ]
    nav_html = "".join(
        f'<a href="{href}"{" class=\"is-active\"" if key == active else ""}>{label}</a>'
        for key, href, label in nav
    )
    alt = alternate_url(current, lang)
    alt_lang = OTHER[lang]
    current_label = "中文" if lang == "zh" else "EN"
    alt_label = "EN" if lang == "zh" else "中文"
    alt_hreflang = "en" if lang == "zh" else "zh-CN"
    alt_lang_attr = "en" if lang == "zh" else "zh-CN"
    return f'''<header class="site-header" id="top">
  <div class="wrap header-inner">
    <a class="brand" href="{home}" aria-label="Apidoc">
      <img src="{asset(current, "img/apidoc-pet.svg")}" width="38" height="38" alt="">
      <span class="brand-name">Apidoc <span class="brand-tag">API</span></span>
    </a>
    <div class="lang-switch" aria-label="{UI[lang]['footer_language']}">
      <span class="is-active" aria-current="true">{current_label}</span>
      <a href="{alt}" hreflang="{alt_hreflang}" lang="{alt_lang_attr}">{alt_label}</a>
    </div>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
      <span class="nav-toggle-bars" aria-hidden="true"></span>
      <span class="sr-only">{UI[lang]['menu_open']}</span>
    </button>
    <nav class="site-nav" id="site-nav" aria-label="Main navigation">
      {nav_html}
      <a class="nav-mobile-only" href="https://github.com/erikwang2013" target="_blank" rel="noopener">{UI[lang]['github']} {icon('i-external', 'icon-xs')}</a>
    </nav>
    <a class="header-cta" href="https://github.com/erikwang2013" target="_blank" rel="noopener">
      {UI[lang]['github']} {icon('i-external', 'icon-xs')}
    </a>
  </div>
</header>'''


def render_footer(lang: str, current: str) -> str:
    docs_links = "".join(
        f'<a href="{docs_url(current, lang, slug)}">{html.escape(DOCS[slug]["title"][lang])}</a>'
        for slug in SLUGS
    )
    project_links = "".join(
        f'<a href="{DOCS[slug]["repo"]}" target="_blank" rel="noopener">{DOCS[slug]["label"]} {icon("i-external", "icon-xs")}</a>'
        for slug in SLUGS
    )
    alt = alternate_url(current, lang)
    alt_label = "English" if lang == "zh" else "中文"
    if lang == "zh":
        pv = '本站总访问量 <b id="busuanzi_value_site_pv">0</b> 次 👀'
        uv = '访客数 <b id="busuanzi_value_site_uv">0</b> 人次 🧑‍🚀'
    else:
        pv = 'Total visits <b id="busuanzi_value_site_pv">0</b> 👀'
        uv = 'Visitors <b id="busuanzi_value_site_uv">0</b> 🧑‍🚀'
    return f'''<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <img src="{asset(current, "img/pet/coquettish.svg")}" width="64" height="64" alt="" aria-hidden="true">
      <div><strong>Apidoc</strong><p>{UI[lang]['footer_tagline']}</p></div>
    </div>
    <nav class="footer-col" aria-label="{UI[lang]['footer_guides']}">
      <h2>{UI[lang]['footer_guides']}</h2>
      {docs_links}
      <a href="{docs_hub_url(current, lang)}">{UI[lang]['docs']}</a>
    </nav>
    <nav class="footer-col" aria-label="{UI[lang]['footer_projects']}">
      <h2>{UI[lang]['footer_projects']}</h2>
      {project_links}
    </nav>
    <div class="footer-col">
      <h2>{UI[lang]['footer_about']}</h2>
      <a href="https://erik.xyz" target="_blank" rel="noopener">艾瑞可 erik</a>
      <a href="https://beian.miit.gov.cn" target="_blank" rel="noopener">粤ICP备2020136226号-8</a>
      <a href="mailto:erik@erik.xyz">erik@erik.xyz</a>
      <a href="https://github.com/HGthecode/apidoc-php" target="_blank" rel="noopener">{UI[lang]['reference_label']} HGthecode/apidoc-php</a>
      <a href="https://packagist.org/packages/hg/apidoc" target="_blank" rel="noopener">hg/apidoc</a>
      <a href="{alt}" hreflang="{OTHER[lang]}" lang="{OTHER[lang]}">{alt_label}</a>
    </div>
  </div>
  <div class="wrap footer-bottom">
    <p>© 2026 erik. Apidoc. {UI[lang]['footer_license']}.</p>
    <p>{UI[lang]['footer_assets']} <a href="https://github.com/erikwang2013/apidoc-php/blob/main/ASSETS-LICENSE.md" target="_blank" rel="noopener">ASSETS-LICENSE.md</a></p>
  </div>
  <div class="wrap footer-info" id="busuanzi"><script async src="//busuanzi.ibruce.info/busuanzi/2.3/busuanzi.pure.mini.js"></script><span id="busuanzi_container_site_pv">{pv}</span><span class="dot">·</span><span id="busuanzi_container_site_uv">{uv}</span></div>
</footer>'''


SHELL = Template('''<!doctype html>
<html lang="$html_lang">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>$title</title>
  <meta name="description" content="$description">
  <meta name="theme-color" content="#f7f6fc">
  <link rel="icon" href="${root}assets/img/apidoc-pet.svg" type="image/svg+xml">
  <link rel="preload" href="${root}assets/img/apidoc-pet.svg" as="image">
  <link rel="stylesheet" href="${root}assets/css/style.css">
  <link rel="alternate" hreflang="zh-CN" href="$alt_zh">
  <link rel="alternate" hreflang="en" href="$alt_en">
  <noscript>
    <style>
      .nav-toggle { display: none !important; }
      .site-nav { position: static !important; display: flex !important; flex-wrap: wrap; opacity: 1 !important; visibility: visible !important; transform: none !important; padding: 0 !important; border: 0 !important; background: transparent !important; box-shadow: none !important; margin-left: auto; }
      @media (max-width: 960px) {
        .header-inner { flex-wrap: wrap; padding-block: 10px; }
        .site-nav { width: 100%; margin: 6px 0 0; }
      }
    </style>
  </noscript>
  <meta property="og:type" content="$og_type">
  <meta property="og:title" content="$title">
  <meta property="og:description" content="$description">
  <meta property="og:image" content="$og_image">
  <meta property="og:image:width" content="1280">
  <meta property="og:image:height" content="640">
  <meta property="og:site_name" content="Apidoc">
  <meta property="og:locale" content="$og_locale">
  <meta property="og:locale:alternate" content="$og_locale_alt">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="$title">
  <meta name="twitter:description" content="$description">
  <meta name="twitter:image" content="$og_image">
  $extra_head
</head>
<body data-copy-label="$copy" data-copied-label="$copied" data-menu-open="$menu_open" data-menu-close="$menu_close">
  <a class="skip-link" href="#main">$skip</a>
  $sprite
  $header
  <main id="main">
  $body
  </main>
  $footer
  <button class="to-top" id="to-top" type="button" aria-label="$back_top" title="$back_top" data-label="$back_top" data-cute="$cute">
    <svg class="to-top-ring" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30"></circle></svg>
    <img class="to-top-pet" src="${root}assets/img/apidoc-pet.svg" width="48" height="48" alt="">
  </button>
  <script src="${root}assets/js/main.js" defer></script>
</body>
</html>''')


def page_shell(lang: str, current: str, body: str, title: str, description: str, extra_head: str = "", og_type: str = "website") -> str:
    root = root_prefix(current)
    return SHELL.substitute(
        html_lang=HTML_LANG[lang],
        title=html.escape(title, quote=False),
        description=html.escape(description, quote=True),
        root=root,
        alt_zh=link(current, lang_urls(current)[0]),
        alt_en=link(current, lang_urls(current)[1]),
        og_type=og_type,
        og_locale="zh_CN" if lang == "zh" else "en_US",
        og_locale_alt="en_US" if lang == "zh" else "zh_CN",
        og_image=root + ("assets/img/php/social-preview-en.png" if lang == "en" else "assets/img/social-preview.png"),
        extra_head=extra_head,
        copy=UI[lang]["copy"],
        copied=UI[lang]["copied"],
        menu_open=UI[lang]["menu_open"],
        menu_close=UI[lang]["menu_close"],
        skip="跳到主要内容" if lang == "zh" else "Skip to main content",
        sprite=SPRITE,
        header=render_header(lang, current, "home" if current in ("index.html", "en/index.html") else "docs"),
        body=body,
        footer=render_footer(lang, current),
        back_top=UI[lang]["back_top"],
        cute=asset(current, "img/pet/cute.svg"),
    )


# ---------------------------------------------------------------------------
# Home page
# ---------------------------------------------------------------------------

def feature_card(lang: str, current: str, index: int, card: dict) -> str:
    title, desc = FEATURE_TEXT[lang][index]
    visual = card["visual"]
    visual_html = ""
    if visual == "response":
        visual_html = f'''<div class="response-stack"><div class="response-line"><span class="status-pill">200 OK</span><span class="response-time">{'示例响应' if lang == 'zh' else 'sample response'}</span></div><div class="mini-json">{{ "code": 0, "data": {{ "id": 1024 }} }}</div></div>'''
    elif visual == "mock":
        visual_html = '''<div class="mock-list"><div><code>nickname</code><span>"erik"</span></div><div><code>email</code><span>"fake:email"</span></div><div><code>id</code><span>1024</span></div></div>'''
    elif visual == "versions":
        visual_html = '''<div class="version-list"><span><b>api</b> v1</span><span><b>admin</b> v2</span><span><b>open</b> v3</span></div>'''
    elif visual == "auth":
        visual_html = '''<div class="security-row"><code>password</code><span>******</span></div>'''
    elif visual == "formats":
        visual_html = '''<div class="gen-list"><span>JSON</span><span>TypeScript</span><span>Markdown</span><span>Swagger</span></div>'''
    elif visual == "codegen":
        visual_html = '''<div class="gen-list"><span>API file</span><span>Handler</span><span>SQL</span></div>'''
    elif visual == "ui":
        visual_html = f'''<div class="ui-mini"><img src="{asset(current, "img/ui/apidoc-icon.svg")}" width="28" height="28" alt=""><span>apidoc-ui · Vue 3</span></div>'''
    elif visual == "security":
        visual_html = '''<div class="security-row"><code>HMAC-SHA256</code><span>CORS</span></div>'''
    elif visual == "pipeline":
        steps = "".join(
            f'<span class="pipeline-step">{step}</span>' + (f'<span class="pipeline-arrow" aria-hidden="true">→</span>' if i < 4 else "")
            for i, step in enumerate(PIPELINE[lang])
        )
        visual_html = f'<div class="pipeline">{steps}</div>'
    pet = ""
    if card.get("pet"):
        pet_class = "card-pet" if card["span"] == "card-wide" else "share-pet"
        pet = f'<img class="{pet_class}" src="{asset(current, "img/pet/" + card["pet"])}" width="{64 if pet_class == "card-pet" else 150}" height="{64 if pet_class == "card-pet" else 150}" alt="">'
    return f'''<article class="bento-card {card['span']} reveal">
      <span class="bento-icon{' bento-icon--light' if card['span'] == 'card-share' else ''}" aria-hidden="true">{icon(HEADER_ICONS.get(card['icon'], 'i-spark'))}</span>
      <h3>{title}</h3><p>{desc}</p>{visual_html}{pet}
    </article>'''


def doc_card(lang: str, current: str, slug: str, large: bool = False) -> str:
    d = DOCS[slug]
    href = docs_url(current, lang, slug)
    items = "".join(f"<li>{item}</li>" for item in d["sections"][lang])
    return f'''<article class="docs-home-card{' docs-home-card--large' if large else ''} reveal">
      <div class="docs-home-top"><span class="lang-badge">{d['label']}</span><span class="version-pill">{d['version']}</span></div>
      <h3>{d['title'][lang]}</h3>
      <p>{d['summary'][lang]}</p>
      <ul>{items}</ul>
      <a class="text-link" href="{href}">{UI[lang]['read_guide']} {icon('i-arrow', 'icon-xs')}</a>
    </article>'''


def home_body(lang: str, current: str) -> str:
    t = UI[lang]
    root = root_prefix(current)
    hero_chips = {
        "php": "PHP · attributes",
        "go": "Go · structs",
        "rust": "Rust · macros",
        "ui": "Shared UI",
    }
    tracks = ""
    for slug in ("php", "go", "rust"):
        d = DOCS[slug]
        facts = "".join(f"<li>{f}</li>" for f in d["facts"][lang])
        tracks += f'''<article class="track-card track-{slug} reveal">
          <div class="track-top"><span class="lang-badge">{d['label']}</span><span class="version-pill">{d['version']}</span></div>
          <img class="track-pet" src="{asset(current, "img/pet/" + d['pet'])}" width="64" height="64" alt="" aria-hidden="true">
          <h3>{d['label']}</h3>
          <p class="track-tagline">{d['tagline'][lang]}</p>
          <p>{d['summary'][lang]}</p>
          <ul class="track-facts">{facts}</ul>
          {command_box(f"cmd-{slug}", d['install'], lang)}
          <div class="track-links"><a href="{docs_url(current, lang, slug)}">{t['read_guide']} {icon('i-arrow', 'icon-xs')}</a><a href="{d['repo']}" target="_blank" rel="noopener">{t['github']} {icon('i-external', 'icon-xs')}</a></div>
        </article>'''
    ui = DOCS["ui"]
    ui_band = f'''<article class="ui-band reveal">
      <div class="ui-band-copy">
        <div class="track-top"><span class="lang-badge">UI</span><span class="version-pill">{ui['version']}</span></div>
        <h3>{ui['title'][lang]}</h3>
        <p>{ui['summary'][lang]}</p>
        <p class="ui-note">{ui['tagline'][lang]} · {ui['frameworks']}</p>
        {command_box("cmd-ui", ui['install'], lang)}
        <div class="track-links"><a href="{docs_url(current, lang, 'ui')}">{t['read_guide']} {icon('i-arrow', 'icon-xs')}</a><a href="{ui['repo']}" target="_blank" rel="noopener">{t['github']} {icon('i-external', 'icon-xs')}</a></div>
      </div>
      <img class="ui-shot" src="{asset(current, 'img/ui/social-preview.svg')}" width="1280" height="640" loading="lazy" decoding="async" alt="">
    </article>'''

    compare_codes = {
        "php": (r'''use erikwang2013\apidoc\annotation as Apidoc;

#[Apidoc\Title("Get user")]
#[Apidoc\Method("GET")]
#[Apidoc\Url("/users/{id}")]
#[Apidoc\RouteParam(
    name: "id", type: "int"
)]
#[Apidoc\Returned(
    name: "name", type: "string"
)]
public function show(int $id) { }''', "php", "User.php", "cmp-php"),
        "go": ('''s.Register(apidoc.Route{
    Method: "GET",
    URL:    "/users/:id",
    Handler: getUser,
    Doc: apidoc.Doc{
        Title: "Get user",
        Params: []apidoc.Param{
            {Name: "id", In: "path",
             Type: "int", Required: true},
        },
        Responses: []apidoc.Response{
            {Name: "name", Type: "string"},
        },
    },
})''', "go", "main.go", "cmp-go"),
        "rust": ('''#[apidoc::title("Get user")]
#[apidoc::method("GET")]
#[apidoc::url("/users/{id}")]
#[apidoc::route_param(
    name = "id", ty = "int", required
)]
#[apidoc::returned(
    name = "name", ty = "string"
)]
async fn get_user() {}''', "rust", "user.rs", "cmp-rust"),
    }
    compare = ""
    for slug in ("php", "go", "rust"):
        d = DOCS[slug]
        code, lex, filename, cid = compare_codes[slug]
        compare += f'''<article class="compare-card reveal">
          <div class="compare-head"><span class="lang-badge">{d['label']}</span><a href="{docs_url(current, lang, slug)}">{t['read_guide']} {icon('i-arrow', 'icon-xs')}</a></div>
          {code_window(code, lex, filename, cid, lang)}
        </article>'''

    features = "".join(feature_card(lang, current, i, c) for i, c in enumerate(FEATURE_CARDS))
    iteration = "".join(f'<div class="iteration-item"><strong>{a}</strong><p>{b}</p></div>' for a, b in ITERATION[lang])
    adapters = "".join(
        f'''<article class="adapter-group reveal"><div class="adapter-head"><h3>{g['label']}</h3><span>{g['count']}</span></div><div class="adapter-chips">{''.join(f'<span>{x}</span>' for x in g['items'])}</div></article>'''
        for g in ADAPTER_GROUPS
    )
    install_cards = ""
    for slug in SLUGS:
        d = DOCS[slug]
        install_cards += f'''<article class="install-card reveal">
          <div class="track-top"><span class="lang-badge">{d['label']}</span><span class="version-pill">{d['version']}</span></div>
          <h3>{d['title'][lang]}</h3>
          {command_box(f"install-{slug}", d['install'], lang)}
          <a class="text-link" href="{docs_url(current, lang, slug)}">{t['read_guide']} {icon('i-arrow', 'icon-xs')}</a>
        </article>'''
    versions = ""
    for v in VERSIONS:
        d = DOCS[v["key"]]
        points = "".join(f"<li>{p}</li>" for p in v["points"][lang])
        versions += f'''<article class="version-card reveal">
          <div class="track-top"><span class="lang-badge">{d['label']}</span><strong>{v['version']}</strong></div>
          <h3>{v['title'][lang]}</h3>
          <ul>{points}</ul>
          <a class="text-link" href="{d['release_url']}" target="_blank" rel="noopener">{t['release_notes']} {icon('i-external', 'icon-xs')}</a>
        </article>'''
    pets = "".join(
        f'''<figure class="pet-card reveal"><img src="{asset(current, "img/pet/" + f)}" width="260" height="260" loading="lazy" decoding="async" alt="Apidoc pet: {en_name}"><figcaption><strong>{name if lang == 'zh' else en_name}</strong><code>{code}</code></figcaption></figure>'''
        for f, name, en_name, code in PETS
    )
    download_links = [
        ("Packagist", "PHP package", "https://packagist.org/packages/erikwang2013/apidoc-php"),
        ("apidoc-php", "GitHub", DOCS["php"]["repo"]),
        ("apidoc-go", "v1.0.7", DOCS["go"]["repo"]),
        ("apidoc-rust", "v2.0.0", DOCS["rust"]["repo"]),
        ("apidoc-ui", "Vue 3.5", DOCS["ui"]["repo"]),
    ]
    download_links_html = "".join(
        f'<a href="{url}" target="_blank" rel="noopener">{label}<span>{meta}</span></a>' for label, meta, url in download_links
    )

    body = f'''<section class="hero" aria-labelledby="hero-title">
  <div class="hero-bg" aria-hidden="true"><canvas id="hero-canvas"></canvas></div>
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="hero-kicker">{icon('i-spark', 'icon-xs')} {t['hero_kicker']}</p>
      <h1 id="hero-title">{t['hero_title']}</h1>
      <p class="hero-sub">{t['hero_sub']}</p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="#languages">{t['choose_language']} {icon('i-arrow')}</a>
        <a class="btn btn-secondary" href="{docs_hub_url(current, lang)}">{t['read_guides']}</a>
      </div>
    </div>
    <div class="hero-visual" aria-hidden="true">
      <div class="orbit orbit-1"></div><div class="orbit orbit-2"></div>
      <img class="hero-pet" src="{asset(current, 'img/apidoc-pet.svg')}" width="300" height="300" alt="">
      <div class="float-chip chip-php">PHP · attributes</div>
      <div class="float-chip chip-go">Go · structs</div>
      <div class="float-chip chip-rust">Rust · macros</div>
      <div class="float-chip chip-ui">Shared UI</div>
    </div>
  </div>
</section>

<section class="stats-band" aria-label="Apidoc">
  <div class="wrap"><div class="stats-grid reveal">
    <div class="stat"><strong>3</strong><span>{t['stats_languages']}</span></div>
    <div class="stat"><strong>16</strong><span>{t['stats_adapters']}</span></div>
    <div class="stat"><strong>1</strong><span>{t['stats_ui']}</span></div>
    <div class="stat"><strong>12</strong><span>{t['stats_pets']}</span></div>
  </div></div>
</section>

<section class="section section-about" id="about" aria-labelledby="about-title">
  <div class="wrap about-grid">
    <div class="about-copy reveal">
      <h2 id="about-title">{t['intro_title']}</h2>
      <p>{t['intro_p1']}</p>
      <p>{t['intro_p2']}</p>
      <p class="about-source">{t['intro_source']}</p>
      <div class="about-tags"><span>PHP &gt;= 8.0</span><span>Go &gt;= 1.24</span><span>Rust &gt;= 1.82</span><span>MIT</span></div>
    </div>
    <figure class="about-figure reveal">
      <img src="{asset(current, 'img/php/social-preview-en.svg' if lang == 'en' else 'img/social-preview.svg')}" width="1280" height="640" loading="lazy" decoding="async" alt="{DOCS['php']['image_alt'][lang]}">
      <figcaption>{t['intro_caption']}</figcaption>
    </figure>
  </div>
</section>

<section class="section section-languages" id="languages" aria-labelledby="languages-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="languages-title">{t['languages_title']}</h2><p>{t['languages_sub']}</p></div>
    <div class="track-grid">{tracks}</div>
    {ui_band}
  </div>
</section>

<section class="section section-compare" id="compare" aria-labelledby="compare-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="compare-title">{t['compare_title']}</h2><p>{t['compare_sub']}</p></div>
    <div class="compare-grid">{compare}</div>
  </div>
</section>

<section class="section section-features" id="features" aria-labelledby="features-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="features-title">{t['features_title']}</h2><p>{t['features_sub']}</p></div>
    <div class="bento">{features}</div>
    <div class="iteration reveal">{iteration}</div>
  </div>
</section>

<section class="section section-docs-home" id="docs" aria-labelledby="docs-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="docs-title">{t['docs_title']}</h2><p>{t['docs_sub']}</p></div>
    <div class="docs-home-grid">{"".join(doc_card(lang, current, s) for s in SLUGS)}</div>
    <div class="docs-home-cta reveal"><a class="btn btn-primary" href="{docs_hub_url(current, lang)}">{t['open_docs']} {icon('i-arrow')}</a></div>
  </div>
</section>

<section class="section section-adapters" id="adapters" aria-labelledby="adapters-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="adapters-title">{t['adapters_title']}</h2><p>{t['adapters_sub']}</p></div>
    <div class="adapter-groups">{adapters}</div>
  </div>
</section>

<section class="section section-install" id="install" aria-labelledby="install-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="install-title">{t['install_title']}</h2><p>{t['install_sub']}</p></div>
    <div class="install-cards">{install_cards}</div>
    <p class="install-note">{t['install_note']}</p>
  </div>
</section>

<section class="section section-versions" id="versions" aria-labelledby="versions-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="versions-title">{t['versions_title']}</h2><p>{t['versions_sub']}</p></div>
    <div class="version-grid">{versions}</div>
  </div>
</section>

<section class="section section-pets" id="pets" aria-labelledby="pets-title">
  <div class="wrap">
    <div class="section-head reveal"><h2 id="pets-title">{t['pets_title']}</h2><p>{t['pets_sub']}</p></div>
    <div class="pet-grid">{pets}</div>
    <p class="pet-license">{t['pets_license']} <a href="https://github.com/erikwang2013/apidoc-php/blob/main/ASSETS-LICENSE.md" target="_blank" rel="noopener">ASSETS-LICENSE.md</a>.</p>
  </div>
</section>

<section class="section section-download" id="download" aria-labelledby="download-title">
  <div class="wrap">
    <div class="download-panel reveal">
      <div class="download-copy">
        <h2 id="download-title">{t['download_title']}</h2>
        <p>{t['download_sub']}</p>
        <div class="download-actions">
          <a class="btn btn-light" href="{docs_hub_url(current, lang)}">{t['download_docs']} {icon('i-arrow')}</a>
          <a class="btn btn-outline-light" href="https://github.com/erikwang2013" target="_blank" rel="noopener">{t['download_github']} {icon('i-external')}</a>
        </div>
      </div>
      <img class="download-pet" src="{asset(current, 'img/pet/knocking.svg')}" width="66" height="66" alt="" aria-hidden="true">
      <div class="download-links">{download_links_html}</div>
    </div>
  </div>
</section>'''
    return body


def home_jsonld(lang: str, current: str) -> str:
    data = {
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        "name": "Apidoc",
        "description": UI[lang]["hero_sub"],
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "Any",
        "programmingLanguage": ["PHP", "Go", "Rust"],
        "license": "https://opensource.org/licenses/MIT",
        "author": {"@type": "Person", "name": "erik", "url": "https://erik.xyz"},
    }
    return '<script type="application/ld+json">' + json.dumps(data, ensure_ascii=False) + "</script>"


def build_home(lang: str) -> None:
    current = "index.html" if lang == "zh" else "en/index.html"
    title = "Apidoc · PHP、Go、Rust 通用 API 文档体系" if lang == "zh" else "Apidoc · API documentation for PHP, Go and Rust"
    description = (
        "Apidoc 是 PHP、Go、Rust 通用的 API 文档体系：注解生成、在线调试、Mock、多应用版本、鉴权、导出与共享文档 UI。"
        if lang == "zh" else
        "Apidoc is an API documentation system for PHP, Go and Rust: annotations, live debugging, Mock, apps/versions, auth, exports and a shared docs UI."
    )
    body = home_body(lang, current)
    write(current, page_shell(lang, current, body, title, description, extra_head=home_jsonld(lang, current)))


# ---------------------------------------------------------------------------
# Docs hub
# ---------------------------------------------------------------------------

def render_docs_hub(lang: str) -> None:
    current = "docs/index.html" if lang == "zh" else "en/docs/index.html"
    t = UI[lang]
    cards = "".join(doc_card(lang, current, s, large=True) for s in SLUGS)
    steps = "".join(
        f'<div class="workflow-step"><span class="workflow-index">{i + 1}</span><strong>{step}</strong></div>'
        for i, step in enumerate(t["workflow_steps"])
    )
    rows = "".join(
        "<tr>" + "".join(f"<td>{cell}</td>" for cell in row) + "</tr>" for row in t["compare_rows"]
    )
    table = f'''<div class="table-scroll"><table class="compare-table"><thead><tr>{''.join(f'<th scope="col">{h}</th>' for h in t['compare_headers'])}</tr></thead><tbody>{rows}</tbody></table></div>'''
    body = f'''<section class="docs-hero">
  <div class="wrap">
    <p class="docs-breadcrumb"><a href="{home_url(current, lang)}">{t['home']}</a><span>/</span>{t['docs']}</p>
    <h1>{t['docs_hub_title']}</h1>
    <p>{t['docs_hub_sub']}</p>
    <p class="docs-meta">{t['docs_hub_meta']}</p>
  </div>
</section>
<div class="wrap docs-hub-body">
  <div class="docs-home-grid docs-hub-grid">{cards}</div>
  <section class="section workflow-section" aria-labelledby="workflow-title">
    <div class="section-head"><h2 id="workflow-title">{t['workflow_title']}</h2><p>{t['workflow_sub']}</p></div>
    <div class="workflow">{steps}</div>
  </section>
  <section class="section compare-section" aria-labelledby="compare-table-title">
    <div class="section-head"><h2 id="compare-table-title">{t['compare_table_title']}</h2><p>{t['compare_table_sub']}</p></div>
    {table}
  </section>
  <div class="docs-hub-cta"><a class="btn btn-primary" href="{home_url(current, lang)}#languages">{t['choose_language']} {icon('i-arrow')}</a><a class="btn btn-secondary" href="{home_url(current, lang)}">{t['home']}</a></div>
</div>'''
    title = "使用文档 · Apidoc" if lang == "zh" else "Usage Guides · Apidoc"
    description = t["docs_hub_sub"]
    write(current, page_shell(lang, current, body, title, description, og_type="article"))


# ---------------------------------------------------------------------------
# Markdown docs
# ---------------------------------------------------------------------------

def add_heading_ids(body: str) -> tuple[str, list[tuple[int, str, str]]]:
    headings: list[tuple[int, str, str]] = []

    def repl(match: re.Match[str]) -> str:
        level = int(match.group(1))
        inner = match.group(2)
        idx = len(headings) + 1
        hid = f"sec-{idx}"
        text = re.sub(r"<[^>]+>", "", inner)
        headings.append((level, hid, text))
        return f'<h{level} id="{hid}">{inner}</h{level}>'

    body = re.sub(r"<h([23])>(.*?)</h\1>", repl, body, flags=re.S)
    return body, headings


def render_markdown(markdown_text: str, lang: str, current: str) -> tuple[str, str]:
    root = root_prefix(current)
    docsbase = DOCS_PREFIX[lang]
    markdown_text = (
        markdown_text.replace("{{root}}", root)
        .replace("{{docsbase}}", docsbase)
        .replace("{{imglang}}", IMAGE_LANG[lang])
    )
    code_blocks: list[str] = []

    def stash(match: re.Match[str]) -> str:
        language = (match.group(1).strip().split()[0] if match.group(1).strip() else "text").lower()
        code = match.group(2)
        try:
            options = {"stripall": True}
            if language == "php":
                options["startinline"] = True
            lexer = get_lexer_by_name(language, **options)
        except ClassNotFound:
            lexer = TextLexer(stripall=True)
        rendered = highlight(code, lexer, HtmlFormatter(nowrap=True))
        slot = len(code_blocks)
        code_blocks.append(f'<pre class="highlight"><code class="language-{html.escape(language)}">{rendered}</code></pre>')
        return f'\n\n<div data-code-slot="{slot}"></div>\n\n'

    source = re.sub(r"^[ \t]*```([^\n`]*)\n(.*?)^[ \t]*```[ \t]*$", stash, markdown_text, flags=re.S | re.M)
    body = markdown.Markdown(extensions=["tables"]).convert(source)
    for i, block in enumerate(code_blocks):
        body = body.replace(f'<div data-code-slot="{i}"></div>', block)
    body = body.replace("<table>", '<div class="table-scroll"><table>').replace("</table>", "</table></div>")
    body = re.sub(r'<a href="(https?://[^"]+)"', r'<a href="\1" target="_blank" rel="noopener"', body)
    body = re.sub(r'<img ([^>]*?)>', lambda m: '<img loading="lazy" decoding="async" ' + m.group(1) + '>', body)
    body, headings = add_heading_ids(body)
    toc = ['<ul class="docs-toc">']
    for level, hid, text in headings:
        cls = ' class="is-sub"' if level == 3 else ""
        toc.append(f'<li{cls}><a href="#{hid}">{html.escape(text)}</a></li>')
    toc.append("</ul>")
    return body, "".join(toc)


def render_doc(lang: str, slug: str) -> None:
    current = f"{DOCS_PREFIX[lang]}{slug}.html"
    d = DOCS[slug]
    t = UI[lang]
    source_file = ROOT / "content" / lang / f"{slug}.md"
    body_md = source_file.read_text(encoding="utf-8")
    article, toc = render_markdown(body_md, lang, current)
    other_links = "".join(
        f'<a class="docs-next-link" href="{docs_url(current, lang, s)}"><span class="lang-badge">{DOCS[s]["label"]}</span><strong>{DOCS[s]["title"][lang]}</strong>{icon('i-arrow', 'icon-xs')}</a>'
        for s in SLUGS if s != slug
    )
    hero = f'''<section class="docs-hero">
  <div class="wrap">
    <p class="docs-breadcrumb"><a href="{home_url(current, lang)}">{t['home']}</a><span>/</span><a href="{docs_hub_url(current, lang)}">{t['docs']}</a><span>/</span>{d['label']}</p>
    <div class="docs-badges"><span class="lang-badge">{d['label']}</span><span class="version-pill">{d['version']}</span></div>
    <h1>{d['title'][lang]}</h1>
    <p>{d['summary'][lang]}</p>
    <div class="docs-actions"><a class="text-link" href="{d['repo']}" target="_blank" rel="noopener">{t['github']} {icon('i-external', 'icon-xs')}</a><a class="text-link" href="{d['source_url']}" target="_blank" rel="noopener">{t['docs_source']} {icon('i-external', 'icon-xs')}</a></div>
    <p class="docs-meta">{t['docs_version']} {d['version']} / {d['source_label'][lang]}</p>
  </div>
</section>'''
    end = f'''<div class="docs-end">
  <div><p><strong>{t['docs_install']}</strong></p>{command_box("doc-install-" + slug, d['install'], lang)}</div>
  <a class="btn btn-secondary" href="{docs_hub_url(current, lang)}">{t['docs_back']}</a>
</div>
<div class="docs-next"><h2>{t['docs_next_title']}</h2><div class="docs-next-grid">{other_links}</div></div>
<div class="docs-end docs-help">
  <div><p><strong>{t['docs_help']}</strong></p><p>{t['docs_help_text']}</p></div>
  <a class="btn btn-secondary" href="{d['repo']}/issues" target="_blank" rel="noopener">{t['docs_issue']} {icon('i-external')}</a>
</div>'''
    layout = f'''<div class="wrap docs-layout">
  <aside class="docs-sidebar">
    <p class="docs-sidebar-title">{t['docs_toc']}</p>
    {toc}
    <a class="docs-edit" href="{d['source_url']}" target="_blank" rel="noopener">{t['docs_source']} {icon('i-external', 'icon-xs')}</a>
  </aside>
  <article class="docs-article">
    <details class="docs-toc-mobile"><summary>{t['docs_toc']}</summary>{toc}</details>
    {article}
    {end}
  </article>
</div>'''
    title = f"{d['title'][lang]} · Apidoc"
    write(current, page_shell(lang, current, hero + layout, title, d['summary'][lang], og_type="article"))


# ---------------------------------------------------------------------------
# Helpers and entry
# ---------------------------------------------------------------------------

def write(rel_path: str, content: str) -> None:
    path = ROOT / rel_path
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    print(f"wrote {rel_path} ({len(content.encode('utf-8')):,} bytes)")


def build_redirect() -> None:
    write(
        "docs.html",
        '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        '<meta http-equiv="refresh" content="0;url=docs/index.html">'
        '<link rel="canonical" href="docs/index.html"><title>Apidoc Docs</title></head>'
        '<body><p><a href="docs/index.html">Apidoc 使用文档</a></p></body></html>',
    )


def main() -> None:
    for lang in LANGS:
        build_home(lang)
        render_docs_hub(lang)
        for slug in SLUGS:
            render_doc(lang, slug)
    build_redirect()


if __name__ == "__main__":
    main()
