<?php

/**
 * Tab and home-screen icons.
 *
 * Built from assets/img/logo.png by bin/favicons.php — rerun that after
 * changing the artwork. Versioned by file mtime so a rebuilt icon actually
 * reaches people; browsers cache favicons harder than almost anything else and
 * will happily show last month's for weeks otherwise.
 */

use Prospector\Support\View;

?>
<link rel="icon" href="<?= View::e(View::asset('assets/img/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= View::e(View::asset('assets/img/favicon-180.png')) ?>" sizes="180x180">
