<?php
require_once __DIR__ . '/bootstrap.php';
if (!empty($_SESSION['profile_user_id'])) {
  header("Location: dashboard.php");
} elseif (has_gateway_session()) {
  header("Location: select_profile.php");
} else {
  header("Location: login.php");
}
exit;
