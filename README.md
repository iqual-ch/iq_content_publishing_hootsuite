# Content Publishing: Hootsuite

Provides [Hootsuite](https://hootsuite.com) integration for the
**Content Publishing** framework. It enables publishing Drupal content to social
media platforms (Twitter/X, Facebook, Instagram, LinkedIn, etc.) via the
Hootsuite scheduling API.

## Requirements

- Drupal **11**
- [Content Publishing (`iq_content_publishing`)](https://github.com/iqual-ch/iq_content_publishing) module
- [Hootsuite API (`iq_hootsuite_api`)](https://github.com/iqual-ch/iq_hootsuite_api) module
- A **Hootsuite Developer** account with an application
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

Hootsuite API is managed by the iqual/iq_hootsuite_api dependency
- **Manages OAuth2 tokens** — handles the full three-legged OAuth2
   authorization-code flow, including automatic token refresh on expiry.
- **Schedules or publishes posts** — sends messages to one or more Hootsuite
   social profiles, with optional image attachments and configurable scheduling
   delay.

## Permissions

This module reuses the __"Administer content publishing"__ permission provided
by `iq_content_publishing`. All administrative routes require this permission.