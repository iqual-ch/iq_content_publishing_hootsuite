<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Hootsuite-specific platform operations.
 *
 * OAuth2 authentication is handled by the iq_hootsuite_api module.
 * This controller provides only the "Fetch Social Profiles" action.
 */
final class HootsuiteController extends ControllerBase {

  /**
   * The Hootsuite API client (from iq_hootsuite_api module).
   */
  protected HootsuiteApiClientInterface $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('iq_hootsuite_api.client');
    return $instance;
  }

  /**
   * Fetches and displays available social profiles from Hootsuite.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform edit form with profiles shown as messages.
   */
  public function fetchProfiles(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $result = $this->apiClient->getSocialProfiles();

    if ($result === FALSE || empty($result['data'])) {
      $this->messenger()->addWarning($this->t('No social profiles found. Please ensure you are connected to Hootsuite via the <a href="@url">Hootsuite API settings</a>.', [
        '@url' => Url::fromRoute('iq_hootsuite_api.settings_form')->toString(),
      ]));

      return new RedirectResponse(
        Url::fromRoute('entity.publishing_platform.edit_form', [
          'publishing_platform' => $platform_config->id(),
        ])->toString()
      );
    }

    $profiles = $result['data'];

    $this->messenger()->addStatus($this->t('Found @count social profile(s):', [
      '@count' => count($profiles),
    ]));

    foreach ($profiles as $profile) {
      $type = $profile['type'] ?? 'UNKNOWN';
      $username = $profile['socialNetworkUsername'] ?? 'N/A';
      $id = $profile['id'] ?? 'N/A';

      $this->messenger()->addStatus($this->t('[@type] @username — ID: @id', [
        '@type' => $type,
        '@username' => $username,
        '@id' => $id,
      ]));
    }

    $this->messenger()->addStatus($this->t('Copy the desired ID(s) into the "Social Profile IDs" field in the platform settings.'));

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.edit_form', [
        'publishing_platform' => $platform_config->id(),
      ])->toString()
    );
  }

}
