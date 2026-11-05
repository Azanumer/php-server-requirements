# Server Requirements Checker

Verify a server meets a JSON profile — PHP version, extensions, `php.ini` limits, writable paths, required functions — **before** you install WordPress or deploy an app, instead of discovering the missing `gd` extension halfway through setup.

## Usage

```bash
php check-requirements.php                          # uses profiles/wordpress.json
php check-requirements.php --profile=profiles/my-app.json
php check-requirements.php --json                   # machine-readable output
```

Exit codes (CI-friendly): **0** = everything passed, **1** = warnings only, **2** = failures (or bad profile).

## Profile format

```json
{
  "name": "My App",
  "php_version": "8.1",
  "extensions": {
    "required": ["json", "mbstring", "pdo_mysql"],
    "recommended": ["gd", "intl"]
  },
  "ini": {
    "memory_limit":       { "type": "bytes",   "min": "256M", "severity": "fail" },
    "max_execution_time": { "type": "seconds", "min": 120,    "severity": "warn" },
    "display_errors":     { "type": "value",   "equals": "0", "severity": "warn" }
  },
  "functions": { "required": ["curl_init"] },
  "writable_paths": ["/var/www/html/wp-content/uploads"]
}
```

- `bytes` accepts `256M` / `1G` / `512K`; `-1` (unlimited) always passes.
- `seconds` treats `0` (unlimited) as passing; `number` is a plain integer minimum with no unlimited exception.
- `severity` is `fail` (exit 2) or `warn` (exit 1) per rule.

`profiles/wordpress.json` ships with the WordPress 6.x hosting requirements (PHP 7.4+, required/recommended extensions, 256M memory limit).

## License

MIT
