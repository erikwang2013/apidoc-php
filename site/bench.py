#!/usr/bin/env python3
"""Benchmark the generated static site.

Acceptance:
  * Every page and static asset must have a max TTFB below 10 ms across the samples.
  * With --full, a warm full-page load must have a median at or below 1 s.

Run from site/:
    python3 bench.py
    python3 bench.py --full
"""
from __future__ import annotations

import argparse
import http.client
import shutil
import statistics
import sys
import threading
import time
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
TTFB_LIMIT_MS = 10.0
TTFB_MAX_LIMIT_MS = 10.0
FULL_LOAD_LIMIT_MS = 1000.0
TTFB_RUNS = 7
FULL_LOAD_RUNS = 5

PAGES = [
    "/",
    "/en/",
    "/docs/",
    "/en/docs/",
    "/docs/php.html",
    "/docs/go.html",
    "/docs/rust.html",
    "/docs/ui.html",
    "/en/docs/php.html",
    "/en/docs/go.html",
    "/en/docs/rust.html",
    "/en/docs/ui.html",
    "/docs.html",
    "/404.html",
    "/robots.txt",
    "/sitemap.xml",
]


class QuietHandler(SimpleHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def __init__(self, *args, **kwargs) -> None:
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def log_message(self, format: str, *args) -> None:  # noqa: A002
        pass


def start_server() -> tuple[ThreadingHTTPServer, int]:
    server = ThreadingHTTPServer(("127.0.0.1", 0), QuietHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server, server.server_address[1]


def request_paths() -> list[str]:
    paths = list(PAGES)
    for path in sorted((ROOT / "assets").rglob("*")):
        if path.is_file():
            paths.append("/" + path.relative_to(ROOT).as_posix())
    return paths


def measure_ttfb(connection: http.client.HTTPConnection, path: str) -> float:
    started = time.perf_counter()
    connection.request("GET", path)
    response = connection.getresponse()
    elapsed_ms = (time.perf_counter() - started) * 1000
    response.read()
    return elapsed_ms


def run_ttfb_benchmark() -> bool:
    server, port = start_server()
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    rows: list[tuple[str, float, float]] = []
    try:
        for path in request_paths():
            for _ in range(2):
                measure_ttfb(connection, path)
            samples = [measure_ttfb(connection, path) for _ in range(TTFB_RUNS)]
            rows.append((path, statistics.median(samples), max(samples)))
    finally:
        connection.close()
        server.shutdown()
        server.server_close()

    rows.sort(key=lambda row: row[1], reverse=True)
    print(f"TTFB benchmark ({TTFB_RUNS} runs; median and max limit {TTFB_LIMIT_MS:.0f} ms)")
    print(f"{'path':<58} {'median':>8} {'max':>8}")
    for path, median, maximum in rows:
        flag = " FAIL" if median >= TTFB_LIMIT_MS or maximum >= TTFB_MAX_LIMIT_MS else ""
        print(f"{path:<58} {median:>7.2f}ms {maximum:>7.2f}ms{flag}")

    failures = [row for row in rows if row[1] >= TTFB_LIMIT_MS or row[2] >= TTFB_MAX_LIMIT_MS]
    print()
    print(f"checked {len(rows)} responses; slowest median {rows[0][1]:.2f} ms")
    if failures:
        print(f"FAIL: {len(failures)} response(s) at or above {TTFB_LIMIT_MS:.0f} ms (median or max)")
        return False
    print(f"PASS: all responses below {TTFB_LIMIT_MS:.0f} ms")
    return True


def run_full_load_benchmark() -> bool:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("Playwright is not installed; skipping the full-page benchmark.")
        return True

    chrome = shutil.which("google-chrome") or shutil.which("chromium") or shutil.which(
        "chromium-browser"
    )
    if not chrome:
        print("Chrome/Chromium not found; skipping the full-page benchmark.")
        return True

    server, port = start_server()
    url = f"http://127.0.0.1:{port}/"
    samples: list[float] = []
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                headless=True,
                executable_path=chrome,
                args=["--no-sandbox"],
            )
            context = browser.new_context(viewport={"width": 1366, "height": 900})
            page = context.new_page()
            page.goto(url, wait_until="load", timeout=30000)
            page.wait_for_timeout(1500)
            for _ in range(FULL_LOAD_RUNS):
                page.reload(wait_until="load", timeout=30000)
                page.wait_for_timeout(300)
                samples.append(
                    page.evaluate(
                        """() => {
                          const nav = performance.getEntriesByType('navigation')[0];
                          return nav.loadEventEnd - nav.startTime;
                        }"""
                    )
                )
            browser.close()
    finally:
        server.shutdown()
        server.server_close()

    median = statistics.median(samples)
    rendered = ", ".join(f"{sample:.0f}ms" for sample in samples)
    print(f"Full-page load (warm reloads): {rendered}")
    print(f"median {median:.1f} ms (limit {FULL_LOAD_LIMIT_MS:.0f} ms)")
    if median > FULL_LOAD_LIMIT_MS:
        print("FAIL: full-page load median is above the limit")
        return False
    print("PASS: full-page load is within the limit")
    return True


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--full",
        action="store_true",
        help="also measure a warm full-page load with Playwright",
    )
    args = parser.parse_args()

    ok = run_ttfb_benchmark()
    if args.full:
        print()
        ok = run_full_load_benchmark() and ok
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
