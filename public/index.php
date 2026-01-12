<?php
// Redirect root to register page so '/' doesn't 404
header('Location: /register.php', true, 302);
exit;
