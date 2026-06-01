# Changelog

All notable changes to this project will be documented in this file.

## [1.1.5] - 2026-05-26

### Added
- **Net-30 Payment Method**: Net-30 (buy-now, pay-later) payment support is now built directly into the NMI plugin.
- **Order Number Passed to NMI as orderID**: The Shopware order number is now sent as `orderid` to NMI for both credit card and ACH payments, in both payment-first and order-first flows. This improves reconciliation and transaction tracking inside the NMI dashboard.
- **Net-30 Invoices with Multiple Saved Cards**: Customers with outstanding Net-30 invoices can now pay using any of their saved cards directly from the account invoices page. Supports both single-card and bulk multi-invoice payment. The invoice page has been redesigned for better usability.
- **Customer Group Configuration for Net-30**: Store administrators can now configure which customer groups have access to the Net-30 payment method from the plugin settings panel.
- **NMI Saved Cards Storefront Page**: Added a dedicated account page where logged-in customers can add new cards and delete existing ones. A link to this page is accessible from the account sidebar.
- **Net-30 ACH Bulk Payment**: Added support for paying Net-30 invoices via ACH (eCheck) in bulk payment flows.
- **Net-30 Payment Link**: Added payment link functionality for Net-30 invoices, with automatic expiration date handling and status/error pages.
- **Net-30 Scheduled Task**: Background task that runs every 3 hours to automatically detect and process expired Net-30 payments.
- **Net-30 Admin Panel Integration**: Administrators can view and manage Net-30 orders directly from the Shopware admin order detail view.

### Security

- Strengthened access controls across saved payment method operations to ensure users can only manage their own data.
- Improved server-side verification of payment transactions before processing state changes, covering both card and ACH flows.
- Tightened webhook logging to emit only the minimum necessary identifiers; removed verbose payload capture across payment services.
- Enforced authentication requirements on additional controllers and restricted sensitive admin endpoints to authorized roles only.


### Fixed
- Resolved a duplicate log entry that was generating two identical entries per webhook event.
- Fixed ACH payment configuration to correctly pass the sales channel context.
- Fixed refund event logger to use structured error logging with context.
  
---

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
