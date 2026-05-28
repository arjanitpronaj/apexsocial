<?php
require_once '../includes/config.php';
session_unset();
session_destroy();
redirect(BASE_URL.'/admin/login.php');
