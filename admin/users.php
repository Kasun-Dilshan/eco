<?php
// Redirect: users.php had a poisoned CDN cache (empty 200 response) on SiteGround.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: manage_users.php', true, 302);
exit();
