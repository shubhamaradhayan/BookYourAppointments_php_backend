<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;

final class GoogleCalendarService
{
    private Client $client;

    public function __construct(
        private readonly array $config,
        private readonly PDO $db,
        private readonly CryptoService $crypto
    ) {
        $this->client = new Client();

        $this->client->setClientId($config['client_id']);
        $this->client->setClientSecret($config['client_secret']);
        $this->client->setRedirectUri($config['redirect_uri']);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setIncludeGrantedScopes(true);
        $this->client->setScopes(
            preg_split('/\s+/', trim($config['scope'])) ?: []
        );
    }

    public function authorizationUrl(int $userId, string $state): string
    {
        $this->client->setState($state);

        return $this->client->createAuthUrl();
    }

    public function exchangeAuthorizationCode(
        int $userId,
        string $code
    ): void {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException(
                'Google OAuth failed: ' . ($token['error_description'] ?? $token['error'])
            );
        }

        $refreshToken = $token['refresh_token'] ?? null;
        $accessToken = $token['access_token'] ?? null;

        if (!$accessToken) {
            throw new RuntimeException('Google did not return an access token.');
        }

        // Google may omit refresh_token on later authorizations.
        if (!$refreshToken) {
            $existing = $this->findConnection($userId);
            $refreshToken = $existing['refresh_token'] ?? null;

            if ($refreshToken) {
                $refreshToken = $this->crypto->decrypt($refreshToken);
            }
        }

        $encryptedRefreshToken = $this->crypto->encrypt($refreshToken);
        $encryptedAccessToken = $this->crypto->encrypt($accessToken);

        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + (int) ($token['expires_in'] ?? 3600)
        );

        $statement = $this->db->prepare(
            'INSERT INTO calendar_connections
                (user_id, provider, calendar_id, access_token, refresh_token, token_expires_at, scope, created_at, updated_at)
             VALUES
                (:user_id, "google", :calendar_id, :access_token, :refresh_token, :token_expires_at, :scope, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                calendar_id = VALUES(calendar_id),
                access_token = VALUES(access_token),
                refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
                token_expires_at = VALUES(token_expires_at),
                scope = VALUES(scope),
                updated_at = NOW()'
        );

        $statement->execute([
            'user_id' => $userId,
            'calendar_id' => $this->config['calendar_id'],
            'access_token' => $encryptedAccessToken,
            'refresh_token' => $encryptedRefreshToken,
            'token_expires_at' => $expiresAt,
            'scope' => $this->config['scope'],
        ]);
    }

    public function disconnect(int $userId): void
    {
        $connection = $this->findConnection($userId);

        if ($connection) {
            try {
                $accessToken = $this->crypto->decrypt($connection['access_token']);

                if ($accessToken) {
                    $this->client->setAccessToken($accessToken);
                    $this->client->revokeToken();
                }
            } catch (Throwable) {
                // Revocation failure should not prevent local disconnect.
            }
        }

        $statement = $this->db->prepare(
            'DELETE FROM calendar_connections
             WHERE user_id = :user_id AND provider = "google"'
        );

        $statement->execute(['user_id' => $userId]);
    }

    public function isConnected(int $userId): bool
    {
        return $this->findConnection($userId) !== null;
    }

    public function createAppointmentEvent(
        int $userId,
        array $appointment,
        array $profile
    ): ?string {
echo "================================";

        echo $userId;
print_r($appointment);
print_r($profile);
        $connection = $this->findConnection($userId);
echo $connection ;
echo "================================";
        if (!$connection) {
            return null;
        }

        $client = $this->authorizedClient($connection);
        $calendar = new Calendar($client);

        // $timeZone = $profile['timezone'] ?: 'Asia/Kolkata';

        // $start = new EventDateTime();
        // $start->setDateTime(
        //     $appointment['appointment_date'] . 'T' .
        //     $appointment['start_time'] . ':00'
        // );
        // $start->setTimeZone($timeZone);

        // $end = new EventDateTime();
        // $end->setDateTime(
        //     $appointment['appointment_date'] . 'T' .
        //     $appointment['end_time'] . ':00'
        // );
        // $end->setTimeZone($timeZone);

        $event = new Event();
        $event->setSummary(
            $appointment['service_name'] . ' - ' . $appointment['client_name']
        );
        $event->setDescription(
            "BookFlow appointment\n\n" .
            "Client: {$appointment['client_name']}\n" .
            "Email: {$appointment['client_email']}\n" .
            "Phone: {$appointment['client_phone']}\n" .
            "Service: {$appointment['service_name']}"
        );
        $event->setStart($start);
        $event->setEnd($end);

        if (!empty($appointment['client_email'])) {
            $attendee = new EventAttendee();
            $attendee->setEmail($appointment['client_email']);
            $event->setAttendees([$attendee]);
        }

        $event->setReminders([
            'useDefault' => true,
        ]);


        // $createdEvent = $calendar->events->insert(
        //     $this->config['calendar_id'],
        //     $event,
        //     ['sendUpdates' => 'all']
        // );

        $createdEvent = $calendar->events->insert(
            $connection['calendar_id'],
            $event,
            ['sendUpdates' => 'all']
        );

        return $createdEvent->getId();
    }

    public function deleteEvent(int $userId, string $eventId): void
    {
        $connection = $this->findConnection($userId);

        if (!$connection) {
            return;
        }

        $client = $this->authorizedClient($connection);
        $calendar = new Calendar($client);

        try {
            // $calendar->events->delete(
            //     $this->config['calendar_id'],
            //     $eventId,
            //     ['sendUpdates' => 'all']
            // );
            $calendar->events->delete(
                $connection['calendar_id'],
                $eventId,
                ['sendUpdates' => 'all']
            );
        } catch (Throwable) {
            // Google can return 404 when an event has already been deleted.
        }
    }

    private function authorizedClient(array $connection): Client
    {
        $accessToken = $this->crypto->decrypt($connection['access_token']);
        $refreshToken = $this->crypto->decrypt($connection['refresh_token']);

        $this->client->setAccessToken([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => strtotime($connection['token_expires_at']),
        ]);

        if ($this->client->isAccessTokenExpired()) {
            if (!$refreshToken) {
                throw new RuntimeException(
                    'Google Calendar authorization expired. Please reconnect.'
                );
            }

            $newToken = $this->client->fetchAccessTokenWithRefreshToken(
                $refreshToken
            );

            if (isset($newToken['error'])) {
                throw new RuntimeException(
                    'Google Calendar token refresh failed.'
                );
            }

            $newAccessToken = $newToken['access_token'] ?? null;

            if (!$newAccessToken) {
                throw new RuntimeException(
                    'Google did not return a refreshed access token.'
                );
            }

            $expiresAt = date(
                'Y-m-d H:i:s',
                time() + (int) ($newToken['expires_in'] ?? 3600)
            );

            $statement = $this->db->prepare(
                'UPDATE calendar_connections
                 SET access_token = :access_token,
                     token_expires_at = :token_expires_at,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            $statement->execute([
                'access_token' => $this->crypto->encrypt($newAccessToken),
                'token_expires_at' => $expiresAt,
                'id' => $connection['id'],
            ]);

            $this->client->setAccessToken([
                'access_token' => $newAccessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => strtotime($expiresAt),
            ]);
        }

        return $this->client;
    }

    private function findConnection(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM calendar_connections
             WHERE user_id = :user_id AND provider = "google"
             LIMIT 1'
        );

        $statement->execute(['user_id' => $userId]);

        $connection = $statement->fetch();

        return $connection ?: null;
    }
}
