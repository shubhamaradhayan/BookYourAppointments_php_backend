<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class EmailService
{
    public function __construct(private readonly array $config)
    {
    }

    public function sendAppointmentConfirmation(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        array $appointment,
        bool $isClient = false
    ): void {
        if (!$this->config['enabled']) {
            return;
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email recipient.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];

        if ($this->config['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $this->buildHtml($recipientName, $appointment, $isClient);
        $mail->AltBody = $this->buildText($recipientName, $appointment, $isClient);
        $mail->send();
    }

    private function buildHtml(string $recipientName, array $appointment, bool $isClient): string
    {
        $name = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $professional = htmlspecialchars((string) $appointment['professional_name'], ENT_QUOTES, 'UTF-8');
        $business = htmlspecialchars((string) ($appointment['business_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $appointment['service_name'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) $appointment['date_display'], ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars((string) $appointment['time_display'], ENT_QUOTES, 'UTF-8');
        $client = htmlspecialchars((string) $appointment['client_name'], ENT_QUOTES, 'UTF-8');
        $clientEmail = htmlspecialchars((string) $appointment['client_email'], ENT_QUOTES, 'UTF-8');
        $clientPhone = htmlspecialchars((string) ($appointment['client_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars((string) ($appointment['address'] ?? ''), ENT_QUOTES, 'UTF-8');
        $calendarUrl = htmlspecialchars((string) ($appointment['google_calendar_url'] ?? ''), ENT_QUOTES, 'UTF-8');

        $calendarButton = $isClient && $calendarUrl !== ''
            ? '<p><a href="' . $calendarUrl . '" style="display:inline-block;padding:12px 18px;background:#111827;color:#fff;text-decoration:none;border-radius:8px;">Add to Google Calendar</a></p>'
            : '';

        $roleMessage = $isClient
            ? 'Your appointment has been successfully booked.'
            : 'A new appointment has been booked for you.';

        return '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f3f4f6;padding:24px;color:#111827;">'
            . '<div style="max-width:620px;margin:auto;background:#fff;border-radius:12px;padding:28px;">'
            . '<h2 style="margin-top:0;">Appointment Confirmation</h2>'
            . '<p>Hi ' . $name . ',</p>'
            . '<p>' . $roleMessage . '</p>'
            . '<table cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;">'
            . '<tr><td><strong>Service</strong></td><td>' . $service . '</td></tr>'
            . '<tr><td><strong>Date</strong></td><td>' . $date . '</td></tr>'
            . '<tr><td><strong>Time</strong></td><td>' . $time . '</td></tr>'
            . '<tr><td><strong>Professional</strong></td><td>' . $professional . '</td></tr>'
            . ($business !== '' ? '<tr><td><strong>Business</strong></td><td>' . $business . '</td></tr>' : '')
            . ($address !== '' ? '<tr><td><strong>Address</strong></td><td>' . $address . '</td></tr>' : '')
            . ($isClient ? '' : '<tr><td><strong>Client</strong></td><td>' . $client . '</td></tr>')
            . ($isClient ? '' : '<tr><td><strong>Client email</strong></td><td>' . $clientEmail . '</td></tr>')
            . ($clientPhone !== '' && !$isClient ? '<tr><td><strong>Client phone</strong></td><td>' . $clientPhone . '</td></tr>' : '')
            . '</table>'
            . $calendarButton
            . '<p style="color:#6b7280;font-size:13px;">This is an automated notification from BookFlow.</p>'
            . '</div></body></html>';
    }

    private function buildText(string $recipientName, array $appointment, bool $isClient): string
    {
        $lines = [
            'Hi ' . $recipientName . ',',
            '',
            $isClient
                ? 'Your appointment has been successfully booked.'
                : 'A new appointment has been booked for you.',
            '',
            'Service: ' . $appointment['service_name'],
            'Date: ' . $appointment['date_display'],
            'Time: ' . $appointment['time_display'],
            'Professional: ' . $appointment['professional_name'],
        ];

        if (!$isClient) {
            $lines[] = 'Client: ' . $appointment['client_name'];
            $lines[] = 'Client email: ' . $appointment['client_email'];
            if (!empty($appointment['client_phone'])) {
                $lines[] = 'Client phone: ' . $appointment['client_phone'];
            }
        }

        if (!empty($appointment['google_calendar_url']) && $isClient) {
            $lines[] = '';
            $lines[] = 'Add to Google Calendar: ' . $appointment['google_calendar_url'];
        }

        return implode("\n", $lines);
    }
}
