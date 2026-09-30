# BookFlow Backend — Core PHP + MySQL + JWT + Google Calendar

Production-oriented backend for the BookFlow appointment SaaS.

The API routes remain in **one file** (`api/index.php`) to keep debugging
simple on XAMPP/shared hosting. External integrations and security utilities
are kept in small, readable classes.

## Stack

- PHP 8.1+
- MySQL 8+
- PDO
- JWT access tokens
- Rotating refresh tokens
- Google OAuth 2.0
- Google Calendar API
- Composer
- Apache / XAMPP
- REST/JSON

## Important architecture

```text
React
   |
   | Authorization: Bearer <JWT>
   v
api/index.php
   |
   +-- JwtService
   +-- Database
   +-- GoogleCalendarService
   +-- CryptoService
   |
   v
MySQL
```

## 1. Put the backend in XAMPP

Linux XAMPP example:

```bash
sudo cp -r bookflow_backend_jwt_google_calendar /opt/lampp/htdocs/bookflow
```

Or use your normal XAMPP `htdocs` directory.

The API should then be:

```text
http://localhost/bookflow/api/index.php?route=health
```

## 2. Install Composer dependencies

Inside the backend directory:

```bash
composer install --no-dev --optimize-autoloader
```

Google's PHP OAuth documentation recommends the Google API Client Library
for PHP. The project therefore uses Composer for the Google client and JWT
library.

## 3. Configure environment

Copy:

```bash
cp .env.example .env
```

Generate a JWT secret:

```bash
php -r "echo bin2hex(random_bytes(64)), PHP_EOL;"
```

Generate the encryption key:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Put both values into `.env`.

Never commit `.env`.

## 4. Database

If the BookFlow database already exists, do not blindly import the entire
schema into production.

The new JWT/Google Calendar version requires these tables:

```text
refresh_tokens
calendar_connections
calendar_events
```

If the existing tables are already present, compare them with
`database/schema.sql` and migrate only the missing columns/tables.

For a new database:

```bash
mysql -u root -p < database/schema.sql
```

Dummy data:

```bash
mysql -u root -p appointment_saas < database/dummydata.sql
```

## 5. Google Cloud setup

Create a Google Cloud project and enable the Google Calendar API.

Create OAuth credentials:

```text
Google Cloud Console
→ APIs & Services
→ Credentials
→ Create Credentials
→ OAuth client ID
→ Web application
```

Add this exact redirect URI for local XAMPP:

```text
http://localhost/bookflow/api/index.php?route=calendar/callback
```

Put the client ID and client secret into `.env`:

```text
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost/bookflow/api/index.php?route=calendar/callback
```

The redirect URI must exactly match the one configured in Google Cloud.

## 6. Google Calendar behavior

Professional:

```text
Login
  ↓
Profile
  ↓
Connect Google Calendar
  ↓
Google OAuth consent
  ↓
Refresh token stored encrypted in MySQL
```

When a client books:

```text
Client booking
    ↓
MySQL transaction
    ↓
Final overlap check
    ↓
Appointment created
    ↓
Google Calendar event created
    ↓
Client receives Google Calendar template URL
```

The professional's Google Calendar event can include the client's email as
an attendee.

## 7. JWT authentication

Access tokens are short-lived by default:

```text
JWT_ACCESS_TTL=900
```

That is 15 minutes.

Refresh tokens are long-lived:

```text
JWT_REFRESH_TTL=2592000
```

That is 30 days.

Refresh tokens are stored only as SHA-256 hashes in MySQL and are rotated
when used.

Frontend sends:

```http
Authorization: Bearer <access_token>
```

## 8. API routes

### Authentication

```text
POST /api/index.php?route=auth/register
POST /api/index.php?route=auth/login
POST /api/index.php?route=auth/refresh
POST /api/index.php?route=auth/logout
GET  /api/index.php?route=auth/me
```

### Profile

```text
GET  /api/index.php?route=profile
PUT  /api/index.php?route=profile
POST /api/index.php?route=profile/photo
```

### Services

```text
GET    /api/index.php?route=services
POST   /api/index.php?route=services
PUT    /api/index.php?route=services/{id}
DELETE /api/index.php?route=services/{id}
```

### Availability

```text
GET /api/index.php?route=availability
PUT /api/index.php?route=availability
```

### Appointments

```text
GET   /api/index.php?route=appointments
PATCH /api/index.php?route=appointments/{id}/status
```

### Google Calendar

```text
GET  /api/index.php?route=calendar/connect
GET  /api/index.php?route=calendar/callback
GET  /api/index.php?route=calendar/status
POST /api/index.php?route=calendar/disconnect
```

### Public booking

```text
GET  /api/index.php?route=public/{slug}
GET  /api/index.php?route=public/{slug}/slots&date=YYYY-MM-DD&service_id=1
POST /api/index.php?route=public/{slug}/book
```

## Security decisions

- Passwords use PHP `password_hash()`.
- JWT secret is externalized.
- Access tokens expire quickly.
- Refresh tokens are rotated.
- Refresh tokens are stored as hashes, not plaintext.
- Google access/refresh tokens are encrypted at rest.
- OAuth state is checked against a short-lived server-side value.
- SQL uses PDO prepared statements.
- Booking performs a final server-side overlap check.
- Uploaded profile images are MIME-validated.
- PHP execution is denied inside profile uploads.
- Production error responses do not expose internal exception messages.
- CORS is restricted to the configured frontend origin.

## Production checklist

Before production:

- Use HTTPS.
- Use a strong random `JWT_SECRET`.
- Use a strong random `APP_ENCRYPTION_KEY`.
- Use a non-root MySQL account.
- Set `APP_ENV=production`.
- Keep `.env` outside public access where the hosting provider allows it.
- Configure a real production `APP_URL` and `FRONTEND_URL`.
- Configure Google's production redirect URI.
- Complete Google's OAuth consent / verification requirements if applicable.
- Configure PHP upload limits deliberately.
- Configure daily MySQL backups.
- Add application/server monitoring.
- Add a background retry job for Google Calendar events if you need guaranteed
  eventual synchronization after external API outages.


## Sign in with Google

Google Sign-In and Google Calendar are intentionally separate:

```text
Google Sign-In
    ↓
Google ID token
    ↓
backend verification
    ↓
BookFlow JWT access + refresh tokens

Google Calendar
    ↓
explicit OAuth connection from Profile
    ↓
encrypted Calendar refresh token
```

The API endpoint is:

```text
POST /api/index.php?route=auth/google
```

The authenticated account-linking endpoint is:

```text
POST /api/index.php?route=auth/google/link
Authorization: Bearer <access_token>
```

The login endpoint verifies Google's ID token before trusting the Google
account identity. The browser does not get to choose the user ID or email.

An existing password account is not silently merged by matching email. This
avoids accidental account linking. Sign in to that account first and then
link Google through an authenticated linking flow.

## 9. Appointment email notifications

When a public client books an appointment, BookFlow attempts to send two emails:

- **Client:** appointment confirmation + appointment details + Add to Google Calendar button.
- **Professional/user:** new appointment notification + client details.

SMTP is used instead of PHP's basic `mail()` function. Configure `.env`:

```env
MAIL_ENABLED=true
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_EMAIL=your-email@gmail.com
MAIL_FROM_NAME=BookFlow
```

Install the Composer dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

For Gmail SMTP, use a Google App Password. For production, use the SMTP
credentials of the mailbox/domain you want BookFlow to send from.

Email delivery happens after the database booking is committed and Google
Calendar processing, and email failures do not roll back the booking.
