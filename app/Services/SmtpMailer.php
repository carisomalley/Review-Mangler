<?php

namespace App\Services;

use App\Env;

/**
 * Minimal SMTP client (AUTH LOGIN over STARTTLS) — no composer dependency,
 * per the Phase 1 architecture decision (CLAUDE.md §9.1). Hostinger's
 * transactional SMTP (smtp.hostinger.com:587, STARTTLS, AUTH LOGIN) is the
 * expected target; any standard SMTP-with-AUTH provider works the same way.
 *
 * If SMTP_HOST is left blank in .env, falls back to PHP's built-in mail()
 * (works out of the box on many shared hosts, but can't authenticate and is
 * more likely to land in spam) — see CLAUDE.md §9.2's note to test
 * deliverability early, and swap to Resend/Postmark if this isn't enough.
 */
class SmtpMailer
{
    public function send(string $toEmail, string $subject, string $body): bool
    {
        $host = Env::get('SMTP_HOST', '');
        if ($host === '') {
            return $this->sendViaPhpMail($toEmail, $subject, $body);
        }
        return $this->sendViaSmtp($host, $toEmail, $subject, $body);
    }

    private function sendViaPhpMail(string $toEmail, string $subject, string $body): bool
    {
        $fromEmail = Env::get('SMTP_FROM_EMAIL', 'no-reply@localhost');
        $headers = "From: Review Mangler <{$fromEmail}>\r\nContent-Type: text/plain; charset=UTF-8";
        return mail($toEmail, $subject, $body, $headers);
    }

    private function sendViaSmtp(string $host, string $toEmail, string $subject, string $body): bool
    {
        $port = (int) Env::get('SMTP_PORT', '587');
        $user = Env::require('SMTP_USER');
        $pass = Env::require('SMTP_PASS');
        $fromEmail = Env::get('SMTP_FROM_EMAIL', $user);
        $fromName = Env::get('SMTP_FROM_NAME', 'Review Mangler');

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
        if (!$socket) {
            error_log("SMTP connect failed: $errstr ($errno)");
            return false;
        }

        try {
            $this->expect($socket, 220);
            $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
            $this->command($socket, "STARTTLS", 220);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed');
            }

            $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
            $this->command($socket, "AUTH LOGIN", 334);
            $this->command($socket, base64_encode($user), 334);
            $this->command($socket, base64_encode($pass), 235);

            $this->command($socket, "MAIL FROM:<{$fromEmail}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, "DATA", 354);

            $date = date('r');
            $message = "From: {$fromName} <{$fromEmail}>\r\n"
                . "To: <{$toEmail}>\r\n"
                . "Subject: {$subject}\r\n"
                . "Date: {$date}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "\r\n"
                . str_replace("\n.", "\n..", $body) // dot-stuffing
                . "\r\n.";
            $this->command($socket, $message, 250);
            $this->command($socket, "QUIT", 221);

            return true;
        } catch (\Throwable $e) {
            error_log('SMTP send failed: ' . $e->getMessage());
            return false;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            // Multi-line SMTP replies use "CODE-" until the final "CODE ".
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP error: expected {$expectedCode}, got: " . trim($response));
        }
        return $response;
    }
}
