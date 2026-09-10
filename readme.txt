=== AI Media Search ===
Contributors: danlapteacru
Tags: media library, search, ai, alt text, images
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search your Media Library by what is in the picture. An AI vision model describes each image so the search box finds it.

== Description ==

The WordPress media search only matches titles, captions, and descriptions. AI Media Search sends each image (and the first-page preview of each PDF) to a vision model of your choice, stores the description and tags it returns, and extends the Media Library search box to match that text. It works in list view, grid view, and the media modal inside the editor.

**Features**

* Automatic description of new uploads in the background.
* Dashboard with counts, filters, and a batch indexer with progress for your existing library.
* Editable AI description on every attachment, with a Regenerate button.
* Choice of provider and model: Anthropic Claude, OpenAI, or Google Gemini. Bring your own API key.
* Optional: fill empty alt text with the AI alt sentence.
* Optional custom prompt for house style or product names.

**Requirements**

You need an API key from the provider you choose. Each request costs a fraction of a cent to a few cents depending on the model; the settings screen shows an estimate next to each model.

== External services ==

This plugin sends image data to a third-party API that you select and configure. Nothing is sent until you save an API key.

**Anthropic Claude** (https://www.anthropic.com/) — When an image or PDF preview is indexed, the plugin sends the image bytes, the instruction text, and your API key to https://api.anthropic.com/v1/messages. Terms: https://www.anthropic.com/legal/commercial-terms. Privacy: https://www.anthropic.com/legal/privacy.

**OpenAI** (https://openai.com/) — Same data is sent to https://api.openai.com/v1/responses. Terms: https://openai.com/policies/terms-of-use. Privacy: https://openai.com/policies/privacy-policy.

**Google Gemini** (https://ai.google.dev/) — Same data is sent to https://generativelanguage.googleapis.com/. Terms: https://ai.google.dev/gemini-api/terms. Privacy: https://policies.google.com/privacy.

The "Test connection" button sends a short text prompt to the selected provider.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it.
3. Open **AI Media Search** in the admin menu, go to the Settings tab, choose a provider, paste your API key, and save.
4. On the Dashboard tab, click **Index all not indexed** to describe your existing library.
5. Search the Media Library for something in a picture.

== Frequently Asked Questions ==

= Does this change my titles, captions, or descriptions? =

No. AI text is stored in its own fields. The only optional write to a standard field is alt text, and only when it is empty and you turned that setting on.

= Which files are described? =

JPEG, PNG, GIF, WebP images, and PDFs for which WordPress generated a preview image (requires the Imagick extension on your server). Other files are marked as skipped.

= Can I correct the AI? =

Yes. Every attachment has an editable "AI description" field. Your edit is what the search uses.

= What does it cost? =

Only what your provider charges. The model dropdown shows a rough per-image estimate. Nothing is sent unless an API key is saved, and existing files are only processed when you start the indexer.

== Screenshots ==

1. Dashboard with counts, filters, and the batch indexer.
2. Settings tab with provider, model, and prompt options.
3. AI description and Regenerate button in the media modal.
4. Media Library search finding an image by its content.

== Changelog ==

= 1.0.0 =
* First release.
