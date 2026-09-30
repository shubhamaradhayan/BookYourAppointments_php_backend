# XAMPP Deployment

## Linux XAMPP

Assume:

```text
/opt/lampp/htdocs/bookflow
```

Start Apache and MySQL:

```bash
sudo /opt/lampp/lampp start
```

Check:

```text
http://localhost/bookflow/api/index.php?route=health
```

Expected:

```json
{
  "success": true,
  "service": "BookFlow API",
  "status": "ok"
}
```

## Composer

Install Composer on the machine if it is not already installed.

Then:

```bash
cd /opt/lampp/htdocs/bookflow
composer install --no-dev --optimize-autoloader
```

## PHP extensions

The backend needs:

- PDO
- pdo_mysql
- openssl
- json
- fileinfo
- curl

Check:

```bash
/opt/lampp/bin/php -m
```

## Permissions

The application needs to write to:

```text
uploads/profile/
```

Example:

```bash
sudo chown -R daemon:daemon uploads/profile
sudo chmod -R 755 uploads/profile
```

Use the Apache user appropriate for your Linux installation.

## React development

The React Vite development server can proxy:

```text
/bookflow-api
```

to:

```text
http://localhost/bookflow/api
```

so the frontend and XAMPP backend can run together during development.

## Email notifications

BookFlow sends an appointment confirmation email to both:

1. The client who booked the appointment.
2. The professional/user who owns the booking profile.

Email is sent through SMTP using PHPMailer. Configure these values in `.env`:

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

For Gmail, use a Google App Password rather than the normal Gmail password.
For Hostinger or another SMTP provider, replace the host, port, username and
password with the SMTP credentials supplied by that provider.

Install dependencies after copying the backend:

```bash
composer install --no-dev --optimize-autoloader
```

Email errors are intentionally caught after the appointment has been committed,
so an SMTP outage does not make a valid appointment disappear. The API response
contains `email_sent.client` and `email_sent.professional` so development/testing
can confirm whether each notification was sent.
