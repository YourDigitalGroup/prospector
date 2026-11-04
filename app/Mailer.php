<?php

declare(strict_types=1);

namespace Prospector;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Prospector\Support\Clock;
use Prospector\Support\Settings;
use Prospector\Support\View;

final class Mailer
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $leads
     * @return array{ok: bool, message: string}
     */
    public static function sendDailyBrief(array $user, array $run, array $leads): array
    {
        $subject = sprintf(
            '%s — %d %s for %s',
            Runs::loopLabel((string) $run['loop']),
            count($leads),
            count($leads) === 1 ? 'lead' : 'leads',
            Clock::display((string) $run['started_at'], 'M j')
        );

        if ($leads === []) {
            $subject = Runs::loopLabel((string) $run['loop']) . ' — no leads cleared the floor today';
        }

        $html = View::renderToString('emails/daily_brief', [
            'user' => $user,
            'run' => $run,
            'leads' => $leads,
            'baseUrl' => View::baseUrl(),
        ]);

        return self::send((string) $user['email'], (string) $user['name'], $subject, $html);
    }

    /** @return array{ok: bool, message: string} */
    public static function sendTest(string $to): array
    {
        $html = '<p style="font-family:system-ui,sans-serif;font-size:15px;">'
            . 'Prospector email is configured correctly. Daily batches will arrive at '
            . htmlspecialchars(self::scheduleDescription(), ENT_QUOTES) . '.</p>';

        return self::send($to, '', 'Prospector test email', $html);
    }

    public static function scheduleDescription(): string
    {
        $hour = Settings::int('run_hour', 7);
        $minute = Settings::int('run_minute', 30);
        $weekdays = Settings::bool('run_weekdays_only', true);

        $time = date('g:i a', mktime($hour, $minute) ?: 0);
        $abbrev = Clock::local()->format('T');

        return $time . ' ' . $abbrev . ($weekdays ? ', Monday through Friday' : ', every day');
    }

    /** @return array{ok: bool, message: string} */
    public static function send(string $to, string $toName, string $subject, string $html): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Invalid recipient address: ' . $to];
        }

        $fromEmail = Settings::get('mail_from_email');
        if ($fromEmail === '') {
            // Fall back to a no-reply on the site's own domain so the message
            // still passes basic sender checks on shared hosting.
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromEmail = 'prospector@' . preg_replace('/^www\./', '', explode(':', $host)[0]);
        }
        $fromName = Settings::get('mail_from_name', 'Prospector');

        if (Settings::get('mail_transport') === 'mailgun') {
            return self::sendViaMailgun($to, $toName, $subject, $html, $fromName);
        }

        $mail = new PHPMailer(true);

        try {
            $transport = Settings::get('mail_transport', 'mail');

            if ($transport === 'smtp') {
                $host = Settings::get('smtp_host');
                if ($host === '') {
                    return ['ok' => false, 'message' => 'SMTP is selected but no SMTP host is set.'];
                }

                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = Settings::int('smtp_port', 587);
                $mail->Timeout = 20;

                $username = Settings::get('smtp_username');
                if ($username !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = $username;
                    $mail->Password = Settings::get('smtp_password');
                }

                $secure = Settings::get('smtp_secure', 'tls');
                if ($secure === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($secure === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPAutoTLS = false;
                }
            } else {
                $mail->isMail();
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = self::toPlainText($html);
            $mail->send();

            return ['ok' => true, 'message' => 'Sent to ' . $to];
        } catch (MailerException $e) {
            return ['ok' => false, 'message' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send through Mailgun's HTTP API.
     *
     * The API rather than Mailgun's SMTP relay: one request, no port 587 to be
     * blocked by the host, and a reply that says what went wrong in words.
     *
     * The From address defaults to prospector@ the sending domain. Mail from an
     * address on a domain Mailgun is not set up for is exactly the mail that
     * lands in spam, which is the problem this transport exists to fix.
     *
     * PROSPECTOR_MAILGUN_BASE points it somewhere else for the checks, and is
     * deliberately an environment variable rather than a setting: nothing a
     * person can type on a screen should be able to send the API key elsewhere.
     *
     * @return array{ok: bool, message: string}
     */
    private static function sendViaMailgun(string $to, string $toName, string $subject, string $html, string $fromName): array
    {
        $key = trim(Settings::get('mailgun_api_key'));
        $domain = strtolower(trim(Settings::get('mailgun_domain')));

        if ($key === '' || $domain === '') {
            return ['ok' => false, 'message' => 'Mailgun is selected but its API key or sending domain is missing.'];
        }

        $fromEmail = trim(Settings::get('mail_from_email'));
        if ($fromEmail === '') {
            $fromEmail = 'prospector@' . $domain;
        }

        $override = getenv('PROSPECTOR_MAILGUN_BASE');
        $base = is_string($override) && $override !== ''
            ? rtrim($override, '/')
            : (Settings::get('mailgun_region') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net');

        $handle = curl_init($base . '/v3/' . rawurlencode($domain) . '/messages');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'api:' . $key,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'from' => self::mailbox($fromName, $fromEmail),
                'to' => self::mailbox($toName, $to),
                'subject' => self::oneLine($subject),
                'html' => $html,
                'text' => self::toPlainText($html),
            ]),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return ['ok' => false, 'message' => 'Could not reach Mailgun: ' . $error];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'Sent to ' . $to . ' via Mailgun'];
        }

        $said = json_decode((string) $body, true);
        $said = is_array($said) && isset($said['message']) ? (string) $said['message'] : trim(strip_tags((string) $body));

        return ['ok' => false, 'message' => match (true) {
            $status === 401 => 'Mailgun refused the API key. Use the domain\'s sending key or the account\'s private API key.',
            $status === 404 => 'Mailgun has no sending domain called "' . $domain . '" on this account'
                . (Settings::get('mailgun_region') === 'eu' ? ' in the EU region.' : ' in the US region — if the domain was set up in the EU, choose EU.'),
            default => 'Mailgun said ' . $status . ': ' . mb_strimwidth($said, 0, 200, '…'),
        }];
    }

    /** "Name <address>", with the name quoted and unable to start a new header. */
    private static function mailbox(string $name, string $address): string
    {
        $name = trim(str_replace(['"', '\\'], '', self::oneLine($name)));

        return $name === '' ? $address : '"' . $name . '" <' . $address . '>';
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? $value);
    }

    private static function toPlainText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<(br|\/p|\/tr|\/h[1-6]|\/div)\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/td>\s*<td[^>]*>/i', ' | ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
