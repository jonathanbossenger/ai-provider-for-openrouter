# Offline supported-options regression test

Run from the repository root. Prerequisites: PHP 7.4 or newer with `ext-json`,
Composer, and network access to install dependencies once. No WordPress bootstrap,
OpenRouter credentials, HTTP client, or API requests are needed.

If `vendor/autoload.php` already provides the SDK version locked by this checkout
(`wordpress/php-ai-client` 0.4.3), run:

```sh
php -d error_reporting=E_ALL tests/model-supported-options.php
```

The current root `composer.lock` omits several required development tools, so a
normal development install fails. `--no-dev` is not a test workaround: the SDK
itself is a development dependency. To run in a clean checkout without changing
the project's dependency manifests, install the locked SDK in a separate directory
and pass its autoloader explicitly:

```sh
deps="$(mktemp -d)"
printf 'Dependency directory: %s\n' "$deps"
composer --working-dir="$deps" --no-plugins --no-scripts require --no-interaction 'wordpress/php-ai-client:0.4.3'
php -d error_reporting=E_ALL tests/model-supported-options.php "$deps/vendor/autoload.php"
```

All variables are defined above; no existing review checkout is required. Keep the
printed dependency directory if rerunning the test. Composer plugins and scripts
are disabled during installation. The test exits nonzero on any failure and prints
individual results plus a summary (14 cases).

The fixtures execute the actual provider metadata parser, SDK model-requirements
matching, and inherited request serializer through small protected-method wrappers.
They verify that `top_k` does not advertise selectable `topK`, while existing sampling
mappings still select and serialize correctly. Coverage also includes normalized and
duplicate names, partial/missing/malformed parameter lists, tool mapping, and the
existing separate JSON/schema gates. The test never sends a request.

Restoring the original `top_k` mapping makes the suite fail: the SDK accepts
`setTopK(7)` during model selection but serializes no `top_k` request parameter.
Reintroduce this mapping only after implementing and testing serializer support.
The baseline-option and schema-gating policies are preserved, not redesigned here.
