# Offline text-provider regression tests

Prerequisites: PHP >= 7.4 with JSON, Composer and network only for dependency installation.
No WordPress bootstrap, HTTP adapter, credentials or inference calls are needed. All transports are fixtures.

The root development lock is inconsistent on trunk (lint tools absent); do not update it for this slice.
Prepare a NEW isolated directory, copy the existing lock and install its exact packages including
wordpress/php-ai-client 0.4.3 at b47c878b161af543f947fdb62999ecc8604998d3:

```sh
# TMPDIR can be set to your approved scratch location; no host-specific paths are required.
deps="${TMPDIR:?Set TMPDIR to an approved scratch directory}/openrouter-test-deps-$(php -r 'print bin2hex(random_bytes(8));')"
php tests/bootstrap-dependencies.php "$deps"
composer --working-dir="$deps" install --no-interaction --no-progress --no-plugins --no-scripts
php tests/run.php "$deps/vendor/autoload.php"
```

Composer warns that the copied lock hash differs from the reduced manifest; the locked versions
are deliberately retained. No dependency update is performed. The bootstrap refuses an existing
directory, runs no external commands, and never deletes anything. Keep the printed path for replay.

The supported-options runner (14 cases) evolves PR #3 head
1386a995a8892dcf688e7047601d87ced9808562, authored by foo-bender
<bender@fooplugins.com>. Parameter intent originated with Brad Vincent
<bradvin@gmail.com> at 0e0d2797e9281260aa2a3fc7acc88e1c368dfd09;
baseline fix at 6caee1b3e05a013197809ae5468cb5c719f0e1a8 and topK fix at the
head above. Only those tests and specific option/helper additions are reused,
not competing whole metadata files or older configs/docs.

## Text contract gate

The text-contract suite exercises the real SDK PromptBuilder, registry/model-requirements
selection and mocked /models + /chat/completions request creation. Models and response files
under fixtures/ are deliberately synthetic offline examples, not recorded production data.
The combined gate includes system instructions, function declarations and JSON schemas through
asJsonResponse (the PHP builder equivalent of WordPress as_json_response), asOutputSchema,
and direct usingModelConfig. Pinned ModelConfig itself defaults schema MIME to JSON; an
additional config fixture overrides the getter to prove genuinely unset MIME is handled.

Runtime policy:
- Keep additive text baseline, including plain text MIME, even without advanced metadata.
- Only tools evidence enables declarations. response_format OR structured_outputs enables
  JSON mode; structured_outputs specifically enables schemas. Typed topK is not encoded.
- Raw JSON schemas are wrapped as name=response/schema/strict=true. SDK name/schema
  envelopes retain their name, description and explicit strict=false. Incompatible explicit
  MIME, malformed envelopes, contradictory provider.require_parameters and custom contract
  collisions fail locally. Schema requests preserve provider preferences and enforce
  require_parameters=true. No schema-dropping retries or image-generation route exists.
- Exact implemented input sets are [text] and, for vision, [text,image]. Text output is [text].
  Architecture input/output arrays take precedence over legacy modality; each direction is
  normalized separately. PDF, video, audio and mixed/image-only output are not advertised.

PR #8 head faf076cedaf737d974395eeeadb5dff0b3484382 (Copilot,
verified commit identity copilot-swe-agent[bot] <198982749+Copilot@users.noreply.github.com>)
provides the text-only multimodal intent. PR #9 head
adcb8014ee91578a29962b5f89009f1a5cee3a95 by Roberto Aranda
<roberto.aranda@automattic.com> provides tool/structured-output discovery intent.
Their whole metadata implementations are not copied; the integration uses one normalized
owner, separates tools/JSON/schema evidence, and advertises only implemented media forms.
PR #7 image implementation is deliberately deferred to a separate independently gated slice.
Issue #11 and Roberto's comment 6043724842 are the policy authority; maintainer approval
has not been received. No production/live OpenRouter/WordPress acceptance is claimed.

The tests execute the provider parser, real SDK requirement selection and inherited serializer.
They prove no typed topK is selectable when the SDK omits top_k, preserving other option mappings.
