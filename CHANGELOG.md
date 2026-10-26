# Changelog

All notable changes to this project will be documented in this file.

## [2.1.1] - 2026-10-21

### Fixed
- ACH/eCheck payments failed at checkout when "Authorize and capture" was enabled: the plugin requested an ACH authorization, which NMI does not support. ACH/eCheck, at checkout and in Net 30 bulk payment, is now always processed as a sale and marked paid; "Authorize and capture" applies to credit cards only.

## [2.1.0] - 2026-10-12

### Security
- Security hardening of the checkout, saved card and gateway communication. Updating is strongly recommended.
- Shoppers see a generic decline message instead of the gateway's decline reason.
- Removed the `billingId` cookie.

### Fixed
- Authorize-only payments (payment-first flow) were refused because the auth's `pending` state at NMI was not accepted.
- Paying again from the account order page (payment-first flow) was refused because the NMI order ID is the predicted next order number; a differing number is now logged instead, since the session binding identifies the transaction.
- Order-first payments with a saved card never submitted the order: the form was re-submitted while the first submit event was still being dispatched, which the browser ignores.
- The saved-card dropdown at checkout preselects the customer's default card even when it is stored in another vault than the first one.
- The Net 30 expiry task handler was registered for every scheduled task, so it also ran on each capture sweep (every 10 minutes); it now handles only its own task.
- The extension verifier and PHPCS pre-push checks pass again: the Blocked Payments module no longer imports its snippets into the bundle (they are loaded from the snippet folder), and PHP files follow PSR-12 (formatting only).
- NET 30 orders could never become overdue: the transition from "Invoicing Pending" to "Overdue" was skipped on install because the overdue state did not exist yet. Plugin update adds it.
- NET 30 payment handler: `supports()` and two error paths called methods that do not exist in Shopware 6.7 (fatal errors).
- The extension verifier passes with PHPStan: typed repositories, no deprecated search result accessors, no default context in CLI/webhook code, unused dependencies removed, partial-delivery capture amounts read in one query, NET 30 overdue flags written in one update.
- Order-first credit card payments used the sandbox key in live mode.
- A declined order-first payment now ends on the error page instead of the finish page.
- Refunds are made once per order return, capped at the captured amount minus earlier refunds, and only for the return that changed state. A manual "refunded" refunds the remaining balance once, and no longer marks the NMI transaction refunded when NMI declined the refund.
- Capture, void and refund use the order's newest NMI transaction when a payment was retried.
- A repeated cancel no longer sends a second void; a zero-amount void marks the NMI transaction voided.
- Exceeds-authorization retries read the auth amount from the auth action and store it; blocked captures record when they were blocked; capture writes only the custom fields it changes.
- Saved cards: one vault row per customer and vault, the default card is shown and charged correctly across vaults, card deletion follows what NMI holds (deleting a vault's last card deletes the vault) and reports gateway failures, and "Delete all" deletes every vault of the customer.
- ACH used the default sales channel's authorize setting.
- Gateway calls have connect and response timeouts.
- Blocked shipping methods disable the pay button like blocked shipping addresses.

## [2.0.3] - 2026-08-21

### Added
- Added customer-level card velocity protection and a Blocked Payments administration view.
- Added an idempotent capture service and scheduled retry task for deferred captures.
- Added `nmi:capture-order` for manually retrying the same idempotent capture flow.
- Added unit coverage for card velocity, gateway responses, legacy transaction rows, capture limits, and controller blocking.

### Fixed
- Rejected successful gateway responses that do not contain a transaction ID.
- Persisted verified NMI transactions before Shopware payment state transitions.
- Limited capture amounts to the gateway authorization and corrected partial-shipment calculations.
- Prevented failed capture and void requests from being recorded as successful.
- Added safe defaults for legacy transaction rows containing null values.
- Backfilled payment method technical names and limited NMI log retention to seven days.
- Fixed 3DS activation when disabled configuration is rendered as a string.
- Prevented duplicate storefront authorization requests and blocked order submission without a transaction reference.
- Fixed saved-card error handlers and guarded optional storefront elements.

### Security
- Removed full gateway response and card metadata from payment logs.

## [2.0.0] - 2026-08-19

### Fixed
- Customers can now complete payment from the account order page using NMI Credit Card or ACH/eCheck.
- Net-30 is now hidden from unapproved customers when they retry or edit payment from their account order page.

### Added
- Added approved-customer access control for the Net-30 payment method.
- Added a customer-detail administration toggle for approving customers for Net-30.
- Added a dedicated administration permission so only authorized administrators can manage Net-30 approvals.

### Changed
- Net-30 is now shown at checkout only for approved customer accounts.


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
