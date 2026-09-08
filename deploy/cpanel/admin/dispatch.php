<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
header('Location: /admin/operations.php', true, 302);
exit;
