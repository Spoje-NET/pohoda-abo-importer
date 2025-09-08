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
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO'], __DIR__ . '/../.env');

use SpojeNet\AboParser\AboParser;
use SpojeNet\Pohoda\AboImporter\Bank;
use Ease\Logger\ToStd;

/**
 * Main importer function
 *
 * @param string $aboFilePath Path to the ABO file to import
 * @return array Import results for reporting
 */
function importAboFile(string $aboFilePath): array
{
    $logger = new ToStd();
    $startTime = new \DateTime();
    $logger->addToLog("Starting ABO import from: {$aboFilePath}", 'info');

    $results = [
        'status' => 'success',
        'timestamp' => null, // Will be set at the end
        'message' => '',
        'artifacts' => [
            'imported_transactions' => [],
            'failed_transactions' => [],
            'skipped_transactions' => [],
        ],
        'metrics' => [
            'total_transactions' => 0,
            'imported_count' => 0,
            'error_count' => 0,
            'skipped_count' => 0,
            'processing_time_seconds' => 0,
        ],
        'file_path' => $aboFilePath,
        'import_summary' => [],
    ];

    if (!file_exists($aboFilePath)) {
        $results['status'] = 'error';
        $results['message'] = "ABO file not found: {$aboFilePath}";
        $results['timestamp'] = (new \DateTime())->format(\DateTime::ATOM);
        $logger->addToLog($results['message'], 'error');
        return $results;
    }

    try {
        // Parse ABO file
        $parser = new AboParser();
        $parsed = $parser->parseFile($aboFilePath);
        
        $logger->addToLog("Parsed ABO file with format: {$parsed['format_version']}", 'info');
        $logger->addToLog("Found " . count($parsed['statements']) . " statements and " . count($parsed['transactions']) . " transactions", 'info');

        // Create Bank client
        $bank = new Bank();
        
        $importedCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        
        $results['metrics']['total_transactions'] = count($parsed['transactions']);

        // Process each transaction
        foreach ($parsed['transactions'] as $transaction) {
            try {
                // Create unique transaction ID from document number and account
                $transactionId = 'ABO_' . $transaction['document_number'] . '_' . $transaction['account_number'];
                
                // Check if this transaction already exists
                if ($bank->checkForTransactionPresence($transactionId)) {
                    $skippedCount++;
                    $results['artifacts']['skipped_transactions'][] = [
                        'transaction_id' => $transactionId,
                        'document_number' => $transaction['document_number'],
                        'amount' => $transaction['amount'],
                        'date' => $transaction['valuation_date'] ?? $transaction['due_date'],
                        'reason' => 'Duplicate transaction already exists in Pohoda',
                    ];
                    $logger->addToLog("Transaction already exists, skipping: {$transaction['document_number']}", 'info');
                    continue;
                }
                
                // Reset bank object for new transaction
                $bank->reset();
                
                // Set basic transaction data
                $bank->setDataValue('bankType', $transaction['amount'] > 0 ? 'receipt' : 'expense');
                $bank->setDataValue('datePayment', $transaction['valuation_date'] ?? $transaction['due_date'] ?? date('Y-m-d'));
                $bank->setDataValue('text', buildTransactionDescription($transaction));
                $bank->setDataValue('intNote', 'Automatic Import: ' . \Ease\Shared::appName() . ' ' . \Ease\Shared::appVersion() . ' #' . $transactionId . '#');
                
                // Set amount
                if ($transaction['amount'] !== null) {
                    $bank->setDataValue('homeCurrency', ['priceNone' => abs($transaction['amount'])]);
                }
                
                // Add partner account if we have counter account
                if (!empty($transaction['counter_account'])) {
                    $bank->setDataValue('paymentAccount', [
                        'accountNo' => $transaction['counter_account'],
                        'bankCode' => '', // Bank code not available in ABO format
                    ]);
                }

                // Add symbols if they exist
                if (!empty($transaction['variable_symbol'])) {
                    $bank->setDataValue('symVar', $transaction['variable_symbol']);
                }
                if (!empty($transaction['constant_symbol'])) {
                    $bank->setDataValue('symConst', $transaction['constant_symbol']);
                }
                if (!empty($transaction['specific_symbol'])) {
                    $bank->setDataValue('symSpec', $transaction['specific_symbol']);
                }
                
                // Set bank account ID from environment or use default
                if (!empty($transaction['account_number']) && \Ease\Shared::cfg('POHODA_BANK_IDS')) {
                    $bank->setDataValue('account', \Ease\Shared::cfg('POHODA_BANK_IDS'));
                }
                
                // Add to Pohoda and commit
                if ($bank->addToPohoda() && $bank->commit()) {
                    $importedCount++;
                    $results['artifacts']['imported_transactions'][] = [
                        'transaction_id' => $transactionId,
                        'document_number' => $transaction['document_number'],
                        'amount' => $transaction['amount'],
                        'date' => $transaction['valuation_date'] ?? $transaction['due_date'],
                    ];
                    $logger->addToLog("Imported transaction: {$transaction['document_number']} (Amount: {$transaction['amount']})", 'success');
                } else {
                    $errorCount++;
                    $results['artifacts']['failed_transactions'][] = [
                        'transaction_id' => $transactionId,
                        'document_number' => $transaction['document_number'],
                        'error' => 'Failed to commit to Pohoda',
                    ];
                    $logger->addToLog("Failed to import transaction: {$transaction['document_number']}", 'warning');
                }
                
            } catch (\Exception $e) {
                $errorCount++;
                $results['artifacts']['failed_transactions'][] = [
                    'transaction_id' => $transactionId ?? 'unknown',
                    'document_number' => $transaction['document_number'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
                $logger->addToLog("Error importing transaction {$transaction['document_number']}: " . $e->getMessage(), 'error');
            }
        }
        
        // Update final metrics
        $results['metrics']['imported_count'] = $importedCount;
        $results['metrics']['error_count'] = $errorCount;
        $results['metrics']['skipped_count'] = $skippedCount;
        $results['metrics']['processing_time_seconds'] = (new \DateTime())->getTimestamp() - $startTime->getTimestamp();
        
        // Set final status
        if ($errorCount > 0 && $importedCount === 0) {
            $results['status'] = 'error';
            $results['message'] = "Import failed: {$errorCount} errors, no transactions imported";
        } elseif ($errorCount > 0) {
            $results['status'] = 'warning';
            $results['message'] = "Import completed with issues: {$importedCount} imported, {$errorCount} errors, {$skippedCount} skipped";
        } else {
            $results['message'] = "Import successful: {$importedCount} imported, {$skippedCount} skipped";
        }
        
        $logger->addToLog($results['message'], $results['status'] === 'error' ? 'error' : 'info');
        
    } catch (\Exception $e) {
        $results['status'] = 'error';
        $results['message'] = "Fatal error during import: " . $e->getMessage();
        $results['metrics']['processing_time_seconds'] = (new \DateTime())->getTimestamp() - $startTime->getTimestamp();
        $logger->addToLog($results['message'], 'error');
    }
    
    // Set final timestamp
    $results['timestamp'] = (new \DateTime())->format(\DateTime::ATOM);
    
    return $results;
}

/**
 * Generate MultiFlexi compatible report
 *
 * @param array $results Import results
 * @param string $outputFile Output file path
 * @return void
 */
function generateReport(array $results, string $outputFile = ''): void
{
    // Conform to MultiFlexi report schema
    $report = [
        'status' => $results['status'],
        'timestamp' => $results['timestamp'],
        'message' => $results['message'],
        'artifacts' => [],
        'metrics' => $results['metrics'],
    ];
    
    // Add artifacts if we have any transactions
    if (!empty($results['artifacts']['imported_transactions'])) {
        $report['artifacts']['imported_transactions'] = array_map(function($tx) {
            return "Transaction {$tx['document_number']}: {$tx['amount']} on {$tx['date']}";
        }, $results['artifacts']['imported_transactions']);
    }
    
    if (!empty($results['artifacts']['failed_transactions'])) {
        $report['artifacts']['failed_transactions'] = array_map(function($tx) {
            return "Failed {$tx['document_number']}: {$tx['error']}";
        }, $results['artifacts']['failed_transactions']);
    }
    
    if (!empty($results['artifacts']['skipped_transactions'])) {
        $report['artifacts']['skipped_transactions'] = array_map(function($tx) {
            return "Skipped {$tx['document_number']}: {$tx['amount']} on {$tx['date']} - {$tx['reason']}";
        }, $results['artifacts']['skipped_transactions']);
    }
    
    $jsonOutput = json_encode($report, \Ease\Shared::cfg('DEBUG') ? JSON_PRETTY_PRINT : 0);
    
    if ($outputFile && $outputFile !== 'php://stdout') {
        $written = file_put_contents($outputFile, $jsonOutput);
        if ($written) {
            echo "Report saved to: {$outputFile}\n";
        } else {
            echo "Failed to save report to: {$outputFile}\n";
        }
    } else {
        echo $jsonOutput . "\n";
    }
}

/**
 * Build transaction description from available data
 *
 * @param array $transaction Transaction data
 * @return string Description text
 */
function buildTransactionDescription(array $transaction): string
{
    $parts = [];
    
    if (!empty($transaction['additional_info'])) {
        $parts[] = $transaction['additional_info'];
    }
    
    if (!empty($transaction['counter_account'])) {
        $parts[] = "Counter account: {$transaction['counter_account']}";
    }
    
    if (!empty($transaction['data_type'])) {
        $parts[] = "Type: {$transaction['data_type']}";
    }
    
    return implode(' | ', $parts) ?: 'Bank transaction from ABO import';
}

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

// Run the import
$results = importAboFile($aboFilePath);

// Generate report if requested
if ($outputFile) {
    generateReport($results, $outputFile);
}

// Set exit code based on results
exit($results['status'] === 'error' ? 1 : 0);
