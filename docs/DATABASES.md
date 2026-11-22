# Databases

By default, the portal selects the database from the task in the PHP route. Each task has its own users, login throttling and session cookie. Register in each task you want to use. Hosted installations can retain the existing shared database for z1–z5 as described below.

| Route | Local database | Schema |
| --- | --- | --- |
| /z1/ | z1 | schema.sql |
| /z2/ | z2 | schema.sql |
| /z3/ | z3 | schema.sql |
| /z4/ | z4 | schema.sql + z4.sql |
| /z5/ | z5 | schema.sql |
| /z6a/ | z6a | schema.sql + z6a.sql |
| /z6b/ | z6b | schema.sql + z6b.sql |

Docker creates these databases and grants each local MySQL account access to its own database. For an existing local volume, run this after starting the database:

```sh
docker compose up -d --wait db
docker compose exec -T db sh /database/init-local.sh
docker compose up -d --build --wait
```

Existing tables and accounts in the old shared database stay there. The local z5 installation uses `storage/z5` for its new account database. Keep the old data until you have verified any migration.

For a hosted installation, import the schemas in the table and keep credentials in `app/config.local.php`, outside the document root. Use `DB_NAME`, `DB_USER`, and `DB_PASSWORD` for z1. For each remaining task, prefix the field with its task name, for example `DB_Z6A_NAME`, `DB_Z6A_USER`, and `DB_Z6A_PASSWORD`. `DB_HOST` and `DB_PORT` set the defaults and can be overridden for each task with the same prefix.

Keep upload directories under private `storage/`. If you carry existing users and files forward, preserve the user IDs and migrate each task's data before switching traffic. Start a new storage directory for z5 if you're starting with a fresh user database.

## Existing shared hosting database

Set `APP_SHARE_LEGACY_DB` to `1` to keep z1–z5 on the existing database configured by `DB_NAME`, `DB_USER` and `DB_PASSWORD`. Their sessions use the legacy `PAS_SESSION` cookie with path `/`. Valid sessions issued by the previous application remain accepted even without a task marker, and the older login scripts remain compatible. The normal inactivity timeout still applies. Leave this option unset for seven isolated databases, as in the default local setup.

MySpotify and MyNetflix remain separate in both modes: they use `DB_Z6A_*` and `DB_Z6B_*`, task-scoped cookies, and task-bound sessions. A legacy session cannot authenticate either media app.

For hosting, an optional private `app/media.local.php` can supplement the existing `app/config.local.php` without rewriting it:

```php
<?php
return [
    'APP_SHARE_LEGACY_DB' => '1',
    'DB_Z6A_NAME' => 'spotify_database',
    'DB_Z6A_USER' => 'spotify_user',
    'DB_Z6A_PASSWORD' => 'set-on-server',
    'DB_Z6B_NAME' => 'netflix_database',
    'DB_Z6B_USER' => 'netflix_user',
    'DB_Z6B_PASSWORD' => 'set-on-server',
];
```

Only `APP_SHARE_LEGACY_DB` and the `HOST`, `PORT`, `NAME`, `USER`, `PASSWORD` fields under `DB_Z6A_*` and `DB_Z6B_*` are read from this supplemental file. These values override matching file configuration, while environment variables retain precedence. Other supplemental keys are ignored. Keep this file private on the server; Git ignores it, release creation excludes it, and deployment rejects an archive containing it.
