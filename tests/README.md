# Offline text-provider regression tests

Prerequisites: PHP >= 7.4 with JSON, Composer and network only for dependency installation.
No WordPress bootstrap, HTTP adapter, credentials or inference calls are needed. All transports are fixtures.

The root development lock is inconsistent on trunk (lint tools absent); do not update it for this slice.
Prepare a NEW isolated directory, copy the existing lock and install its exact packages including
wordpress/php-ai-client 0.4.3 at b47c878b161af543f947fdb62999ecc8604998d3:

```sh
# TMPDIR can be set to your approved scratch location; no host-specific paths are required.
deps="$TMPDIR/openrouter-test-deps-$(php -r 'print bin2hex(random_bytes(8));')"
php tests/bootstrap-dependencies.php "$deps"
composer --working-dir="$deps" install --no-interaction --no-progress --no-plugins --no-scripts
php -d error_reporting=E_ALL tests/model-supported-options.php "$deps/vendor/autoload.php"
```

Composer warns that the copied lock hash differs from the reduced manifest; the locked versions
are deliberately retained. No dependency update is performed. The bootstrap refuses an existing
directory, runs no external commands, and never deletes anything. Keep the printed path for replay.

The supported-options runner (14 cases) is reused from PR #3 head
1386a995a8892dcf688e7047601d87ced9808562, authored by foo-bender
<bender@fooplugins.com>. Parameter intent originated with Brad Vincent
<bradvin@gmail.com> at 0e0d2797e9281260aa2a3fc7acc88e1c368dfd09;
baseline fix at 6caee1b3e05a013197809ae5468cb5c719f0e1a8 and topK fix at the
head above. Only those tests and specific option/helper additions are reused,
not competing whole metadata files or older configs/docs.

The tests execute the provider parser, real SDK requirement selection and inherited serializer.
They prove no typed topK is selectable when the SDK omits top_k, preserving other option mappings.
