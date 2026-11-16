# Simple CSV Redirects

WordPress plugin for 301 redirects. I wrote it after moving a client site to a new domain, where I had a long list of old and new URLs and had to set up the redirects one by one.

You upload a CSV with two columns (old URL, new URL), or add a single redirect by hand. The list is in Tools > CSV Redirects, where you can also delete entries.

Only the path of the old URL is compared, without the domain, query string and trailing slash, and case doesn't matter. So `https://site.pl/O-Nas/?x=1` and `/o-nas` are the same redirect. CSV can use a comma or a semicolon (Excel in Polish uses `;`). The header row and broken rows are skipped. See `example.csv`.

Redirects are stored in a separate table, `wp_scr_redirects`, created on activation and removed on uninstall.

## Styles

Admin styles are written in SCSS and built with Gulp:

```
npm install
npm run build   # assets/scss -> assets/css
npm run watch   # rebuild on save
```
