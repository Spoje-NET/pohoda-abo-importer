<?php

declare(strict_types=1);

/**
 * ABO Importer Bank Client with Idempotency Support
 *
 * This class extends the mServer Bank client to add idempotency features
 * for importing ABO bank statements.
 *
 * @author VitexSoftware
 */

namespace SpojeNet\Pohoda\AboImporter;

use Ease\Shared;

class Bank extends \mServer\Bank
{
    /**
     * Is Record with current transaction ID already present in Pohoda?
     *
     * @param string $transactionId Transaction identifier
     * @return bool True if transaction exists
     */
    public function checkForTransactionPresence(string $transactionId): bool
    {
        // Create a new checker instance to avoid modifying current state
        $checker = new \mServer\Bank();
        $checker->userAgent(Shared::AppName() . '-' . Shared::AppVersion() . ' ' . $this->userAgent());
        $checker->defaultHttpHeaders['STW-Application'] = Shared::AppName() . ' ' . Shared::AppVersion();

        // Search for records with the transaction ID in the internal note field (Pozn2)
        $filter = "Pozn2 like '%#{$transactionId}#%'";
        $lrq = $checker->queryFilter($filter, 'TransactionID: ' . $transactionId);

        $found = $checker->getListing($lrq);

        // If the result is invalid, throw an exception
        if ($found === false) {
            throw new \RuntimeException('Error fetching records for transaction check.');
        }

        return !empty($found);
    }

    /**
     * Extract transaction ID from internal note field
     *
     * @param string|null $intNote Internal note content
     * @return string|null Transaction ID or null if not found
     */
    public static function intNote2TransactionId(?string $intNote): ?string
    {
        if (empty($intNote)) {
            return null;
        }

        $matches = [];

        // Match pattern like #ABO_123456_789012345#
        if (preg_match('/#([^#]+)#/', $intNote, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Main importer method to process ABO file
     *
     * @param string $aboFilePath Path to the ABO file to import
     * @return array Import results for reporting
     */
    public function importAboFile(string $aboFilePath): array
    {
        $logger = new \Ease\Logger\ToStd();
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
            $parser = new \SpojeNet\AboParser\AboParser();
            $parsed = $parser->parseFile($aboFilePath);
            
            $logger->addToLog("Parsed ABO file with format: {$parsed['format_version']}", 'info');
            $logger->addToLog("Found " . count($parsed['statements']) . " statements and " . count($parsed['transactions']) . " transactions", 'info');

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
                    if ($this->checkForTransactionPresence($transactionId)) {
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
                    $this->reset();
                    
                    // Set basic transaction data
                    $this->setDataValue('bankType', $transaction['amount'] > 0 ? 'receipt' : 'expense');
                    $this->setDataValue('datePayment', $transaction['valuation_date'] ?? $transaction['due_date'] ?? date('Y-m-d'));
                    $this->setDataValue('text', $this->buildTransactionDescription($transaction));
                    $this->setDataValue('intNote', 'Automatic Import: ' . Shared::appName() . ' ' . Shared::appVersion() . ' #' . $transactionId . '#');
                    
                    // Set amount
                    if ($transaction['amount'] !== null) {
                        $this->setDataValue('homeCurrency', ['priceNone' => abs($transaction['amount'])]);
                    }
                    
                    // Add partner account if we have counter account
                    if (!empty($transaction['counter_account'])) {
                        $this->setDataValue('paymentAccount', [
                            'accountNo' => $transaction['counter_account'],
                            'bankCode' => '', // Bank code not available in ABO format
                        ]);
                    }

                    // Add symbols if they exist
                    if (!empty($transaction['variable_symbol'])) {
                        $this->setDataValue('symVar', $transaction['variable_symbol']);
                    }
                    if (!empty($transaction['constant_symbol'])) {
                        $this->setDataValue('symConst', $transaction['constant_symbol']);
                    }
                    if (!empty($transaction['specific_symbol'])) {
                        $this->setDataValue('symSpec', $transaction['specific_symbol']);
                    }
                    
                    // Set bank account ID from environment or use default
                    if (!empty($transaction['account_number']) && Shared::cfg('POHODA_BANK_IDS')) {
                        $this->setDataValue('account', Shared::cfg('POHODA_BANK_IDS'));
                    }
                    
                    // Add to Pohoda and commit
                    if ($this->addToPohoda() && $this->commit()) {
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
     * Build transaction description from available data
     *
     * @param array $transaction Transaction data
     * @return string Description text
     */
    protected function buildTransactionDescription(array $transaction): string
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

    /**
     * Generate MultiFlexi compatible report
     *
     * @param array $results Import results
     * @param string $outputFile Output file path
     * @return void
     */
    public function generateReport(array $results, string $outputFile = ''): void
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
        
        $jsonOutput = json_encode($report, Shared::cfg('DEBUG') ? JSON_PRETTY_PRINT : 0);
        
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
}
