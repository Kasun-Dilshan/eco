<?php
session_start();
require_once __DIR__ . '/../includes/investor_auth.php';

clearInvestorSession();
header('Location: login.php?logout=1');
exit();
