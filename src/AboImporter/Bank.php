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
}
