<?php

/**
 * ABO Importer for Pohoda via mServer
 *
 * This script imports ABO bank statement files into Pohoda accounting software
 * using the mServer API. It parses ABO format files and creates bank movements.
 *
 * Usage: php src/importer.php [abo-file-path]
 *
 * @author VitexSoftware
 */

require __DIR__ . '/../vendor/autoload.php';

// Initialize environment configuration
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'POHODA_BANK_IDS' ], __DIR__ . '/../.env');

use SpojeNet\Pohoda\AboImporter\Bank;

// Main execution
$options = getopt('o::e::', ['output::', 'environment::']);

// Initialize environment
$envFile = array_key_exists('environment', $options) 
    ? $options['environment'] 
    : (array_key_exists('e', $options) ? $options['e'] : '.env');

// Get ABO file path
if ($argc > 1 && !str_starts_with($argv[1], '-')) {
    $aboFilePath = $argv[1];
} else {
    // Default to looking for vystup.abo in current directory
    $aboFilePath = 'vystup.abo';
}

// Get output file for report
$outputFile = array_key_exists('output', $options) 
    ? $options['output'] 
    : (array_key_exists('o', $options) ? $options['o'] : \Ease\Shared::cfg('RESULT_FILE', ''));

// Create Bank instance and run the import
$bank = new Bank();
$results = $bank->importAboFile($aboFilePath);

// Generate report if requested
if ($outputFile) {
    $bank->generateReport($results, $outputFile);
}

// Set exit code based on results
exit($results['status'] === 'error' ? 1 : 0);
