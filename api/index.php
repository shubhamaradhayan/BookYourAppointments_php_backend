<?php

declare(strict_types=1);

/**
 * BookFlow API
 *
 * All REST API routes intentionally live in this file to keep debugging
 * straightforward on XAMPP/shared hosting.
 *
 * Business logic that talks to external systems (Google Calendar) is kept
 * in src/GoogleCalendarService.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/JwtService.php';
require_once __DIR__ . '/../src/CryptoService.php';
require_once __DIR__ . '/../src/GoogleCalendarService.php';
require_once __DIR__ . '/../src/GoogleAuthService.php';
require_once __DIR__ . '/../src/EmailService.php';

Env::load(__DIR__ . '/../.env');

$config = require __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

$allowedOrigin = $config['app']['frontend_url'];

if ($allowedOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = Database::connection($config['database']);
$jwt = new JwtService($config['jwt']);
$crypto = new CryptoService($config['encryption']['key']);
$google = new GoogleCalendarService(
    $config['google'],
    $db,
    $crypto
);

$googleAuth = new GoogleAuthService($config['google']);
$emailService = new EmailService($config['mail']);

$route = trim((string) ($_GET['route'] ?? ''), '/');
$method = strtoupper($_SERVER['REQUEST_METHOD']);

/*
 * OAuth callback uses a short-lived PHP session only for OAuth state.
 * Authentication itself is JWT-based and does not depend on PHP sessions.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new InvalidArgumentException('Request body must be valid JSON.');
    }

    return $data;
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function fail(string $message, int $status = 400, array $errors = []): never
{
    respond(
        [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ],
        $status
    );
}

function bearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function requireAuth(JwtService $jwt): int
{
    $token = bearerToken();

    if (!$token) {
        fail('Authentication token is required.', 401);
    }

    try {
        $payload = $jwt->decode($token);
    } catch (Throwable) {
        fail('Invalid or expired authentication token.', 401);
    }

    if (($payload['type'] ?? '') !== 'access') {
        fail('Invalid access token.', 401);
    }

    $userId = (int) ($payload['sub'] ?? 0);

    if ($userId <= 0) {
        fail('Invalid authentication subject.', 401);
    }

    return $userId;
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function normalizeSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

// function publicCalendarUrl(
//     string $title,
//     string $start,
//     string $end,
//     string $details,
//     string $location = ''
// ): string {
//     return 'https://calendar.google.com/calendar/render?' . http_build_query([
//         'action' => 'TEMPLATE',
//         'text' => $title,
//         'dates' => gmdate('Ymd\THis\Z', strtotime($start)) .
//             '/' .
//             gmdate('Ymd\THis\Z', strtotime($end)),
//         'details' => $details,
//         'location' => $location,
//     ]);
// }


function publicCalendarUrl(
    string $title,
    string $start,
    string $end,
    string $details,
    string $location = '',
    string $timezone = 'Asia/Kolkata'
): string {
    $tz = new DateTimeZone($timezone);

    $startDateTime = new DateTimeImmutable($start, $tz);
    $endDateTime = new DateTimeImmutable($end, $tz);

    $dates =
        $startDateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z')
        . '/'
        .
        $endDateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $title,
        'dates' => $dates,
        'details' => $details,
        'location' => $location,
    ]);
}

function fetchUser(PDO $db, int $userId): ?array
{
    $statement = $db->prepare(
        'SELECT id, name, email, created_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $statement->execute(['id' => $userId]);

    $user = $statement->fetch();

    return $user ?: null;
}

function fetchProfile(PDO $db, int $userId): ?array
{
    $statement = $db->prepare(
        'SELECT *
         FROM professional_profiles
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $statement->execute(['user_id' => $userId]);

    $profile = $statement->fetch();

    return $profile ?: null;
}

function refreshTokenHash(string $token): string
{
    return hash('sha256', $token);
}

try {
    /*
     * --------------------------------------------------------
     * Health
     * --------------------------------------------------------
     */
    if ($route === 'health' && $method === 'GET') {
        respond([
            'success' => true,
            'service' => 'BookFlow API',
            'status' => 'ok',
        ]);
    }

    /*
     * --------------------------------------------------------
     * Authentication
     * --------------------------------------------------------
     */
    if ($route === 'auth/register' && $method === 'POST') {
        $body = requestBody();

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $profession = trim((string) ($body['profession'] ?? ''));
        $businessName = trim((string) ($body['business_name'] ?? ''));

        if ($name === '') {
            fail('Name is required.');
        }

        if (!validateEmail($email)) {
            fail('A valid email address is required.');
        }

        if (strlen($password) < 8) {
            fail('Password must contain at least 8 characters.');
        }

        $existing = $db->prepare(
            'SELECT id FROM users WHERE email = :email LIMIT 1'
        );
        $existing->execute(['email' => $email]);

        if ($existing->fetch()) {
            fail('An account with this email already exists.', 409);
        }

        $db->beginTransaction();

        try {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $userStatement = $db->prepare(
                'INSERT INTO users
                    (name, email, password_hash, created_at, updated_at)
                 VALUES
                    (:name, :email, :password_hash, NOW(), NOW())'
            );

            $userStatement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]);

            $userId = (int) $db->lastInsertId();

            $slugBase = normalizeSlug(
                $businessName !== '' ? $businessName : $name
            );

            if ($slugBase === '') {
                $slugBase = 'professional';
            }

            $slug = $slugBase . '-' . $userId;

            $profileStatement = $db->prepare(
                'INSERT INTO professional_profiles
                    (user_id, name, profession, business_name, slug, timezone, created_at, updated_at)
                 VALUES
                    (:user_id, :name, :profession, :business_name, :slug, :timezone, NOW(), NOW())'
            );

            $profileStatement->execute([
                'user_id' => $userId,
                'name' => $name,
                'profession' => $profession,
                'business_name' => $businessName,
                'slug' => $slug,
                'timezone' => 'Asia/Kolkata',
            ]);

            $db->commit();

            $accessToken = $jwt->createAccessToken($userId);
            $refresh = $jwt->createRefreshToken($userId);

            $refreshStatement = $db->prepare(
                'INSERT INTO refresh_tokens
                    (user_id, token_hash, expires_at, created_at)
                 VALUES
                    (:user_id, :token_hash, :expires_at, NOW())'
            );

            $refreshStatement->execute([
                'user_id' => $userId,
                'token_hash' => refreshTokenHash($refresh['token']),
                'expires_at' => $refresh['expires_at'],
            ]);

            respond([
                'success' => true,
                'message' => 'Account created successfully.',
                'user' => fetchUser($db, $userId),
                'access_token' => $accessToken,
                'refresh_token' => $refresh['token'],
            ], 201);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /*
     * --------------------------------------------------------
     * Google Sign-In
     * --------------------------------------------------------
     *
     * The frontend sends the Google ID token (credential).
     * We verify that token on the server and then issue our own
     * BookFlow JWT access/refresh tokens.
     */
    if ($route === 'auth/google' && $method === 'POST') {
        $body = requestBody();
        $credential = trim((string) ($body['credential'] ?? ''));

        $googleUser = $googleAuth->verifyIdToken($credential);

        $statement = $db->prepare(
            'SELECT id, name, email, google_id, auth_provider, created_at
             FROM users
             WHERE google_id = :google_id
             LIMIT 1'
        );

        $statement->execute([
            'google_id' => $googleUser['google_id'],
        ]);

        $user = $statement->fetch();

        if (!$user) {
            /*
             * We intentionally do not silently merge an existing password
             * account by email. Account linking should be an authenticated
             * action to avoid accidental account takeover.
             */
            $emailStatement = $db->prepare(
                'SELECT id
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $emailStatement->execute([
                'email' => $googleUser['email'],
            ]);

            // if ($emailStatement->fetch()) {
                // fail(
                //     'An account already exists with this email. Sign in with your password first, then link Google from your profile.',
                //     409
                // );
            // Fix: Capture the fetched row into a variable
            if ($existingUser = $emailStatement->fetch(PDO::FETCH_ASSOC)) {
                $update = $db->prepare(
                    'UPDATE users
                     SET google_id = :google_id, updated_at = NOW()
                     WHERE id = :id'
                );

                $update->execute([
                    'google_id' => $googleUser['google_id'],
                    'id' => $existingUser['id'], // Fix: Access the array key correctly
                ]);

                // Fix: Fetch the full user record so $user is properly populated
                $user = fetchUser($db, (int) $existingUser['id']);
               
            }
            else {  // updated on sept 23, 2026

        
            $db->beginTransaction();

            try {
                $userStatement = $db->prepare(
                    'INSERT INTO users
                        (name, email, password_hash, google_id,
                         auth_provider, created_at, updated_at)
                     VALUES
                        (:name, :email, NULL, :google_id,
                         "google", NOW(), NOW())'
                );

                $userStatement->execute([
                    'name' => $googleUser['name'] !== ''
                        ? $googleUser['name']
                        : 'Google User',
                    'email' => $googleUser['email'],
                    'google_id' => $googleUser['google_id'],
                ]);

                $userId = (int) $db->lastInsertId();

                $slugBase = normalizeSlug(
                    $googleUser['name'] !== ''
                        ? $googleUser['name']
                        : 'professional'
                );

                $slug = ($slugBase ?: 'professional') . '-' . $userId;

                $profileStatement = $db->prepare(
                    'INSERT INTO professional_profiles
                        (user_id, name, slug, timezone, created_at, updated_at)
                     VALUES
                        (:user_id, :name, :slug, "Asia/Kolkata", NOW(), NOW())'
                );

                $profileStatement->execute([
                    'user_id' => $userId,
                    'name' => $googleUser['name'] !== ''
                        ? $googleUser['name']
                        : 'Google User',
                    'slug' => $slug,
                ]);

                $db->commit();

                $user = fetchUser($db, $userId);
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $exception;
            }
            }
        } else {
            $userId = (int) $user['id'];

            /*
             * Keep the Google display name current, but do not overwrite
             * profile/business information on every login.
             */
            $update = $db->prepare(
                'UPDATE users
                 SET name = :name,
                     auth_provider = CASE
                         WHEN password_hash IS NULL THEN "google"
                         ELSE "password_google"
                     END,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            $update->execute([
                'name' => $googleUser['name'] !== ''
                    ? $googleUser['name']
                    : $user['name'],
                'id' => $userId,
            ]);

            $user = fetchUser($db, $userId);
        }

        $userId = (int) $user['id'];

        $accessToken = $jwt->createAccessToken($userId);
        $refresh = $jwt->createRefreshToken($userId);

        $refreshStatement = $db->prepare(
            'INSERT INTO refresh_tokens
                (user_id, token_hash, expires_at, created_at)
             VALUES
                (:user_id, :token_hash, :expires_at, NOW())'
        );

        $refreshStatement->execute([
            'user_id' => $userId,
            'token_hash' => refreshTokenHash($refresh['token']),
            'expires_at' => $refresh['expires_at'],
        ]);

        respond([
            'success' => true,
            'message' => 'Signed in with Google successfully.',
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refresh['token'],
        ]);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $body = requestBody();
        
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $statement = $db->prepare(
            'SELECT id, name, email, password_hash, created_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if (
            !$user ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            fail('Invalid email or password.', 401);
        }

        if (password_needs_rehash(
            $user['password_hash'],
            PASSWORD_DEFAULT
        )) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);

            $update = $db->prepare(
                'UPDATE users
                 SET password_hash = :password_hash, updated_at = NOW()
                 WHERE id = :id'
            );

            $update->execute([
                'password_hash' => $newHash,
                'id' => $user['id'],
            ]);
        }

        $accessToken = $jwt->createAccessToken((int) $user['id']);
        $refresh = $jwt->createRefreshToken((int) $user['id']);

        $refreshStatement = $db->prepare(
            'INSERT INTO refresh_tokens
                (user_id, token_hash, expires_at, created_at)
             VALUES
                (:user_id, :token_hash, :expires_at, NOW())'
        );

        $refreshStatement->execute([
            'user_id' => $user['id'],
            'token_hash' => refreshTokenHash($refresh['token']),
            'expires_at' => $refresh['expires_at'],
        ]);

        unset($user['password_hash']);

        respond([
            'success' => true,
            'message' => 'Login successful.',
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refresh['token'],
        ]);
    }

    if ($route === 'auth/refresh' && $method === 'POST') {
        $body = requestBody();
        $refreshToken = trim((string) ($body['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            fail('Refresh token is required.', 401);
        }

        try {
            $payload = $jwt->decode($refreshToken);
        } catch (Throwable) {
            fail('Invalid or expired refresh token.', 401);
        }

        if (($payload['type'] ?? '') !== 'refresh') {
            fail('Invalid refresh token.', 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $tokenHash = refreshTokenHash($refreshToken);

        $statement = $db->prepare(
            'SELECT id
             FROM refresh_tokens
             WHERE user_id = :user_id
               AND token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );

        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);

        $storedToken = $statement->fetch();

        if (!$storedToken) {
            fail('Refresh token is no longer valid.', 401);
        }

        // Rotate refresh tokens to reduce replay risk.
        $revoke = $db->prepare(
            'UPDATE refresh_tokens
             SET revoked_at = NOW()
             WHERE id = :id'
        );

        $revoke->execute(['id' => $storedToken['id']]);

        $newAccessToken = $jwt->createAccessToken($userId);
        $newRefresh = $jwt->createRefreshToken($userId);

        $insert = $db->prepare(
            'INSERT INTO refresh_tokens
                (user_id, token_hash, expires_at, created_at)
             VALUES
                (:user_id, :token_hash, :expires_at, NOW())'
        );

        $insert->execute([
            'user_id' => $userId,
            'token_hash' => refreshTokenHash($newRefresh['token']),
            'expires_at' => $newRefresh['expires_at'],
        ]);

        respond([
            'success' => true,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefresh['token'],
        ]);
    }

    if ($route === 'auth/google/link' && $method === 'POST') {
        $userId = requireAuth($jwt);
        $body = requestBody();
        $credential = trim((string) ($body['credential'] ?? ''));

        $googleUser = $googleAuth->verifyIdToken($credential);

        $existingGoogle = $db->prepare(
            'SELECT id
             FROM users
             WHERE google_id = :google_id
             LIMIT 1'
        );

        $existingGoogle->execute([
            'google_id' => $googleUser['google_id'],
        ]);

        $linkedUser = $existingGoogle->fetch();

        if ($linkedUser && (int) $linkedUser['id'] !== $userId) {
            fail(
                'This Google account is already linked to another BookFlow account.',
                409
            );
        }

        $update = $db->prepare(
            'UPDATE users
             SET google_id = :google_id,
                 auth_provider = CASE
                     WHEN password_hash IS NULL THEN "google"
                     ELSE "password_google"
                 END,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $update->execute([
            'google_id' => $googleUser['google_id'],
            'id' => $userId,
        ]);

        respond([
            'success' => true,
            'message' => 'Google account linked successfully.',
        ]);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        $body = requestBody();
        $refreshToken = trim((string) ($body['refresh_token'] ?? ''));

        if ($refreshToken !== '') {
            $statement = $db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW()
                 WHERE token_hash = :token_hash'
            );

            $statement->execute([
                'token_hash' => refreshTokenHash($refreshToken),
            ]);
        }

        respond([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    if ($route === 'auth/me' && $method === 'GET') {
        $userId = requireAuth($jwt);
        $user = fetchUser($db, $userId);

        if (!$user) {
            fail('User not found.', 404);
        }

        respond([
            'success' => true,
            'user' => $user,
            'profile' => fetchProfile($db, $userId),
        ]);
    }

    /*
     * --------------------------------------------------------
     * Profile
     * --------------------------------------------------------
     */
    if ($route === 'profile' && $method === 'GET') {
        $userId = requireAuth($jwt);
        $profile = fetchProfile($db, $userId);

        if (!$profile) {
            fail('Profile not found.', 404);
        }

        respond([
            'success' => true,
            'profile' => $profile,
        ]);
    }

    if ($route === 'profile' && $method === 'PUT') {
        $userId = requireAuth($jwt);
        $body = requestBody();

        $profile = fetchProfile($db, $userId);

        if (!$profile) {
            fail('Profile not found.', 404);
        }

        $name = trim((string) ($body['name'] ?? $profile['name']));
        $profession = trim(
            (string) ($body['profession'] ?? $profile['profession'])
        );
        $businessName = trim(
            (string) ($body['business_name'] ?? $profile['business_name'])
        );
        $phone = trim((string) ($body['phone'] ?? $profile['phone']));
        $address = trim((string) ($body['address'] ?? $profile['address']));
        $bio = trim((string) ($body['bio'] ?? $profile['bio']));
        $timezone = trim(
            (string) ($body['timezone'] ?? $profile['timezone'] ?: 'Asia/Kolkata')
        );

        $slug = normalizeSlug(
            (string) ($body['slug'] ?? $profile['slug'])
        );

        if ($name === '') {
            fail('Name is required.');
        }

        if ($slug === '') {
            fail('Public booking slug is required.');
        }

        $slugCheck = $db->prepare(
            'SELECT id
             FROM professional_profiles
             WHERE slug = :slug AND user_id != :user_id
             LIMIT 1'
        );

        $slugCheck->execute([
            'slug' => $slug,
            'user_id' => $userId,
        ]);

        if ($slugCheck->fetch()) {
            fail('This public booking slug is already in use.', 409);
        }

        $statement = $db->prepare(
            'UPDATE professional_profiles
             SET name = :name,
                 profession = :profession,
                 business_name = :business_name,
                 phone = :phone,
                 address = :address,
                 bio = :bio,
                 slug = :slug,
                 timezone = :timezone,
                 updated_at = NOW()
             WHERE user_id = :user_id'
        );

        $statement->execute([
            'name' => $name,
            'profession' => $profession,
            'business_name' => $businessName,
            'phone' => $phone,
            'address' => $address,
            'bio' => $bio,
            'slug' => $slug,
            'timezone' => $timezone,
            'user_id' => $userId,
        ]);

        respond([
            'success' => true,
            'profile' => fetchProfile($db, $userId),
        ]);
    }

    if ($route === 'profile/photo' && $method === 'POST') {
        $userId = requireAuth($jwt);
        
        if (!isset($_FILES['photo'])) {
            fail('Profile photo is required.');
        }

        $file = $_FILES['photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            fail('Unable to upload the profile photo.');
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            fail('Profile photo must be smaller than 5 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            fail('Only JPG, PNG and WebP images are allowed.');
        }

        $uploadDirectory = __DIR__ . '/../uploads/profile';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0755, true);
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $destination = $uploadDirectory . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            fail('Unable to save the uploaded photo.', 500);
        }


        // 1. Check existing photo
        $existsFileStatement = $db->prepare(
            'SELECT profile_photo 
             FROM professional_profiles 
             WHERE user_id = :user_id'
        );

        $existsFileStatement->execute([
            'user_id' => $userId,
        ]);

        $existingProfile = $existsFileStatement->fetch(PDO::FETCH_ASSOC);

        // 2. If an old photo exists and is stored locally, delete it from disk
        if ($existingProfile && !empty($existingProfile['profile_photo'])) {
            // $oldFilePath = __DIR__ . '/uploads/' . $existingProfile['profile_photo']; // Apne folder path ke mutabiq adjust karein
            $oldFilePath = $_SERVER['DOCUMENT_ROOT'] .$existingProfile['profile_photo']; // Apne folder path ke mutabiq adjust karein
            error_log("oldFilePath".$oldFilePath);
            if (file_exists($oldFilePath)) {
                error_log("==========exists================");
                unlink($oldFilePath);
            }
        }

        // 3. Proceed to save the new uploaded file name/path into the database...


        $publicPath = '/bookflow/uploads/profile/' . $fileName;

        $statement = $db->prepare(
            'UPDATE professional_profiles
             SET profile_photo = :profile_photo, updated_at = NOW()
             WHERE user_id = :user_id'
        );

        $statement->execute([
            'profile_photo' => $publicPath,
            'user_id' => $userId,
        ]);

        respond([
            'success' => true,
            'profile_photo' => $publicPath,
        ]);
    }

    /*
     * --------------------------------------------------------
     * Services
     * --------------------------------------------------------
     */
    if ($route === 'services' && $method === 'GET') {
        $userId = requireAuth($jwt);

        $statement = $db->prepare(
            'SELECT *
             FROM services
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );

        $statement->execute(['user_id' => $userId]);

        respond([
            'success' => true,
            'services' => $statement->fetchAll(),
        ]);
    }

    if ($route === 'services' && $method === 'POST') {
        $userId = requireAuth($jwt);
        $body = requestBody();

        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $duration = (int) ($body['duration_minutes'] ?? 0);
        $price = (float) ($body['price'] ?? 0);

        if ($name === '') {
            fail('Service name is required.');
        }

        if ($duration < 5 || $duration > 1440) {
            fail('Service duration must be between 5 and 1440 minutes.');
        }

        if ($price < 0) {
            fail('Service price cannot be negative.');
        }

        $statement = $db->prepare(
            'INSERT INTO services
                (user_id, name, description, duration_minutes, price, is_active, created_at, updated_at)
             VALUES
                (:user_id, :name, :description, :duration, :price, 1, NOW(), NOW())'
        );

        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'duration' => $duration,
            'price' => $price,
        ]);

        respond([
            'success' => true,
            'message' => 'Service created.',
            'service_id' => (int) $db->lastInsertId(),
        ], 201);
    }

    if (preg_match('#^services/(\d+)$#', $route, $matches)) {
        $userId = requireAuth($jwt);
        $serviceId = (int) $matches[1];

        if ($method === 'PUT') {
            $body = requestBody();

            $statement = $db->prepare(
                'UPDATE services
                 SET name = :name,
                     description = :description,
                     duration_minutes = :duration,
                     price = :price,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );

            $statement->execute([
                'name' => trim((string) ($body['name'] ?? '')),
                'description' => trim((string) ($body['description'] ?? '')),
                'duration' => (int) ($body['duration_minutes'] ?? 30),
                'price' => (float) ($body['price'] ?? 0),
                'id' => $serviceId,
                'user_id' => $userId,
            ]);

            respond([
                'success' => true,
                'message' => 'Service updated.',
            ]);
        }

        if ($method === 'DELETE') {
            $statement = $db->prepare(
                'UPDATE services
                 SET is_active = 0, updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );

            $statement->execute([
                'id' => $serviceId,
                'user_id' => $userId,
            ]);

            respond([
                'success' => true,
                'message' => 'Service removed.',
            ]);
        }
    }

    /*
     * --------------------------------------------------------
     * Availability
     * --------------------------------------------------------
     */
    if ($route === 'availability' && $method === 'GET') {
        $userId = requireAuth($jwt);

        $statement = $db->prepare(
            'SELECT day_of_week, start_time, end_time, is_available
             FROM availability_rules
             WHERE user_id = :user_id
             ORDER BY day_of_week'
        );

        $statement->execute(['user_id' => $userId]);
        $rules = $statement->fetchAll(PDO::FETCH_ASSOC);

        error_log("Availability Rules: " . json_encode($rules));
        respond([
            'success' => true,
            // 'rules' => $statement->fetchAll(),
            'rules' => $rules ,
        ]);
    }

    if ($route === 'availability' && $method === 'PUT') {
        $userId = requireAuth($jwt);
        $body = requestBody();
        $rules = $body['rules'] ?? [];

        if (!is_array($rules)) {
            fail('Availability rules must be an array.');
        }

        $db->beginTransaction();

        try {
            $delete = $db->prepare(
                'DELETE FROM availability_rules
                 WHERE user_id = :user_id'
            );

            $delete->execute(['user_id' => $userId]);

            $insert = $db->prepare(
                'INSERT INTO availability_rules
                    (user_id, day_of_week, start_time, end_time, is_available, created_at, updated_at)
                 VALUES
                    (:user_id, :day_of_week, :start_time, :end_time, :is_available, NOW(), NOW())'
            );

            foreach ($rules as $rule) {
                $day = (int) ($rule['day_of_week'] ?? -1);
                $start = (string) ($rule['start_time'] ?? '09:00');
                $end = (string) ($rule['end_time'] ?? '17:00');
                $available = !empty($rule['is_available']) ? 1 : 0;

                if ($day < 0 || $day > 6) {
                    continue;
                }

                $insert->execute([
                    'user_id' => $userId,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_available' => $available,
                ]);
            }

            $db->commit();

            respond([
                'success' => true,
                'message' => 'Availability saved.',
            ]);
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /*
     * --------------------------------------------------------
     * Appointments
     * --------------------------------------------------------
     */
    // if ($route === 'appointments' && $method === 'GET') {
    //     $userId = requireAuth($jwt);

    //     $statement = $db->prepare(
    //         'SELECT
    //             a.*, c.*, 
    //             s.name AS service_name
    //          FROM appointments a
    //          INNER JOIN services s ON s.id = a.service_id
    //          INNER JOIN clients c ON c.id = a.client_id
    //          WHERE a.user_id = :user_id
    //          ORDER BY a.appointment_date DESC, a.start_time DESC'
    //     );

    //     $statement->execute(['user_id' => $userId]);

    //     respond([
    //         'success' => true,
    //         'appointments' => $statement->fetchAll(),
    //     ]);
    // }
 if ($route === 'appointments' && $method === 'GET') {
        $userId = requireAuth($jwt);
        $dateFilter = trim($_GET['date'] ?? '');
        $idFilter = trim($_GET['id'] ?? '');
        $nameFilter = trim($_GET['name'] ?? '');
        $phoneFilter = trim($_GET['phone'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        
        error_log("=============" . $dateFilter . " | page=" . ($_GET['page'] ?? 1) . " | limit=" . ($_GET['limit'] ?? 10));

        // Pagination parameters (guaranteed integers)
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        // 1. Fetch real counts for the user in a single query
        if ($dateFilter == '') {
        $statsSql = 'SELECT
            SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) AS today_appointment,
            SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END) AS confirmed_appointments,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_appointments,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_appointments,
            SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_appointments
        FROM appointments
        WHERE user_id = :user_id' ;

        $statsStatement = $db->prepare($statsSql);
        $statsStatement->execute(['user_id' => $userId]);
        }else{
            $statsSql = 'SELECT
            SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) AS today_appointment,
            SUM(CASE WHEN status = "confirmed" AND appointment_date = :date_conf THEN 1 ELSE 0 END) AS confirmed_appointments,
            SUM(CASE WHEN status = "pending" AND appointment_date = :date_pend THEN 1 ELSE 0 END) AS pending_appointments,
            SUM(CASE WHEN status = "completed" AND appointment_date = :date_comp THEN 1 ELSE 0 END) AS completed_appointments,
            SUM(CASE WHEN status = "cancelled" AND appointment_date = :date_canc THEN 1 ELSE 0 END) AS cancelled_appointments
        FROM appointments
        WHERE user_id = :user_id' ;

        $statsStatement = $db->prepare($statsSql);
        $statsStatement->execute([
            'user_id' => $userId,
            'date_conf' => $dateFilter,
            'date_pend' => $dateFilter,
            'date_canc' => $dateFilter,
            'date_comp' => $dateFilter,
            ]);
        }
        $stats = $statsStatement->fetch(PDO::FETCH_ASSOC);

        // 2. Fetch the paginated appointments list
        $sql = 'SELECT COUNT(*) OVER () AS total_count,
                a.*, 
                c.id AS client_id, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                s.name AS service_name
             FROM appointments a
             INNER JOIN services s ON s.id = a.service_id
             INNER JOIN clients c ON c.id = a.client_id
             WHERE a.user_id = :user_id';

        if ($dateFilter !== '') {
            $sql .= ' AND a.appointment_date = :appointment_date';
        }

        if ($idFilter !== '') {
            $sql .= ' AND a.id LIKE :appointment_id';
        }

        if ($nameFilter !== '') {
            $sql .= ' AND c.name LIKE :appointment_client_name';
        }

        if ($phoneFilter !== '') {
            $sql .= ' AND c.phone LIKE :appointment_phone';
        }

        if ($statusFilter !== '') {
            error_log($statusFilter);
            $sql .= ' AND a.status = :appointment_status';
        }



        $sql .= " ORDER BY a.appointment_date DESC, a.start_time DESC
                  LIMIT $limit OFFSET $offset";
        
        $statement = $db->prepare($sql);

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        if ($dateFilter !== '') {
            $statement->bindValue(':appointment_date', $dateFilter, PDO::PARAM_STR);
        }
        if ($idFilter !== '') {
            $statement->bindValue(':appointment_id', '%' . $idFilter . '%', PDO::PARAM_STR);
        }
        if ($nameFilter !== '') {
            $statement->bindValue(':appointment_client_name', '%' . $nameFilter . '%', PDO::PARAM_STR);
        }
        if ($phoneFilter !== '') {
            $statement->bindValue(':appointment_phone', '%' . $phoneFilter . '%', PDO::PARAM_STR);
        }
        if ($statusFilter !== '') {
            $statement->bindValue(':appointment_status', $statusFilter, PDO::PARAM_STR);
        }
        
        $statement->execute();

        $appointments = $statement->fetchAll(PDO::FETCH_ASSOC);

        $totalCount = !empty($appointments)
            ? (int)$appointments[0]['total_count']
            : 0;

        foreach ($appointments as &$appointment) {
            unset($appointment['total_count']);
        }
        unset($appointment);

        respond([
            'success' => true,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int)ceil($totalCount / $limit)),
            'today_appointment' => (int) ($stats['today_appointment'] ?? 0),
            'confirmed_appointments' => (int) ($stats['confirmed_appointments'] ?? 0),
            'pending_appointments' => (int) ($stats['pending_appointments'] ?? 0),
            'completed_appointments' => (int) ($stats['completed_appointments'] ?? 0),
            'cancelled_appointments' => (int) ($stats['cancelled_appointments'] ?? 0),
            'appointments' => $appointments,
        ]);
    }
    // if ($route === 'appointments' && $method === 'GET') {
    //     // /appointments?date=2026-09-24&page=1&limit=10
    //     $userId = requireAuth($jwt);

    //     // Pagination parameters
    //     $page = max(1, (int) ($_GET['page'] ?? 1));
    //     $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
    //     $offset = ($page - 1) * $limit;

    //     // Optional date filter (Expected format: YYYY-MM-DD)
    //     $dateFilter = trim($_GET['date'] ?? '');
    
    //     // Build base query and parameters
    //     $sql = 'SELECT
    //                 a.*, 
    //                 c.id AS client_id, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
    //                 s.name AS service_name
    //             FROM appointments a
    //             INNER JOIN services s ON s.id = a.service_id
    //             INNER JOIN clients c ON c.id = a.client_id
    //             WHERE a.user_id = :user_id';

    //     if ($dateFilter !== '') {
    //         $sql .= ' AND a.appointment_date = :appointment_date';
    //     }

    //     $sql .= ' ORDER BY a.appointment_date DESC, a.start_time DESC
    //               LIMIT :limit OFFSET :offset';

    //     $statement = $db->prepare($sql);

    //     // Bind parameters safely
    //     $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
    //     if ($dateFilter !== '') {
    //         $statement->bindValue(':appointment_date', $dateFilter, PDO::PARAM_STR);
    //     }
        
    //     $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    //     $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

    //     $statement->execute();

    //     respond([
    //         'success' => true,
    //         'page' => $page,
    //         'limit' => $limit,
    //         'date_filter' => $dateFilter !== '' ? $dateFilter : null,
    //         'appointments' => $statement->fetchAll(PDO::FETCH_ASSOC),
    //     ]);
    // }

    if (preg_match('#^appointments/(\d+)/status$#', $route, $matches)) {
        if ($method !== 'PATCH') {
            fail('Method not allowed.', 405);
        }

        $userId = requireAuth($jwt);
        $appointmentId = (int) $matches[1];
        $body = requestBody();
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        $allowedStatuses = [
            'pending',
            'confirmed',
            'completed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            fail('Invalid appointment status.');
        }

        $statement = $db->prepare(
            'SELECT *
             FROM appointments
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $appointmentId,
            'user_id' => $userId,
        ]);

        $appointment = $statement->fetch();

        if (!$appointment) {
            fail('Appointment not found.', 404);
        }

        $update = $db->prepare(
            'UPDATE appointments
             SET status = :status, updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        $update->execute([
            'status' => $status,
            'id' => $appointmentId,
            'user_id' => $userId,
        ]);

        respond([
            'success' => true,
            'message' => 'Appointment status updated.',
        ]);
    }

    /*
     * --------------------------------------------------------
     * Google Calendar connection
     * --------------------------------------------------------
     */
    if ($route === 'calendar/connect' && $method === 'GET') {
        $userId = requireAuth($jwt);

        $state = bin2hex(random_bytes(32));

        // Store a short-lived OAuth state in the server-side session.
        // This protects the callback from CSRF.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['google_oauth_state'] = [
            'value' => $state,
            'user_id' => $userId,
            'expires_at' => time() + 600,
        ];

        // GoogleCalendarService sets the same state value on the
        // Google OAuth authorization request.
        $authUrl = $google->authorizationUrl($userId, $state);

        respond([
            'success' => true,
            'authorization_url' => $authUrl,
        ]);
    }

    if ($route === 'calendar/callback' && $method === 'GET') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');

        $storedState = $_SESSION['google_oauth_state'] ?? null;

        if (
            !$storedState ||
            !hash_equals((string) $storedState['value'], $state) ||
            (int) $storedState['expires_at'] < time()
        ) {
            fail('Invalid or expired Google OAuth state.', 400);
        }

        if ($code === '') {
            fail('Google did not return an authorization code.', 400);
        }

        $userId = (int) $storedState['user_id'];

        $google->exchangeAuthorizationCode($userId, $code);

        unset($_SESSION['google_oauth_state']);

        header(
            'Location: ' .
            $config['app']['frontend_url'] .
            '/profile?calendar=connected'
        );

        exit;
    }

    if ($route === 'calendar/status' && $method === 'GET') {
        $userId = requireAuth($jwt);

        respond([
            'success' => true,
            'connected' => $google->isConnected($userId),
        ]);
    }

    if ($route === 'calendar/disconnect' && $method === 'POST') {
        $userId = requireAuth($jwt);

        $google->disconnect($userId);

        respond([
            'success' => true,
            'message' => 'Google Calendar disconnected.',
        ]);
    }

    // check slug

    if ($route === 'check-slug' && $method === 'GET') {
        $userId = requireAuth($jwt);    
        $slug = trim($_GET['slug'] ?? '');

        if ($slug === '') {
            respond([
                'success' => false,
                'message' => 'Slug is required.',
            ], 400);
        }

        $sql = 'SELECT EXISTS (
                    SELECT 1
                    FROM professional_profiles
                    WHERE slug = :slug
                      AND id != :user_id
                ) AS slug_exists';

        $statement = $db->prepare($sql);

        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        $statement->execute();

        $slugExists = (bool) $statement->fetchColumn();

        respond([
            'success' => true,
            'slug' => $slug,
            'exists' => $slugExists,
            'available' => !$slugExists,
        ]);
    }


    if ($route === 'cancel-appointment' && $method === 'GET') {

        $userId = requireAuth($jwt);
        $appointmentId = (int) trim($_GET['appointment_id'] ?? '');
        $appointmentSql = 'SELECT status FROM appointments WHERE id = :appointment_id';
        $appointmentStatement = $db->prepare($appointmentSql);
        $appointmentStatement->execute([
            'appointment_id' => $appointmentId,
        ]);

        $appointmentStats = $appointmentStatement->fetch(PDO::FETCH_ASSOC);
        $appointmentStatus = (string) ($appointmentStats['external_event_id'] ?? '');
        if($appointmentStatus === "cancelled" or $appointmentStatus === "completed"){
            return([
                'success' => false,
                'message' => 'This Appointment is already '.$appointmentStatus. '.',
            ]);
        }
        try {
            // Transaction start karein taaki data safe rahe
            $db->beginTransaction();

            // 1. Calendar event ID nikaalein database se
            $statsSql = 'SELECT external_event_id FROM calendar_events WHERE appointment_id = :appointment_id';
            $statsStatement = $db->prepare($statsSql);
            $statsStatement->execute([
                'appointment_id' => $appointmentId,
            ]);

            $stats = $statsStatement->fetch(PDO::FETCH_ASSOC);
            $eventId = (string) ($stats['external_event_id'] ?? '');

            // 2. Agar Google Calendar event ID hai, toh use delete karein
            $calendarDeleted = false;
            if (!empty($eventId)) {
                $calendarDeleted = $google->deleteEvent($userId, $eventId);
            }

            // 3. Database mein appointment ka status 'cancelled' update karein
            $updateSql = "UPDATE appointments SET status = 'cancelled' WHERE id = :appointment_id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                'appointment_id' => $appointmentId
            ]);

            $db->commit();

            error_log("Appointment ID {$appointmentId} cancelled successfully.");

            respond([
                'success' => true,
                'message' => 'This appointment is cancelled successfully',
                'calendar_synced' => $calendarDeleted
            ]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log("Cancel Appointment Error: " . $e->getMessage());

            respond([
                'success' => false,
                'message' => 'Failed to cancel appointment: ' . $e->getMessage()
            ], 500);
        }
    }
    

    if ($route === 'mark-as-done' && $method === 'GET') {
        $userId = requireAuth($jwt);
        $appointmentId = (int) trim($_GET['appointment_id'] ?? '');


        $appointmentSql = 'SELECT status FROM appointments WHERE id = :appointment_id';
        $appointmentStatement = $db->prepare($appointmentSql);
        $appointmentStatement->execute([
            'appointment_id' => $appointmentId,
        ]);

        $appointmentStats = $appointmentStatement->fetch(PDO::FETCH_ASSOC);
        $appointmentStatus = (string) ($appointmentStats['external_event_id'] ?? '');
        if($appointmentStatus === "cancelled" or $appointmentStatus === "completed"){
            return([
                'success' => false,
                'message' => 'This Appointment is already '.$appointmentStatus. '.',
            ]);
        }
        try{
        // 3. Database mein appointment ka status 'checkup-done' update karein
        $updateSql = "UPDATE appointments SET status = 'completed' WHERE id = :appointment_id";
        $updateStmt = $db->prepare($updateSql);
        $updateStmt->execute([
            'appointment_id' => $appointmentId
        ]);
         respond([
                'success' => true,
                'message' => 'This appointment is mark as done successfully',
                // 'calendar_synced' => $calendarDeleted
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log("Mark as done Appointment Error: " . $e->getMessage());

            respond([
                'success' => false,
                'message' => 'Failed to mark as done this appointment: ' . $e->getMessage()
            ], 500);
        }
    }


    /*
     * --------------------------------------------------------
     * Public profile and booking
     * --------------------------------------------------------
     */
    if (preg_match('#^public/([^/]+)$#', $route, $matches)) {
        if ($method !== 'GET') {
            fail('Method not allowed.', 405);
        }

        $slug = $matches[1];

        $statement = $db->prepare(
            'SELECT *
             FROM professional_profiles
             WHERE slug = :slug
             LIMIT 1'
        );

        $statement->execute(['slug' => $slug]);
        $profile = $statement->fetch();

        if (!$profile) {
            fail('Professional profile not found.', 404);
        }

        $services = $db->prepare(
            'SELECT id, name, description, duration_minutes, price
             FROM services
             WHERE user_id = :user_id AND is_active = 1
             ORDER BY name'
        );

        $services->execute(['user_id' => $profile['user_id']]);

        respond([
            'success' => true,
            'profile' => $profile,
            'services' => $services->fetchAll(),
        ]);
    }

    if (preg_match('#^public/([^/]+)/slots$#', $route, $matches)) {
        if ($method !== 'GET') {
            fail('Method not allowed.', 405);
        }

        $slug = $matches[1];
        $date = (string) ($_GET['date'] ?? '');
        $serviceId = (int) ($_GET['service_id'] ?? 0);

        $profileStatement = $db->prepare(
            'SELECT *
             FROM professional_profiles
             WHERE slug = :slug
             LIMIT 1'
        );

        $profileStatement->execute(['slug' => $slug]);
        $profile = $profileStatement->fetch();

        if (!$profile) {
            fail('Professional profile not found.', 404);
        }

        $professionalStatement = $db->prepare(
            'SELECT id, name, email
             FROM users
             WHERE id = :user_id
             LIMIT 1'
        );

        $professionalStatement->execute([
            'user_id' => $profile['user_id'],
        ]);

        $professional = $professionalStatement->fetch();

        if (!$professional || !validateEmail((string) $professional['email'])) {
            fail('Professional email address is not configured.', 500);
        }

        $serviceStatement = $db->prepare(
            'SELECT *
             FROM services
             WHERE id = :id
               AND user_id = :user_id
               AND is_active = 1
             LIMIT 1'
        );

        $serviceStatement->execute([
            'id' => $serviceId,
            'user_id' => $profile['user_id'],
        ]);

        $service = $serviceStatement->fetch();

        if (!$service) {
            fail('Service not found.', 404);
        }

        $dateObject = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone($profile['timezone'] ?: 'Asia/Kolkata')
        );

        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            fail('Invalid appointment date.');
        }

        $dayOfWeek = (int) $dateObject->format('w');

        $availability = $db->prepare(
            'SELECT *
             FROM availability_rules
             WHERE user_id = :user_id
               AND day_of_week = :day
               AND is_available = 1
             LIMIT 1'
        );

        $availability->execute([
            'user_id' => $profile['user_id'],
            'day' => $dayOfWeek,
        ]);

        $rule = $availability->fetch();

        if (!$rule) {
            respond([
                'success' => true,
                'slots' => [],
            ]);
        }

        $existing = $db->prepare(
            'SELECT start_time, end_time
             FROM appointments
             WHERE user_id = :user_id
               AND appointment_date = :appointment_date
               AND status IN ("pending", "confirmed")
             ORDER BY start_time'
        );

        $existing->execute([
            'user_id' => $profile['user_id'],
            'appointment_date' => $date,
        ]);

        $booked = $existing->fetchAll();

        $timezone = new DateTimeZone(
            $profile['timezone'] ?: 'Asia/Kolkata'
        );

        $cursor = new DateTimeImmutable(
            $date . ' ' . $rule['start_time'],
            $timezone
        );

        $closingTime = new DateTimeImmutable(
            $date . ' ' . $rule['end_time'],
            $timezone
        );

        $duration = (int) $service['duration_minutes'];
        $slotInterval = 15;
        $slots = [];

        while ($cursor < $closingTime) {
            $slotEnd = $cursor->modify("+{$duration} minutes");

            if ($slotEnd > $closingTime) {
                break;
            }

            $overlaps = false;

            foreach ($booked as $booking) {
                $bookingStart = new DateTimeImmutable(
                    $date . ' ' . $booking['start_time'],
                    $timezone
                );

                $bookingEnd = new DateTimeImmutable(
                    $date . ' ' . $booking['end_time'],
                    $timezone
                );

                if ($cursor < $bookingEnd && $slotEnd > $bookingStart) {
                    $overlaps = true;
                    break;
                }
            }

            // Do not expose past slots for today's date.
            if (
                $dateObject->format('Y-m-d') ===
                (new DateTimeImmutable('now', $timezone))->format('Y-m-d') &&
                $cursor <= new DateTimeImmutable('now', $timezone)
            ) {
                $overlaps = true;
            }

            if (!$overlaps) {
                $slots[] = [
                    'start_time' => $cursor->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                ];
            }

            $cursor = $cursor->modify("+{$slotInterval} minutes");
        }

        respond([
            'success' => true,
            'slots' => $slots,
        ]);
    }

    if (preg_match('#^public/([^/]+)/book$#', $route, $matches)) {
        if ($method !== 'POST') {
            fail('Method not allowed.', 405);
        }

        $slug = $matches[1];
        $body = requestBody();

        $date = trim((string) ($body['appointment_date'] ?? ''));
        $startTime = trim((string) ($body['start_time'] ?? ''));
        $clientName = trim((string) ($body['client_name'] ?? ''));
        $clientEmail = strtolower(
            trim((string) ($body['client_email'] ?? ''))
        );
        $clientPhone = trim((string) ($body['client_phone'] ?? ''));
        $clientNotes = trim((string) ($body['client_notes'] ?? ''));
        $serviceId = (int) ($body['service_id'] ?? 0);

        if ($clientName === '') {
            fail('Client name is required.');
        }

        if (!validateEmail($clientEmail)) {
            fail('A valid client email is required.');
        }

        if ($serviceId <= 0 || $date === '' || $startTime === '') {
            fail('Service, date and time are required.');
        }

        $profileStatement = $db->prepare(
            'SELECT *
             FROM professional_profiles
             WHERE slug = :slug
             LIMIT 1'
        );

        $profileStatement->execute(['slug' => $slug]);
        $profile = $profileStatement->fetch();

        if (!$profile) {
            fail('Professional profile not found.', 404);
        }

        $professionalStatement = $db->prepare(
            'SELECT id, name, email
             FROM users
             WHERE id = :user_id
             LIMIT 1'
        );

        $professionalStatement->execute([
            'user_id' => $profile['user_id'],
        ]);

        $professional = $professionalStatement->fetch();

        if (!$professional || !validateEmail((string) $professional['email'])) {
            fail('Professional email address is not configured.', 500);
        }

        $serviceStatement = $db->prepare(
            'SELECT *
             FROM services
             WHERE id = :id
               AND user_id = :user_id
               AND is_active = 1
             LIMIT 1'
        );

        $serviceStatement->execute([
            'id' => $serviceId,
            'user_id' => $profile['user_id'],
        ]);

        $service = $serviceStatement->fetch();

        if (!$service) {
            fail('Service not found.', 404);
        }

        $timezone = new DateTimeZone(
            $profile['timezone'] ?: 'Asia/Kolkata'
        );

        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' ' . $startTime,
            $timezone
        );

        if (!$start || $start->format('Y-m-d H:i') !== $date . ' ' . $startTime) {
            fail('Invalid appointment date or time.');
        }

        $end = $start->modify(
            '+' . (int) $service['duration_minutes'] . ' minutes'
        );

        if ($start <= new DateTimeImmutable('now', $timezone)) {
            fail('This appointment time is already in the past.');
        }

        $db->beginTransaction();

        try {
            /*
             * Final server-side overlap check.
             *
             * This is required even though the frontend displays available
             * slots, because two clients can submit the same slot at nearly
             * the same time.
             */
            $overlap = $db->prepare(
                'SELECT id
                 FROM appointments
                 WHERE user_id = :user_id
                   AND appointment_date = :appointment_date
                   AND status IN ("pending", "confirmed")
                   AND start_time < :end_time
                   AND end_time > :start_time
                 FOR UPDATE'
            );

            $overlap->execute([
                'user_id' => $profile['user_id'],
                'appointment_date' => $date,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ]);

            if ($overlap->fetch()) {
                $db->rollBack();
                fail(
                    'This time has just been booked. Please choose another slot.',
                    409
                );
            }

            $clientStatement = $db->prepare(
                'SELECT id
                 FROM clients
                 WHERE user_id = :user_id
                   AND (email = :email
                   OR phone = :phone)
                 LIMIT 1'
            );

            $clientStatement->execute([
                'user_id' => $profile['user_id'],
                'email' => $clientEmail,
                'phone' => $clientPhone,
            ]);

            $client = $clientStatement->fetch();

            if ($client) {
                $clientId = (int) $client['id'];

                $updateClient = $db->prepare(
                    'UPDATE clients
                    --  SET name = :name, phone = :phone, updated_at = NOW()
                     SET name = :name, email = :email, phone = :phone, updated_at = NOW()
                     WHERE id = :id'
                );

                $updateClient->execute([
                    'name' => $clientName,
                    'phone' => $clientPhone,
                    'email' => $clientEmail,
                    'id' => $clientId,
                ]);
            } else {
                $insertClient = $db->prepare(
                    'INSERT INTO clients
                        (user_id, name, email, phone, created_at, updated_at)
                     VALUES
                        (:user_id, :name, :email, :phone, NOW(), NOW())'
                );

                $insertClient->execute([
                    'user_id' => $profile['user_id'],
                    'name' => $clientName,
                    'email' => $clientEmail,
                    'phone' => $clientPhone,
                ]);

                $clientId = (int) $db->lastInsertId();
            }

            $appointmentStatement = $db->prepare(
                'INSERT INTO appointments
                    (user_id, client_id, service_id, appointment_date,
                     start_time, end_time, status, notes, created_at, updated_at)
                 VALUES
                    (:user_id, :client_id, :service_id, :appointment_date,
                     :start_time, :end_time, "confirmed", :notes, NOW(), NOW())'
            );

            $appointmentStatement->execute([
                'user_id' => $profile['user_id'],
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'appointment_date' => $date,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'notes' => trim((string) ($body['client_notes'] ?? '')),
            ]);

            $appointmentId = (int) $db->lastInsertId();

            $db->commit();

            /*
             * Google Calendar is intentionally called after the DB
             * transaction. The appointment remains valid even if Google
             * temporarily fails, so external API outages do not roll back
             * the booking itself.
             */
            $googleEventId = null;

            try {
                $googleEventId = $google->createAppointmentEvent(
                    (int) $profile['user_id'],
                    [
                        'service_name' => $service['name'],
                        'client_name' => $clientName,
                        'client_email' => $clientEmail,
                        'client_phone' => $clientPhone,
                        'client_notes' => $clientNotes,
                        'appointment_date' => $date,
                        'start_time' => $start->format('H:i:s'),
                        'end_time' => $end->format('H:i:s'),
                    ],
                    $profile
                );

                if ($googleEventId) {
                    $eventStatement = $db->prepare(
                        'INSERT INTO calendar_events
                            (appointment_id, provider, external_event_id, created_at)
                         VALUES
                            (:appointment_id, "google", :external_event_id, NOW())
                         ON DUPLICATE KEY UPDATE
                            external_event_id = VALUES(external_event_id)'
                    );

                    $eventStatement->execute([
                        'appointment_id' => $appointmentId,
                        'external_event_id' => $googleEventId,
                    ]);
                }
            } catch (Throwable $exception) {
                error_log(
                    'BookFlow Google Calendar error: ' .
                    $exception->getMessage()
                );
                // Booking is already committed. A later retry/sync job can
                // reconcile a missing Google event.
            }

            $calendarTitle =
                $service['name'] . ' - ' . $profile['name'];

            $calendarDetails =
                "Appointment with {$profile['name']}\n" .
                "Service: {$service['name']}\n" .
                "Professional: {$profile['business_name']}";

            // $googleCalendarUrl = publicCalendarUrl(
            //     $calendarTitle,
            //     $start->format('Y-m-d H:i:s'),
            //     $end->format('Y-m-d H:i:s'),
            //     $calendarDetails,
            //     $profile['address'] ?? ''
            // );

            $googleCalendarUrl = publicCalendarUrl(
                $calendarTitle,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $calendarDetails,
                $profile['address'] ?? '',
                $timezone->getName()
            );

            $appointmentMailData = [
                'service_name' => $service['name'],
                'professional_name' => $profile['name'],
                'business_name' => $profile['business_name'] ?? '',
                'client_name' => $clientName,
                'client_email' => $clientEmail,
                'client_phone' => $clientPhone,
                'client_notes' => $clientNotes,
                'date_display' => $start->format('l, d M Y'),
                'time_display' => $start->format('h:i A') . ' - ' . $end->format('h:i A') . ' (' . $timezone->getName() . ')',
                'address' => $profile['address'] ?? '',
                'google_calendar_url' => $googleCalendarUrl,
            ];

            $mailSent = [
                'client' => false,
                'professional' => false,
            ];

            try {
                $emailService->sendAppointmentConfirmation(
                    $clientEmail,
                    $clientName,
                    'Appointment confirmed - ' . $service['name'],
                    $appointmentMailData,
                    true
                );
                $mailSent['client'] = true;
            } catch (Throwable) {
                // Email failure must not invalidate an already-created booking.
            }

            try {
                $emailService->sendAppointmentConfirmation(
                    (string) $professional['email'],
                    (string) $professional['name'],
                    'New appointment - ' . $service['name'],
                    $appointmentMailData,
                    false
                );
                $mailSent['professional'] = true;
            } catch (Throwable) {
                // Email failure must not invalidate an already-created booking.
            }

            respond([
                'success' => true,
                'message' => 'Appointment booked successfully.',
                'appointment_id' => $appointmentId,
                'google_event_created' => $googleEventId !== null,
                'email_sent' => $mailSent,
                'google_calendar_url' => $googleCalendarUrl,
            ], 201);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    fail('Route not found.', 404);
} catch (InvalidArgumentException $exception) {
    fail($exception->getMessage(), 400);
} catch (PDOException $exception) {
    error_log('BookFlow database error: ' . $exception->getMessage());
    fail('A database error occurred.', 500);
} catch (Throwable $exception) {
    error_log('BookFlow API error: ' . $exception->getMessage());

    if ($config['app']['env'] === 'local') {
        fail($exception->getMessage(), 500);
    }

    fail('An unexpected server error occurred.', 500);
}
