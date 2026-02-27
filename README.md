# Content Publishing: Hootsuite

Provides [Hootsuite](https://hootsuite.com) integration for the
**Content Publishing** framework. It enables publishing Drupal content to social
media platforms (Twitter/X, Facebook, Instagram, LinkedIn, etc.) via the
Hootsuite scheduling API.

## Requirements

- Drupal **11**
- [Content Publishing (`iq_content_publishing`)](https://github.com/iqual-ch/iq_content_publishing) module
- A **Hootsuite Developer** account with an OAuth2 application
  (<https://hootsuite.com/developers/my-apps>)

## Installation

Install as you would normally install a contributed Drupal module. For further
information see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

```bash
composer require iqual/iq_content_publishing_hootsuite
drush en iq_content_publishing_hootsuite
```
## Configuration

1. Navigate to **Administration » Configuration » Content Publishing » Platforms**
   (`/admin/config/content-publishing/platforms`).
2. Add a new platform and select **Hootsuite** as the plugin.
3. Enter your **OAuth2 Client ID** and **Client Secret** obtained from the
   Hootsuite Developer Portal, then save the configuration.
4. From the platform list, click **Connect to Hootsuite** in the operations
   dropdown. You will be redirected to Hootsuite to authorize the application.
5. After authorization, click **Fetch Profiles** to retrieve your available
   social media profiles.
6. In the platform settings, enter the **Social Profile IDs** you want to
   publish to (one per line).
7. Optionally configure whether posts are sent immediately or scheduled with a
   delay.

## How It Works

The module registers a `hootsuite` content publishing platform plugin that:

- **Generates social media copy** — uses the Content Publishing AI pipeline
  with built-in prompt instructions optimised for short-form social posts
  (≤ 280 characters, hashtags, CTA, etc.).
- **Manages OAuth2 tokens** — handles the full three-legged OAuth2
  authorization-code flow, including automatic token refresh on expiry.
- **Schedules or publishes posts** — sends messages to one or more Hootsuite
  social profiles, with optional image attachments and configurable scheduling
  delay.

### Key Components

| Component | Description |
|---|---|
| `HootsuitePlatform` plugin | Content publishing platform plugin providing the credentials form, settings form, output schema, and publish logic. |
| `HootsuiteApiClient` service | Low-level Hootsuite REST API client handling OAuth2 token exchange/refresh, message scheduling, media upload, and profile retrieval. |
| `HootsuiteOAuthController` | Controller for the OAuth2 connect / callback / disconnect / fetch-profiles routes. |

### Routes

| Path | Description |
|---|---|
| `/admin/config/content-publishing/hootsuite/oauth/connect/{platform_config}` | Initiates the OAuth2 authorization flow. |
| `/admin/config/content-publishing/hootsuite/oauth/callback` | OAuth2 callback that exchanges the authorization code for tokens. |
| `/admin/config/content-publishing/hootsuite/oauth/disconnect/{platform_config}` | Clears stored OAuth tokens. |
| `/admin/config/content-publishing/hootsuite/fetch-profiles/{platform_config}` | Fetches available social profiles from Hootsuite. |

## Permissions

This module reuses the **"Administer content publishing"** permission provided
by `iq_content_publishing`. All administrative routes require this permission.

## Troubleshooting

- Check the Drupal log (`/admin/reports/dblog`) filtered by the
  `iq_content_publishing_hootsuite` channel for API errors.
- If the token has expired, the module will attempt to auto-refresh it on the
  next publish. If refresh fails, re-authorize via **Connect to Hootsuite**.
- Ensure the **Redirect URI** configured in your Hootsuite Developer app
  matches the site's callback URL exactly
  (`https://your-site.com/admin/config/content-publishing/hootsuite/oauth/callback`).