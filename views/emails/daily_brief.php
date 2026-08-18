<?php

use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * Daily brief email. Table-based layout with inline styles, because that is
 * still what mail clients render reliably. Designed to be read on a phone
 * between calls.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $run
 * @var list<array<string, mixed>> $leads
 * @var string $baseUrl
 */

$e = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$firstName = explode(' ', trim((string) $user['name']))[0];
$loopLabel = Runs::loopLabel((string) $run['loop']);
$dateLabel = Clock::display((string) $run['started_at'], 'l, F j');

$ink = '#16181d';
$muted = '#6b7280';
$border = '#e3e7ec';
$accent = '#3f6212';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($loopLabel) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;">
<div style="display:none;max-height:0;overflow:hidden;">
    <?= count($leads) ?> qualified <?= count($leads) === 1 ? 'lead' : 'leads' ?> for <?= $e($dateLabel) ?>.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f8;padding:20px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="max-width:660px;background:#ffffff;border:1px solid <?= $border ?>;border-radius:12px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    <!-- header -->
    <tr>
        <td style="padding:20px 24px;background:#12140f;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td width="30" style="vertical-align:middle;">
                        <!-- Clients that strip inline SVG just show the wordmark, which stands alone fine. -->
                        <svg width="26" height="26" viewBox="0 0 100 100" fill="#cdf565"
                             style="display:block;">
                            <path d="M2.5 63 C2.5 35 21 7 50 1 L56 15.5 C29.5 22 11 41 5.5 57.5 Z"/>
                            <path d="M97.5 63 C97.5 35 79 7 50 1 L44 15.5 C70.5 22 89 41 94.5 57.5 Z"/>
                            <g stroke="#cdf565" stroke-linecap="butt" fill="none">
                                <path d="M26 16 L78 95.5" stroke-width="10"/>
                                <path d="M74 16 L22 95.5" stroke-width="10"/>
                                <path d="M27.7 18.7 L32.9 26.9" stroke-width="14.4"/>
                                <path d="M72.3 18.7 L67.1 26.9" stroke-width="14.4"/>
                                <path d="M74.3 90 L77.5 94.8" stroke-width="12.8"/>
                                <path d="M25.7 90 L22.5 94.8" stroke-width="12.8"/>
                            </g>
                        </svg>
                    </td>
                    <td style="vertical-align:middle;padding-left:10px;">
                        <div style="color:#ffffff;font-size:16px;font-weight:600;letter-spacing:-.01em;">Prospector</div>
                        <div style="color:#9aa0aa;font-size:12px;"><?= $e($loopLabel) ?></div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- intro -->
    <tr>
        <td style="padding:24px 24px 8px;">
            <h1 style="margin:0 0 6px;font-size:20px;color:<?= $ink ?>;font-weight:650;letter-spacing:-.02em;">
                <?php if ($leads === []): ?>
                    Nothing cleared the bar today
                <?php else: ?>
                    <?= count($leads) ?> <?= count($leads) === 1 ? 'lead' : 'leads' ?>, <?= $e($firstName) ?>
                <?php endif; ?>
            </h1>
            <p style="margin:0;color:<?= $muted ?>;font-size:13.5px;">
                <?= $e($dateLabel) ?>
                <?php if (!empty($run['vertical'])): ?> · <?= $e($run['vertical']) ?><?php endif; ?>
                <?php if (!empty($run['geography'])): ?> · <?= $e($run['geography']) ?><?php endif; ?>
            </p>
        </td>
    </tr>

    <?php if ($leads === []): ?>
        <tr>
            <td style="padding:8px 24px 24px;">
                <p style="margin:0 0 12px;color:<?= $ink ?>;font-size:14px;line-height:1.6;">
                    The research ran, but nothing in today's pool met the fit-score floor — so you are getting
                    an honest empty batch instead of padding. The full brief explains what was screened and why
                    each candidate fell short.
                </p>
                <a href="<?= $e($baseUrl) ?>/runs/<?= (int) $run['id'] ?>"
                   style="display:inline-block;background:#cdf565;color:#14180b;padding:10px 18px;border-radius:8px;
                          font-size:14px;font-weight:600;text-decoration:none;">Read the brief</a>
            </td>
        </tr>
    <?php else: ?>
        <tr>
            <td style="padding:8px 24px 4px;">
                <a href="<?= $e($baseUrl) ?>/leads?run_id=<?= (int) $run['id'] ?>"
                   style="display:inline-block;background:#cdf565;color:#14180b;padding:10px 18px;border-radius:8px;
                          font-size:14px;font-weight:600;text-decoration:none;">Open these in Prospector</a>
            </td>
        </tr>

        <?php foreach ($leads as $index => $lead): ?>
            <tr>
                <td style="padding:16px 24px;<?= $index > 0 ? 'border-top:1px solid ' . $border . ';' : 'padding-top:20px;' ?>">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="vertical-align:top;">
                                <div style="font-size:16px;font-weight:650;color:<?= $ink ?>;letter-spacing:-.01em;">
                                    <?php if (!empty($lead['website'])): ?>
                                        <a href="<?= $e($lead['website']) ?>" style="color:<?= $ink ?>;text-decoration:none;">
                                            <?= $e($lead['company']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= $e($lead['company']) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="color:<?= $muted ?>;font-size:12.5px;margin-top:2px;">
                                    <?= $e($lead['vertical'] ?? '') ?>
                                    <?php if (!empty($lead['door'])): ?> · <?= $e($lead['door']) ?><?php endif; ?>
                                    <?php if (!empty($lead['market'])): ?> · <?= $e($lead['market']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td width="46" align="right" style="vertical-align:top;">
                                <span style="display:inline-block;background:#f0f7dd;color:<?= $accent ?>;
                                             border-radius:7px;padding:3px 8px;font-size:13px;font-weight:700;">
                                    <?= (int) $lead['fit_score'] ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <?php if (!empty($lead['decision_maker'])): ?>
                        <div style="margin-top:10px;font-size:13.5px;color:<?= $ink ?>;">
                            <strong><?= $e($lead['decision_maker']) ?></strong>
                            <?php if (!empty($lead['title'])): ?>
                                <span style="color:<?= $muted ?>;"> — <?= $e($lead['title']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="margin-top:10px;font-size:13.5px;color:<?= $muted ?>;">
                            No decision-maker named — call the main line and ask for marketing.
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:4px;font-size:13px;">
                        <?php if (!empty($lead['email'])): ?>
                            <a href="mailto:<?= $e($lead['email']) ?>" style="color:#1f6feb;text-decoration:none;">
                                <?= $e($lead['email']) ?>
                            </a>
                            <?php if (($lead['email_confidence'] ?? '') === 'pattern'): ?>
                                <span style="color:#a1620a;background:#fdf3e3;border-radius:4px;padding:1px 6px;font-size:11.5px;font-weight:600;">
                                    unverified pattern — check before sending
                                </span>
                            <?php elseif (!empty($lead['email_confidence'])): ?>
                                <span style="color:<?= $muted ?>;font-size:11.5px;">(<?= $e($lead['email_confidence']) ?>)</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php $phone = $lead['direct_phone'] ?: $lead['phone']; ?>
                        <?php if (!empty($phone)): ?>
                            <?php if (!empty($lead['email'])): ?><span style="color:<?= $border ?>;"> · </span><?php endif; ?>
                            <a href="tel:<?= $e(preg_replace('/[^0-9+]/', '', (string) $phone)) ?>"
                               style="color:#1f6feb;text-decoration:none;"><?= $e($phone) ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($lead['why'])): ?>
                        <div style="margin-top:11px;font-size:13.5px;color:<?= $ink ?>;line-height:1.55;">
                            <span style="color:<?= $muted ?>;font-weight:600;">Why them: </span><?= $e($lead['why']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($lead['hook'])): ?>
                        <div style="margin-top:9px;padding:10px 12px;background:#f7f8fa;border-left:3px solid #cdf565;
                                    border-radius:0 6px 6px 0;font-size:13.5px;color:<?= $ink ?>;line-height:1.55;">
                            <span style="color:<?= $muted ?>;font-weight:600;">Open with: </span><?= $e($lead['hook']) ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:10px;font-size:12.5px;">
                        <a href="<?= $e($baseUrl) ?>/leads/<?= (int) $lead['id'] ?>"
                           style="color:#1f6feb;text-decoration:none;font-weight:600;">Open in Prospector →</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>

        <tr>
            <td style="padding:18px 24px;border-top:1px solid <?= $border ?>;background:#f7f8fa;">
                <p style="margin:0 0 10px;font-size:13px;color:<?= $muted ?>;line-height:1.6;">
                    Log what happens on each one — no answer, not interested, meeting booked, signed. Those
                    dispositions are what sharpen the next batch.
                </p>
                <a href="<?= $e($baseUrl) ?>/runs/<?= (int) $run['id'] ?>"
                   style="color:#1f6feb;text-decoration:none;font-size:13px;font-weight:600;">
                    Read the full research brief →
                </a>
            </td>
        </tr>
    <?php endif; ?>

    <tr>
        <td style="padding:16px 24px;border-top:1px solid <?= $border ?>;">
            <p style="margin:0;font-size:11.5px;color:<?= $muted ?>;line-height:1.6;">
                Sent by Prospector for <?= $e($user['email']) ?>.
                Every contact detail is labelled with how well it was verified — anything marked
                <em>pattern</em> is an educated guess and must be confirmed before a bulk send.
            </p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
