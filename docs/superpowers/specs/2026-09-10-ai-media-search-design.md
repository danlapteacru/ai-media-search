# AI Media Search — Design

Date: 2026-09-10
Status: approved in discussion, pending written review

## Problem

The WordPress Media Library search box only matches an attachment's title, caption,
and description. Typing "woman" returns nothing unless someone typed that word into a
field. Nothing on wordpress.org fixes this: alt-text generators never touch search,
"Media Search Enhanced" only widens the text fields searched, and "Media Library
Organizer" auto-categorizes but leaves the search box alone.

## Goal

A wordpress.org plugin that makes the Media Library search work like Photos on a
phone: the site owner types "woman on a beach" and gets images whose pixels match,
without ever having written that text.

## Decisions taken

| Decision | Choice |
|---|---|
| Matching approach | Vision-model descriptions and tags in post meta, matched by keyword. No embeddings in v1. |
| Providers | Anthropic Claude, OpenAI, Google Gemini. Site owner picks one. |
| Model | Per-provider dropdown in settings with cost hints and a "custom ID" entry. Site owner chooses. |
| Audience | Public wordpress.org release. Follow plugin review guidelines. |
| Indexing trigger | Automatic on upload via WP-Cron, plus a browser-driven bulk indexer for the existing library. |
| Alt text | Optional, off by default: fill an empty alt field with the model's alt sentence. |
| Visibility | Editable "AI description" field on the attachment details sidebar and edit page, with Regenerate. |
| File types | Images (jpeg, png, gif, webp) and PDFs via the first-page preview WP generates. |
| Admin UI | Own top-level menu page "AI Media Search" with two tabs, Dashboard and Settings, modeled on the "AI Alt-Text Generator" plugin's layout (stat cards as filters, paginated asset table, select rows, run with progress bar). |
| Custom prompt | Optional textarea in settings. Its text is appended to the built-in prompt as extra guidance. |
| Storage | Post meta plus `posts_join` / `posts_search` filters. No custom tables. |
| Background work | WP-Cron single events for uploads; REST-driven batches for bulk. No Action Scheduler. |
| HTTP | WordPress HTTP API (`wp_remote_post`) for all providers. No vendored SDKs. |

## Out of scope for v1

Video and audio, frontend search, embeddings or vector search, folders or
categories, API key encryption, multisite network-level settings (per-site settings
work as normal), and any provider beyond the three above.

## Naming

- Slug and text domain: `ai-media-search` (checked free on wordpress.org on 2026-09-10)
- Function and hook prefix: `aims_`
- PHP namespace: `AIMS`
- Option name: `aims_settings` (single array)
- Meta key prefix: `_aims_`
- REST namespace: `aims/v1`
- Requirements: PHP 7.4+, WordPress 6.0+

## Architecture

```
upload ──► add_attachment ──► Queue (schedule cron +10s)
                                   │
                            cron: aims_index_attachment
                                   │
                                   ▼
   Regenerate button ──► REST ──► Indexer ──► Image_Preparer ──► file path + mime
   Dashboard buttons ──► REST ──►    │
                                     ├──► Provider::describe() ──► Description_Result
                                     │
                                     └──► post meta (_aims_*), optional alt text

search box "woman" ──► WP_Query(post_type=attachment, s=woman)
                            │
                            ▼
                    Search filters: LEFT JOIN postmeta _aims_search_text,
                    rewrite each title clause to also match that meta
```

## Components

### Bootstrap — `ai-media-search.php`, `uninstall.php`

Plugin header, constants (version, path, URL), a small PSR-4 autoloader for the
`AIMS\` namespace under `includes/`, and instantiation of `AIMS\Plugin` on
`plugins_loaded`. Activation registers nothing heavy. Deactivation clears scheduled
`aims_index_attachment` events. `uninstall.php` deletes `aims_settings` and every
`_aims_*` meta row.

### `AIMS\Plugin`

Wires every other component's hooks. No logic of its own.

### `AIMS\Settings` — option registration

Registers the single `aims_settings` option with the Settings API and sanitizes it.
Keys and defaults:

| Key | Type | Default |
|---|---|---|
| `provider` | `claude` \| `openai` \| `gemini` | `claude` |
| `api_keys` | array keyed by provider id | all empty |
| `models` | array keyed by provider id, model ID string | first entry of each provider's known list |
| `custom_models` | array keyed by provider id, free text used when `models[x] === 'custom'` | all empty |
| `language` | string | `English` |
| `custom_prompt` | string, textarea | empty |
| `auto_index` | bool | `true` |
| `fill_alt` | bool | `false` |
| `batch_size` | int 1–10 | `3` |

`Settings::get( string $key )` returns one value with the default applied.
`Settings::get_active_model()` resolves the model ID for the active provider,
honouring the custom entry.

### `AIMS\Admin_Page` — top-level menu page with two tabs

`add_menu_page()` with slug `ai-media-search`, capability `manage_options`, icon
`dashicons-search`. The page renders a header, a tab bar (Dashboard, Settings), and
one tab body. Tab switching is client-side; the active tab is remembered in the URL
hash. Markup uses core admin classes (`wrap`, `widefat`, `button`, `notice`) and a
small stylesheet, no utility framework.

**Settings tab** is a standard `options.php` form with these fields:

- Provider: radio, one of `claude`, `openai`, `gemini`.
- API key: one password field per provider. All three are stored so switching
  provider does not lose keys.
- Model: one select per provider listing known model IDs with a short cost hint
  (for example "claude-opus-5 — about $0.015 per image") and a final "Custom…"
  entry that reveals a text input for any model ID. Initial selection is the first
  entry in each list. The lists live in each provider class.
- Description language: text field, default "English".
- Custom prompt: textarea. Appended to the built-in prompt as "Additional guidance
  from the site owner". Empty by default.
- Auto-index new uploads: checkbox, default on.
- Fill empty alt text: checkbox, default off.
- Batch size: number 1–10, default 3.
- Test connection: button that calls the test REST route with the saved provider
  and shows success or the error message inline.

A plain notice on this tab reads: "Images and PDF previews are sent to the selected
third-party API for analysis." The same text appears in `readme.txt` under
"External services", which the wordpress.org review requires.

**Dashboard tab** shows:

- Five stat cards that double as filter links: All, Indexed, Not indexed, Failed,
  Skipped. Counts come from `Stats::counts()`.
- A paginated table (50 rows per page, `paged` and `filter` query args) of eligible
  attachments (images and PDFs, `post_status = inherit`). Columns: checkbox,
  thumbnail (60 px), title and filename, AI description (first 160 characters), tags,
  status with timestamp or error text, actions ("Index" or "Regenerate" button, and
  an "Edit" link to the attachment page). A select-all checkbox in the header.
- Below the table: "Index selected" button, "Index all not indexed" button, a
  "Retry failed" checkbox, a progress bar, a running count, a Stop button, and a
  scrolling log of per-file results.
- If no API key is saved for the active provider, both buttons are disabled and a
  notice links to the Settings tab.

Both buttons drive the same loop in `admin.js`: call the bulk REST route with either
an explicit list of IDs (selected rows) or no list (server picks the next unindexed
rows) until the response reports zero remaining or Stop is pressed. Rows update in
place after each batch.

### `AIMS\Stats`

`Stats::counts(): array` returns `total`, `indexed`, `not_indexed`, `failed`,
`skipped` using one `SELECT meta_value, COUNT(*)` grouped query on
`_aims_status` joined to eligible attachments, plus the total. Cached in a
transient for 60 seconds and cleared by the indexer after every write.

### `AIMS\Providers\Provider_Interface`

```php
interface Provider_Interface {
    /** @return Description_Result|\WP_Error */
    public function describe( string $file_path, string $mime_type, string $instructions );
    /** @return true|\WP_Error */
    public function test_connection();
    public static function get_id(): string;
    public static function get_label(): string;
    /** @return array<string, string> model id => human hint */
    public static function get_known_models(): array;
}
```

`Description_Result` is a small value object with `description` (string),
`tags` (string[]), and `alt` (string).

### `AIMS\Providers\Claude_Provider`, `OpenAI_Provider`, `Gemini_Provider`

Each builds one request with `wp_remote_post`, timeout 60 seconds, sending the file
as base64 inline image data and the shared prompt, and asks for JSON through the
provider's native schema mode. Request shapes below were verified against each
provider's live documentation on 2026-09-10.

**Claude** — `POST https://api.anthropic.com/v1/messages`, headers `x-api-key`,
`anthropic-version: 2023-06-01`, `content-type: application/json`. Body:

```json
{
  "model": "claude-opus-5",
  "max_tokens": 1024,
  "system": "<instruction text>",
  "messages": [{"role": "user", "content": [
    {"type": "image", "source": {"type": "base64", "media_type": "image/jpeg", "data": "<b64>"}},
    {"type": "text", "text": "Describe this image."}
  ]}],
  "output_config": {"format": {"type": "json_schema", "schema": { ...schema... }}}
}
```

Response JSON is in `content[0].text`. `stop_reason: "refusal"` means refused.
Schema objects need `additionalProperties: false` and a full `required` list.
Images may be up to 10 MB base64; Claude 4.7 and later view up to a 2576 px long
edge, so we downscale to about 1600 px to keep cost predictable.
Known models with hints: `claude-opus-5` ($5 / $25 per MTok), `claude-sonnet-5`
($2 / $10), `claude-haiku-4-5` ($1 / $5).

**OpenAI** — `POST https://api.openai.com/v1/responses`, header
`Authorization: Bearer <key>`. Body:

```json
{
  "model": "gpt-5.6-terra",
  "instructions": "<instruction text>",
  "input": [{"role": "user", "content": [
    {"type": "input_text", "text": "Describe this image."},
    {"type": "input_image", "image_url": "data:image/jpeg;base64,<b64>", "detail": "auto"}
  ]}],
  "text": {"format": {"type": "json_schema", "name": "media_description", "schema": { ...schema... }, "strict": true}}
}
```

Response JSON is the first `output[]` item of type `message`, its
`content[]` item of type `output_text`, field `text`. A `content[]` item of type
`refusal` means refused. Known models: `gpt-6-astra` ($10 / $50), `gpt-5.6-sol`
($4 / $20), `gpt-5.6-terra` ($2 / $12), `gpt-5.6-luna` ($0.20 / $1.20).

**Gemini** — `POST https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent`,
header `x-goog-api-key`. Body:

```json
{
  "systemInstruction": {"parts": [{"text": "<instruction text>"}]},
  "contents": [{"parts": [
    {"inline_data": {"mime_type": "image/jpeg", "data": "<b64>"}},
    {"text": "Describe this image."}
  ]}],
  "generationConfig": {"responseMimeType": "application/json", "responseSchema": { ...schema... }}
}
```

Response JSON is in `candidates[0].content.parts[0].text`. `finishReason`
`SAFETY` means refused; a missing `candidates` array with a `promptFeedback.blockReason`
also means refused. Inline requests are capped at 20 MB. Known models:
`gemini-3.8-flash` ($0.75 / $3.75), `gemini-3.5-flash-lite` ($0.30 / $2.50),
`gemini-2.5-flash` ($0.30 / $2.50), `gemini-2.5-flash-lite` ($0.10 / $0.40),
`gemini-2.5-pro` ($1.25 / $10).

Response handling in all three: transport errors, non-200 status, missing or
malformed JSON, and refusals return a `WP_Error` whose code is one of
`auth_error` (401, 403), `rate_limited` (429), `server_error` (5xx and transport),
`refused`, or `bad_response`. The indexer uses the code to decide whether to retry.

Shared prompt logic lives in `AIMS\Prompt`, so all three providers send the same
instructions and parse the same three fields. The JSON schema is the same object
for all three (Gemini accepts the same subset we use: object, string, array of
string, required, additionalProperties).

### `AIMS\Prompt`

Builds the instruction text and the JSON schema. The instruction asks for:

- `description`: two to four sentences covering subjects, people, actions,
  setting, colors, visible text, and style. Written in the configured language.
- `tags`: ten to twenty lowercase tags, single words or short phrases, including
  plain synonyms so that "woman", "female", and "lady" all match.
- `alt`: one sentence under 125 characters suitable as HTML alt text.

When the custom prompt setting is non-empty, it is appended under the heading
"Additional guidance from the site owner:".

`Prompt::parse( string $json )` strips code fences, decodes, validates that all
three fields are present and of the right type, trims, and returns a
`Description_Result` or `WP_Error( 'bad_response' )`.

### `AIMS\Image_Preparer`

Given an attachment ID, returns `[ path, mime ]` or `WP_Error`.

- Images: reads `wp_get_attachment_metadata()['sizes']`, picks the smallest
  registered size whose longest edge is at least 1000 px. If none qualifies, uses
  the original only if its longest edge is at most 1600 px; otherwise generates a
  temporary 1600 px copy with `wp_get_image_editor()`, used for the request and
  deleted afterwards. This keeps requests small and within provider image limits.
- PDFs: uses the preview image WP generated on upload (present in metadata under
  `sizes` when Imagick is available). If absent, returns
  `WP_Error( 'no_preview' )`, and the indexer marks the attachment `skipped`.
- Anything else: `WP_Error( 'unsupported' )` → `skipped`.

### `AIMS\Indexer`

`index_attachment( int $id ): true|WP_Error`

1. Acquire a transient lock `aims_lock_{id}` for 120 seconds; bail if held.
2. Set `_aims_status = pending`.
3. `Image_Preparer` → on `no_preview` or `unsupported`, set `skipped` and return.
4. Resolve the configured provider, call `describe()`.
5. On success write meta:
   - `_aims_description`, `_aims_tags` (array), `_aims_alt`
   - `_aims_search_text`: description, tags joined by spaces, and alt,
     concatenated into one lowercase string. This single row is what search joins
     on, so the join is one-to-one and needs no `DISTINCT`.
   - `_aims_status = indexed`, `_aims_indexed_at` (timestamp),
     `_aims_provider` (for example `claude:claude-opus-5`)
   - delete `_aims_error`
   - If the alt setting is on and `_wp_attachment_image_alt` is empty, write
     `_aims_alt` into it.
6. On error write `_aims_status = failed` and `_aims_error`, return the error.
7. Release the lock.

`rebuild_search_text( int $id )` recomputes `_aims_search_text` from the stored
fields. Called after a user edits the description.

### `AIMS\Queue`

- On `add_attachment`, if auto-index is enabled and the mime type is eligible,
  `wp_schedule_single_event( time() + 10, 'aims_index_attachment', [ $id ] )`.
- Cron handler calls `Indexer::index_attachment()`. If the result is a `WP_Error`
  with code `rate_limited` or `server_error` and no retry has happened yet
  (`_aims_retry_count < 1`), schedule one more event 5 minutes later.

### `AIMS\Search`

Hooks `posts_join` and `posts_search`, both guarded by: query post type is
`attachment` (string or single-element array), search string non-empty, and the
filter has not already been applied to this query. The media grid and the media
modal both go through `wp_ajax_query_attachments`, which builds a `WP_Query`, so one
implementation covers list view, grid view, and the editor.

- `posts_join` appends
  `LEFT JOIN {$wpdb->postmeta} aims_st ON ({$wpdb->posts}.ID = aims_st.post_id AND aims_st.meta_key = '_aims_search_text')`.
- `posts_search` finds each `({$wpdb->posts}.post_title LIKE '...')` clause WP
  generated and replaces it with
  `({$wpdb->posts}.post_title LIKE '...' OR aims_st.meta_value LIKE '...')`,
  using the same escaped term. WP's own AND-across-terms structure is kept, so a
  two-word query still requires both words.

`_aims_search_text` is stored lowercase; the default utf8mb4 collations compare
case-insensitively, so search terms need no extra handling.

### `AIMS\Attachment_Fields`

- `attachment_fields_to_edit`: adds "AI description" (textarea), "AI tags"
  (read-only text), "AI index" (status line with timestamp or error), and a
  Regenerate button carrying the attachment ID. Appears in the media modal sidebar
  and on the attachment edit page.
- `attachment_fields_to_save`: stores the edited description and calls
  `Indexer::rebuild_search_text()`.
- `manage_media_columns` / `manage_media_custom_column`: an "AI index" column
  showing status.
- `bulk_actions-upload` / `handle_bulk_actions-upload`: an "Index with AI" action
  that schedules a cron event per selected attachment and shows an admin notice
  with the count.

### `AIMS\REST`

All routes require a logged-in user and a valid REST nonce.

| Route | Method | Capability | Behaviour |
|---|---|---|---|
| `/aims/v1/index/{id}` | POST | `upload_files` and `edit_post` on the ID | Runs the indexer synchronously and returns the stored fields. |
| `/aims/v1/bulk` | POST | `manage_options` | Body: optional `ids` (int[]), `batch_size`, `retry_failed`. With `ids`, indexes up to `batch_size` of them and returns the rest as `remaining_ids`. Without, selects the next N attachment IDs whose status is missing (or `failed` when `retry_failed`), indexes each, returns per-ID results and the remaining count. |
| `/aims/v1/stats` | GET | `manage_options` | Returns the counts shown on the settings panel. |
| `/aims/v1/test` | POST | `manage_options` | Calls `test_connection()` on the saved provider. |

### Assets — `assets/admin.js`, `assets/admin.css`

- On attachment details views: delegate clicks on the Regenerate button, call the
  index route, update the sidebar fields in place, show inline errors.
- On the plugin page: tab switching, select-all, and the bulk loop. "Index
  selected" sends the checked IDs; "Index all not indexed" sends none and lets the
  server pick. Either way the loop calls the bulk route until remaining is zero or
  Stop is pressed, updating the progress bar, the log, and the affected table rows
  after each batch.

Plain ES2017, no build step, enqueued only on the relevant admin screens.

## Error handling summary

| Situation | Result |
|---|---|
| Unsupported mime or PDF without preview | status `skipped`, no API call |
| Missing API key | `WP_Error( 'auth_error' )`, status `failed`, settings page shows a notice |
| 401 / 403 from provider | `auth_error`, `failed`, no retry |
| 429 | `rate_limited`, `failed`, one cron retry after 5 minutes |
| 5xx or HTTP transport error | `server_error`, `failed`, one cron retry |
| Refusal or malformed JSON | `refused` / `bad_response`, `failed`, no retry |
| Attachment deleted | meta goes with it; a scheduled event for a missing ID exits silently |

## Cost guidance shown in settings

Estimates assume a 1500-token image and 300 output tokens. Shown next to each model
in the dropdown, rounded.

| Model | Per image | 10,000 images |
|---|---|---|
| claude-opus-5 | $0.015 | $150 |
| claude-sonnet-5 | $0.006 | $60 |
| claude-haiku-4-5 | $0.003 | $30 |

| gpt-6-astra | $0.030 | $300 |
| gpt-5.6-sol | $0.012 | $120 |
| gpt-5.6-terra | $0.0066 | $66 |
| gpt-5.6-luna | $0.0007 | $7 |
| gemini-3.8-flash | $0.0022 | $22 |
| gemini-2.5-flash | $0.0012 | $12 |
| gemini-2.5-flash-lite | $0.0003 | $3 |

Prices verified 2026-09-10 against each provider's pricing page.

## Security and wordpress.org compliance

- All output escaped, all input sanitized, capabilities checked on every route and
  form handler, nonces on every form and REST call.
- Direct file access blocked with `ABSPATH` checks.
- No external calls without the disclosure notice in settings and `readme.txt`.
- All strings translatable under the `ai-media-search` text domain.
- GPL-2.0-or-later license header.

## Testing

- **Unit tests** with PHPUnit and Brain Monkey (no WordPress install needed):
  - `Prompt::parse()` against good, fenced, partial, and malformed JSON fixtures.
  - Each provider's request body builder and response parser against recorded
    fixture responses, including 429, 401, refusal, and malformed cases.
  - `Search` SQL rewriting: single term, multi-term, non-attachment queries left
    untouched, already-filtered queries not double-joined.
  - `Image_Preparer` size selection given synthetic metadata arrays.
- **Static checks**: PHPCS with the WordPress Coding Standards ruleset, and the
  official Plugin Check plugin before release.
- **Manual integration** through wp-env when Docker is available: upload an
  image, confirm the cron event fires, search for a word from the description,
  regenerate from the modal, run the bulk indexer on a small library.
