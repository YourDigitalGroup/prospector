<?php

use Prospector\Attachment;
use Prospector\Merge;
use Prospector\Support\View;

/**
 * The message body: a formatting toolbar, a contenteditable box, a merge-variable
 * picker and an attachment list.
 *
 * Shared by the one-lead dialog and the bulk one, because the two should not
 * drift — a variable that works when writing to one person has to work when
 * writing to forty, and the only difference between the two screens is who it
 * goes to.
 *
 * The editable box is not the field that posts. app.js copies its HTML into the
 * hidden input on submit, which means the form still works if the editor fails
 * to initialise, and it keeps the posted value in one obvious place. Everything
 * that comes back is rebuilt against an allow-list in RichText before it is
 * stored or sent — nothing here is a security boundary.
 *
 * @var string      $id            unique per instance, since two of these can be on one page
 * @var string|null $signatureHtml rendered signature, or '' for none
 * @var string|null $signatureLink where to go and set one up
 */

$composerId = $id;
$limits = Attachment::limits();
?>

<div class="field">
    <div class="composer-head">
        <label for="<?= View::e($composerId) ?>-editor">Message</label>

        <?php /* execCommand is formally deprecated and is still the only thing
                 every current browser implements for this. The alternative is a
                 selection-and-Range implementation several hundred lines long,
                 for bold and italic. */ ?>
        <div class="toolbar" role="toolbar" aria-label="Formatting">
            <button type="button" class="tool" data-format="bold" title="Bold" aria-label="Bold"><b>B</b></button>
            <button type="button" class="tool" data-format="italic" title="Italic" aria-label="Italic"><i>I</i></button>
            <button type="button" class="tool" data-format="underline" title="Underline" aria-label="Underline"><u>U</u></button>
            <span class="tool-sep"></span>
            <select class="tool-size" data-format="fontSize" aria-label="Text size">
                <option value="">Size</option>
                <option value="2">Small</option>
                <option value="3" selected>Normal</option>
                <option value="5">Large</option>
                <option value="6">Huge</option>
            </select>
            <span class="tool-sep"></span>
            <button type="button" class="tool" data-format="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list">&bull;&nbsp;&ndash;</button>
            <button type="button" class="tool" data-format="createLink" title="Add a link" aria-label="Add a link">&#128279;</button>
            <button type="button" class="tool" data-format="removeFormat" title="Clear formatting" aria-label="Clear formatting">&#10005;</button>
        </div>
    </div>

    <div class="composer" id="<?= View::e($composerId) ?>-editor" contenteditable="true" role="textbox"
         aria-multiline="true" data-composer="<?= View::e($composerId) ?>"
         data-placeholder="Good talking on Tuesday — here is the one-pager I mentioned."></div>

    <input type="hidden" name="body" data-composer-value="<?= View::e($composerId) ?>">
</div>

<div class="field">
    <div class="composer-head">
        <label for="<?= View::e($composerId) ?>-merge">Insert a detail about them</label>
    </div>
    <select id="<?= View::e($composerId) ?>-merge" class="merge-picker"
            data-merge-for="<?= View::e($composerId) ?>">
        <option value="">Add a variable…</option>
        <?php foreach (Merge::groups() as $heading => $tokens): ?>
            <optgroup label="<?= View::e($heading) ?>">
                <?php foreach ($tokens as $token => $label): ?>
                    <option value="{{<?= View::e($token) ?>}}"><?= View::e($label) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>
    <div class="hint">
        Filled in per person as it sends, so one message reads right for all of them.
        A first name nobody recorded becomes &ldquo;there&rdquo;, so a greeting never
        arrives half-written.
    </div>
</div>

<div class="field">
    <div class="composer-head">
        <label>Attachments</label>
        <span class="dim small"><?= (int) $limits['files'] ?> max, <?= (int) $limits['megabytes'] ?>MB each</span>
    </div>
    <ul class="attach-list" data-attach-list="<?= View::e($composerId) ?>"></ul>
    <input type="file" class="attach-input" data-attach-input="<?= View::e($composerId) ?>"
           accept="<?= View::e(Attachment::accept()) ?>" multiple>
    <div class="hint"><?= View::e(implode(', ', $limits['extensions'])) ?>. Uploaded when you pick them.</div>
</div>

<?php if (($signatureHtml ?? '') !== ''): ?>
    <details class="signature-preview">
        <summary>Signed off with your signature</summary>
        <div class="signature-render"><?= $signatureHtml ?></div>
    </details>
<?php elseif (($signatureLink ?? null) !== null): ?>
    <p class="hint">
        No signature set, so this goes out unsigned.
        <a href="<?= View::e($signatureLink) ?>">Set one up</a>.
    </p>
<?php endif; ?>
