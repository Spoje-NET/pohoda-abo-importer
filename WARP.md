# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

The pohoda-abo-importer is a PHP application that imports ABO (bank statement) files into Pohoda accounting software using mServer. It parses ABO format bank statements and creates bank movements in Pohoda via the mServer API.

## Architecture

The project consists of:
- **ABO Parser**: Uses `spojenet/abo-parser` to parse bank statement files
- **Pohoda Connector**: Uses `vitexsoftware/pohoda-connector` to communicate with Pohoda via mServer
- **Importer Script**: Main logic in `src/importer.php` that processes movements
- **CLI Executables**: Bash scripts in `bin/` for command-line usage

## Dependencies

Key dependencies:
- `spojenet/abo-parser`: Parses ABO format bank statements
- `vitexsoftware/pohoda-connector`: Connects to Pohoda mServer API
- PHPStan for static analysis
- PHPUnit for testing

## Environment Configuration

Copy `example.env` to `.env` and configure:
- `POHODA_URL`: mServer endpoint (default: http://127.0.0.1:50000)
- `POHODA_USERNAME`/`POHODA_PASSWORD`: mServer credentials
- `POHODA_ICO`: Company identification number
- `POHODA_BANK_IDS`: Code of Bank import tos
- `POHODA_TIMEOUT`: API timeout in seconds
- `POHODA_DEBUG`: Enable debug mode

## Development Commands

### Setup
```bash
make vendor              # Install composer dependencies
```

### Code Quality
```bash
make static-code-analysis          # Run PHPStan analysis
make static-code-analysis-baseline # Generate PHPStan baseline
make cs                           # Fix coding standards with PHP CS Fixer
```

### Testing
```bash
make tests     # Run PHPUnit tests
make phpunit   # Alternative command for running tests
```

### Help
```bash
make help      # Show all available make targets
```

## Usage

The application processes ABO files and imports movements into Pohoda:

1. Place ABO file as `vystup.abo` in project root
2. Configure environment variables in `.env`
3. Run the importer: `bin/pohoda-abo-importer`

## Code Standards

- PHP 8.4 or later required
- Follow PSR-12 coding standard
- All code comments and messages in English
- Include docblocks for all functions and classes with parameters and return types
- Use meaningful variable names and avoid magic numbers/strings
- Always include type hints for function parameters and return types
- Handle exceptions properly with meaningful error messages
- Create or update PHPUnit tests when creating/updating classes

## CI/CD

GitHub Actions workflow runs on push/PR to main branch:
- Validates composer.json/composer.lock
- Caches composer packages
- Installs dependencies

## Development Notes

- The project uses `\Ease\Shared::init()` for environment configuration
- Main importer logic processes each movement from ABO parser and creates BankMovement records
- Error handling and logging should be implemented for production use
- Tests should be placed in `tests/` directory (currently not present)
