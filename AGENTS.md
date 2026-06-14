FbAdsDashboard quick map

Rules
- Increment the version on every code edit.
- Everything in the dashboard UI must stay in English.
- Prefer small, targeted changes over broad rewrites.
- Make changes locally first, then commit, push, and deploy to the VPS from `main`.

Read first
- `campaign_builder.php` - Campaign Builder page and front-end logic.
- `api/campaign_builder.php` - Campaign Builder API and task creation.
- `domains.php` and `api/domains.php` - Domains & FP configs and delivery IDs.
- `api/ext/tasks.php` - external worker task contract.
- `api/sync_keitaro.php` - Keitaro import and sync logging.
- `index.php` - main dashboard tables and summary views.

Do not deep-read unless needed
- `.backups/`
- `uploads/`
- generated exports or temporary screenshots

Current focus
- Builder config flow comes from Domains & FP.
- Pixel handling supports auto or manual mode.
- `URL Params` should stay readable in the builder and resolve before enqueue.
- Geo summary must stay fast and read-only.

Recent notes
- Added `page_id` and `pixel_id` to Domains & FP configs.
- Added API sync logs view.
- Added responsive table behavior and geo parsing cleanup.
- Moved campaign geo extraction to `campaign_geo(name)`.

Task log
- Done: local start/stop launchers and shortcut files.
- Done: builder config selection and delivery IDs.
- Done: pixel mode auto/manual with resolved URL params.
- Done: `sub_id_3` default changed to `14886`.
- Next: verify geo summary no longer hangs, then clean any dead notes if they become obsolete.
