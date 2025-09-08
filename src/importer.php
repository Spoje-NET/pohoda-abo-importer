<?php

/**
 * ABO Importer for Pohoda via mServer
 *
 * This script imports ABO bank statement files into Pohoda accounting software
 * using the mServer API. It parses ABO format files and creates bank movements.
 *
 * Usage: php src/importer.php [abo-file-path-or-pattern]
 *        php src/importer.php /path/to/file.abo
 *        php src/importer.php "/path/to/files/*.abo-standard"
 *        php src/importer.php "/path/to/files/file?.abo"
 *
 * @author VitexSoftware
 */

require __DIR__ . '/../vendor/autoload.php';

// Initialize environment configuration
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'POHODA_BANK_IDS' ], __DIR__ . '/../.env');

use SpojeNet\Pohoda\AboImporter\Bank;

/**
 * Display help information
 */
function showHelp(string $scriptName): void
{
    echo "ABO Importer for Pohoda via mServer\n\n";
    echo "USAGE:\n";
    echo "  php {$scriptName} [options] <file-path-or-pattern> [additional-patterns...]\n\n";
    echo "EXAMPLES:\n";
    echo "  # Import single file:\n";
    echo "  php {$scriptName} statement.abo\n\n";
    echo "  # Import all .abo-standard files in a directory:\n";
    echo "  php {$scriptName} \"tests/*.abo-standard\"\n\n";
    echo "  # Import files matching pattern with ? wildcard:\n";
    echo "  php {$scriptName} \"tests/2?_*.abo-standard\"\n\n";
    echo "  # Import multiple patterns or files:\n";
    echo "  php {$scriptName} file1.abo \"dir/*.abo\" \"other/?.abo-standard\"\n\n";
    echo "  # Generate report:\n";
    echo "  php {$scriptName} \"*.abo\" --output report.json\n\n";
    echo "OPTIONS:\n";
    echo "  -o, --output <file>      Save report to file (use - for stdout)\n";
    echo "  -e, --environment <file> Load environment from custom file\n";
    echo "  -h, --help              Show this help message\n\n";
    echo "PATTERNS:\n";
    echo "  *    Matches any number of characters\n";
    echo "  ?    Matches exactly one character\n";
    echo "  [ab] Matches any character in brackets\n\n";
    echo "Note: Patterns should be quoted to prevent shell expansion.\n";
}

// Main execution
$options = getopt('o::e::h', ['output::', 'environment::', 'help']);

// Check for help option
if (array_key_exists('help', $options) || array_key_exists('h', $options)) {
    showHelp($argv[0]);
    exit(0);
}

// Initialize environment
$envFile = array_key_exists('environment', $options) 
    ? $options['environment'] 
    : (array_key_exists('e', $options) ? $options['e'] : '.env');

/**
 * Resolve file paths from patterns and arguments
 *
 * @param array $argv Command line arguments
 * @return array Array of resolved file paths
 */
function resolveFilePaths(array $argv): array
{
    $filePaths = [];
    
    // Skip script name and process all non-option arguments
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        // Skip option arguments
        if (str_starts_with($arg, '-')) {
            // Skip next argument if this is an option that takes a value
            if (in_array($arg, ['-o', '--output', '-e', '--environment'])) {
                $i++; // Skip the value argument
            }
            continue;
        }
        
        // Check if the argument contains glob patterns
        if (strpos($arg, '*') !== false || strpos($arg, '?') !== false || strpos($arg, '[') !== false) {
            // Use glob to expand the pattern
            $matches = glob($arg);
            if ($matches) {
                $filePaths = array_merge($filePaths, $matches);
            } else {
                fwrite(STDERR, "Warning: Pattern '" . $arg . "' matched no files\n");
            }
        } else {
            // Regular file path
            $filePaths[] = $arg;
        }
    }
    
    return array_unique($filePaths);
}

// Get ABO file paths
$aboFilePaths = resolveFilePaths($argv);

if (empty($aboFilePaths)) {
    fwrite(STDERR, "No input files provided or no files matched the pattern\n");
    fwrite(STDERR, "Usage: php " . $argv[0] . " [file-path-or-pattern]\n");
    fwrite(STDERR, "Examples:\n");
    fwrite(STDERR, "  php " . $argv[0] . " file.abo\n");
    fwrite(STDERR, "  php " . $argv[0] . " \"*.abo-standard\"\n");
    fwrite(STDERR, "  php " . $argv[0] . " \"/path/to/files/*.abo\"\n");
    exit(1);
}

// Get output file for report
$outputFile = array_key_exists('output', $options) 
    ? $options['output'] 
    : (array_key_exists('o', $options) ? $options['o'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout'));

// Create Bank instance and run the import
$bank = new Bank();

// Determine whether to use single file or multi-file import
if (count($aboFilePaths) === 1) {
    // Single file import
    $results = $bank->importAboFile($aboFilePaths[0]);
} else {
    $bank->addStatusMessage("Found " . count($aboFilePaths) . " files to process", 'info');
    foreach ($aboFilePaths as $i => $path) {
        $bank->addStatusMessage("File " . ($i + 1) . ": " . basename($path), 'info');
    }
    $results = $bank->importMultipleAboFiles($aboFilePaths);
}

// Generate report if requested
if ($outputFile) {
    $bank->generateReport($results, $outputFile);
}

// Set exit code based on results
exit($results['status'] === 'error' ? 1 : 0);
