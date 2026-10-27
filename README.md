# daily.dev reader

The app uses a daily.dev Personal Access Token (PAT) to call the Public API. OAuth and Symfony TUI have been removed for now; commands use the regular Symfony Console so Ctrl+C can interrupt them normally.

## Authentication

Create a Personal Access Token in [daily.dev API settings](https://daily.dev/settings/api), then put it in `.env.local`:

```dotenv
DD_TOKEN=your-personal-access-token
```

Keep the token private and do not commit `.env.local`. The token is sent as a bearer token directly to the daily.dev Public API; there is no OAuth login, refresh, or local token-file flow.

## Reading and bookmarking

```sh
php bin/console app:daily-dev:feed
```

The command prints the For You feed and exits. Use the options shown below to fetch fewer posts, display a post, bookmark it, or remove its bookmark:

```sh
php bin/console app:daily-dev:feed --limit=10
php bin/console app:daily-dev:feed --post=POST_ID
php bin/console app:daily-dev:feed --bookmark=POST_ID
php bin/console app:daily-dev:feed --unbookmark=POST_ID
```

## Public API client

`DailyDevApiClient` exposes `forYou()`, `popular()`, `discussed()`, `byTag()`, `bySource()`, `post()`, `bookmarks()`, `bookmark()` and `unbookmark()`. Feed and bookmark list methods return the API response, including its pagination cursor.

The Public API specification does not include an endpoint for reposting to squads. Post detail also does not return full article text for regular articles; it provides post metadata and a URL.
