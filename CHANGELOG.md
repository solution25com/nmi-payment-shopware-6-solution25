# Changelog

All notable changes to this project will be documented in this file.

## [1.1.3] - 2026/04/24

### Fixed
- **Pay button issue**  
  Fixed an issue where the pay button prevented customers from completing the payment process in the new version of shopware.

- **Terms and conditions validation**  
  Improved validation to ensure payments cannot proceed unless the terms and conditions are properly selected.

---
## [1.1.2] - 2025-12-19

### Fixes
- Added compatibility with Shopware 6.7.x
---

## [1.1.1] - 2025-12-19

### Added Features
- Webhooks and validation to handle:
    - Void
    - Refund
    - Partial refund
- New payment method added - **ACH**
- Automatic webhook registration when the **Test Connection** button returns a successful response

### Fixed
- Resolved an issue where production and sandbox API keys did not correctly set the environment mode

---

[1.0.0] - 03-11-2025
Added
Initial release of the plugin.
Core functionality implemented.
Basic configuration options available.
