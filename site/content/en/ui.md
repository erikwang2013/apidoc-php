apidoc-ui is the shared documentation front-end for PHP, Go and Rust backends. It fetches app config and document data, renders endpoints by app/version/group, and provides live debugging, sharing, Swagger export and cache management. The build output is fully static and has no server runtime dependency.

<figure class="doc-figure"><img src="{{root}}assets/img/ui/social-preview.svg" alt="apidoc-ui documentation front-end"><figcaption>apidoc-ui: one static documentation interface for PHP, Go and Rust backends.</figcaption></figure>

## When to use it

- You already use apidoc-php, apidoc-go or apidoc-rust and want one documentation UI.
- You want to deploy the docs front-end to a static server, CDN or a backend `public` directory.
- You need apps/versions, live debugging, sharing, Swagger export and cache management.
- You want title, backend URL and UI language to be runtime configuration instead of a rebuild.

## Build and deploy

Node.js 18+ is required:

```bash
npm ci
npm run dev      # local dev; port in config/constant.ts
npm run build    # vue-tsc + Vite build, output in apidoc/
```

Deployment checklist:

1. Copy the whole `apidoc/` directory to a static server or a backend `public` directory.
2. `config.js` must sit next to `index.html`; the build already copies it.
3. Clear the old directory before replacing files so stale hashed assets do not linger.
4. Hard-refresh after editing `config.js`; `config.js?v=<version>` is the cache-busting stamp.

## Runtime config

`config.js` sits next to `index.html`. Edits take effect on refresh without a rebuild:

```js
window.apidocFeConfig = {
  TITLE: "Apidoc",
  SHOW_VERSION: true,
  HTTP: {
    HOSTS: ["https://api.example.com"], // multiple backends; [] means same origin
    API_PREFIX: "/apidoc",
    TIMEOUT: 30000,
    WITHCREDENTIALS: false,
    ENCODEURICOMPONENT: false,
  },
  AUTH: {
    ERROR_STATUS: 401,
    ERROR_CODE_FIELD: "code",
  },
  MENU: { SHOWURL: false },
  METHOD_COLOR: {
    GET: "#87d068",
    POST: "#2db7f5",
    PUT: "#ff9800",
    DELETE: "#ff4d4f",
    PATCH: "#802feb",
  },
  API_DETAIL_TABS: ["table", "json", "ts", "debug"],
  LOAD_SCRIPTS: ["./utils/md5.js"],
};
```

| Config | Description |
| --- | --- |
| `TITLE` | Page title. |
| `SHOW_VERSION` | Show the version in the home footer. |
| `CACHE.PREFIX` | Local cache key prefix; use different values per environment. |
| `HTTP.HOSTS` | Backend URLs to switch between; empty means same origin. |
| `HTTP.API_PREFIX` | Request prefix, default `/apidoc`. |
| `HTTP.TIMEOUT` | Request timeout in milliseconds. |
| `HTTP.WITHCREDENTIALS` | Send credentials on cross-origin requests. |
| `AUTH.ERROR_STATUS` / `ERROR_CODE_FIELD` | Auth failure status and response field. |
| `MENU.SHOWURL` | Show endpoint URLs in the menu. |
| `METHOD_COLOR` | Colors per HTTP method. |
| `API_DETAIL_TABS` | Detail tab order: `table`, `json`, `ts`, `debug`. |
| `LOAD_SCRIPTS` | Extra scripts, such as md5. |
| `DEBUG_EVENTS` | Debug event extensions; see below. |
| `MOCK_EXTENDS` | Custom Mock rules. |
| `CUSTOM_METHODS` | Custom helpers. |
| `LANG` | UI language packs; add or extend as needed. |

## Multi-host, apps and appKey

- With multiple `HOSTS`, the UI provides a host switcher; each host can be a different backend.
- One backend can host multiple apps. The UI chooses `appKey` in this order: URL parameter `?appKey=xxx`, the first app returned by the backend, then manual selection in the sidebar.
- Auth tokens are cached per appKey (`CACHE.PREFIX + appKey`), so switching apps does not mix credentials.
- Version nodes appear in the app tree; switching reloads the endpoint data for that node.

## Auth

When the backend enables a password, the UI shows a password overlay. After login it exchanges the password for a token and stores it per appKey. `AUTH.ERROR_STATUS` and `AUTH.ERROR_CODE_FIELD` identify unauthorized responses. For cross-origin deployments:

- The backend must allow the docs page Origin.
- With `HTTP.WITHCREDENTIALS: true`, the backend cannot use `*`; it must reflect a specific Origin.
- Tokens live in browser storage; clear them on shared machines.

## Live debugging and global params

The Debug tab supports:

- Header, Query, Body and Route params;
- Global params that are sent with every request;
- File selection and upload;
- Before/after request events, to mutate params or format the response;
- Mock prefill from backend samples;
- Response status, latency and pretty JSON.

`DEBUG_EVENTS` registers custom handlers, for example hashing a password field or writing a token into a global header:

```js
window.apidocFeConfig.DEBUG_EVENTS = {
  setToken(param) {
    return new Promise((resolve, reject) => {
      this.setGlobalHeader({ ...param, value: `Bearer ${param.value}` })
        .then(() => resolve(param))
        .catch(reject);
    });
  },
};
```

## Sharing, exports and caching

- Sharing: create a link for an app or endpoint, with a token when needed.
- Exports: Swagger/OpenAPI JSON, Markdown, Postman JSON and printable PDF.
- Cache management: generate or clear endpoint caches; refresh after backend cache changes.
- JSON / TypeScript views: copy request/response examples and type definitions for the front-end.

## Common deployments

### Same origin

When the backend and UI share a domain, use `HOSTS: []` and `API_PREFIX: "/apidoc"`. Put the UI build in the backend `public` directory, or proxy the docs prefix appropriately.

### Cross origin

Host the UI on a static site, set `HOSTS: ["https://api.example.com"]`, and add the UI origin to the backend CORS allowlist. If cookies are needed, set `WITHCREDENTIALS: true` and reflect a specific Origin on the backend.

### Multiple backends

List multiple URLs in `HOSTS` for test/staging/production or multiple services. Tokens and app selection are kept per host/app.

### Subpath deployment

The build uses relative paths, so it can live under `/docs/` or another subpath. Keep `config.js` next to `index.html` and adjust `API_PREFIX` or `HOSTS` for the deployment path.

## Rules and troubleshooting

| Problem | Check |
| --- | --- |
| Blank page or 404 | Is `config.js` next to `index.html`? Does the static server fall back to `index.html`? |
| Empty endpoint list | Do `HOSTS` / `API_PREFIX` point at the backend docs API? Is the backend returning 401? |
| CORS failure | Does the backend allow the UI Origin? Does `WITHCREDENTIALS` conflict with `*`? |
| Config edits do nothing | Hard-refresh; check the `config.js?v=` stamp. |
| Stale assets | Clear the old directory before publishing. |
| Auth keeps failing | Check the password, app password, token expiry and appKey. |
| Share link fails | Check the host, appKey and token in the link. |
| Debug response is wrong | Check global headers/query/body, before scripts and target CORS. |

## Reference

- [apidoc-ui README](https://github.com/erikwang2013/apidoc-ui)
- [apidoc-ui repository](https://github.com/erikwang2013/apidoc-ui)
- Backend guides: [PHP]({{root}}{{docsbase}}php.html) · [Go]({{root}}{{docsbase}}go.html) · [Rust]({{root}}{{docsbase}}rust.html)
