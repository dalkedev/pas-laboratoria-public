# PAS labs

PHP and MySQL exercises with a Bootstrap interface. By default, there is one database and one login session per task. Hosted installations can retain the shared z1–z5 database; see [database configuration](docs/DATABASES.md).

| Task | Application |
| --- | --- |
| [z1](public/z1/) | Login, registration and sessions |
| [z2](public/z2/) | Bootstrap and profile avatars |
| [z3](public/z3/) | Visitor information and geolocation |
| [z4](public/z4/) | Service monitoring |
| [z5](public/z5/) | MyCloud |
| [z6a](public/z6a/) | MySpotify |
| [z6b](public/z6b/) | MyNetflix |

## Run locally

Start Docker Desktop, then run:

```sh
docker compose up -d --build --wait
```

Open [the task list](http://127.0.0.1:8081/). Docker creates seven MySQL databases. For an existing volume or hosted setup, see [database setup](docs/DATABASES.md).

## MySpotify and MyNetflix

Use [MySpotify](http://127.0.0.1:8081/z6a/) for MP3 files and [MyNetflix](http://127.0.0.1:8081/z6b/) for MP4 files. Create an account in each app you want to use, upload a file, choose its genre, and add it to a playlist. Sign in with a second account to see public playlists and add their tracks to your own.

A playlist starts with a click and plays the remaining items in order. The apps share the PHP handlers, Bootstrap layout, and playback code. The task configuration selects the database, tables, media type, and storage folder. Uploads are stored outside the document root and streamed through the application.

| Application | Media table | Genre table | Upload folder |
| --- | --- | --- | --- |
| MySpotify | `song` | `musictype` | `storage/songs` |
| MyNetflix | `film` | `filmtype` | `storage/films` |
