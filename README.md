# daily.dev TUI

The daily.dev OAuth client and Public API are used by a terminal reader built with Symfony TUI. The Symfony TUI component is experimental.

## Authentication

Create an OAuth app at [daily.dev API settings](https://daily.dev/settings/api). Copy its client ID and secret into `.env.local` along with `DAILY_DEV_REDIRECT_URI=http://127.0.0.1:8080/auth/callback` and `DAILY_DEV_SCOPES="openid profile offline_access read write"`. Register the exact redirect URI with the OAuth app. The `read` and `write` scopes allow reading the feed and changing bookmarks; keep the client secret private.

Run `php bin/console app:daily-dev:login`, open the printed URL, authorize the app, then paste the full redirect URL shown in the browser. The command validates the OAuth `state`, exchanges the code using PKCE, and stores the token set with file permissions restricted to the current user. The file is not encrypted. Tokens are stored at `~/.config/dailydev/tokens.json` by default; set `DAILY_DEV_TOKEN_FILE` to use another location. Run `php bin/console app:daily-dev:logout` to remove the saved tokens.

The OAuth flow uses no database or Symfony Security authenticator. Refresh-token handling is available through `DailyDevOAuthFlow::refreshStoredTokens()` and persists the complete rotated token set.

## Terminal reader

Run `php bin/console app:daily-dev:tui` to sign in and open the For You feed. When OAuth is not configured, the TUI displays the setup steps and links to [daily.dev API settings](https://daily.dev/settings/api). After adding credentials to `.env.local`, restart the command; it opens the authorization URL in the default browser. Approve access and paste the full redirect URL into the TUI. If automatic browser opening is unavailable, the authorization URL remains visible for manual use. The TUI validates the callback and OAuth state before saving tokens. Use the arrow keys to browse, Enter to load the selected post details, `b` to bookmark, `u` to remove a bookmark, `n` for the next feed page, `r` to refresh, and `q`, Ctrl+C, or Ctrl+D to quit. At terminal widths of 100 columns or more, the reader uses a 1:3 feed-to-detail split; at narrower widths, the panes stack vertically.

## Public API client

`DailyDevApiClient` exposes `forYou()`, `popular()`, `discussed()`, `byTag()`, `bySource()`, `post()`, `bookmarks()`, `bookmark()` and `unbookmark()`. Feed and bookmark list methods return the API response, including its pagination cursor, for the TUI to pass to the next page request. An expiring access token is refreshed before a call. After changing scopes, run `app:daily-dev:logout` and sign in again.

The Public API specification does not include an endpoint for reposting to squads. Post detail also does not return full article text for regular articles; it provides post metadata and a URL.
