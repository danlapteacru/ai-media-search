     1	# AI Media Search — Design
     2	
     3	Date: 2026-09-10
     4	Status: approved in discussion, pending written review
     5	
     6	## Problem
     7	
     8	The WordPress Media Library search box only matches an attachment's title, caption,
     9	and description. Typing "woman" returns nothing unless someone typed that word into a
    10	field. Nothing on wordpress.org fixes this: alt-text generators never touch search,
    11	"Media Search Enhanced" only widens the text fields searched, and "Media Library
    12	Organizer" auto-categorizes but leaves the search box alone.
    13	
    14	## Goal
    15	
    16	A wordpress.org plugin that makes the Media Library search work like Photos on a
    17	phone: the site owner types "woman on a beach" and gets images whose pixels match,
    18	without ever having written that text.
    19	
    20	## Decisions taken
    21	
    22	| Decision | Choice |
    23	|---|---|
    24	| Matching approach | Vision-model descriptions and tags in post meta, matched by keyword. No embeddings in v1. |
    25	| Providers | Anthropic Claude, OpenAI, Google Gemini. Site owner picks one. |
    26	| Model | Per-provider dropdown in settings with cost hints and a "custom ID" entry. Site owner chooses. |
    27	| Audience | Public wordpress.org release. Follow plugin review guidelines. |
    28	| Indexing trigger | Automatic on upload via WP-Cron, plus a browser-driven bulk indexer for the existing library. |
    29	| Alt text | Optional, off by default: fill an empty alt field with the model's alt sentence. |
    30	| Visibility | Editable "AI description" field on the attachment details sidebar and edit page, with Regenerate. |
    31	| File types | Images (jpeg, png, gif, webp) and PDFs via the first-page preview WP generates. |
    32	| Admin UI | Own top-level menu page "AI Media Search" with two tabs, Dashboard and Settings, modeled on the "AI Alt-Text Generator" plugin's layout (stat cards as filters, paginated asset table, select rows, run with progress bar). |
| Custom prompt | Optional textarea in settings. Its text is appended to the built-in prompt as extra guidance. |
| Storage | Post meta plus `posts_join` / `posts_search` filters. No custom tables. |
    33	| Background work | WP-Cron single events for uploads; REST-driven batches for bulk. No Action Scheduler. |
    34	| HTTP | WordPress HTTP API (`wp_remote_post`) for all providers. No vendored SDKs. |
    35	
    36	## Out of scope for v1
    37	
    38	Video and audio, frontend search, embeddings or vector search, folders or
    39	categories, API key encryption, multisite network-level settings (per-site settings
    40	work as normal), and any provider beyond the three above.
    41	
    42	## Naming
    43	
    44	- Slug and text domain: `ai-media-search` (checked free on wordpress.org on 2026-09-10)
    45	- Function and hook prefix: `aims_`
    46	- PHP namespace: `AIMS`
    47	- Option name: `aims_settings` (single array)
    48	- Meta key prefix: `_aims_`
    49	- REST namespace: `aims/v1`
    50	- Requirements: PHP 7.4+, WordPress 6.0+
    51	
    52	## Architecture
    53	
    54	```
    55	upload ──► add_attachment ──► Queue (schedule cron +10s)
    56	                                   │
    57	                            cron: aims_index_attachment
    58	                                   │
    59	                                   ▼
    60	   Regenerate button ──► REST ──► Indexer ──► Image_Preparer ──► file path + mime
    61	   Dashboard buttons ──► REST ──►    │
    62	                                     ├──► Provider::describe() ──► Description_Result
    63	                                     │
    64	                                     └──► post meta (_aims_*), optional alt text
    65	
    66	search box "woman" ──► WP_Query(post_type=attachment, s=woman)
    67	                            │
    68	                            ▼
    69	                    Search filters: LEFT JOIN postmeta _aims_search_text,
    70	                    rewrite each title clause to also match that meta
    71	```
    72	
    73	## Components
    74	
    75	### Bootstrap — `ai-media-search.php`, `uninstall.php`
    76	
    77	Plugin header, constants (version, path, URL), a small PSR-4 autoloader for the
    78	`AIMS\` namespace under `includes/`, and instantiation of `AIMS\Plugin` on
    79	`plugins_loaded`. Activation registers nothing heavy. Deactivation clears scheduled
    80	`aims_index_attachment` events. `uninstall.php` deletes `aims_settings` and every
    81	`_aims_*` meta row.
    82	
    83	### `AIMS\Plugin`
    84	
    85	Wires every other component's hooks. No logic of its own.
    86	
    87	### `AIMS\Settings` — option registration

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
   116	
   117	```php
   118	interface Provider_Interface {
   119	    /** @return Description_Result|\WP_Error */
   120	    public function describe( string $file_path, string $mime_type, string $language );
   121	    /** @return true|\WP_Error */
   122	    public function test_connection();
   123	    public static function get_id(): string;
   124	    public static function get_label(): string;
   125	    /** @return array<string, string> model id => human hint */
   126	    public static function get_known_models(): array;
   127	}
   128	```
   129	
   130	`Description_Result` is a small value object with `description` (string),
   131	`tags` (string[]), and `alt` (string).
   132	
   133	### `AIMS\Providers\Claude_Provider`, `OpenAI_Provider`, `Gemini_Provider`

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
   155	
   156	Builds the instruction text and the JSON schema. The instruction asks for:
   157	
   158	- `description`: two to four sentences covering subjects, people, actions,
   159	  setting, colors, visible text, and style. Written in the configured language.
   160	- `tags`: ten to twenty lowercase tags, single words or short phrases, including
   161	  plain synonyms so that "woman", "female", and "lady" all match.
   162	- `alt`: one sentence under 125 characters suitable as HTML alt text.
   163	
   164	When the custom prompt setting is non-empty, it is appended under the heading
"Additional guidance from the site owner:".

`Prompt::parse( string $json )` strips code fences, decodes, validates that all
   165	three fields are present and of the right type, trims, and returns a
   166	`Description_Result` or `WP_Error( 'bad_response' )`.
   167	
   168	### `AIMS\Image_Preparer`
   169	
   170	Given an attachment ID, returns `[ path, mime ]` or `WP_Error`.
   171	
   172	- Images: reads `wp_get_attachment_metadata()['sizes']`, picks the smallest
   173	  registered size whose longest edge is at least 1000 px. If none qualifies, uses
   174	  the original only if its longest edge is at most 1600 px; otherwise generates a
   175	  temporary 1600 px copy with `wp_get_image_editor()`, used for the request and
   176	  deleted afterwards. This keeps requests small and within provider image limits.
   177	- PDFs: uses the preview image WP generated on upload (present in metadata under
   178	  `sizes` when Imagick is available). If absent, returns
   179	  `WP_Error( 'no_preview' )`, and the indexer marks the attachment `skipped`.
   180	- Anything else: `WP_Error( 'unsupported' )` → `skipped`.
   181	
   182	### `AIMS\Indexer`
   183	
   184	`index_attachment( int $id ): true|WP_Error`
   185	
   186	1. Acquire a transient lock `aims_lock_{id}` for 120 seconds; bail if held.
   187	2. Set `_aims_status = pending`.
   188	3. `Image_Preparer` → on `no_preview` or `unsupported`, set `skipped` and return.
   189	4. Resolve the configured provider, call `describe()`.
   190	5. On success write meta:
   191	   - `_aims_description`, `_aims_tags` (array), `_aims_alt`
   192	   - `_aims_search_text`: description, tags joined by spaces, and alt,
   193	     concatenated into one lowercase string. This single row is what search joins
   194	     on, so the join is one-to-one and needs no `DISTINCT`.
   195	   - `_aims_status = indexed`, `_aims_indexed_at` (timestamp),
   196	     `_aims_provider` (for example `claude:claude-opus-5`)
   197	   - delete `_aims_error`
   198	   - If the alt setting is on and `_wp_attachment_image_alt` is empty, write
   199	     `_aims_alt` into it.
   200	6. On error write `_aims_status = failed` and `_aims_error`, return the error.
   201	7. Release the lock.
   202	
   203	`rebuild_search_text( int $id )` recomputes `_aims_search_text` from the stored
   204	fields. Called after a user edits the description.
   205	
   206	### `AIMS\Queue`
   207	
   208	- On `add_attachment`, if auto-index is enabled and the mime type is eligible,
   209	  `wp_schedule_single_event( time() + 10, 'aims_index_attachment', [ $id ] )`.
   210	- Cron handler calls `Indexer::index_attachment()`. If the result is a `WP_Error`
   211	  with code `rate_limited` or `server_error` and no retry has happened yet
   212	  (`_aims_retry_count < 1`), schedule one more event 5 minutes later.
   213	
   214	### `AIMS\Search`
   215	
   216	Hooks `posts_join` and `posts_search`, both guarded by: query post type is
   217	`attachment` (string or single-element array), search string non-empty, and the
   218	filter has not already been applied to this query. The media grid and the media
   219	modal both go through `wp_ajax_query_attachments`, which builds a `WP_Query`, so one
   220	implementation covers list view, grid view, and the editor.
   221	
   222	- `posts_join` appends
   223	  `LEFT JOIN {$wpdb->postmeta} aims_st ON ({$wpdb->posts}.ID = aims_st.post_id AND aims_st.meta_key = '_aims_search_text')`.
   224	- `posts_search` finds each `({$wpdb->posts}.post_title LIKE '...')` clause WP
   225	  generated and replaces it with
   226	  `({$wpdb->posts}.post_title LIKE '...' OR aims_st.meta_value LIKE '...')`,
   227	  using the same escaped term. WP's own AND-across-terms structure is kept, so a
   228	  two-word query still requires both words.
   229	
   230	Terms are lowercased before matching, and `_aims_search_text` is stored lowercase,
   231	so results do not depend on collation.
   232	
   233	### `AIMS\Attachment_Fields`
   234	
   235	- `attachment_fields_to_edit`: adds "AI description" (textarea), "AI tags"
   236	  (read-only text), "AI index" (status line with timestamp or error), and a
   237	  Regenerate button carrying the attachment ID. Appears in the media modal sidebar
   238	  and on the attachment edit page.
   239	- `attachment_fields_to_save`: stores the edited description and calls
   240	  `Indexer::rebuild_search_text()`.
   241	- `manage_media_columns` / `manage_media_custom_column`: an "AI index" column
   242	  showing status.
   243	- `bulk_actions-upload` / `handle_bulk_actions-upload`: an "Index with AI" action
   244	  that schedules a cron event per selected attachment and shows an admin notice
   245	  with the count.
   246	
   247	### `AIMS\REST`
   248	
   249	All routes require a logged-in user and a valid REST nonce.
   250	
   251	| Route | Method | Capability | Behaviour |
   252	|---|---|---|---|
   253	| `/aims/v1/index/{id}` | POST | `upload_files` and `edit_post` on the ID | Runs the indexer synchronously and returns the stored fields. |
   254	| `/aims/v1/bulk` | POST | `manage_options` | Body: optional `ids` (int[]), `batch_size`, `retry_failed`. With `ids`, indexes up to `batch_size` of them and returns the rest as `remaining_ids`. Without, selects the next N attachment IDs whose status is missing (or `failed` when `retry_failed`), indexes each, returns per-ID results and the remaining count. |
   255	| `/aims/v1/bulk/stats` | GET | `manage_options` | Returns the counts shown on the settings panel. |
   256	| `/aims/v1/test` | POST | `manage_options` | Calls `test_connection()` on the saved provider. |
   257	
   258	### Assets — `assets/admin.js`, `assets/admin.css`
   259	
   260	- On attachment details views: delegate clicks on the Regenerate button, call the
   261	  index route, update the sidebar fields in place, show inline errors.
   262	- On the settings page: the bulk loop. Start fetches stats, then calls the bulk
   263	  route in a loop until remaining is zero or Stop is pressed. Progress bar and log
   264	  update after each batch.
   265	
   266	Plain ES2017, no build step, enqueued only on the relevant admin screens.
   267	
   268	## Error handling summary
   269	
   270	| Situation | Result |
   271	|---|---|
   272	| Unsupported mime or PDF without preview | status `skipped`, no API call |
   273	| Missing API key | `WP_Error( 'auth_error' )`, status `failed`, settings page shows a notice |
   274	| 401 / 403 from provider | `auth_error`, `failed`, no retry |
   275	| 429 | `rate_limited`, `failed`, one cron retry after 5 minutes |
   276	| 5xx or HTTP transport error | `server_error`, `failed`, one cron retry |
   277	| Refusal or malformed JSON | `refused` / `bad_response`, `failed`, no retry |
   278	| Attachment deleted | meta goes with it; a scheduled event for a missing ID exits silently |
   279	
   280	## Cost guidance shown in settings
   281	
   282	Estimates assume a 1500-token image and 300 output tokens. Shown next to each model
   283	in the dropdown, rounded.
   284	
   285	| Model | Per image | 10,000 images |
   286	|---|---|---|
   287	| claude-opus-5 | $0.015 | $150 |
   288	| claude-sonnet-5 | $0.006 | $60 |
   289	| claude-haiku-4-5 | $0.003 | $30 |
   290	
   291	OpenAI and Gemini hints are filled in during implementation from current pricing
   292	pages.
   293	
   294	## Security and wordpress.org compliance
   295	
   296	- All output escaped, all input sanitized, capabilities checked on every route and
   297	  form handler, nonces on every form and REST call.
   298	- Direct file access blocked with `ABSPATH` checks.
   299	- No external calls without the disclosure notice in settings and `readme.txt`.
   300	- All strings translatable under the `ai-media-search` text domain.
   301	- GPL-2.0-or-later license header.
   302	
   303	## Testing
   304	
   305	- **Unit tests** with PHPUnit and Brain Monkey (no WordPress install needed):
   306	  - `Prompt::parse()` against good, fenced, partial, and malformed JSON fixtures.
   307	  - Each provider's request body builder and response parser against recorded
   308	    fixture responses, including 429, 401, refusal, and malformed cases.
   309	  - `Search` SQL rewriting: single term, multi-term, non-attachment queries left
   310	    untouched, already-filtered queries not double-joined.
   311	  - `Image_Preparer` size selection given synthetic metadata arrays.
   312	- **Static checks**: PHPCS with the WordPress Coding Standards ruleset, and the
   313	  official Plugin Check plugin before release.
   314	- **Manual integration** through wp-env when Docker is available: upload an
   315	  image, confirm the cron event fires, search for a word from the description,
   316	  regenerate from the modal, run the bulk indexer on a small library.
