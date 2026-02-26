<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\iq_content_publishing_hootsuite\Service\HootsuiteApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Hootsuite OAuth2 authorization flow.
 *
 * Handles the three-legged OAuth2 authorization code flow:
 * 1. connect() — Redirects admin to Hootsuite authorization page.
 * 2. callback() — Receives the authorization code and exchanges it for tokens.
 * 3. disconnect() — Clears stored tokens.
 */
final class HootsuiteOAuthController extends ControllerBase {

  /**
   * The Hootsuite API client.
   */
  protected HootsuiteApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('iq_content_publishing_hootsuite.api_client');
    return $instance;
  }

  /**
   * Initiates the OAuth2 authorization flow.
   *
   * Redirects the user to Hootsuite's authorization page.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   Redirect to Hootsuite OAuth page.
   */
  public function connect(PublishingPlatformConfigInterface $platform_config, Request $request): TrustedRedirectResponse {
    $credentials = $platform_config->getCredentials();

    if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
      $this->messenger()->addError($this->t('Please save the platform configuration with your Client ID and Secret first.'));
      return new RedirectResponse(Url::fromRoute('entity.publishing_platform.collection')->toString());
    }

    $redirectUri = Url::fromRoute('iq_content_publishing_hootsuite.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();

    // Generate state parameter for CSRF protection, including the config ID.
    $state = $platform_config->id() . '|' . bin2hex(random_bytes(16));

    // Store the state in the session for validation.
    $session = $request->getSession();
    $session->set('hootsuite_oauth_state', $state);

    $authUrl = $this->apiClient->getAuthorizationUrl(
      $credentials['client_id'],
      $redirectUri,
      $state,
    );

    return new TrustedRedirectResponse($authUrl);
  }

  /**
   * Handles the OAuth2 callback from Hootsuite.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with the authorization code.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform configuration list.
   */
  public function callback(Request $request): RedirectResponse {
    $collectionUrl = Url::fromRoute('entity.publishing_platform.collection')->toString();

    // Validate state parameter.
    $state = $request->query->get('state');
    $session = $request->getSession();
    $storedState = $session->get('hootsuite_oauth_state');
    $session->remove('hootsuite_oauth_state');

    if (empty($state) || $state !== $storedState) {
      $this->messenger()->addError($this->t('Invalid OAuth state parameter. Please try again.'));
      return new RedirectResponse($collectionUrl);
    }

    // Handle error response from Hootsuite.
    $error = $request->query->get('error');
    if ($error) {
      $errorDescription = $request->query->get('error_description', 'Unknown error');
      $this->messenger()->addError($this->t('Hootsuite authorization failed: @error — @description', [
        '@error' => $error,
        '@description' => $errorDescription,
      ]));
      return new RedirectResponse($collectionUrl);
    }

    // Get the authorization code.
    $code = $request->query->get('code');
    if (empty($code)) {
      $this->messenger()->addError($this->t('No authorization code received from Hootsuite.'));
      return new RedirectResponse($collectionUrl);
    }

    // Extract platform config ID from state.
    [$platformConfigId] = explode('|', $state, 2);

    // Load the platform config entity.
    $platformConfig = $this->entityTypeManager()
      ->getStorage('publishing_platform')
      ->load($platformConfigId);

    if (!$platformConfig) {
      $this->messenger()->addError($this->t('Platform configuration not found.'));
      return new RedirectResponse($collectionUrl);
    }

    $credentials = $platformConfig->getCredentials();

    $redirectUri = Url::fromRoute('iq_content_publishing_hootsuite.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();

    // Exchange authorization code for tokens.
    $tokenData = $this->apiClient->exchangeAuthorizationCode(
      $code,
      $credentials['client_id'],
      $credentials['client_secret'],
      $redirectUri,
    );

    if (!$tokenData) {
      $this->messenger()->addError($this->t('Failed to exchange authorization code for tokens. Please try again.'));
      return new RedirectResponse($collectionUrl);
    }

    // Store tokens in the platform config.
    $credentials['access_token'] = $tokenData['access_token'];
    $credentials['refresh_token'] = $tokenData['refresh_token'] ?? '';
    $credentials['token_expires'] = time() + ($tokenData['expires_in'] ?? 2592000);

    $platformConfig->set('credentials', $credentials);
    $platformConfig->save();

    $this->messenger()->addStatus($this->t('Successfully connected to Hootsuite!'));

    return new RedirectResponse($collectionUrl);
  }

  /**
   * Disconnects from Hootsuite by clearing stored tokens.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform configuration list.
   */
  public function disconnect(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $credentials = $platform_config->getCredentials();

    // Clear token fields but preserve client ID and secret.
    $credentials['access_token'] = '';
    $credentials['refresh_token'] = '';
    $credentials['token_expires'] = 0;

    $platform_config->set('credentials', $credentials);
    $platform_config->save();

    $this->messenger()->addStatus($this->t('Disconnected from Hootsuite.'));

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.collection')->toString()
    );
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
    $credentials = $platform_config->getCredentials();
    $accessToken = $this->apiClient->getValidAccessToken($credentials, $platform_config->id());

    if (!$accessToken) {
      $this->messenger()->addError($this->t('No valid access token. Please connect to Hootsuite first.'));
      return new RedirectResponse(
        Url::fromRoute('entity.publishing_platform.collection')->toString()
      );
    }

    $profiles = $this->apiClient->getSocialProfiles($accessToken);

    if (empty($profiles)) {
      $this->messenger()->addWarning($this->t('No social profiles found for this Hootsuite account.'));
    }
    else {
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
    }

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.edit_form', [
        'publishing_platform' => $platform_config->id(),
      ])->toString()
    );
  }

}
