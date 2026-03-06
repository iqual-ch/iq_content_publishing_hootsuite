<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Plugin\ContentPublishingPlatform;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hootsuite publishing platform plugin.
 *
 * Publishes AI-generated content to social media platforms
 * through the Hootsuite scheduling API.
 *
 * OAuth2 authentication and token management are handled centrally
 * by the iq_hootsuite_api module.
 */
#[ContentPublishingPlatform(
  id: 'hootsuite',
  label: new TranslatableMarkup('Hootsuite'),
  description: new TranslatableMarkup('Publish content to social media platforms via Hootsuite.'),
)]
final class HootsuitePlatform extends ContentPublishingPlatformBase {

  /**
   * The Hootsuite API client (from iq_hootsuite_api module).
   */
  protected HootsuiteApiClientInterface $apiClient;

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
    $options = [];
    $fetchError = FALSE;

    try {
      $result = $this->apiClient->getSocialProfiles();
      if ($result && !empty($result['data'])) {
        foreach ($result['data'] as $profile) {
          $id = $profile['id'] ?? '';
          $type = $profile['type'] ?? 'UNKNOWN';
          $username = $profile['socialNetworkUsername'] ?? 'N/A';
          if ($id) {
            $options[$id] = "[{$type}] {$username} (ID: {$id})";
          }
        }
      }
    }
    catch (\Exception $e) {
      $fetchError = TRUE;
    }

    if (empty($options)) {
      $settingsUrl = Url::fromRoute('iq_hootsuite_api.settings')->toString();
      $form['social_profile_ids_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Social Profiles'),
        '#markup' => $fetchError
          ? $this->t('Could not fetch social profiles from Hootsuite. Please check your <a href="@url">API connection</a>.', ['@url' => $settingsUrl])
          : $this->t('No social profiles found. Please ensure profiles are connected in your <a href="@url">Hootsuite account</a>.', ['@url' => $settingsUrl]),
      ];
    }

    $form['social_profile_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Social Profiles'),
      '#description' => $this->t('Select the Hootsuite social profiles to publish to.'),
      '#options' => $options,
      '#default_value' => $settings['social_profile_ids'] ?? [],
      '#access' => !empty($options),
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
    // Determine social profiles to publish to.
    $socialProfileIds = $settings['social_profile_ids'] ?? [];
    if (empty($socialProfileIds)) {
      return PublishingResult::failure(
        'No social profile IDs configured. Please configure at least one Hootsuite social profile.',
        ['error' => 'no_profiles']
      );
    }

    // Filter out unchecked profiles (checkboxes return 0 for unchecked).
    $socialProfileIds = array_filter($socialProfileIds);
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
          $mediaUrls[] = ['url' => $imageData['url']];
        }
      }
    }

    // Hootsuite requires scheduling at least 5 minutes in the future.
    $delay = 5;

    $sendTime = new \DateTimeImmutable(
      '+' . $delay . ' minutes',
      new \DateTimeZone('UTC')
    );
    $scheduledSendTime = $sendTime->format('Y-m-d\TH:i:s\Z');

    // Build options for the API client.
    $options = [
      'emailNotification' => FALSE,
    ];
    if (!empty($mediaUrls)) {
      $options['mediaUrls'] = $mediaUrls;
    }

    // Schedule the message via the iq_hootsuite_api module.
    $result = $this->apiClient->scheduleMessage(
      $text,
      array_values($socialProfileIds),
      $scheduledSendTime,
      $options,
    );

    if ($result !== FALSE && !empty($result['data'])) {
      $messageIds = array_map(fn($msg) => $msg['id'] ?? '', $result['data']);
      $messageIds = array_filter($messageIds);
      $profileCount = count($socialProfileIds);

      return PublishingResult::success(
        "Successfully scheduled to {$profileCount} social profile(s) via Hootsuite. Scheduled for: {$scheduledSendTime}",
        [
          'message_ids' => $messageIds,
          'scheduled_time' => $scheduledSendTime,
          'social_profiles' => $socialProfileIds,
          'api_response' => $result['data'],
        ]
      );
    }

    return PublishingResult::failure(
      'Failed to schedule Hootsuite message. Check the Hootsuite API logs for details.',
      [
        'error' => 'schedule_failed',
        'api_response' => $result,
      ]
    );
  }

}
