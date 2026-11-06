# Releasing

This plugin lives in **one repo**: GitHub. Pushing a version tag auto-deploys to
WordPress.org SVN via `.github/workflows/deploy-wordpress-org.yml`
(using `10up/action-wordpress-plugin-deploy`). You never touch SVN by hand.

## Release checklist

1. **Bump the version** in all three places (they must match exactly):
   - `swish-migrate-and-backup.php` → `* Version:` header
   - `swish-migrate-and-backup.php` → `define('SWISH_BACKUP_VERSION', '...')`
   - `readme.txt` → `Stable tag:`
2. Update `readme.txt` `== Changelog ==` and `CHANGELOG.md`.
3. Commit and push to `main`.
4. Tag and push the tag:
   ```bash
   git tag v1.5.0      # use the new version, with a leading "v"
   git push origin v1.5.0
   ```
5. The workflow runs automatically:
   - It **fails fast** if the tag version, plugin header, and readme `Stable tag`
     don't all match.
   - On success it copies the code (respecting `.distignore`) to SVN `trunk` and
     `tags/1.5.0` (the leading `v` is stripped), and publishes the ZIP as a run
     artifact. WordPress.org serves the tag matching `Stable tag`.

Watch it: `gh run watch` or https://github.com/denischweya/swish-migrate-and-backup/actions

## WordPress.org assets (banner / icon / screenshots)

These live in `.wordpress-org/` in this repo and deploy with every tag. To change
the banner, icon, or a screenshot, edit the file in `.wordpress-org/` and ship it
in the next release. `.wordpress-org/` is excluded from the plugin itself via
`.distignore`.

## Notes

- Tags must start with a digit after the `v` (trigger is `v[0-9]*`), e.g. `v1.5.0`.
- SVN credentials are stored as the GitHub repository secrets `SVN_USERNAME` and
  `SVN_PASSWORD` — the deploy uses those; nothing is needed locally.
