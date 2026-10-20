# daily.dev TUI

The daily.dev OAuth client is used by the TUI through Symfony services; it does not add web routes or a web login page.

## Authentication

Create an OAuth app in daily.dev and set `DAILY_DEV_CLIENT_ID`, `DAILY_DEV_CLIENT_SECRET`, and `DAILY_DEV_REDIRECT_URI` in `.env.local`. Register the exact redirect URI with the OAuth app. The default URI is `http://127.0.0.1:8080/auth/callback`. The default scopes include `read` and `write`; bookmark changes require `write`.

Run `php bin/console app:daily-dev:login`, open the printed URL, authorize the app, then paste the full redirect URL shown in the browser. The command validates the OAuth `state`, exchanges the code using PKCE, and stores the token set with file permissions restricted to the current user. The file is not encrypted. Tokens are stored at `~/.config/dailydev/tokens.json` by default; set `DAILY_DEV_TOKEN_FILE` to use another location. Run `php bin/console app:daily-dev:logout` to remove the saved tokens.

The OAuth flow uses no database or Symfony Security authenticator. Refresh-token handling is available through `DailyDevOAuthFlow::refreshStoredTokens()` and persists the complete rotated token set.

## Public API client

`DailyDevApiClient` exposes `forYou()`, `popular()`, `discussed()`, `byTag()`, `bySource()`, `post()`, `bookmarks()`, `bookmark()` and `unbookmark()`. Feed and bookmark list methods return the API response, including its pagination cursor, for the TUI to pass to the next page request. An expiring access token is refreshed before a call. After changing scopes, run `app:daily-dev:logout` and sign in again.

The Public API specification does not include an endpoint for reposting to squads. Post detail also does not return full article text for regular articles; it provides post metadata and a URL.
