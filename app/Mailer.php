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
