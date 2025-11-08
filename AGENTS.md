# Repository Guidelines

## Project Structure & Module Organization
- `flagshipshipping.php` is the core PrestaShop carrier module; controllers live under `controllers/`, views under `views/`.
- Supporting classes and SDK overrides sit in `classes/` and `vendor/`.
- Tests use PHPUnit and reside in `tests/` with a custom bootstrap; diagnostics and utilities live in `tools/`.
- Assets such as images are stored in `views/img/`, while configuration templates live in `views/templates/`.

## Build, Test, and Development Commands
- `composer install` — installs the FlagShip SDK and PHPUnit dependencies (run inside the module root).
- `vendor\bin\phpunit` — executes the module’s test suite defined in `tests/FlagshipShippingTest.php`.
- `php tools/flagship_diag/rate_check.php --cart-id=<ID>` — reproduces the checkout payload and prints SmartShip quote responses for troubleshooting.

## Coding Style & Naming Conventions
- Follow PrestaShop’s PHP style: 4‑space indentation, braces on the next line for class/method declarations, and snake_case for configuration keys.
- Prefer descriptive method names (`getPayloadForShipment`, `logQuoteTrafficIfEnabled`) and inline documentation where logic is non-obvious.
- Strings should be single-quoted unless interpolation or escaping makes double quotes clearer.
- Run `php -l <file>` or PHPUnit before committing to catch syntax issues.

## Testing Guidelines
- Tests use PHPUnit 9 (`vendor\bin\phpunit`); individual tests can be filtered, e.g., `vendor\bin\phpunit --filter testBuildCheckoutPayloadNormalizesAddresses`.
- Add new tests to `tests/FlagshipShippingTest.php`; mirror existing naming (`test…`) and leverage the proxy helper to access protected methods.
- Keep scenario fixtures deterministic—mock packing responses or configuration via the bootstrap stubs when possible.
- Always rerun relevant tests locally before replying; double-check your changes satisfy the latest requirements and state any assumptions explicitly.

## Commit & Pull Request Guidelines
- Use concise, action-oriented commit subjects (e.g., “Normalize SmartShip payload…”); the history favors sentence case without trailing periods.
- Each pull request should describe the functional change, reference relevant configurations (e.g., `FS_DEBUG_RATE_TRAFFIC`), and include test evidence (`vendor\bin\phpunit` output, diagnostic steps, screenshots for UI changes).
- Highlight any new config flags or manual steps in the PR body so integrators can update PrestaShop settings accordingly.
- Do not commit unless specifically required to by the user.
- Output the suggested commit message for the user.
