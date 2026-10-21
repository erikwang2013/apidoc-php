#!/usr/bin/env python3
"""Contract tests for the generated static site.

Run from site/:
    python3 -m unittest test_build -v
"""
from __future__ import annotations

import html
import json
import re
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urlparse

import build

ROOT = Path(__file__).resolve().parent
SITE_URL = build.SITE_URL

PAGES = {
    "index.html": "/",
    "en/index.html": "/en/",
    "docs/index.html": "/docs/",
    "en/docs/index.html": "/en/docs/",
    "docs/php.html": "/docs/php.html",
    "docs/go.html": "/docs/go.html",
    "docs/rust.html": "/docs/rust.html",
    "docs/ui.html": "/docs/ui.html",
    "en/docs/php.html": "/en/docs/php.html",
    "en/docs/go.html": "/en/docs/go.html",
    "en/docs/rust.html": "/en/docs/rust.html",
    "en/docs/ui.html": "/en/docs/ui.html",
}

SITEMAP_NS = {
    "s": "http://www.sitemaps.org/schemas/sitemap/0.9",
    "x": "http://www.w3.org/1999/xhtml",
}


def read(rel_path: str) -> str:
    return (ROOT / rel_path).read_text(encoding="utf-8")


def jsonld_blocks(html_text: str) -> list[dict]:
    blocks = []
    for match in re.finditer(
        r'<script type="application/ld\+json">(.*?)</script>', html_text, re.S
    ):
        blocks.append(json.loads(match.group(1)))
    return blocks


def collect_types(value) -> set[str]:
    found: set[str] = set()
    if isinstance(value, dict):
        type_value = value.get("@type")
        if isinstance(type_value, str):
            found.add(type_value)
        elif isinstance(type_value, list):
            found.update(item for item in type_value if isinstance(item, str))
        for child in value.values():
            found |= collect_types(child)
    elif isinstance(value, list):
        for child in value:
            found |= collect_types(child)
    return found


class BuildContractTest(unittest.TestCase):
    def assert_contains(self, needle: str, haystack: str, message: str) -> None:
        self.assertTrue(needle in haystack, message)

    def assert_not_contains(self, needle: str, haystack: str, message: str) -> None:
        self.assertFalse(needle in haystack, message)

    @classmethod
    def setUpClass(cls) -> None:
        subprocess.run(
            [sys.executable, "build.py"],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_every_page_has_absolute_canonical_and_og_url(self) -> None:
        for rel_path, route in PAGES.items():
            html_text = read(rel_path)
            canonical = f"{SITE_URL}{route}"
            self.assert_contains(
                f'<link rel="canonical" href="{canonical}">',
                html_text,
                f"{rel_path}: canonical",
            )
            self.assert_contains(
                f'<meta property="og:url" content="{canonical}">',
                html_text,
                f"{rel_path}: og:url",
            )

    def test_every_page_has_x_default_and_absolute_social_image(self) -> None:
        for rel_path, route in PAGES.items():
            html_text = read(rel_path)
            self.assert_contains(
                'hreflang="x-default"', html_text, f"{rel_path}: x-default"
            )
            image_match = re.search(
                r'<meta property="og:image" content="([^"]+)">', html_text
            )
            self.assertIsNotNone(image_match, f"{rel_path}: og:image missing")
            assert image_match is not None
            self.assertTrue(
                image_match.group(1).startswith(SITE_URL + "/"),
                f"{rel_path}: og:image must be absolute, got {image_match.group(1)}",
            )
            twitter_match = re.search(
                r'<meta name="twitter:image" content="([^"]+)">', html_text
            )
            self.assertIsNotNone(twitter_match, f"{rel_path}: twitter:image missing")
            assert twitter_match is not None
            self.assertTrue(
                twitter_match.group(1).startswith(SITE_URL + "/"),
                f"{rel_path}: twitter:image must be absolute",
            )

    def test_every_page_has_donation_entry_and_modal(self) -> None:
        for rel_path in PAGES:
            html_text = read(rel_path)
            self.assert_contains(
                'class="donate"', html_text, f"{rel_path}: donate section"
            )
            self.assert_contains(
                'data-donate="wechat"', html_text, f"{rel_path}: WeChat trigger"
            )
            self.assert_contains(
                'data-donate="alipay"', html_text, f"{rel_path}: Alipay trigger"
            )
            self.assert_contains(
                '<dialog class="donate-dialog" id="donate-dialog"',
                html_text,
                f"{rel_path}: donation dialog",
            )
            self.assert_contains(
                'aria-haspopup="dialog"',
                html_text,
                f"{rel_path}: triggers announce dialog",
            )
            self.assert_contains(
                'type="submit"', html_text, f"{rel_path}: modal close control"
            )

    def test_analytics_and_three_are_not_in_initial_page_html(self) -> None:
        for rel_path in PAGES:
            html_text = read(rel_path)
            self.assert_not_contains(
                "busuanzi.pure.mini.js",
                html_text,
                f"{rel_path}: busuanzi must be injected after load",
            )
            self.assert_not_contains(
                "three.module.min.js",
                html_text,
                f"{rel_path}: three.js must stay out of the initial HTML",
            )

    def test_main_js_contains_dialog_and_deferred_loaders(self) -> None:
        script = read("assets/js/main.js")
        self.assertIn("donate-dialog", script)
        self.assertIn("showModal", script)
        self.assertIn("busuanzi.pure.mini.js", script)
        self.assertIn("addEventListener('load'", script)
        self.assertIn("requestIdleCallback", script)

    def test_robots_points_to_absolute_sitemap(self) -> None:
        robots = read("robots.txt")
        self.assertIn("User-agent: *", robots)
        self.assertIn(f"Sitemap: {SITE_URL}/sitemap.xml", robots)

    def test_sitemap_lists_all_pages_with_language_alternates(self) -> None:
        tree = ET.fromstring(read("sitemap.xml"))
        urls = tree.findall("s:url", SITEMAP_NS)
        locations = {url.find("s:loc", SITEMAP_NS).text for url in urls}
        expected = {f"{SITE_URL}{route}" for route in PAGES.values()}
        self.assertEqual(locations, expected)

        for url in urls:
            loc = url.find("s:loc", SITEMAP_NS).text
            alternates = {
                link.get("hreflang") for link in url.findall("x:link", SITEMAP_NS)
            }
            self.assertEqual(
                alternates, {"zh-CN", "en", "x-default"}, f"{loc}: alternates"
            )

    def test_docs_pages_expose_article_and_breadcrumb_schema(self) -> None:
        for rel_path in ("docs/php.html", "en/docs/php.html"):
            types: set[str] = set()
            for block in jsonld_blocks(read(rel_path)):
                types |= collect_types(block)
            self.assertIn("TechArticle", types, f"{rel_path}: TechArticle")
            self.assertIn("BreadcrumbList", types, f"{rel_path}: BreadcrumbList")
            self.assertIn("Organization", types, f"{rel_path}: Organization")

    def test_home_exposes_website_and_software_schema(self) -> None:
        types: set[str] = set()
        for block in jsonld_blocks(read("index.html")):
            types |= collect_types(block)
        self.assertIn("WebSite", types)
        self.assertIn("SoftwareApplication", types)
        self.assertIn("Organization", types)

    def test_legacy_redirect_is_noindex_and_canonical(self) -> None:
        html_text = read("docs.html")
        self.assertIn('name="robots" content="noindex,follow"', html_text)
        self.assertIn(
            f'<link rel="canonical" href="{SITE_URL}/docs/">', html_text
        )

    def test_docs_hub_renders_faq_with_schema(self) -> None:
        for rel_path in ("docs/index.html", "en/docs/index.html"):
            html_text = read(rel_path)
            types: set[str] = set()
            for block in jsonld_blocks(html_text):
                types |= collect_types(block)
            self.assertIn("FAQPage", types, f"{rel_path}: FAQPage")
            self.assertGreaterEqual(
                html_text.count('<details class="faq-item">'),
                4,
                f"{rel_path}: visible FAQ items",
            )

    def test_404_page_is_noindex_with_navigation(self) -> None:
        html_text = read("404.html")
        self.assertIn('name="robots" content="noindex,follow"', html_text)
        self.assertIn('href="index.html"', html_text)
        self.assertIn('href="docs/index.html"', html_text)
        self.assertIn("404", html_text)

    def test_sitemap_includes_image_entries(self) -> None:
        tree = ET.fromstring(read("sitemap.xml"))
        namespaces = {
            **SITEMAP_NS,
            "image": "http://www.google.com/schemas/sitemap-image/1.1",
        }
        images = tree.findall(".//image:image", namespaces)
        self.assertGreaterEqual(len(images), 9)
        for image in images:
            loc = image.find("image:loc", namespaces)
            self.assertIsNotNone(loc, "image:loc missing")
            assert loc is not None and loc.text is not None
            self.assertTrue(loc.text.startswith(SITE_URL + "/"), loc.text)
            self.assertTrue(
                (ROOT / urlparse(loc.text).path.lstrip("/")).is_file(), loc.text
            )

    def test_titles_and_descriptions_are_unique_and_sized(self) -> None:
        titles: set[str] = set()
        descriptions: set[str] = set()
        for rel_path in PAGES:
            html_text = read(rel_path)
            title_match = re.search(r"<title>(.*?)</title>", html_text, re.S)
            desc_match = re.search(
                r'<meta name="description" content="([^"]*)">', html_text
            )
            self.assertIsNotNone(title_match, f"{rel_path}: title")
            self.assertIsNotNone(desc_match, f"{rel_path}: description")
            assert title_match is not None and desc_match is not None
            title = html.unescape(title_match.group(1))
            description = html.unescape(desc_match.group(1))
            self.assertNotIn(title, titles, f"{rel_path}: duplicate title")
            self.assertNotIn(
                description, descriptions, f"{rel_path}: duplicate description"
            )
            titles.add(title)
            descriptions.add(description)
            self.assertGreaterEqual(
                len(title), 15, f"{rel_path}: title too short ({len(title)})"
            )
            self.assertLessEqual(
                len(title), 90, f"{rel_path}: title too long ({len(title)})"
            )
            self.assertGreaterEqual(
                len(description),
                50,
                f"{rel_path}: description too short ({len(description)})",
            )
            self.assertLessEqual(
                len(description),
                170,
                f"{rel_path}: description too long ({len(description)})",
            )

    def test_primary_pages_mention_api_documentation_in_title(self) -> None:
        for rel_path in PAGES:
            title_match = re.search(r"<title>(.*?)</title>", read(rel_path), re.S)
            self.assertIsNotNone(title_match, f"{rel_path}: title")
            assert title_match is not None
            title = html.unescape(title_match.group(1))
            if rel_path.startswith("en/"):
                self.assertIn(
                    "api documentation", title.lower(), f"{rel_path}: {title}"
                )
            else:
                self.assertIn("API 文档", title, f"{rel_path}: {title}")

    def test_primary_headings_mention_api_documentation(self) -> None:
        docs_pages = (
            "docs/index.html",
            "docs/php.html",
            "docs/go.html",
            "docs/rust.html",
            "docs/ui.html",
            "en/docs/index.html",
            "en/docs/php.html",
            "en/docs/go.html",
            "en/docs/rust.html",
            "en/docs/ui.html",
        )
        for rel_path in docs_pages:
            html_text = read(rel_path)
            h1_match = re.search(r"<h1[^>]*>(.*?)</h1>", html_text, re.S)
            self.assertIsNotNone(h1_match, f"{rel_path}: h1")
            assert h1_match is not None
            h1 = re.sub(r"<[^>]+>", "", h1_match.group(1))
            if rel_path.startswith("en/"):
                self.assertIn(
                    "api documentation", h1.lower(), f"{rel_path}: {h1}"
                )
            else:
                self.assertIn("API 文档", h1, f"{rel_path}: {h1}")

    def test_docs_content_images_have_width_and_height(self) -> None:
        docs_pages = (
            "docs/php.html",
            "docs/go.html",
            "docs/rust.html",
            "docs/ui.html",
            "en/docs/php.html",
            "en/docs/go.html",
            "en/docs/rust.html",
            "en/docs/ui.html",
        )
        for rel_path in docs_pages:
            html_text = read(rel_path)
            images = re.findall(r"<img\b[^>]*>", html_text)
            self.assertTrue(images, f"{rel_path}: no images")
            for image in images:
                self.assertIn('width="', image, f"{rel_path}: {image[:100]}")
                self.assertIn('height="', image, f"{rel_path}: {image[:100]}")


if __name__ == "__main__":
    unittest.main()
