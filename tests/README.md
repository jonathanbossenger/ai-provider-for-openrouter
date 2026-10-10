# Offline text and image provider regression tests

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
  require_parameters=true. No schema-dropping retries. Ordinary text calls on dual models
  remain on the text serializer/parser and do not request image modalities.
- Exact implemented input sets are [text] and, for vision, [text,image]. Text output is [text].
  Architecture input/output arrays take precedence over legacy modality; each direction is
  normalized separately. Image-capable models additionally advertise separate [image]
  output for the implemented image route, never mixed [text,image]. PDF, video, audio
  and streaming are not advertised.

PR #8 head faf076cedaf737d974395eeeadb5dff0b3484382 (Copilot,
verified commit identity copilot-swe-agent[bot] <198982749+Copilot@users.noreply.github.com>)
provides the text-only multimodal intent. PR #9 head
adcb8014ee91578a29962b5f89009f1a5cee3a95 by Roberto Aranda
<roberto.aranda@automattic.com> provides tool/structured-output discovery intent.
Their whole metadata implementations are not copied; the integration uses one normalized
owner, separates tools/JSON/schema evidence, and advertises only implemented media forms.
PR #7 image intent is implemented in the separate image slice described below.
Issue #11 and Roberto's comment 6043724842 are the policy authority; maintainer approval
has not been received. No production/live OpenRouter/WordPress acceptance is claimed.

The tests execute the provider parser, real SDK requirement selection and inherited serializer.
They prove no typed topK is selectable when the SDK omits top_k, preserving other option mappings.

## Image contract gate

The runner also executes 146 image contracts (203 tests total: 14 options, 43 text,
146 image). Real SDK registry/PromptBuilder `generateImageResult()` performs normal
image-only requirement selection and interface dispatch. Synthetic dual-output,
image-only, text-only and malformed metadata fixtures and a strict mock transport
exercise requests and results. No generated/input URL or local path is retrieved.

- Image generation and editing use POST `/chat/completions`, not `/images` or
  `/images/generations`. Only image calls set wire `modalities`: `["text","image"]`
  for dual models and `["image"]` for image-only models. These are distinct from
  the SDK's exact `[image]` output requirement. The dedicated Image API's options
  and discovery are not implemented.
- Text plus optional reference images and complete message history/system are
  preserved. Image inputs accept HTTP(S) URLs without credentials/fragments or
  canonical base64 data URIs declaring PNG, JPEG, WebP or GIF. URLs are forwarded
  to OpenRouter, never downloaded by this adapter. Caller-created SDK File DTOs
  may themselves read caller-supplied local files; this adapter adds no path or
  URL resolution from generated responses.
- Image calls accept systemInstruction, image-only outputModalities, and bounded
  customOptions (`user`; provider `only/order/ignore`, `allow_fallbacks`, simple
  `sort`). Every other configured SDK field is rejected locally, even explicit
  candidateCount=1: count, MIME/file type, size/aspect/orientation, sampling,
  tools/schema and streaming are not silently ignored or coerced. Custom options
  cannot override model/messages/modalities or bypass those restrictions. Shared
  dual-model metadata still supports text tools/schema/options; this is not a
  claim that those options work on image calls.
- Results parse `choices[].message.images[].image_url.url` and retain candidate/
  image order, optional response text, result ID and response model in additional
  data. Supported declared result MIME is PNG/JPEG/WebP/GIF/SVG (MIME casing is
  normalized). Canonical nonempty base64 is validated before SDK File construction;
  these are MIME declarations, not a guarantee of decoded raster/SVG validity.
  Remote result URLs are rejected because their MIME is unknown, never guessed
  from a `.png` extension or fetched. Empty, malformed or unsupported responses
  and streaming fail with controlled errors rather than successful empty results.
- Usage maps prompt_tokens to prompt/input, completion_tokens to completion/output,
  and total_tokens to total. SDK 0.4.3 requires integers and has no nullable count:
  absent/null counts use the explicit provider-specific sentinel -1, NOT zero or
  an inferred total. Consumers must treat -1 as unknown, not billable tokens.
  Original optional/nested usage is retained in additionalData.openrouter_usage;
  additionalData.openrouter_unknown_token_counts names unknown fields. This is
  not a universal SDK unknown-count convention or proven billing compatibility.

PR #7 provenance: head 826b0c8c94a10e771ea1592fc4f379ab7fe948f1; authored by
copilot-swe-agent[bot] <198982749+Copilot@users.noreply.github.com>, with verified
trailers crediting jonathanbossenger <180629+jonathanbossenger@users.noreply.github.com>
in commits 3df7074ee2bacc5ff323363465bfc9b5aab21c74,
293c4eabcab8ba487b9e44c13c0b5b0b860ad822 and the head above. Its routing/editing
intent is retained; endpoint, exact modality sets, MIME/usage and parser naming
are reworked against the pinned SDK and current OpenRouter Chat Completions docs:
https://openrouter.ai/docs/api/api-reference/chat/create-a-chat-completion
and https://openrouter.ai/docs/guides/overview/multimodal/image-understanding.
The separate current Image API is documented at
https://openrouter.ai/docs/guides/overview/multimodal/image-generation.

Tests/bootstrap are development-only: /tests is excluded by .distignore from the
WordPress release filter and by .gitattributes export-ignore from source/Composer
archives. Run these commands from a Git checkout, not a release/source archive.
Static PHP 7.4 compatibility and the pinned SDK's array_is_list/str_contains
polyfills are checked; actual execution on PHP 7.4 and authenticated image/editing
WordPress/OpenStation acceptance remain outstanding. No live readiness is claimed.
