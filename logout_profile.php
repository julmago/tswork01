<?php
require_once __DIR__ . '/bootstrap.php';
require_gateway();

clear_profile_session();
redirect('select_profile.php');
