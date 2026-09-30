# Google Calendar Integration

## OAuth flow

BookFlow uses the Google OAuth 2.0 web-server flow.

```text
BookFlow
   |
   | authorization URL
   v
Google OAuth
   |
   | authorization code
   v
calendar/callback
   |
   | access + refresh token
   v
Encrypted MySQL storage
```

The application requests Calendar event and free/busy access.

The backend keeps the Google refresh token encrypted so it can refresh the
access token when the professional is not actively using the application.

## Required redirect URI

Local:

```text
http://localhost/bookflow/api/index.php?route=calendar/callback
```

Production example:

```text
https://your-domain.com/bookflow/api/index.php?route=calendar/callback
```

The URI must exactly match the URI configured in Google Cloud.

## Professional calendar

The application creates events in:

```text
primary
```

by default.

You can change this through:

```text
GOOGLE_CALENDAR_ID
```

## Client calendar

After booking, the API also returns:

```text
google_calendar_url
```

The frontend can display:

```text
Add to Google Calendar
```

This is a calendar template link and does not require the client to authorize
BookFlow.

## Important production behavior

A database booking is the source of truth.

Google Calendar is an external integration.

If Google is temporarily unavailable:

1. The appointment remains stored in MySQL.
2. The API does not tell the client that the booking failed merely because
   Google failed.
3. A background retry/synchronization worker should be added for guaranteed
   eventual Google synchronization.

That worker can be implemented later with cron + a small `calendar_sync_jobs`
table or Celery if the backend is migrated to Django.
