# MDB Ad Management

WordPress plugin for uploading image ads and showing them in posts.

- Ads are placed between the paragraphs of single posts automatically, or wherever you put the `[mdb_ad]` shortcode.
- Each ad can be scheduled with start and end dates.
- Impressions, clicks and click-through rate are tracked per ad.
- An ad links to a website, starts a phone call, or has no link.
- The admin list has search, filters, bulk actions, and edit and delete. Deleting an ad also deletes its image.

Requires WordPress 5.8+ and PHP 7.0+.

## Install

Download the zip from the [latest release](https://github.com/shobhithn/mdb_ad_management/releases/latest), then in WordPress go to **Plugins → Add New Plugin → Upload Plugin**.

Updates then appear on the Plugins page like any other plugin.

## Shortcode

| Shortcode | Shows |
|---|---|
| `[mdb_ad]` | one random active ad |
| `[mdb_ad count="3"]` | three random active ads |
| `[mdb_ad id="5"]` | ad 5, if it is active |

## Publishing an update

Sites running the plugin check this repo's latest release about twice a day.

1. Raise the version in `mdb_ad_management.php`, in **both** places:
   ```php
    * Version:           2.0.1
   define( 'MDB_ADS_VERSION', '2.0.1' );
   ```
2. Commit and push.
3. On GitHub, open **Releases → Draft a new release**:
   - **Tag:** `v2.0.1` (create it on publish). It must be higher than the version sites have installed.
   - **Title:** `2.0.1`
   - **Notes:** what changed. These show in WordPress under "View details".
   - Publish it. Don't tick "pre-release", because sites ignore pre-releases.

That's all: sites show **Update now** for the new version. To see it straight away on a site, go to **Dashboard → Updates → Check again**.

Attaching a zip to the release is optional. Without one, WordPress installs GitHub's source zip and the plugin renames the folder correctly.
