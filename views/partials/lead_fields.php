<?php

use Prospector\LeadForm;
use Prospector\Support\View;

/**
 * The field cards of the lead form, shared by adding a lead and editing one.
 *
 * Rendered from LeadForm's groups, so neither screen lists fields by hand and
 * the two cannot drift apart when a field is added.
 *
 * @var array<string, list<string>> $groups
 * @var array<string, string>       $values
 * @var array<string, string>       $errors
 *
 * Set $noPlaceholders when editing. The examples ("dwhitfield@prairieskyradio
 * .com") help someone filling in a blank form, but on an existing lead an empty
 * box showing a plausible address reads as the lead's own data.
 */
?>
    <?php foreach ($groups as $heading => $fields): ?>
        <div class="card">
            <div class="card-head"><h2><?= View::e($heading) ?></h2></div>
            <div class="card-body">
                <div class="field-grid">
                    <?php foreach ($fields as $field): ?>
                        <?php
                        $spec = LeadForm::field($field);
                        $value = (string) ($values[$field] ?? '');
                        $error = $errors[$field] ?? null;
                        $wide = ($spec['type'] ?? 'text') === 'textarea';
                        ?>
                        <div class="field<?= $wide ? ' field-wide' : '' ?>">
                            <label for="f-<?= View::e($field) ?>"><?= View::e($spec['label']) ?></label>

                            <?php if (($spec['type'] ?? '') === 'textarea'): ?>
                                <textarea id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>"
                                          rows="<?= (int) ($spec['rows'] ?? 3) ?>"
                                          placeholder="<?= View::e(empty($noPlaceholders) ? ($spec['placeholder'] ?? '') : '') ?>"><?= View::e($value) ?></textarea>

                            <?php elseif (($spec['type'] ?? '') === 'select'): ?>
                                <select id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>">
                                    <?php foreach (($spec['options'] ?? []) as $key => $label): ?>
                                        <option value="<?= View::e((string) $key) ?>" <?= $value === (string) $key ? 'selected' : '' ?>>
                                            <?= View::e((string) $label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php else: ?>
                                <input type="<?= View::e($spec['type'] ?? 'text') ?>"
                                       id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>"
                                       value="<?= View::e($value) ?>"
                                       <?php if (($spec['type'] ?? '') === 'number'): ?>min="0" max="100"<?php endif; ?>
                                       <?= $field === 'company' ? 'required' . (empty($noAutofocus) ? ' autofocus' : '') : '' ?>
                                       placeholder="<?= View::e(empty($noPlaceholders) ? ($spec['placeholder'] ?? '') : '') ?>">
                            <?php endif; ?>

                            <?php if ($error !== null): ?>
                                <div class="hint hint-error"><?= View::e($error) ?></div>
                            <?php elseif (isset($spec['hint'])): ?>
                                <div class="hint"><?= View::e($spec['hint']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
