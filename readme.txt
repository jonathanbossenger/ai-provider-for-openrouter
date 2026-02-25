=== AI Provider for OpenRouter ===
Contributors: psykro
Tags: ai, openrouter, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI Provider for OpenRouter for the PHP AI Client SDK.

== Description ==

This plugin provides OpenRouter integration for the PHP AI Client SDK. It enables WordPress sites to use hundreds of AI models from various providers through OpenRouter's unified, OpenAI-compatible API.

**Features:**

* Text generation with any OpenRouter-supported model
* Automatic model discovery from the OpenRouter API
* Automatic provider registration

Available models are dynamically discovered from the OpenRouter /models endpoint, including models from OpenAI, Anthropic, Google, Meta, Mistral, and many more.

**Requirements:**

* PHP 7.4 or higher
* For WordPress 6.9, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
* For WordPress 7.0 and above, no additional changes are required
* OpenRouter API key

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-openrouter/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your OpenRouter API key via the `OPENROUTER_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an OpenRouter API key? =

Visit [OpenRouter](https://openrouter.ai/) to create an account and generate an API key at https://openrouter.ai/settings/keys.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the OpenRouter-specific implementation that the PHP AI Client uses.

== Changelog ==

= 1.0.0 =

* Initial release
* Support for text generation models via OpenRouter
* Automatic model discovery from the OpenRouter API
