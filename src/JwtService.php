<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    public function __construct(private readonly array $config)
    {
        if (strlen($this->config['secret']) < 64) {
            throw new RuntimeException(
                'JWT_SECRET must contain at least 64 characters.'
            );
        }
    }

    public function createAccessToken(int $userId): string
    {
        $now = time();

        $payload = [
            'iss' => $this->config['issuer'],
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $this->config['access_ttl'],
            'type' => 'access',
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode(
            $payload,
            $this->config['secret'],
            'HS256'
        );
    }

    public function createRefreshToken(int $userId): array
    {
        $now = time();
        $jti = bin2hex(random_bytes(24));

        $payload = [
            'iss' => $this->config['issuer'],
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $this->config['refresh_ttl'],
            'type' => 'refresh',
            'jti' => $jti,
        ];

        return [
            'token' => JWT::encode(
                $payload,
                $this->config['secret'],
                'HS256'
            ),
            'jti' => $jti,
            'expires_at' => date(
                'Y-m-d H:i:s',
                $now + $this->config['refresh_ttl']
            ),
        ];
    }

    public function decode(string $token): array
    {
        $decoded = JWT::decode(
            $token,
            new Key($this->config['secret'], 'HS256')
        );

        return (array) $decoded;
    }
}
