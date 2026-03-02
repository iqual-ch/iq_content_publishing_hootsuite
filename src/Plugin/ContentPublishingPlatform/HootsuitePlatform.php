<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Plugin\ContentPublishingPlatform;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\iq_content_publishing_hootsuite\Service\HootsuiteApiClient;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hootsuite publishing platform plugin.
 *
 * Publishes AI-generated content to social media platforms
 * through the Hootsuite scheduling API.
 */
#[ContentPublishingPlatform(
  id: 'hootsuite',
  label: new TranslatableMarkup('Hootsuite'),
  description: new TranslatableMarkup('Publish content to social media platforms via Hootsuite.'),
)]
final class HootsuitePlatform extends ContentPublishingPlatformBase {

  /**
   * The Hootsuite API client.
   */
  protected HootsuiteApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('iq_content_publishing_hootsuite.api_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'text' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Post text'),
        'description' => (string) $this->t('The main content of the social media post. Keep under 280 characters for best compatibility.'),
        'required' => TRUE,
        'max_length' => 280,
        'ai_generated' => TRUE,
      ],
      'image' => [
        'type' => 'image',
        'label' => (string) $this->t('Image'),
        'description' => (string) $this->t('Image to attach to the Hootsuite post.'),
        'required' => FALSE,
        'max' => 1,
        'ai_generated' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructions(): string {
    return <<<'INSTRUCTIONS'
Transform the following Drupal content into a compelling social media post.

Guidelines:
- Keep the text concise and engaging (under 280 characters for Twitter/X compatibility).
- Use an attention-grabbing opening.
- Include a clear call-to-action.
- Use relevant hashtags (2-3 maximum).
- Include the content URL at the end of the text.
- Maintain the brand voice: professional yet approachable.
- Do NOT include any markdown formatting.

Available tokens:
- [node:title] — The content title.
- [node:url] — The full URL to the content.
- [node:summary] — The content summary.
- [node:content_type] — The content type label.
INSTRUCTIONS;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCredentialsForm(array $form, array $credentials): array {
    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Client ID'),
      '#description' => $this->t('Your Hootsuite application Client ID from the <a href="https://hootsuite.com/developers/my-apps" target="_blank">Hootsuite Developer Portal</a>.'),
      '#default_value' => $credentials['client_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Client Secret'),
      '#description' => $this->t('Your Hootsuite application Client Secret.'),
      '#default_value' => $credentials['client_secret'] ?? '',
      '#required' => TRUE,
    ];

    // Show connection status.
    if (!empty($credentials['access_token'])) {
      $tokenExpires = $credentials['token_expires'] ?? 0;
      $isExpired = $tokenExpires < time();

      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => $isExpired
          ? '<span style="color: orange;">⚠ Token expired — will auto-refresh on next publish.</span>'
          : '<span style="color: green;">✓ Connected to Hootsuite</span>',
      ];
    }

    // Hidden fields to preserve token values across form submissions.
    $form['access_token'] = [
      '#type' => 'hidden',
      '#default_value' => $credentials['access_token'] ?? '',
    ];
    $form['refresh_token'] = [
      '#type' => 'hidden',
      '#default_value' => $credentials['refresh_token'] ?? '',
    ];
    $form['token_expires'] = [
      '#type' => 'hidden',
      '#default_value' => $credentials['token_expires'] ?? 0,
    ];

    // OAuth connect/disconnect links (only work after entity is saved).
    $form['oauth_actions'] = [
      '#type' => 'item',
      '#title' => $this->t('Hootsuite Authorization'),
    ];

    if (!empty($credentials['access_token'])) {
      $form['oauth_actions']['#markup'] = $this->t('You are connected to Hootsuite. Save the platform configuration first, then use the operations dropdown on the platform list to disconnect or refresh the connection.');
    }
    else {
      $form['oauth_actions']['#markup'] = $this->t('Save this platform configuration first with your Client ID and Secret, then use the <strong>Connect to Hootsuite</strong> link from the platform list to authorize.');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings): array {
    $form['social_profile_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Social Profile IDs'),
      '#description' => $this->t('Enter one Hootsuite social profile ID per line. You can find these by connecting your Hootsuite account and using the "Fetch Profiles" action from the platform list.'),
      '#default_value' => is_array($settings['social_profile_ids'] ?? '') ? implode("\n", $settings['social_profile_ids']) : ($settings['social_profile_ids'] ?? ''),
      '#rows' => 4,
    ];

    $form['send_now'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish as soon as possible'),
      '#description' => $this->t('When checked, messages are scheduled 5 minutes from now (Hootsuite minimum). Otherwise, the configured delay is used.'),
      '#default_value' => $settings['send_now'] ?? TRUE,
    ];

    $form['scheduled_delay_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Scheduled delay (minutes)'),
      '#description' => $this->t('How many minutes from now to schedule the post. Minimum is 5 (Hootsuite requirement).'),
      '#default_value' => $settings['scheduled_delay_minutes'] ?? 5,
      '#min' => 5,
      '#max' => 43200,
      '#states' => [
        'visible' => [
          ':input[name="plugin_settings[send_now]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
      return FALSE;
    }

    // If we have an access token, validate it.
    if (!empty($credentials['access_token'])) {
      return $this->apiClient->validateConnection($credentials['access_token']);
    }

    // Without an access token, we just check that client ID/secret are present.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings): PublishingResult {
    // Get a valid access token (handles refresh if needed).
    $accessToken = $this->apiClient->getValidAccessToken($credentials);
    if (!$accessToken) {
      return PublishingResult::failure(
        'No valid Hootsuite access token available. Please re-authorize the connection.',
        ['error' => 'token_unavailable']
      );
    }

    // Determine social profiles to publish to.
    $socialProfileIds = $settings['social_profile_ids'] ?? [];
    if (empty($socialProfileIds)) {
      return PublishingResult::failure(
        'No social profile IDs configured. Please configure at least one Hootsuite social profile.',
        ['error' => 'no_profiles']
      );
    }

    // Filter out empty lines.
    $socialProfileIds = array_filter(array_map('trim', $socialProfileIds));
    if (empty($socialProfileIds)) {
      return PublishingResult::failure(
        'No valid social profile IDs configured.',
        ['error' => 'no_valid_profiles']
      );
    }

    // Extract text content from structured fields.
    $text = $fields['text'] ?? '';
    if (empty($text)) {
      return PublishingResult::failure(
        'No post text provided.',
        ['error' => 'empty_text']
      );
    }

    // Extract image URLs for media attachments.
    $mediaUrls = [];
    $images = $fields['image'] ?? [];
    if (is_array($images)) {
      foreach ($images as $imageData) {
        if (is_array($imageData) && !empty($imageData['url'])) {
          $mediaUrls[] = $imageData['url'];
        }
      }
    }

    // Determine scheduling.
    $sendNow = $settings['send_now'] ?? TRUE;
    $delayMinutes = (int) ($settings['scheduled_delay_minutes'] ?? 5);

    // Schedule the message via the Hootsuite API.
    $result = $this->apiClient->scheduleMessage(
      $accessToken,
      $text,
      array_values($socialProfileIds),
      $sendNow,
      $delayMinutes,
      $mediaUrls,
    );

    if ($result['success']) {
      $messageIds = $result['message_ids'] ?? [];
      $scheduledTime = $result['scheduled_time'] ?? 'unknown';
      $profileCount = count($socialProfileIds);

      return PublishingResult::success(
        "Successfully scheduled to {$profileCount} social profile(s) via Hootsuite. Scheduled for: {$scheduledTime}",
        [
          'message_ids' => $messageIds,
          'scheduled_time' => $scheduledTime,
          'social_profiles' => $socialProfileIds,
          'api_response' => $result['data'] ?? [],
        ]
      );
    }

    return PublishingResult::failure(
      'Failed to schedule Hootsuite message: ' . ($result['error'] ?? 'Unknown error'),
      [
        'error' => $result['error'] ?? '',
        'response_body' => $result['response_body'] ?? '',
      ]
    );
  }

}
