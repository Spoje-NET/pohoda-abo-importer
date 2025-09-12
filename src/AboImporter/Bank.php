<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaABOimporter package
 *
 * https://github.com/Spoje-NET/pohoda-abo-importer
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\Pohoda\AboImporter;

use Ease\Shared;

class Bank extends \mServer\Bank
{
    /**
     * Is Record with current transaction ID already present in Pohoda?
     *
     * @param string $transactionId Transaction identifier
     *
     * @return bool True if transaction exists
     */
    public function checkForTransactionPresence(string $transactionId): bool
    {
        // Create a new checker instance to avoid modifying current state
        $checker = new \mServer\Bank();
        $checker->userAgent(Shared::AppName().'-'.Shared::AppVersion().' '.$this->userAgent());
        $checker->defaultHttpHeaders['STW-Application'] = Shared::AppName().' '.Shared::AppVersion();

        // Search for records with the transaction ID in the internal note field (Pozn2)
        $filter = "Pozn2 like '%#{$transactionId}#%'";
        $lrq = $checker->queryFilter($filter, 'TransactionID: '.$transactionId);

        $found = $checker->getListing($lrq);

        // If the result is invalid, throw an exception
        if ($found === false) {
            throw new \RuntimeException('Error fetching records for transaction check.');
        }

        return !empty($found);
    }

    /**
     * Extract transaction ID from internal note field.
     *
     * @param null|string $intNote Internal note content
     *
     * @return null|string Transaction ID or null if not found
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
     * Main importer method to process ABO file.
     *
     * @param string $aboFilePath Path to the ABO file to import
     *
     * @return array Import results for reporting
     */
    public function importAboFile(string $aboFilePath): array
    {
        $startTime = new \DateTime();
        $this->addStatusMessage("Starting ABO import from: {$aboFilePath}", 'info');

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
            $this->addStatusMessage($results['message'], 'error');

            return $results;
        }

        try {
            // Parse ABO file
            $parser = new \SpojeNet\AboParser\AboParser();
            $parsed = $parser->parseFile($aboFilePath);

            $this->addStatusMessage("Parsed ABO file with format: {$parsed['format_version']}", 'info');
            $this->addStatusMessage('Found '.\count($parsed['statements']).' statements and '.\count($parsed['transactions']).' transactions', 'info');

            $importedCount = 0;
            $errorCount = 0;
            $skippedCount = 0;

            $results['metrics']['total_transactions'] = \count($parsed['transactions']);

            // Process each transaction
            foreach ($parsed['transactions'] as $transaction) {
                try {
                    // Create unique transaction ID from document number and account
                    $transactionId = 'ABO_'.$transaction['document_number'].'_'.$transaction['account_number'];

                    // Check if this transaction already exists
                    if ($this->checkForTransactionPresence($transactionId)) {
                        ++$skippedCount;
                        $results['artifacts']['skipped_transactions'][] = [
                            'transaction_id' => $transactionId,
                            'document_number' => $transaction['document_number'],
                            'amount' => $transaction['amount'],
                            'date' => $transaction['valuation_date'] ?? $transaction['due_date'],
                            'reason' => 'Duplicate transaction already exists in Pohoda',
                        ];
                        $this->addStatusMessage("Transaction already exists, skipping: {$transaction['document_number']}", 'warning');

                        continue;
                    }

                    // Reset bank object for new transaction
                    $this->reset();
                    $xmlData = [];

                    // Set basic transaction data
                    $xmlData['bankType'] = $transaction['amount'] > 0 ? 'receipt' : 'expense';
                    $xmlData['datePayment'] = $transaction['valuation_date'] ?? $transaction['due_date'];
                    $xmlData['dateStatement'] = $transaction['valuation_date'] ?? $transaction['due_date'];
                    $xmlData['text'] = $this->buildTransactionDescription($transaction);
                    $xmlData['note'] = ''; // External note field
                    $xmlData['intNote'] = sprintf('%s %s Job: %s Trans: #%s#', Shared::appName(), Shared::appVersion(), Shared::cfg('MULTIFLEXI_JOB_ID', Shared::cfg('JOB_ID', 'n/a')), $transactionId);

                    // Set amount using homeCurrency structure (following Raiffeisen bank pattern)
                    // Always use absolute value and let bankType determine direction
                    $xmlData['homeCurrency'] = [
                        'priceNone' => abs($transaction['amount']),
                    ];

                    // Add partner account and identity if we have counter account (following Raiffeisen pattern)
                    if (!empty($transaction['counter_account'])) {
                        $paymentAccount = [
                            'accountNo' => $transaction['counter_account'],
                        ];

                        // Add bank code if available
                        if (!empty($transaction['counter_bank_code'])) {
                            $paymentAccount['bankCode'] = $transaction['counter_bank_code'];
                        } else {
                            $paymentAccount['bankCode'] = Shared::cfg('POHODA_BANK_CODE');
                        }

                        $xmlData['paymentAccount'] = $paymentAccount;

                        // Add partner identity if we have additional info about the counter party
                        if (!empty($transaction['additional_info'])) {
                            $xmlData['partnerIdentity'] = [
                                'address' => [
                                    'name' => $transaction['additional_info'],
                                ],
                            ];
                        }
                    }

                    // Add symbols if they exist
                    if (!empty($transaction['variable_symbol'])) {
                        $xmlData['symVar'] = $transaction['variable_symbol'];
                    }

                    if (!empty($transaction['constant_symbol'])) {
                        $xmlData['symConst'] = $transaction['constant_symbol'];
                    }

                    if (!empty($transaction['specific_symbol'])) {
                        $xmlData['symSpec'] = $transaction['specific_symbol'];
                    }

                    // Set bank account ID from environment or leave empty
                    if (Shared::cfg('POHODA_BANK_IDS')) {
                        $xmlData['account'] = Shared::cfg('POHODA_BANK_IDS');
                    }

                    // Add to Pohoda and commit (following exact Raiffeisen pattern)
                    if ($this->insertTransactionToPohoda($xmlData) && $this->commit()) {
                        ++$importedCount;
                        $results['artifacts']['imported_transactions'][] = [
                            'transaction_id' => $transactionId,
                            'document_number' => $transaction['document_number'],
                            'amount' => $transaction['amount'],
                            'date' => $transaction['valuation_date'] ?? $transaction['due_date'],
                        ];
                        $this->addStatusMessage("Imported transaction: {$transaction['document_number']} (Amount: {$transaction['amount']})", 'success');
                    } else {
                        ++$errorCount;
                        $results['artifacts']['failed_transactions'][] = [
                            'transaction_id' => $transactionId,
                            'document_number' => $transaction['document_number'],
                            'error' => 'Failed to commit to Pohoda',
                        ];
                        $this->addStatusMessage("Failed to import transaction: {$transaction['document_number']}", 'warning');
                    }
                } catch (\Exception $e) {
                    ++$errorCount;
                    $results['artifacts']['failed_transactions'][] = [
                        'transaction_id' => $transactionId ?? 'unknown',
                        'document_number' => $transaction['document_number'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ];
                    $this->addStatusMessage("Error importing transaction {$transaction['document_number']}: ".$e->getMessage(), 'error');
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

            $this->addStatusMessage($results['message'], $results['status'] === 'error' ? 'error' : 'info');
        } catch (\Exception $e) {
            $results['status'] = 'error';
            $results['message'] = 'Fatal error during import: '.$e->getMessage();
            $results['metrics']['processing_time_seconds'] = (new \DateTime())->getTimestamp() - $startTime->getTimestamp();
            $this->addStatusMessage($results['message'], 'error');
        }

        // Set final timestamp
        $results['timestamp'] = (new \DateTime())->format(\DateTime::ATOM);

        return $results;
    }

    /**
     * Import multiple ABO files.
     *
     * @param array $aboFilePaths Array of ABO file paths to import
     *
     * @return array Consolidated import results
     */
    public function importMultipleAboFiles(array $aboFilePaths): array
    {
        $overallStartTime = new \DateTime();

        $consolidatedResults = [
            'status' => 'success',
            'timestamp' => null,
            'message' => '',
            'artifacts' => [
                'imported_transactions' => [],
                'failed_transactions' => [],
                'skipped_transactions' => [],
                'processed_files' => [],
                'failed_files' => [],
            ],
            'metrics' => [
                'total_files' => \count($aboFilePaths),
                'processed_files' => 0,
                'failed_files' => 0,
                'total_transactions' => 0,
                'imported_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'processing_time_seconds' => 0,
            ],
            'file_results' => [],
        ];

        $this->addStatusMessage('Starting batch ABO import for '.\count($aboFilePaths).' files', 'info');

        foreach ($aboFilePaths as $filePath) {
            $this->addStatusMessage('Processing file: '.basename($filePath), 'info');

            try {
                $fileResult = $this->importAboFile($filePath);
                $consolidatedResults['file_results'][] = $fileResult;

                // Aggregate metrics
                $consolidatedResults['metrics']['total_transactions'] += $fileResult['metrics']['total_transactions'] ?? 0;
                $consolidatedResults['metrics']['imported_count'] += $fileResult['metrics']['imported_count'] ?? 0;
                $consolidatedResults['metrics']['error_count'] += $fileResult['metrics']['error_count'] ?? 0;
                $consolidatedResults['metrics']['skipped_count'] += $fileResult['metrics']['skipped_count'] ?? 0;

                // Merge artifacts
                $consolidatedResults['artifacts']['imported_transactions'] = array_merge(
                    $consolidatedResults['artifacts']['imported_transactions'],
                    $fileResult['artifacts']['imported_transactions'] ?? [],
                );
                $consolidatedResults['artifacts']['failed_transactions'] = array_merge(
                    $consolidatedResults['artifacts']['failed_transactions'],
                    $fileResult['artifacts']['failed_transactions'] ?? [],
                );
                $consolidatedResults['artifacts']['skipped_transactions'] = array_merge(
                    $consolidatedResults['artifacts']['skipped_transactions'],
                    $fileResult['artifacts']['skipped_transactions'] ?? [],
                );

                if ($fileResult['status'] === 'error') {
                    ++$consolidatedResults['metrics']['failed_files'];
                    $consolidatedResults['artifacts']['failed_files'][] = [
                        'file' => $filePath,
                        'error' => $fileResult['message'],
                    ];
                    $this->addStatusMessage('Failed to process file: '.basename($filePath).' - '.$fileResult['message'], 'error');
                } else {
                    ++$consolidatedResults['metrics']['processed_files'];
                    $consolidatedResults['artifacts']['processed_files'][] = [
                        'file' => $filePath,
                        'transactions' => $fileResult['metrics']['imported_count'] ?? 0,
                        'status' => $fileResult['status'],
                    ];
                    $this->addStatusMessage('Successfully processed file: '.basename($filePath).' ('.($fileResult['metrics']['imported_count'] ?? 0).' transactions)', 'success');
                }
            } catch (\Exception $e) {
                ++$consolidatedResults['metrics']['failed_files'];
                $consolidatedResults['artifacts']['failed_files'][] = [
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ];
                $this->addStatusMessage('Exception processing file: '.basename($filePath).' - '.$e->getMessage(), 'error');
            }
        }

        // Calculate overall processing time
        $consolidatedResults['metrics']['processing_time_seconds'] =
            (new \DateTime())->getTimestamp() - $overallStartTime->getTimestamp();

        // Set overall status
        if ($consolidatedResults['metrics']['failed_files'] === \count($aboFilePaths)) {
            $consolidatedResults['status'] = 'error';
            $consolidatedResults['message'] = sprintf(
                'Batch import failed: All %d files failed to process',
                \count($aboFilePaths),
            );
        } elseif ($consolidatedResults['metrics']['failed_files'] > 0) {
            $consolidatedResults['status'] = 'warning';
            $consolidatedResults['message'] = sprintf(
                'Batch import completed with issues: %d/%d files processed successfully, %d transactions imported, %d errors, %d skipped',
                $consolidatedResults['metrics']['processed_files'],
                $consolidatedResults['metrics']['total_files'],
                $consolidatedResults['metrics']['imported_count'],
                $consolidatedResults['metrics']['error_count'],
                $consolidatedResults['metrics']['skipped_count'],
            );
        } else {
            $consolidatedResults['message'] = sprintf(
                'Batch import successful: %d files processed, %d transactions imported, %d skipped',
                $consolidatedResults['metrics']['processed_files'],
                $consolidatedResults['metrics']['imported_count'],
                $consolidatedResults['metrics']['skipped_count'],
            );
        }

        $consolidatedResults['timestamp'] = (new \DateTime())->format(\DateTime::ATOM);
        $this->addStatusMessage($consolidatedResults['message'], $consolidatedResults['status'] === 'error' ? 'error' : 'info');

        return $consolidatedResults;
    }

    /**
     * Generate MultiFlexi compatible report.
     *
     * @param array  $results    Import results
     * @param string $outputFile Output file path
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
            $report['artifacts']['imported_transactions'] = array_map(static function ($tx) {
                return "Transaction {$tx['document_number']}: {$tx['amount']} on {$tx['date']}";
            }, $results['artifacts']['imported_transactions']);
        }

        if (!empty($results['artifacts']['failed_transactions'])) {
            $report['artifacts']['failed_transactions'] = array_map(static function ($tx) {
                return "Failed {$tx['document_number']}: {$tx['error']}";
            }, $results['artifacts']['failed_transactions']);
        }

        if (!empty($results['artifacts']['skipped_transactions'])) {
            $report['artifacts']['skipped_transactions'] = array_map(static function ($tx) {
                return "Skipped {$tx['document_number']}: {$tx['amount']} on {$tx['date']} - {$tx['reason']}";
            }, $results['artifacts']['skipped_transactions']);
        }

        // Add batch-specific artifacts if they exist
        if (!empty($results['artifacts']['processed_files'])) {
            $report['artifacts']['processed_files'] = array_map(static function ($file) {
                return "Processed {$file['file']}: {$file['transactions']} transactions ({$file['status']})";
            }, $results['artifacts']['processed_files']);
        }

        if (!empty($results['artifacts']['failed_files'])) {
            $report['artifacts']['failed_files'] = array_map(static function ($file) {
                return "Failed {$file['file']}: {$file['error']}";
            }, $results['artifacts']['failed_files']);
        }

        $jsonOutput = json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0);

        if ($outputFile && $outputFile !== 'php://stdout') {
            $written = file_put_contents($outputFile, $jsonOutput);

            if ($written) {
                echo "Report saved to: {$outputFile}\n";
            } else {
                echo "Failed to save report to: {$outputFile}\n";
            }
        } else {
            echo $jsonOutput."\n";
        }
    }

    /**
     * Build transaction description from available data.
     *
     * @param array $transaction Transaction data
     *
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
     * Insert transaction to Pohoda (following Raiffeisen Bank pattern).
     *
     * @return bool Success status
     */
    protected function insertTransactionToPohoda(array $xmlData): bool
    {
        $this->takeData($xmlData);

        try {
            $result = $this->addToPohoda();

            return $result instanceof self;
        } catch (\Exception $e) {
            $this->addStatusMessage('Error inserting transaction to Pohoda: '.$e->getMessage(), 'error');

            return false;
        }
    }
}
