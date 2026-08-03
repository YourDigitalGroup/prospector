<?php

/**
 * Fit-score chip.
 *
 * @var int|string $value
 */
$score = (int) ($value ?? 0);
$class = $score >= 85 ? 's-high' : ($score >= 70 ? 's-mid' : 's-low');
?>
<span class="score <?= $class ?>" title="Fit score <?= $score ?> out of 100"><?= $score ?></span>
