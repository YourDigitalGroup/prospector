<?php

use Prospector\Auth;
use Prospector\Support\View;

/**
 * Upload leads. Two states in one screen: the form, and the form plus a preview
 * of what was understood. Nothing is stored until the preview is confirmed.
 *
 * @var list<array<string, mixed>> $rows
 * @var list<string> $problems
 * @var list<string> $columns
 * @var list<string> $ignored
 * @var string $raw
 * @var list<array<string, mixed>> $owners
 * @var int $targetUserId
 * @var bool $sendEmail
 * @var list<string> $fields
 * @var string $mode
 * @var array<string, string> $modes
 * @var array{rows: list<array<string, mixed>>, new: int, updated: int, unchanged: int, fields: int}|null $preview
 */

// Keyed by row position, so the table can ask what each row would do as it
// walks the parsed rows.
$verdicts = $preview['rows'] ?? [];

$hasPreview = $rows !== [] || $problems !== [];

// Columns worth showing in the preview table, in a sensible reading order,
// narrowed to the ones this file actually supplied.
$preferred = ['company', 'decision_maker', 'title', 'email', 'phone', 'market', 'state', 'vertical', 'fit_score'];
$show = array_values(array_filter($preferred, static function (string $field) use ($rows): bool {
    foreach ($rows as $row) {
        if (trim((string) ($row[$field] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}));
?>

<div class="page-head">
    <div>
        <h1>Upload leads</h1>
        <div class="sub">Add a list by hand — CSV, or JSON from a batch run elsewhere</div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads/new')) ?>">
            <?php $name = 'plus'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Add one by hand
        </a>
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads')) ?>">
            <?php $name = 'arrow-left'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Back to leads
        </a>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><?= $hasPreview ? 'Change the file' : 'Choose a file' ?></h2></div>
        <div class="card-body">
            <form method="post" action="<?= View::e(View::url('leads/import')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">

                <?php if ($owners !== []): ?>
                    <div class="field">
                        <label for="user_id">Whose leads are these?</label>
                        <select id="user_id" name="user_id">
                            <?php foreach ($owners as $owner): ?>
                                <option value="<?= (int) $owner['id'] ?>" <?= (int) $owner['id'] === $targetUserId ? 'selected' : '' ?>>
                                    <?= View::e($owner['name']) ?> — <?= View::e($owner['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">De-duplication is per owner, so the same company can sit with both Billy and Darren.</div>
                    </div>
                <?php endif; ?>

                <?php /* The question an upload has to answer now that a row can
                         change a record as well as add one. Default is to take
                         the file's word for it, because the reason to re-upload
                         a list is almost always that it now knows more than we
                         do. */ ?>
                <div class="field">
                    <label for="mode">If a lead is already on file</label>
                    <select id="mode" name="mode">
                        <?php foreach ($modes as $key => $label): ?>
                            <option value="<?= View::e($key) ?>" <?= $mode === $key ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">
                        An empty column never overwrites anything, whichever you pick — a file
                        exported without the phone column will not wipe every phone number.
                        Status, notes and the GoHighLevel link are never touched.
                    </div>
                </div>

                <div class="field">
                    <label for="file">CSV or JSON file</label>
                    <input type="file" id="file" name="file" accept=".csv,.tsv,.txt,.json,text/csv,application/json">
                    <div class="hint">Up to 2MB. Excel's "CSV UTF-8" export works.</div>
                </div>

                <div class="field">
                    <label for="raw">…or paste the rows</label>
                    <textarea id="raw" name="raw" rows="7" spellcheck="false"
                              placeholder="company,website,market,state,fit_score&#10;Prairie Sky Radio,prairieskyradio.com,Sioux City,IA,88"><?= View::e($raw) ?></textarea>
                    <div class="hint">A file wins if you give both.</div>
                </div>

                <div class="check">
                    <input type="checkbox" id="send_email" name="send_email" value="1" <?= $sendEmail ? 'checked' : '' ?>>
                    <label for="send_email">Email the brief to them once imported</label>
                </div>

                <div class="btn-row">
                    <button class="btn btn-primary" type="submit">Check the file</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>What it accepts</h2></div>
        <div class="card-body">
            <p class="dim">
                The first row must be a header. Names are matched loosely — <code>Company Name</code>,
                <code>company_name</code> and <code>Organization</code> all mean the same thing —
                so a list you already have will usually import as-is. Commas, semicolons and tabs
                all work as separators.
            </p>

            <p class="dim mt">Only <strong>company</strong> is required. Everything else is optional:</p>

            <div class="chips">
                <?php foreach ($fields as $field): ?>
                    <code class="chip"><?= View::e($field) ?></code>
                <?php endforeach; ?>
            </div>

            <div class="btn-row mt">
                <a class="btn btn-sm" href="<?= View::e(View::url('leads/sample.csv')) ?>">
                    <?php $name = 'download'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                    Sample CSV
                </a>
                <a class="btn btn-sm" href="<?= View::e(View::url('leads/sample.json')) ?>">
                    <?php $name = 'download'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                    Sample JSON
                </a>
            </div>
            <div class="hint">
                Three made-up rows with the right headers — fill it in, or hand it to whoever
                is building your list. Every address in it is at <code>example.com</code>, so
                importing the sample by accident emails nobody.
            </div>

            <ul class="notes mt">
                <li><strong>No fit-score floor applies to uploads.</strong> That floor stops a research
                    batch padding itself to hit a number; you have already made that call. Rows with no
                    score come in at 0.</li>
                <li><strong>Email addresses without a stated confidence are treated as unverified</strong>
                    (<code>pattern</code>), which keeps them out of the GoHighLevel email field until
                    someone confirms them.</li>
                <li><strong>Several people at one organisation is fine.</strong> A duplicate means the
                    same person at the same company, matched on the email address when there is one
                    and on the name when there is not — so a marketing director and a GM at the same
                    hospital are two leads, not a rejected row.</li>
                <li><strong>A lead already on file is updated, not duplicated.</strong> Re-upload a
                    list once the addresses have been found and the addresses land on the leads
                    you already have. Matching is on the person at the company — by email when
                    there is one, and by name when the email has only just turned up, so a row
                    that gained an address still finds its own lead rather than making a second
                    one.</li>
                <li><strong>Nothing is written until the preview is confirmed</strong>, and the
                    preview names every field each row would change.</li>
            </ul>
        </div>
    </div>
</div>

<?php if ($hasPreview): ?>
    <div class="card mt">
        <div class="card-head">
            <h2>Preview</h2>
            <span class="dim small">
                <?php if ($preview !== null): ?>
                    <?= (int) $preview['new'] ?> new
                    <?php if ($preview['updated'] > 0): ?>
                        · <?= (int) $preview['updated'] ?> to update
                    <?php endif; ?>
                    <?php if ($preview['unchanged'] > 0): ?>
                        · <?= (int) $preview['unchanged'] ?> unchanged
                    <?php endif; ?>
                <?php else: ?>
                    <?= count($rows) ?> ready
                <?php endif; ?>
                <?= $problems !== [] ? ' · ' . count($problems) . ' to look at' : '' ?>
            </span>
        </div>

        <div class="card-body">
            <?php if ($problems !== []): ?>
                <div class="alert alert-warning">
                    <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                    <div>
                        <strong><?= count($problems) ?> row<?= count($problems) === 1 ? '' : 's' ?> need a look:</strong>
                        <ul class="notes mt-sm">
                            <?php foreach (array_slice($problems, 0, 15) as $problem): ?>
                                <li><?= View::e($problem) ?></li>
                            <?php endforeach; ?>
                            <?php if (count($problems) > 15): ?>
                                <li class="dim">…and <?= count($problems) - 15 ?> more.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($ignored !== []): ?>
                <p class="hint">
                    Columns ignored because they do not map to a lead field:
                    <?php foreach ($ignored as $column): ?><code><?= View::e($column) ?></code> <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($rows !== []): ?>
            <div class="table-scroll">
                <table class="data">
                    <thead>
                        <tr>
                            <th class="nowrap">Lands as</th>
                            <?php foreach ($show as $field): ?>
                                <th<?= $field === 'fit_score' ? ' class="nowrap"' : '' ?>>
                                    <?= View::e(str_replace('_', ' ', $field)) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($rows, 0, 50, true) as $index => $row): ?>
                            <?php
                            $entry = $verdicts[$index] ?? null;
                            $verdict = $entry['verdict'] ?? 'new';
                            $changes = $entry['changes'] ?? [];
                            $existing = $entry['existing'] ?? null;

                            /*
                             * What the lead will hold once this row has landed,
                             * which is not always what the row says. A file with
                             * an empty score column leaves the score alone, and
                             * printing the file's blank — a bare 0 — next to a
                             * lead scored 70 reads like the upload is about to
                             * wipe it. The preview is a promise about the
                             * result, so it shows the result.
                             */
                            $after = static function (string $field) use ($row, $changes, $existing): string {
                                if (isset($changes[$field])) {
                                    return (string) $changes[$field]['to'];
                                }

                                $incoming = trim((string) ($row[$field] ?? ''));

                                if ($existing === null) {
                                    return $incoming;
                                }

                                // Unchanged, so whatever is on file is what it
                                // will still be — blank incoming cell or not.
                                return trim((string) ($existing[$field] ?? ''));
                            };
                            ?>
                            <tr class="<?= $verdict === 'unchanged' ? 'row-muted' : '' ?>">
                                <td class="nowrap">
                                    <?php if ($verdict === 'new'): ?>
                                        <span class="badge badge-new">New</span>
                                    <?php elseif ($verdict === 'updated'): ?>
                                        <span class="badge badge-high">Update</span>
                                        <div class="cell-sub">
                                            <?php /* Named rather than counted: "3 fields" tells
                                                     you nothing about whether you want it. */ ?>
                                            <?= View::e(implode(', ', array_map(
                                                static fn (string $f): string => str_replace('_', ' ', $f),
                                                array_keys($changes)
                                            ))) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">No change</span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($show as $field): ?>
                                    <?php
                                    $change = $changes[$field] ?? null;
                                    $value = $after($field);
                                    ?>
                                    <td class="<?= $field === 'company' ? 'cell-primary' : 'cell-clip' ?><?= $change !== null ? ' is-changing' : '' ?>">
                                        <?php if ($field === 'email' && $value !== ''): ?>
                                            <?php $confidence = $after('email_confidence') ?: 'pattern'; ?>
                                            <?= View::e($value) ?>
                                            <span class="badge badge-<?= View::e($confidence) ?>">
                                                <?= View::e($confidence) ?>
                                            </span>
                                        <?php else: ?>
                                            <?= View::e($value) ?>
                                        <?php endif; ?>

                                        <?php if ($change !== null && $change['from'] !== ''): ?>
                                            <div class="cell-sub was">was <?= View::e(mb_strimwidth($change['from'], 0, 40, '…')) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-foot">
                <?php if (count($rows) > 50): ?>
                    <span class="dim small">Showing the first 50 of <?= count($rows) ?>. All of them will be imported.</span>
                <?php endif; ?>

                <form method="post" action="<?= View::e(View::url('leads/import')) ?>" class="inline-form" style="margin-left:auto">
                    <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="confirm" value="1">
                    <input type="hidden" name="user_id" value="<?= (int) $targetUserId ?>">
                    <?php if ($sendEmail): ?>
                        <input type="hidden" name="send_email" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="mode" value="<?= View::e($mode) ?>">
                    <input type="hidden" name="parsed" value="<?= View::e(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
                    <button class="btn btn-primary" type="submit">
                        <?php if ($preview !== null && $preview['updated'] > 0 && $preview['new'] > 0): ?>
                            Add <?= (int) $preview['new'] ?> and update <?= (int) $preview['updated'] ?>
                        <?php elseif ($preview !== null && $preview['updated'] > 0): ?>
                            Update <?= (int) $preview['updated'] ?> lead<?= $preview['updated'] === 1 ? '' : 's' ?>
                        <?php else: ?>
                            Import <?= $preview !== null ? (int) $preview['new'] : count($rows) ?>
                            lead<?= ($preview !== null ? $preview['new'] : count($rows)) === 1 ? '' : 's' ?>
                        <?php endif; ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
