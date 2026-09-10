# AI Media Search

A WordPress plugin that searches your Media Library by what is in the picture. A vision model describes each image so the search box finds "woman on a beach" even when nobody typed it.

## Requirements

* WordPress 6.0+
* PHP 7.4+
* An API key from Anthropic, OpenAI, or Google

## Install

1. Clone or download this repository into `wp-content/plugins/ai-media-search`.
2. Activate the plugin from the Plugins screen.
3. Open **AI Media Search** in the admin menu and add an API key on the Settings tab.
4. Run **Index all not indexed** on the Dashboard to describe your existing library.

## Development

```
composer install
composer test
composer lint
```

## License

GPL-2.0-or-later
