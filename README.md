# Simple CSV Redirects

WordPress plugin for 301 redirects. I wrote it after moving a client site to a new domain, where I had a long list of old and new URLs and had to set up the redirects one by one.

You upload a CSV with two columns (old URL, new URL), or add a single redirect by hand. Everything is in Tools > CSV Redirects.

What it does:

- imports redirects from CSV, with a comma or a semicolon (Excel in Polish uses `;`); the header row and broken rows are skipped, and a path that already exists gets the new address
- exports all redirects back to CSV, so the list can be edited in Excel and imported again
- counts how many times each redirect was used and when it was used last, so it's easy to see which ones are not needed any more
- search, pagination and deleting several redirects at once
- English and Polish interface

Only the path of the old URL is compared, without the domain, query string and trailing slash, and case doesn't matter. So `https://site.pl/O-Nas/?x=1` and `/o-nas` are the same redirect. A redirect to the same page is not saved, because it would loop. See `example.csv`.

Redirects are stored in a separate table, `wp_scr_redirects`. It's created on activation, updated when a new version of the plugin adds columns, and removed on uninstall.

## Development

Styles are written in SCSS and the admin script in plain JavaScript. Gulp builds them from `assets/src` into `assets/dist`:

```
npm install
npm run build   # compile SCSS and minify JS
npm run watch   # rebuild on save
```

Translations are in `languages`. The `.pot` file is the template for other languages.
