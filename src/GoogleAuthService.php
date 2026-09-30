<?php

declare(strict_types=1);

use Google\Client;

final class GoogleAuthService
{
    private Client $client;

    public function __construct(
        private readonly array $config
    ) {
        $this->client = new Client();
        $this->client->setClientId($config['login_client_id']);
    }

    /**
     * Verify a Google Sign-In ID token.
     *
     * The signature, issuer, audience and token expiry are validated by the
     * Google PHP client. The backend never trusts a name/email supplied by
     * the browser directly.
     */
    public function verifyIdToken(string $credential): array
    {
        if ($credential === '') {
            throw new InvalidArgumentException(
                'Google credential is required.'
            );
        }

        $payload = $this->client->verifyIdToken($credential);

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Google returned an invalid ID token.'
            );
        }

        $googleId = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = (bool) ($payload['email_verified'] ?? false);

        if ($googleId === '' || $email === '') {
            throw new RuntimeException(
                'Google account information is incomplete.'
            );
        }

        if (!$emailVerified) {
            throw new RuntimeException(
                'Your Google email address is not verified.'
            );
        }

        return [
            'google_id' => $googleId,
            'email' => $email,
            'name' => trim((string) ($payload['name'] ?? '')),
            'picture' => trim((string) ($payload['picture'] ?? '')),
            'email_verified' => $emailVerified,
        ];
    }
}
