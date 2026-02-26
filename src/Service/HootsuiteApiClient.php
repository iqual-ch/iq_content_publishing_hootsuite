<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_hootsuite\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Client for the Hootsuite REST API.
 *
 * Handles OAuth2 token management and API requests to the
 * Hootsuite platform (https://platform.hootsuite.com).
 */
final class HootsuiteApiClient {

  /**
   * Hootsuite API base URL.
   */
  private const API_BASE_URL = 'https://platform.hootsuite.com';

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a HootsuiteApiClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->logger = $loggerFactory->get('iq_content_publishing_hootsuite');
  }

  /**
   * Builds the OAuth2 authorization URL.
   *
   * @param string $clientId
   *   The OAuth2 client ID.
   * @param string $redirectUri
   *   The callback URI.
   * @param string $state
   *   The state parameter for CSRF protection.
   *
   * @return string
   *   The full authorization URL.
   */
  public function getAuthorizationUrl(string $clientId, string $redirectUri, string $state): string {
    $params = [
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $redirectUri,
      'scope' => 'offline',
      'state' => $state,
    ];
    return self::API_BASE_URL . '/oauth2/auth?' . http_build_query($params);
  }

  /**
   * Exchanges an authorization code for access and refresh tokens.
   *
   * @param string $code
   *   The authorization code.
   * @param string $clientId
   *   The OAuth2 client ID.
   * @param string $clientSecret
   *   The OAuth2 client secret.
   * @param string $redirectUri
   *   The callback URI.
   *
   * @return array|null
   *   The token data or NULL on failure. Keys:
   *   - access_token: The access token.
   *   - refresh_token: The refresh token.
   *   - expires_in: Token lifetime in seconds.
   */
  public function exchangeAuthorizationCode(string $code, string $clientId, string $clientSecret, string $redirectUri): ?array {
    try {
      $response = $this->httpClient->request('POST', self::API_BASE_URL . '/oauth2/token', [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $redirectUri,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!empty($data['access_token'])) {
        return $data;
      }

      $this->logger->error('Hootsuite token exchange returned no access token: @response', [
        '@response' => (string) $response->getBody(),
      ]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Hootsuite token exchange failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Refreshes an expired access token.
   *
   * @param string $refreshToken
   *   The refresh token.
   * @param string $clientId
   *   The OAuth2 client ID.
   * @param string $clientSecret
   *   The OAuth2 client secret.
   *
   * @return array|null
   *   The new token data or NULL on failure.
   */
  public function refreshAccessToken(string $refreshToken, string $clientId, string $clientSecret): ?array {
    try {
      $response = $this->httpClient->request('POST', self::API_BASE_URL . '/oauth2/token', [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refreshToken,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!empty($data['access_token'])) {
        return $data;
      }

      $this->logger->error('Hootsuite token refresh returned no access token.');
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Hootsuite token refresh failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets a valid access token, refreshing if necessary.
   *
   * @param array $credentials
   *   The stored credentials.
   * @param string|null $platformConfigId
   *   The platform config entity ID, used to persist refreshed tokens.
   *
   * @return string|null
   *   A valid access token, or NULL if unavailable.
   */
  public function getValidAccessToken(array $credentials, ?string $platformConfigId = NULL): ?string {
    if (empty($credentials['access_token'])) {
      return NULL;
    }

    // Check if token is still valid (with 60-second buffer).
    $tokenExpires = $credentials['token_expires'] ?? 0;
    if ($tokenExpires > (time() + 60)) {
      return $credentials['access_token'];
    }

    // Token expired — attempt refresh.
    if (empty($credentials['refresh_token']) || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
      $this->logger->warning('Cannot refresh Hootsuite token: missing refresh_token or client credentials.');
      return NULL;
    }

    $tokenData = $this->refreshAccessToken(
      $credentials['refresh_token'],
      $credentials['client_id'],
      $credentials['client_secret'],
    );

    if (!$tokenData) {
      return NULL;
    }

    // Persist refreshed tokens to the config entity.
    if ($platformConfigId) {
      $this->persistTokens($platformConfigId, $tokenData);
    }

    return $tokenData['access_token'];
  }

  /**
   * Persists new tokens to the platform config entity.
   *
   * @param string $platformConfigId
   *   The platform config entity ID.
   * @param array $tokenData
   *   The token data from Hootsuite.
   */
  public function persistTokens(string $platformConfigId, array $tokenData): void {
    try {
      $storage = $this->entityTypeManager->getStorage('publishing_platform');
      /** @var \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface|null $entity */
      $entity = $storage->load($platformConfigId);
      if ($entity) {
        $credentials = $entity->getCredentials();
        $credentials['access_token'] = $tokenData['access_token'];
        if (!empty($tokenData['refresh_token'])) {
          $credentials['refresh_token'] = $tokenData['refresh_token'];
        }
        $credentials['token_expires'] = time() + ($tokenData['expires_in'] ?? 2592000);
        $entity->set('credentials', $credentials);
        $entity->save();
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to persist Hootsuite tokens: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Retrieves the social profiles available to the authenticated user.
   *
   * @param string $accessToken
   *   The OAuth2 access token.
   *
   * @return array
   *   Array of social profiles, each with keys: id, type,
   *   socialNetworkUsername, avatarUrl, etc.
   */
  public function getSocialProfiles(string $accessToken): array {
    try {
      $response = $this->httpClient->request('GET', self::API_BASE_URL . '/v1/me/socialProfiles', [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      return $data['data'] ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch Hootsuite social profiles: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Schedules a message to one or more social profiles.
   *
   * @param string $accessToken
   *   The OAuth2 access token.
   * @param string $text
   *   The message text.
   * @param array $socialProfileIds
   *   Array of social profile IDs to post to.
   * @param bool $sendNow
   *   If TRUE, schedules 5 minutes from now (Hootsuite minimum).
   * @param int $delayMinutes
   *   Minutes to delay scheduled send (default: 5).
   * @param array $mediaUrls
   *   Optional array of media URLs to attach to the message.
   *
   * @return array
   *   Result array with 'success' boolean and 'data'/'error' keys.
   */
  public function scheduleMessage(string $accessToken, string $text, array $socialProfileIds, bool $sendNow = TRUE, int $delayMinutes = 5, array $mediaUrls = []): array {
    // Hootsuite requires scheduledSendTime at least 5 minutes in the future.
    $sendTime = new \DateTimeImmutable(
      '+' . max(5, $delayMinutes) . ' minutes',
      new \DateTimeZone('UTC')
    );

    $payload = [
      'text' => $text,
      'socialProfileIds' => $socialProfileIds,
      'scheduledSendTime' => $sendTime->format('Y-m-d\TH:i:s\Z'),
      'emailNotification' => FALSE,
    ];

    // Attach media if provided.
    if (!empty($mediaUrls)) {
      $payload['mediaUrls'] = [];
      foreach ($mediaUrls as $url) {
        $payload['mediaUrls'][] = ['url' => $url];
      }
    }

    try {
      $response = $this->httpClient->request('POST', self::API_BASE_URL . '/v1/messages', [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json;charset=utf-8',
        ],
        'json' => $payload,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);

      if (!empty($data['data'])) {
        $messageIds = array_map(fn($msg) => $msg['id'] ?? '', $data['data']);
        return [
          'success' => TRUE,
          'data' => $data['data'],
          'message_ids' => array_filter($messageIds),
          'scheduled_time' => $sendTime->format('Y-m-d\TH:i:s\Z'),
        ];
      }

      return [
        'success' => FALSE,
        'error' => 'No messages returned in response.',
        'response' => $data,
      ];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Hootsuite schedule message failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      $errorBody = '';
      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $errorBody = (string) $e->getResponse()->getBody();
      }

      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
        'response_body' => $errorBody,
      ];
    }
  }

  /**
   * Retrieves a message by ID.
   *
   * @param string $accessToken
   *   The OAuth2 access token.
   * @param string $messageId
   *   The Hootsuite message ID.
   *
   * @return array|null
   *   The message data or NULL on failure.
   */
  public function getMessage(string $accessToken, string $messageId): ?array {
    try {
      $response = $this->httpClient->request('GET', self::API_BASE_URL . '/v1/messages/' . $messageId, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      return $data['data'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to retrieve Hootsuite message @id: @message', [
        '@id' => $messageId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Deletes a scheduled message.
   *
   * @param string $accessToken
   *   The OAuth2 access token.
   * @param string $messageId
   *   The Hootsuite message ID.
   *
   * @return bool
   *   TRUE if deletion was successful.
   */
  public function deleteMessage(string $accessToken, string $messageId): bool {
    try {
      $this->httpClient->request('DELETE', self::API_BASE_URL . '/v1/messages/' . $messageId, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);
      return TRUE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to delete Hootsuite message @id: @message', [
        '@id' => $messageId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Validates that given credentials can connect to Hootsuite.
   *
   * @param string $accessToken
   *   The OAuth2 access token.
   *
   * @return bool
   *   TRUE if the API responds successfully.
   */
  public function validateConnection(string $accessToken): bool {
    try {
      $response = $this->httpClient->request('GET', self::API_BASE_URL . '/v1/me', [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      return !empty($data['data']['id']);
    }
    catch (GuzzleException $e) {
      return FALSE;
    }
  }

}
