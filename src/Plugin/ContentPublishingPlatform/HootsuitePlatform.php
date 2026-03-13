<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Plugin\ContentPublishingPlatform;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hootsuite publishing platform plugin.
 *
 * Publishes AI-generated content to social media platforms
 * through the Hootsuite scheduling API. Each connected social profile
 * is exposed as a separate tool, allowing per-profile content generation
 * and publishing.
 *
 * OAuth2 authentication and token management are handled centrally
 * by the iq_hootsuite_api module.
 */
#[ContentPublishingPlatform(
  id: 'hootsuite',
  label: new TranslatableMarkup('Hootsuite'),
  description: new TranslatableMarkup('Publish content to social media platforms via Hootsuite.'),
)]
final class HootsuitePlatform extends ContentPublishingPlatformBase implements MultiToolPlatformInterface {

  /**
   * The Hootsuite API client (from iq_hootsuite_api module).
   */
  protected HootsuiteApiClientInterface $apiClient;

  /**
   * Cached available tools to avoid redundant API calls within a request.
   */
  protected array $availableToolsCache = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('iq_hootsuite_api.client');
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
  public function getAvailableTools(array $settings = []): array {
    $cacheKey = md5(serialize($settings));
    if (isset($this->availableToolsCache[$cacheKey])) {
      return $this->availableToolsCache[$cacheKey];
    }

    $tools = [];

    try {
      $result = $this->apiClient->getSocialProfiles();
      if ($result && !empty($result['data'])) {
        foreach ($result['data'] as $profile) {
          $id = $profile['id'] ?? '';
          if (empty($id)) {
            continue;
          }
          $type = $profile['type'] ?? 'UNKNOWN';
          $username = $profile['socialNetworkUsername'] ?? 'N/A';
          $profileName = "[{$type}] {$username}";

          $tools[$id] = [
            'id' => $id,
            'name' => $profileName,
            'description' => (string) $this->t('Post to @profile via Hootsuite.', [
              '@profile' => $profileName,
            ]),
            'group' => 'social',
            'group_label' => (string) $this->t('Social Profiles'),
            'network_type' => $type,
          ];
        }
      }
    }
    catch (\Exception) {
      // API not reachable — return empty tools.
    }

    $this->availableToolsCache[$cacheKey] = $tools;
    return $tools;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchemaForTool(string|int $toolId): array {
    // Resolve the network type for character limit hints.
    $maxLength = 280;
    $tools = $this->availableToolsCache;
    foreach ($tools as $cache) {
      if (isset($cache[(string) $toolId])) {
        $networkType = strtoupper($cache[(string) $toolId]['network_type'] ?? '');
        if (in_array($networkType, ['LINKEDIN', 'FACEBOOKPAGE', 'FACEBOOK'], TRUE)) {
          $maxLength = 3000;
        }
        break;
      }
    }

    return [
      'text' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Post text'),
        'description' => (string) $this->t('The main content of the social media post.'),
        'required' => TRUE,
        'max_length' => $maxLength,
        'ai_generated' => TRUE,
      ],
      'image' => [
        'type' => 'image',
        'label' => (string) $this->t('Image'),
        'description' => (string) $this->t('Image to attach to the post.'),
        'required' => FALSE,
        'max' => 1,
        'ai_generated' => FALSE,
      ],
      'scheduled_time' => [
        '#type' => 'datetime',
        '#title' => (string) $this->t('Scheduled time'),
        '#description' => (string) $this->t('The date and time to schedule the post for. Must be in the future.'),
        '#required' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructionsForTool(string|int $toolId): string {
    // Try to resolve the profile name and network for a tailored prompt.
    $profileName = 'social media';
    $networkType = '';
    $tools = $this->availableToolsCache;
    foreach ($tools as $cache) {
      if (isset($cache[(string) $toolId])) {
        $profileName = $cache[(string) $toolId]['name'];
        $networkType = strtoupper($cache[(string) $toolId]['network_type'] ?? '');
        break;
      }
    }

    // Infer character limit guidance per network.
    $charGuidance = match ($networkType) {
      'TWITTER' => 'Keep under 280 characters. Use hashtags sparingly (2-3 max).',
      'LINKEDIN' => 'Keep under 3000 characters. Use a professional tone with industry-relevant insights.',
      'FACEBOOKPAGE', 'FACEBOOK' => 'Keep under 500 characters for best engagement. Use an engaging, conversational tone.',
      'INSTAGRAM', 'INSTAGRAMBUSINESS' => 'Write a caption with relevant hashtags (5-10). Include a call-to-action.',
      default => 'Keep the text concise and engaging. Adapt length to the platform.',
    };

    return <<<INSTRUCTIONS
Create a compelling {$networkType} post based on the following Drupal content.

Guidelines:
- {$charGuidance}
- Use an attention-grabbing opening.
- Include a clear call-to-action.
- Include the content URL using [node:url] at the end.
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
    // Authentication is managed centrally by the iq_hootsuite_api module.
    // Check connection status by calling the API.
    $connected = FALSE;
    $accountInfo = '';

    try {
      $me = $this->apiClient->getMe();
      if ($me && !empty($me['data'])) {
        $connected = TRUE;
        $fullName = $me['data']['fullName'] ?? '';
        $email = $me['data']['email'] ?? '';
        $accountInfo = trim("{$fullName} ({$email})", ' ()');
      }
    }
    catch (\Exception $e) {
      // Not connected or API error.
    }

    $settingsUrl = Url::fromRoute('iq_hootsuite_api.settings')->toString();

    if ($connected) {
      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => '<span style="color: green;">✓ Connected to Hootsuite</span>'
          . ($accountInfo ? ' — ' . $accountInfo : ''),
      ];
    }
    else {
      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => '<span style="color: red;">✗ Not connected to Hootsuite.</span><br>'
          . $this->t('Please <a href="@url">configure and authorize Hootsuite</a> in the Hootsuite API settings.', [
            '@url' => $settingsUrl,
          ]),
      ];
    }

    $form['api_settings_link'] = [
      '#type' => 'item',
      '#title' => $this->t('Hootsuite API Settings'),
      '#markup' => $this->t('<a href="@url">Hootsuite API configuration</a> — manage OAuth2 credentials and connection.', [
        '@url' => $settingsUrl,
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings, array $credentials = []): array {
    $form['scheduling'] = [
      '#type' => 'details',
      '#title' => $this->t('Scheduling'),
      '#open' => TRUE,
    ];

    $form['scheduling']['scheduled_delay_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Scheduling delay (minutes)'),
      '#description' => $this->t('Hootsuite requires scheduling at least 5 minutes in the future. Set the delay in minutes from the time of publishing.'),
      '#default_value' => $settings['scheduled_delay_minutes'] ?? 5,
      '#min' => 5,
      '#parents' => ['plugin_settings', 'scheduled_delay_minutes'],
    ];

    $form['scheduling']['send_now'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send as soon as possible'),
      '#description' => $this->t('When checked, posts will be scheduled with the minimum delay (5 minutes). When unchecked, the delay above is used.'),
      '#default_value' => $settings['send_now'] ?? FALSE,
      '#parents' => ['plugin_settings', 'send_now'],
    ];

    // Show a note about tool selection.
    $form['profiles_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Social Profiles'),
      '#markup' => $this->t('Social profiles are automatically discovered from Hootsuite and shown as individual tools when publishing. Select which profiles to enable in the <strong>Tools</strong> section above after saving.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    // Authentication is managed by iq_hootsuite_api.
    // Validate by checking if we can reach the API.
    try {
      $me = $this->apiClient->getMe();
      return $me !== FALSE && !empty($me['data']);
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult {
    // The toolId is the social profile ID.
    $socialProfileId = $toolId !== NULL ? (string) $toolId : '';
    if (empty($socialProfileId)) {
      return PublishingResult::failure(
        'No social profile specified. Each social profile is a separate tool — please select one when publishing.',
        ['error' => 'no_profile']
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
          $mediaUrls[] = ['url' => $imageData['url']];
        }
      }
    }

    // Scheduled time.
    $scheduledTime = $fields['scheduled_time'] ?? NULL;
    $scheduledSendTime = $scheduledTime instanceof DrupalDateTime
      ? $scheduledTime->format('Y-m-d\TH:i:s\Z')
      : NULL;
    
      // Build options for the API client.
    $options = [];
    if (!empty($mediaUrls)) {
      $options['mediaUrls'] = $mediaUrls;
    }

    // Schedule the message to the specific profile via the Hootsuite API.
    $result = $this->apiClient->scheduleMessage(
      $text,
      [$socialProfileId],
      $scheduledSendTime,
      $options,
    );

    if ($result !== FALSE && !empty($result['data'])) {
      $messageIds = array_map(fn($msg) => $msg['id'] ?? '', $result['data']);
      $messageIds = array_filter($messageIds);

      return PublishingResult::success(
        "Successfully scheduled to Hootsuite profile {$socialProfileId}. Scheduled for: {$scheduledSendTime}",
        [
          'message_ids' => $messageIds,
          'scheduled_time' => $scheduledSendTime,
          'social_profile_id' => $socialProfileId,
          'api_response' => $result['data'],
        ]
      );
    }

    return PublishingResult::failure(
      'Failed to schedule Hootsuite message. Check the Hootsuite API logs for details.',
      [
        'error' => 'schedule_failed',
        'social_profile_id' => $socialProfileId,
        'api_response' => $result,
      ]
    );
  }

}
