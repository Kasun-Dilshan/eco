<?php
/**
 * CLI / one-off: assign HPP template to all approved High Profit investments.
 * Usage: php admin/sync_hpp_agreements.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/agreement_helpers.php';

$sync = syncApprovedHppAgreements($db, 'system_sync');
echo json_encode($sync, JSON_PRETTY_PRINT) . PHP_EOL;
