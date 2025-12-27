<!--
Guidance for AI coding agents working on this repository.
Keep instructions short, actionable and specific to patterns discovered in the codebase.
-->
# Copilot instructions for Project Admin

- Repo layout: the webroot is `public/`. PHP source files live under `src/`.
- Entry point: `public/index.php` — a minimal front controller that maps `?page=<slug>` to files in `src/pages/` using the `$availablePages` map.
- Layout: `src/layout.php` composes pages; it includes `src/partials/header.php` and `src/partials/sidebar.php` and then includes the resolved ` $contentFile`.

Key conventions and actionable rules
- Routing: To add a page, add an entry to the `$availablePages` array in `public/index.php` and create `src/pages/<slug>.php`.
  - Example: to add `reports`: add `'reports' => 'Reports'` to `$availablePages` and create `src/pages/reports.php`.
- Template composition: `src/layout.php` expects these variables to be set before `require`:
  - `$page` (the slug)
  - `$pageTitle` (string used in `<title>`)
  - `$contentFile` (absolute path to the page file)
  - `$availablePages` (array used for sidebar)

- Partial responsibility:
  - `src/partials/header.php` contains the header UI. Keep non-UI logic out of it.
  - `src/partials/sidebar.php` expects `$availablePages` and `$page` and uses `htmlspecialchars()` for labels. Preserve this pattern.

- Assets: static assets live under `public/assets/*`. Files are referenced from the browser root as `assets/...` by `src/layout.php`.

Security and encoding
- The project already uses `htmlspecialchars()` for `$pageTitle` and sidebar labels; preserve this pattern for any user-supplied/output strings.

Developer workflows (how to run and debug)
- No build step or composer dependencies are present.
- Quick dev server (PowerShell):
```
php -S localhost:8000 -t public
```
Then open `http://localhost:8000/?page=dashboard`.
- Laragon environment: the project location (`c:\laragon\www\project-admin`) suggests Laragon. You can also use Laragon's local domain or the PHP built-in server above.
- To enable verbose PHP errors for local debugging, run (PowerShell):
```
php -d display_errors=1 -d error_reporting=E_ALL -S localhost:8000 -t public
```

Project-specific patterns to follow
- Single-file pages: each page is a small PHP fragment (no class/controller). Keep page files focused on markup and simple presentation logic only.
- State is passed via globals before including `src/layout.php` — prefer setting variables in `public/index.php` rather than inside `layout.php`.
- Use `__DIR__`-based includes (the repo already uses `__DIR__ . '/partials/...'`) for stable paths.

PR checklist for UI/page changes
- Add or update `$availablePages` in `public/index.php` when you add a page slug.
- Create `src/pages/<slug>.php` with only markup and minimal inline logic.
- If layout changes needed, update `src/layout.php` and `src/partials/*`.
- Add any new static files to `public/assets/` and reference them as `assets/...` from `layout.php`.

Notes for AI edits
- Avoid introducing framework-specific code — this project is intentionally minimal PHP.
- Preserve existing HTML structure and CSS class names to avoid breaking styles (see `public/assets/css/style.css`).
- Localization: many strings are in Indonesian. Keep language consistent and match existing phrasing when editing text.
- Do not change `public/` as a location for web-accessible files; server must use `public/` as document root.

Files to inspect for examples
- `public/index.php` — routing and page resolution
- `src/layout.php` — page composition and assets
- `src/partials/header.php`, `src/partials/sidebar.php` — UI components
- `src/pages/*.php` — page examples (dashboard, users, settings)

If anything here is unclear, ask for the intended developer workflow (Laragon virtual host vs PHP built-in) or whether pages will move to a controller architecture.
