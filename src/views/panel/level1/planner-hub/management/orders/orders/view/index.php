<?php

use App\Utils\LocationUtils;

$id = max(0, (int)($_GET['id'] ?? 0));
$target = 'panel/planner-hub/management/orders/orders/edit';
if ($id > 0) {
    $target .= '?id=' . $id;
}

header('Location: ' . LocationUtils::pathFor($target), true, 302);
exit;
