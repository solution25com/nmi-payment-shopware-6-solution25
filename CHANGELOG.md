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
- **Vault IDOR Protection**: Added `VaultOwnershipGuard` ownership verification to all vault mutation routes (view vaulted customer, delete vaulted customer, add card). Prevents a logged-in customer from accessing or modifying another customer's saved payment methods.
- **Transaction Verification (Credit Card)**: Browser-submitted `nmi_transaction_id` is now server-verified via `queryTransaction()` checking condition, amount, and order_id before any payment state transition.
- **Transaction Verification (ACH)**: Applied the same `queryTransaction()` server-side verification to the ACH payment flow.
- **Secure Webhook Logging**: Webhook events now log only `event_type` and `transaction_id` instead of the full provider payload. All other sensitive log points across `NMIWebhookService`, `NMIPaymentDataRequestService`, `OrderVoidNmiPayment`, and `RefundEventSubscriber` have been replaced with structured, redacted log entries.
- **Login and ACL Enforcement**: Added `_loginRequired: true` to `NMISavedCardsController` and `AccountInvoicesController`. Added `system_config:read` ACL restriction to the admin test-connection API endpoint.

### Fixed
- Removed a duplicate log entry in `NMIWebhookService` that was generating two identical entries per webhook event.
- Fixed `AchEcheck::pay()` to pass `$salesChannelId` when calling `getConfig('authorizeAndCapture')`.
- Fixed `RefundEventSubscriber` logger call signature to use `logger->error()` with structured context instead of broken `logger->log()`.

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
