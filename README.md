# Pohoda ABO Importer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-blue.svg)](https://php.net)
[![MultiFlexi Compatible](https://img.shields.io/badge/MultiFlexi-Compatible-green.svg)](https://github.com/VitexSoftware/MultiFlexi)

A robust PHP application for importing ABO (Czech bank statement format) files into Pohoda accounting software using the mServer API. Features comprehensive error handling, duplicate detection, and MultiFlexi-compatible reporting.

## Features

### 🏦 **ABO Format Support**
- **Complete ABO Parsing**: Supports both basic and extended ABO formats
- **Automatic Detection**: Automatically detects ABO format version
- **Czech Banking Standard**: Full compliance with Czech ABO format specification
- **Multiple File Support**: Process single files or batch operations

### 🔄 **Pohoda Integration**
- **mServer API**: Direct integration with Pohoda via mServer
- **Bank Movements**: Creates proper bank movement records
- **Automatic Classification**: Receipt/expense classification based on amount
- **Symbol Mapping**: Variable, constant, and specific symbol support

### 🛡️ **Idempotency & Data Integrity**
- **Duplicate Detection**: Prevents reimporting the same transactions
- **Transaction Tracking**: Unique ID generation for each transaction
- **Safe Re-imports**: Run the same import multiple times safely
- **Data Validation**: Comprehensive validation before import

### 📊 **Professional Reporting**
- **MultiFlexi Compatible**: Reports conform to MultiFlexi schema
- **Detailed Metrics**: Import statistics and processing times
- **Comprehensive Artifacts**: Lists of imported, failed, and skipped transactions
- **JSON/Human Readable**: Machine and human-readable output formats

### ⚡ **Enterprise Features**
- **CLI Interface**: Command-line tools for automation
- **Environment Configuration**: Flexible configuration via environment variables
- **Extensive Logging**: Debug and audit trails
- **Error Recovery**: Graceful handling of connection issues

## Installation

### Requirements
- PHP 8.4 or later
- Composer
- Pohoda mServer running and accessible
- Access to ABO format bank statement files

### Via Composer
```bash
composer install
```

### Via APT (when available)
```bash
apt install pohoda-abo-importer
```

## Configuration

### Environment Setup
1. Copy the example configuration:
```bash
cp example.env .env
```

2. Edit `.env` with your settings:
```bash
# Pohoda mServer Configuration
POHODA_URL=http://127.0.0.1:50000
POHODA_USERNAME=api
POHODA_PASSWORD=your_password
POHODA_ICO=12345678
POHODA_TIMEOUT=60
POHODA_DEBUG=false

# Bank Account Mapping
POHODA_BANK_IDS=KB

# Logging
EASE_LOGGER=console|syslog

# Report Output (optional)
RESULT_FILE=import_report.json
```

### Required Settings
- `POHODA_URL`: mServer endpoint URL
- `POHODA_USERNAME`: mServer authentication username
- `POHODA_PASSWORD`: mServer authentication password
- `POHODA_ICO`: Company identification number

## Usage

### Basic Import
```bash
# Import a specific ABO file
php src/importer.php path/to/bank_statement.abo-standard

# Import default file (vystup.abo)
php src/importer.php

# Using the CLI tool
bin/pohoda-abo-importer path/to/bank_statement.abo-standard
```

### With Report Generation
```bash
# Save report to file
php src/importer.php statement.abo-standard -o import_report.json

# Output report to stdout
php src/importer.php statement.abo-standard -o php://stdout

# Use environment variable
RESULT_FILE=report.json php src/importer.php statement.abo-standard
```

### Environment-specific Configuration
```bash
# Use different environment file
php src/importer.php statement.abo-standard -e production.env

# Combine options
php src/importer.php statement.abo-standard -e prod.env -o prod_report.json
```

## Report Format

The importer generates comprehensive reports in MultiFlexi-compatible JSON format:

```json
{
    "status": "success|warning|error",
    "timestamp": "2025-09-07T23:57:41+00:00",
    "message": "Import successful: 5 imported, 2 skipped",
    "artifacts": {
        "imported_transactions": [
            "Transaction 123456: 1000.50 on 2025-09-07",
            "Transaction 123457: -500.25 on 2025-09-07"
        ],
        "failed_transactions": [
            "Failed 123458: Connection timeout to mServer"
        ],
        "skipped_transactions": [
            "Skipped 123459: 750.00 on 2025-09-07 - Duplicate transaction already exists in Pohoda"
        ]
    },
    "metrics": {
        "total_transactions": 7,
        "imported_count": 5,
        "error_count": 1,
        "skipped_count": 2,
        "processing_time_seconds": 12
    }
}
```

### Report Fields
- **status**: Overall import result (`success`, `warning`, `error`)
- **timestamp**: ISO8601 completion timestamp
- **message**: Human-readable summary
- **artifacts**: Detailed lists of processed transactions
- **metrics**: Import statistics and performance data

## Development

### Code Quality
```bash
# Install dependencies
make vendor

# Run static analysis
make static-code-analysis

# Fix coding standards
make cs

# Run tests
make tests
```

### Available Make Targets
```bash
make help    # Show all available targets
```

## Architecture

### Core Components
- **ABO Parser**: `spojenet/abo-parser` - Parses Czech ABO format files
- **Pohoda Connector**: `vitexsoftware/pohoda-connector` - mServer API client
- **Custom Bank Class**: Enhanced bank client with idempotency support
- **Report Generator**: MultiFlexi-compatible report generation

### Data Flow
1. **Parse**: ABO file → Structured transaction data
2. **Validate**: Check for duplicates and data integrity
3. **Transform**: Map ABO fields to Pohoda bank movement fields
4. **Import**: Send to Pohoda via mServer API
5. **Report**: Generate comprehensive import report

### Idempotency Implementation
- **Unique IDs**: Format `ABO_{document_number}_{account_number}`
- **Duplicate Check**: Search existing records by transaction ID
- **Safe Storage**: Transaction IDs stored in internal note field
- **Pattern**: `#ABO_123456_789012345#` for easy identification

## MultiFlexi Integration

This application is fully compatible with MultiFlexi application management:

```json
{
    "name": "Pohoda ABO Importer",
    "description": "Import ABO bank statement files into Pohoda using mServer",
    "executable": "pohoda-abo-importer",
    "requirements": ["mServer", "Pohoda"],
    "topics": ["Bank", "ABO", "Import", "Pohoda", "Statements"]
}
```

## Troubleshooting

### Common Issues

#### Connection Problems
```bash
# Check mServer status
curl http://127.0.0.1:50000/status

# Enable debug mode
POHODA_DEBUG=true php src/importer.php file.abo-standard
```

#### Import Failures
```bash
# Check detailed logs
EASE_LOGGER=console php src/importer.php file.abo-standard

# Validate ABO file format
php -r "require 'vendor/autoload.php'; \$p = new \SpojeNet\AboParser\AboParser(); var_dump(\$p->parseFile('file.abo-standard'));"
```

#### Permission Issues
```bash
# Ensure proper file permissions
chmod +x bin/pohoda-abo-importer

# Check file access
ls -la path/to/abo/file
```

### Debug Information
- XML request/response files saved in `/tmp/`
- Enable `POHODA_DEBUG=true` for detailed XML validation
- Use `EASE_LOGGER=console` for verbose logging

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Standards
- PHP 8.4+ with strict types
- PSR-12 coding standard
- English comments and messages
- Comprehensive docblocks
- PHPUnit tests for new features
- Static analysis with PHPStan

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/Spoje-NET/pohoda-abo-importer/issues)
- **Documentation**: [Project Wiki](https://github.com/Spoje-NET/pohoda-abo-importer/wiki)
- **Community**: [Discussions](https://github.com/Spoje-NET/pohoda-abo-importer/discussions)

## Related Projects

- [pohoda-raiffeisenbank](https://github.com/Spoje-NET/pohoda-raiffeisenbank) - Raiffeisenbank API integration
- [php-abo-parser](https://github.com/Spoje-NET/php-abo-parser) - ABO format parser library
- [PHP-Pohoda-Connector](https://github.com/VitexSoftware/PHP-Pohoda-Connector) - Pohoda mServer client
- [MultiFlexi](https://github.com/VitexSoftware/MultiFlexi) - Application management platform

---

**Made with ❤️ by [Spoje.Net IT](https://spojenet.cz)**
