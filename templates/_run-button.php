<?php

use DealRadar\Dashboard\View as V;

/** @var DealRadar\Dashboard\DashboardData $data */
/** @var string $page */

$availableAt = $data->manualAvailableAt();
?>
<form class="run-form" method="post" action="?page=<?= V::e($page) ?>" data-run-form>
  <input type="hidden" name="action" value="run">
  <input type="hidden" name="csrf" value="<?= V::e($_SESSION['csrf'] ?? '') ?>">
  <button class="btn run" type="submit" <?= $availableAt ? 'disabled' : '' ?>>
    <?= V::icon('download', 16) ?><span data-run-label>今すぐ取得</span>
  </button>
  <small class="run-hint"><?= $availableAt ? V::e($availableAt->format('H:i')) . ' から取得できます' : '連打しても1回だけ実行されます' ?></small>
</form>
