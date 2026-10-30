# MFC-Operations — Project Handoff for Codex

> **Purpose:** Give Codex a reliable starting point for future work so it can understand the application's architecture, workflows, conventions, and recent changes without re-reading every file on every task.
>
> **Repository:** https://github.com/erichmerilles/MFC-Operations  
> **Primary branch reviewed:** `main`  
> **Application source folder:** `MFC-Operations/`  
> **Last handoff review:** 2026-10-26
>
> **Important:** This document is a project map, not a substitute for checking the specific files involved in a change. The repository is the source of truth. If the code, database, or this document disagree, inspect the current code and schema and update this document as needed. Do not assume static review proves runtime behavior.

---

## 1. Project overview

MFC-Operations is a PHP/MySQL internal operations application for managing withdrawal records and preparing invoice drafts for QuickBooks Desktop Enterprise Solutions 24.0.

The application separates presentation pages from backend operations:

- **Frontend:** PHP pages, shared layout, styles, and browser-side JavaScript.
- **Backend:** application bootstrap/configuration, authentication and shared helpers, POST controllers, JSON APIs, exports, database schema, and migrations.
- **Database:** MySQL, normally run locally through XAMPP during development.
- **QuickBooks:** invoice preparation remains a local draft workflow. A signed localhost bridge and PHP posting lifecycle now contain a narrowly allowlisted, offline-tested InvoiceAdd implementation, but all live posting remains default-disabled and gated on administrator/company setup. Software readiness is not proof of a real company-file transaction.

The application should preserve the distinction between the original withdrawal transaction and the invoice draft created from it.

## 2. Repository and source layout

The repository has a project folder nested inside the repository. Most application files are under `MFC-Operations/`, not the repository root.

The documented layout is approximately:

```text
MFC-Operations/
├── index.php
├── frontend/
│   ├── assets/                 # CSS, JavaScript, and frontend assets
│   ├── includes/               # Shared visual layout and navigation
│   ├── login.php
│   ├── setup.php               # Initial administrator setup; remove after use
│   ├── dashboard.php
│   ├── withdrawal_form.php
│   ├── withdrawal_print.php
│   ├── withdrawals.php
│   ├── invoice_preparation.php
│   ├── clients.php             # Client master and invoice history
│   ├── products_sites.php      # Product and site master data
│   ├── settings.php
│   └── quickbooks_status.php   # Status view; not proof of working QB integration
└── backend/
    ├── config/
    │   └── app.php             # Application bootstrap/configuration
    ├── core/                   # Database, authentication, authorization,
    │                           # validation, and shared business helpers
    ├── controllers/            # POST actions (for example save withdrawal/invoice)
    ├── api/                    # JSON endpoints called by frontend JavaScript
    ├── exports/                # CSV export endpoints
    ├── database/
    │   ├── schema.sql          # Initial database schema
    │   └── migrations/         # Controlled changes to existing databases
    └── ...
```

This is a guide to the expected structure, not an exhaustive file listing. Check the actual repository tree before adding a new file or assuming a path.

### Key file references

- [Repository](https://github.com/erichmerilles/MFC-Operations)
- [Application README](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/README.md)
- [Withdrawal register](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/withdrawals.php)
- [Withdrawal form](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/withdrawal_form.php)
- [Invoice preparation](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/invoice_preparation.php)
- [Clients page](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/clients.php)
- [Products & Sites page](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/products_sites.php)
- [Shared sidebar](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/includes/sidebar.php)
- [Client invoice history API](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/backend/api/client_invoice_history.php)
- [Client invoice detail API](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/backend/api/client_invoice_detail.php)
- [Application README's database upgrade instructions](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/README.md)

## 3. Main business workflow

### A. Withdrawal workflow

1. An authenticated user opens the Withdrawal Register.
2. The user creates or opens a withdrawal in `frontend/withdrawal_form.php`.
3. The form loads active clients and eligible products from the database.
4. Withdrawal line items are saved to the withdrawal tables by the relevant backend controller.
5. The user can find saved withdrawals in `frontend/withdrawals.php`.
6. A saved withdrawal can be opened, printed, or used as the source for invoice preparation.

A withdrawal is the original operational record. Invoice-only edits must not silently change the withdrawal's original line items or values.

### B. Invoice preparation workflow

1. The user opens Invoice Preparation from a saved withdrawal.
2. `frontend/invoice_preparation.php` locates the withdrawal and its invoice draft.
3. If a draft does not exist, the page creates an invoice header and copies withdrawal lines into `invoice_items`.
4. Invoice lines are stored separately from withdrawal lines. They may contain invoice-specific item mapping, description, unit, price, amount, tax choice, site, and additional invoice-only lines, according to the current schema and UI.
5. The invoice save controller validates the submitted data and saves the draft.
6. The invoice status controls whether it can be edited. Current code treats statuses such as `Invoiced`, `Queued`, `Processing`, and `Uncertain Result` as locked because a submission may have occurred.
7. Live QuickBooks posting is disabled. The package includes a Windows bridge for authenticated health checks and bounded, read-only diagnostics only. A running QuickBooks process is not proof that the intended company file is open or authorized.

Invoice drafts also have a local `invoices.memo` field. `frontend/invoice_preparation.php` loads it with the invoice header and posts edits to `backend/controllers/invoice_save.php`; the controller validates and saves it in the existing invoice update transaction. Existing installations need `20261019_invoice_memo.sql`. This memo is not synchronized to QuickBooks.

Displayed monetary values use PHP peso formatting with two decimals and thousands separators. Editable price fields show grouped values when not focused and accept correctly grouped comma input; database values remain numeric.

The invoice line table in `frontend/invoice_preparation.php` uses fixed pixel widths for compact fields and lets Description take the remaining space, styled in `frontend/assets/css/app.css`. The shared header adds an asset modification-time query to the CSS URL so updated styles are fetched. Product selection displays only `products.qb_description`. Product Master `item_code` and `qb_code` are aliases for the same business product code; invoice and Sales Order lines use that code, not a QuickBooks ListID. Opening a draft does not update saved invoice descriptions.

The updated invoice page passed PHP syntax validation. Automated PHP, MariaDB, bridge HTTP, and compiled Windows-bridge tests are present; passing them does not prove a real company-file connection. The revised layout has not been visually checked in a browser.

**Do not implement automatic QuickBooks posting, enable a send action, or claim successful QB integration unless that is explicitly requested and all integration prerequisites are verified.**

Sales Order Preparation is implemented as a separate local-only workflow in `frontend/sales_order_preparation.php` with saves handled by `backend/controllers/sales_order_save.php`. It uses separate `sales_orders` and `sales_order_items` tables from `20261019_sales_order_preparation.sql`, links each draft to its source withdrawal, and preserves invoice records unchanged. Apply the migration before using the screen. S.O. NO. is manually entered and unique; customer/date/PO default from the withdrawal; ITEM is selected from Product Master and its internal `products.qb_list_id` reference is snapshotted when present; staff do not enter ListIDs. DESCRIPTION defaults to the stored QB product description; SITE is selected from active sites; ORDERED defaults to the whole withdrawal quantity; U/M is displayed read-only and saved from Product Master; RATE defaults from Product Master; AMOUNT is recalculated from ORDERED × RATE. LOT NUMBER, CLASS, STATUS, FULFILLED QUANTITY, EXPIRATION DATE, TAX, and Memo remain optional local values. Blank TAX is saved as `NULL` and requires the reviewed `20261026_sales_order_optional_tax.sql` migration when the target schema still has a non-null enum column. The app has no live QuickBooks Sales Order submission or verified field mapping; do not queue or send these drafts.

### C. Client invoice history workflow (recent update)

1. The authenticated user opens `frontend/clients.php`.
2. Clients are displayed in an accordion, with search and client-list pagination.
3. Opening a client's accordion loads invoice history through `backend/api/client_invoice_history.php`.
4. Invoice history is scoped to the selected client and paginated at 10 invoices per page.
5. Clicking an invoice number / details action opens a Bootstrap modal.
6. The modal requests `backend/api/client_invoice_detail.php` with the selected invoice ID and client ID.
7. The detail API verifies that the invoice belongs to that client, then loads the invoice header and actual invoice rows from `invoice_items`. It does **not** use withdrawal rows as a substitute for the invoice's saved rows.
8. The modal displays the stored invoice lines and a calculated sum of saved line amounts.

## 4. Data concepts and mapping rules

Use the actual current schema and controller validation as the final authority. The following distinctions are important:

- **Client/customer:** A record in `clients`; invoice headers reference a client/customer ID.
- **Withdrawal:** Original operational record, identified by a withdrawal number such as `withdrawal_no`.
- **Withdrawal line:** An item in `withdrawal_items`, linked to a withdrawal and product.
- **Invoice draft/header:** A record in `invoices`, associated with a source withdrawal and customer.
- **Invoice line:** A saved row in `invoice_items`, associated with an invoice. Invoice rows can differ from withdrawal rows.
- **Product:** Master record in `products`.
- **Site:** Master record in `sites`; invoice lines store their selected site through `site_id` where applicable.

### Product identifiers and descriptions

Do not treat the following as interchangeable:

- **HT Code:** Withdrawal-side business code.
- **HT Description:** Withdrawal-side display description.
- **Item Code / QB Code:** The same business product code, stored in the legacy `products.item_code` and current `products.qb_code` columns. New and edited Product Master records write the same value to both fields. Invoice and Sales Order saves reject conflicting stored values; Product Master requires explicit confirmation before resolving a conflict.
- **QB Description:** Invoice-side invoice description.
- **`products.qb_list_id`:** Internal QuickBooks ListID reference, not the business Item Code. It is not entered or displayed to staff; current signed reference verification, export eligibility, and Sales Order draft snapshots consume it. The signed verification table also stores the evidence/ListID, but current code requires the local product mirror to agree. Retain the column until a reviewed replacement updates all these dependencies and preserves existing Sales Order snapshots.

Product/site master logic supports separate withdrawal-side HT mappings and invoice-side QB descriptions. Item Code and QB Code are the confirmed exception: they represent the same business product code. Do not assume HT Code or either description is interchangeable with that code.

### Site rules

- Sites are maintained as master records.
- Invoice site selection is associated with each invoice line.
- The intended updated design does not rely on a product-level default site.
- Before changing schema or queries involving sites, search the current code for `default_site_id` and confirm the migration's staged cleanup has actually been completed in the target database.

### Amounts and invoice totals

- The invoice preparation page currently computes its displayed sum from saved `invoice_items.amount` values.
- The client invoice detail API likewise calculates `subtotal` by summing saved line amounts.
- Do not assume this is the final QuickBooks invoice total, tax-inclusive total, or total after all discounts/charges. Confirm the actual business rules and schema before changing the label or calculation.
- If the source of the final total is not explicitly represented, label the value accurately as a subtotal/sum of saved line amounts rather than inventing tax or discount rules.

## 5. Authentication, security, and coding conventions

Follow the patterns already used by nearby files.

- Frontend pages generally load the application through `require_once __DIR__ . '/../backend/config/app.php'` or a shared layout that loads it.
- Authenticated pages use `require_login()`.
- Administrator-only client maintenance uses `require_admin()`.
- Product/site management uses the current authorization helper, including `can_manage_products_sites($pdo)` and `require_products_sites_manager()` where appropriate.
- State-changing POST requests should validate CSRF tokens using the existing `verify_csrf()` pattern.
- Use PDO prepared statements for user-provided values. Never concatenate untrusted input into SQL.
- Validate IDs, page numbers, quantities, prices, dates, and status transitions on the server. Browser validation is not a security boundary.
- Keep API responses JSON and use appropriate HTTP status codes for errors.
- Avoid returning raw exception messages or database details to the browser. Log technical details server-side and return a safe message.
- Escape HTML output using the existing `e()` helper.
- Use the existing activity logging conventions for master-data changes.
- Respect existing role and permission behavior. Hiding a button is not sufficient authorization.
- Keep the current frontend design system, shared layout, sidebar, and Bootstrap conventions. Do not add another CSS/JS framework without a clear need.
- Avoid destructive database changes. Do not delete historical products, sites, clients, withdrawals, invoices, or line items just to make a UI change.
- Do not rename tables or columns, change status values, or alter foreign keys without tracing all reads/writes and preparing a safe migration.

## 6. Recent updates already present in the reviewed `main` branch

These are the changes observed in the repository during the handoff review. Confirm the current branch before relying on them.

### A. Dedicated Clients page

- `frontend/clients.php` provides a dedicated Clients screen.
- The shared sidebar contains a Clients navigation link.
- The page supports searching and paginating the client list.
- Clients are displayed as accordion entries.
- Client maintenance actions are restricted to administrators in the page's server-side POST handling.
- Client data remains in the database; moving the UI out of Products & Sites should not delete client records.

### B. Client invoice history API

`backend/api/client_invoice_history.php`:

- Requires login.
- Validates the client ID and checks that the client exists.
- Returns the selected client's invoices, 10 invoices per page.
- Orders history by invoice date and invoice ID descending.
- Aggregates `invoice_items` by invoice before joining the totals to invoice headers, avoiding duplicate invoice header rows due to line-item joins.
- Returns invoice metadata, item count, total quantity, and the sum of stored line amounts.

### C. Invoice details modal and API

`backend/api/client_invoice_detail.php`:

- Requires login and validates invoice/client IDs.
- Checks that the requested invoice belongs to the requested client.
- Loads the invoice header and its saved `invoice_items` rows.
- Joins the site name where available and returns the stored product code/name, description, quantity, unit, price, amount, and tax code fields supported by the query.
- Orders invoice rows by `invoice_items.id`.
- Calculates `subtotal` as the sum of saved `invoice_items.amount`.

The Clients page uses a Bootstrap modal for invoice detail display. Preserve the use of actual invoice rows; do not replace this with withdrawal rows.

### D. Products & Sites separation and mapping work

`frontend/products_sites.php` focuses on product/site master data rather than being the main client-management screen. The current product logic includes separate HT and QB mapping fields and product/site permission checks.

The README documents a staged migration for product/site permissions and mapping cleanup. **Do not assume every migration step has been run in every local database.** The live database must be checked before relying on schema changes or removing legacy columns.

### E. Known follow-up items from static source review

The following issues were identified in the reviewed source and should be addressed if still present:

1. **Client invoice-history Retry behavior:** The Retry action appeared to click the accordion header. Since the accordion is already open, this can collapse it instead of directly retrying the failed request. Retry should call the history loader directly and keep the accordion open.
2. **Invoice-history pagination failures:** Pagination requests should have error handling. If a request fails, the UI should show a useful error and Retry action rather than remaining stuck on “Loading invoice history…”. Preserve the current page when a request fails.
3. **Invoice detail amount label:** The detail API sums saved line amounts but calls the returned field `subtotal`; the UI may label the displayed amount “Invoice Total.” Confirm the displayed label is financially accurate. Do not invent tax or discount rules.

These were static-review findings, not proof that the live application currently reproduces the issues. Check the current source before modifying.

## 7. Database and migration safety

The application README documents XAMPP setup and database upgrade steps. Important rules:

- Back up the existing MySQL database before any migration.
- Do not import `schema.sql` over an existing production or working database; it is for initial setup.
- Check the current migration files and migration history before running a script twice.
- The README mentions an invoice-number migration and `backend/database/migrations/20261015_product_site_permissions.sql`.
- The product/site migration is staged. It calls for reviewing duplicate codes and incomplete mappings, completing authoritative HT mappings, deploying compatible PHP files, verifying that the code no longer reads `products.default_site_id`, inspecting `SHOW CREATE TABLE products`, and only then applying the commented final DDL/unique indexes where appropriate.
- Do not manufacture HT codes or QuickBooks IDs. They must come from authoritative company data.
- Preserve historical transaction IDs and invoice-line `site_id` values.
- Validate the migration against the actual database version and real company records before running any DDL.
- Withdrawal and invoice line quantities are whole units in the current form/controller logic and initial schema. The `20261019_whole_number_quantities.sql` migration checks for fractional historical quantities and stops before DDL if any exist; resolve those records against authoritative data rather than rounding them automatically. Price and kg-per-box fields remain decimal values.

### QuickBooks export, invoice posting engine, and bridge status

- Phase 3A typed export tables and Phase 3B signed reference-verification/audit logic exist in `backend/core/quickbooks_exports.php`, with the PHP client in `backend/core/quickbooks_bridge_client.php`. The Admin preview persists immutable local snapshots. Invoice posting is a separate Admin/CSRF-protected one-shot workflow; unknown outcomes are terminal and never automatically retried.
- The Windows bridge is `backend/quickbooks-bridge/Program.cs`; `build.ps1` and `start.ps1` compile and launch it outside the web root. It binds to IPv4 loopback and supports signed `health`, ListID-filtered `diagnose`, validation-only operations, and a separately gated invoice-only `submit`. It verifies company identity and active customer/item/tax/site/U/M/lot settings immediately before constructing and sending `InvoiceAddRq`. Health checks SDK COM registration and process presence only; diagnostics create and close a RequestProcessor connection/session and issue read-only queries.
- InvoiceAdd QBXML generation, successful/rejected/ambiguous response parsing, PHP signed-response validation, snapshot preflight, atomic claim, durable attempt outcomes, and the Admin confirmation interface have offline mocked tests. These tests are software evidence only; no live invoice has been created or claimed.
- **Two independent readiness states:** offline implementation is present and tested; live QuickBooks posting is not authorized or verified. Keep `WQ_QB_INVOICE_WRITE_ENABLED` unset in both PHP and bridge environments until an authorized administrator completes the live setup and explicitly approves a controlled test posting.
- The Admin QuickBooks Export page includes signed mapping verification and a selected-export review. Customer references require a unique active exact-name candidate. Item references require an explicit Admin selection and confirmation of one bounded active candidate; the exact ListID and company are rechecked before signed mapping evidence is stored. This is a direct local-product-to-QuickBooks-ListID approval, not an Item Code/SKU equivalence. **Send to QuickBooks** is enabled only for a pending invoice after local snapshot/source validation, PHP and bridge write flags, authenticated company identity, current signed customer/item/tax mappings, active tax/site settings, exact site FullName matches, and any supplied unit/lot values pass preflight. Expiration dates remain rejected because no supported line-level destination has been approved. Health status does not establish a company session.
- Preview and the bridge's submit-contract validator fail closed on QBSDK 16.0 field limits: `RefNumber` 11, `PONumber` 25, `Memo` 4095, inventory Site name 31, U/M 31, and Lot Number 40 characters. Overlength values are blockers and are never truncated or rewritten.
- Authentication uses a shared UTF-8 secret of at least 32 bytes. Request HMAC-SHA256 signs the 10-digit Unix timestamp, operation, GUID request ID, and SHA-256 digest of the exact raw JSON body, separated by LF. The bridge enforces 30 seconds of skew and stores replayed GUIDs in LocalAppData. Response HMAC signs timestamp, literal `response`, request ID, HTTP status, and exact response-body digest. PHP uses IPv4 loopback HTTP only, ignores proxy settings, disables redirects, and validates signed responses. Request/response limits, timeouts, unknown-field rejection, and sanitized errors are enforced. Configure the bridge and PHP process environments separately; secrets are not shared automatically between Apache and a manually launched process.
- A diagnostic response must contain a `found` boolean; a found record must also contain a name and explicit boolean `active`. Missing, null, or malformed QuickBooks `IsActive` values are not treated as active, and PHP rejects those diagnostic responses.
- **Configured database snapshot (read-only, 2026-10-27):** `withdrawal_qb` contains `qb_exports`, `qb_export_attempts`, `qb_export_reference_verifications`, and `qb_export_events`; the verification table has `company_file_fingerprint`, `diagnostic_request_id`, and `evidence_hmac`. The expected source/status, attempt, audit, and reference uniqueness indexes are present. No export status rows or legacy `qb_jobs` rows were returned by the read-only counts. This session performed no DDL or data changes; the time/source of the schema change since the earlier audit is unknown. No production migration is authorized by this work. Inspect each deployment before migration and test changes on a disposable database.
- An authorized read-only diagnostic requires an administrator to open the intended company file in QuickBooks Desktop and authorize its normal RequestProcessor prompt if shown. Build with `backend/quickbooks-bridge/build.ps1`, launch manually as that interactive user with `start.ps1`, and stop it with Ctrl+C; do not use a Windows service or terminate another process for port conflicts. The listener at `127.0.0.1:8765` is not proof of SDK/session access. `QuickBooksReadOnlyBridge.exe --identify-current-company` explicitly opens/closes a read-only session and returns a candidate identity and fingerprint without exposing the company path or bridge secret. QBSDK 16.0 `CompanyQueryRq` supplies `CompanyName` and `LegalCompanyName`; the v2 HMAC fingerprint binds these names to the canonical SDK-reported path. Visually confirm the company in QuickBooks before configuring `WQ_QB_COMPANY_FINGERPRINT` for both bridge and PHP processes; neither process auto-populates it. This identity check is not proof against replacement contents that retain the same path and names. `health()` reports bridge/SDK/process status only. `diagnose($entity, $listIds)` requires the expected fingerprint, opens a session, re-queries the active company identity immediately before the diagnostic, and queries only the supplied ListIDs (up to 50; no enumeration). PHP verifies the signed identity fields and fingerprint. The Admin connection check exposes the active company and last successful diagnostic time for the current browser session. Customer and product mapping evidence is saved only after an Admin verification step and a fresh exact ListID/company check.
- **Bridge setup checklist (posting remains disabled by default):** a deployment administrator must securely inject `WQ_QB_BRIDGE_URL` (`http://127.0.0.1:8765`), `WQ_QB_BRIDGE_SECRET` (at least 32 UTF-8 bytes), `WQ_QB_COMPANY_FINGERPRINT` (the confirmed v2 candidate fingerprint), and `WQ_QB_VERIFICATION_SECRET` (at least 32 bytes) into the relevant PHP and interactive bridge process environments. Do not put secret values in source, command-line arguments, logs, or `setx`; use the organization's protected process-environment/secret-injection mechanism and stop if it cannot protect them. Open the intended company in QuickBooks, confirm both company names in the UI, run `--identify-current-company`, and only then configure the returned fingerprint for both processes. Build and start with the existing scripts, then use the Admin **Check active QuickBooks company and session** action and read-only diagnostics. Verify customer, item, tax, site, U/M, and lot rules against the intended company; verify exact invoice reference numbers and accounting totals; then obtain written authorization before setting `WQ_QB_INVOICE_WRITE_ENABLED=true` independently for PHP and the bridge. Expiration dates remain unsupported and must be cleared or rejected. A changed path or company name changes the v2 fingerprint and invalidates prior company-bound reference evidence; repeat identity confirmation and mapping verification. No secret or company identity is generated/configured by this code change.
- **Local runtime check (2026-10-26):** `QBXMLRP2.RequestProcessor` is registered, but no QuickBooks process or listener on port 8765 was running. The four `WQ_QB_*` settings were unset at Process, User, and Machine scopes. The bridge build and self-tests passed; startup correctly refused to run without its secret. No SDK company session or live PHP-to-bridge authentication was attempted.
- **Local runtime check (2026-10-27):** No QuickBooks process or bridge listener on port 8765 was active. `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_INVOICE_WRITE_ENABLED` were unset at Process, User, and Machine scopes. QBSDK 16.0 documentation is installed at `C:\Program Files\Intuit\IDN\QBSDK16.0\doc\qbxmlops160.xml`; `CompanyQueryRq` supports requesting `CompanyName`/`LegalCompanyName`. The bridge now includes those company names in its candidate identity and requires them for signed diagnostics. This was verified by local self-tests only; no live SDK session was opened.
- **Name-based verification workflow:** the Admin-only `frontend/quickbooks_exports.php` page can explicitly request bounded customer/item name diagnostics; page load itself does not start a company SDK session. The signed bridge uses exact `NameFilter`/`FullName` queries and rejects results over 50; it does not enumerate the master list. Customer matching uses QuickBooks `Name`, with `FullName` and separate `CompanyName` returned for review; CompanyName is not a query filter. Customer verification requires one unique active candidate. For products, the Admin must manually select and affirm the exact intended active item; the controller rejects missing confirmation, candidates outside the bounded result set, inactive records, or any mismatch on the fresh ListID/company check. The original source field or import process for local `Item Code` remains unproven, so Item Code is neither matched to `ManufacturerPartNumber` nor transmitted in InvoiceAdd.
- **Read-only company configuration diagnostics:** the Admin export page now offers explicit CSRF-protected POST actions for SDK company preferences, invoice custom-field definitions, exact inventory-site `FullName`, item U/M, the configured default tax item, and locally stored tax-code references. These use the existing signed, company-fingerprinted `diagnose` operation; page load and health checks still do not open a QuickBooks session. Inventory-site SDK query filters do not support an exact `NameFilter` criterion, so the diagnostic uses `FullName` equality and does not infer a site mapping from local text. Item U/M diagnostics require a stored QuickBooks item ListID, query that item and its referenced `UnitOfMeasureSet`, and return the configured units and sales default; the local SKU is never used as a substitute.
- The `PreferencesQueryRq` diagnostic returns only selected sales-tax, multi-location inventory, lot-tracking, and expiration preference fields. `DataExtDefQueryRq` is restricted to `AssignToObject=Invoice` and reports definitions only. A transaction-level Invoice definition does not prove that Lot Number or Expiration Date can be represented with the required line-level semantics. Neither query establishes company field mappings, tax calculations, or eligibility to post. Diagnostic results are displayed as escaped session data; the last successful company identity and timestamp are retained only in the current administrator's browser session, and no diagnostic is saved as reference-verification evidence.
- The local verification table's active tax-code ListIDs may be queried against the currently fingerprint-verified company; the result is diagnostic only and does not update local tax mappings. No customer, item, site, U/M, tax, or custom-field mapping is automatically approved by these new actions.
- **Latest environment check:** `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_INVOICE_WRITE_ENABLED` were unset at Process, User, and Machine scopes. Consequently, no live company diagnostic was attempted. The new diagnostics were exercised only through signed PHP mock responses, mocked qbXML query responses, and isolated local bridge protocol tests. No QuickBooks record was created, the bridge remains read-only, and the InvoiceWrite feature remains disabled by default.
- **Item Code source trace:** The user has confirmed that local Item Code and QB Code are the same business value. `20261015_product_site_permissions.sql` copies legacy `products.item_code` into `products.qb_code`; `frontend/products_sites.php` writes the same code to both columns; invoice and Sales Order saves use that shared local product code. This does not establish which QuickBooks item field or import process originally supplied it. A read-only check of the configured database on 2026-10-26 found 94 products: 75 nonblank code pairs matched exactly; 18 pairs were both NULL; one pair was both blank; there were no null/blank asymmetries, conflicts, raw differences, or duplicates in either column. The schema has a unique index on `qb_code`, but not on legacy `item_code`. Installed QBXML 16.0 supports item `Name`, `FullName`, `SalesDesc`, and `ManufacturerPartNumber`, but no generic SKU field or SKU filter. Do not assume `ManufacturerPartNumber` is Item Code, or that item `SalesDesc` is invoice-line `Desc`. InvoiceAdd now uses the verified item ListID directly; the local code is retained in the immutable preview only and omitted from the posting payload.
- **Product ListID storage:** The configured database had no populated or blank `products.qb_list_id` values, no duplicate product ListIDs, and no ListID index/constraint. The column is nevertheless read by typed export validation, populated by successful signed reference verification, and copied into Sales Order line snapshots; the export compares it against signed evidence before using it. It is an internal database value and not a staff input. Keep the column until those flows have a reviewed replacement; no removal migration is proposed or applied.
- Lookup results return internal ListIDs automatically but never accept a browser-entered ListID. Item candidates require explicit Admin selection and confirmation; no candidate is automatically persisted. This workflow has automated mock/self-test coverage only; it has not been run against the intended live company. No customer/item mapping from the actual QuickBooks file is verified by the feature's existence alone.
- No customer, item, tax-code/tax-item, or inventory-site mapping has been approved against the intended company file. Site, U/M, Lot Number, Expiration Date, tax behavior/rounding, and invoice/Sales Order reference-field mappings remain unverified. Do not infer support from local fields or a query response.
- The installed SDK 16.0 `doc/qbxmlops160.xml` schema documents `InvoiceLineAdd` fields `InventorySiteRef`, `UnitOfMeasure`, and `LotNumber` (maximum 40 characters), plus line-level `DataExt`; it also documents `DataExtDefQueryRq` for transaction custom-field definitions. The bridge supports read-only custom-field-definition and item U/M diagnostics, but those results do not prove a usable company mapping. For QuickBooks Desktop, `InvoiceAdd` `RefNumber` is limited to 11 characters by this schema. The preview and posting path reject overlength source values; they are not truncated or rewritten.
- The detailed offline test commands and results are recorded in the Offline implementation verification section above. Those tests do not establish real company-file access.

## 8. Local development and testing

The application README describes an XAMPP setup. Typical checks:

1. Confirm the project is placed in the intended `htdocs` folder and `APP_BASE_URL` / `WQ_BASE_URL` matches the local URL configuration.
2. Confirm database credentials and the database name in the current configuration.
3. Use a disposable/test database or backup before testing migrations.
4. Log in with a test account appropriate to the role being tested.
5. Test both an administrator and a normal employee / permission-limited account.
6. Check PHP syntax for every modified PHP file.
7. Test actual browser flows, not only direct API calls.
8. Inspect browser console/network errors and PHP/server logs.
9. Test empty data, multiple pages, invalid IDs, inactive records, and server/network failures.
10. Test a real saved invoice with multiple invoice rows and confirm the modal rows match `invoice_items` exactly.
11. Verify that invoice-specific edits do not modify the original withdrawal.
12. Do not mark QuickBooks integration as tested unless an authorized read-only RequestProcessor session is established against the intended QuickBooks Enterprise company file. The current bridge does not post transactions.

A static source review alone cannot confirm that the application runs against the user's local PHP version, database schema, data, browser, or QuickBooks environment.

## 9. How Codex should work on this repository

At the beginning of a task, Codex should:

1. Read this handoff file.
2. Confirm the checked-out repository, current branch, and working-tree changes. Do not overwrite user changes.
3. Inspect only the files directly involved in the requested task and their immediate dependencies (shared helpers, API/controller, relevant schema/migration, and calling frontend).
4. Search for all references to the affected tables, columns, functions, routes, and status values before changing them.
5. Verify the real schema and current migrations instead of assuming that the local database matches `schema.sql`.
6. Make the smallest safe change that satisfies the request. Avoid broad refactors and unrelated formatting.
7. Preserve the separated frontend/backend architecture and existing UI conventions.
8. Validate server-side authorization, CSRF, input validation, prepared SQL, output escaping, and error handling.
9. Run available syntax checks/tests. If a browser/database/integration test cannot be run, state that explicitly.
10. Review the diff for accidental changes and report:
    - files changed;
    - behavior changed;
    - schema/migration impact;
    - tests actually run and results;
    - tests not run and why;
    - remaining risks or manual steps.

### Do not do these things

- Do not re-analyze every file unless the requested change genuinely requires a broad audit.
- Do not assume a file's existence means the feature works end-to-end.
- Do not fabricate schema columns, functions, endpoints, business rules, or QuickBooks mappings.
- Do not copy invoice detail from `withdrawal_items` when saved `invoice_items` are required.
- Do not silently alter invoice totals, tax behavior, prices, quantities, or status transitions.
- Do not delete or reset database records to make tests pass.
- Do not enable unverified QuickBooks posting.
- Do not report a test as passed unless it was actually run.

## 10. Suggested task prompt for Codex

Paste this after the specific feature or bug request:

> Read `MFC-Operations/PROJECT_HANDOFF.md` first. Treat it as orientation, not as proof that the current code or database is unchanged. Confirm the current branch and working-tree changes, then inspect only the relevant files and their direct dependencies. Search for all references to any affected tables, columns, routes, helpers, and status values before editing. Preserve the frontend/backend separation, existing business rules, historical records, security checks, and QuickBooks integration boundary. Make the smallest safe change. Run the available syntax checks and targeted tests, and clearly distinguish verified results from anything that could not be tested in this environment. Report the exact files changed, tests run, database impact, and remaining risks. Do not perform unrelated refactoring.

## 11. Offline QA findings (2026-10-26)

- Read-only inspection of the configured `withdrawal_qb` schema found the typed export tables, signed-evidence columns, and `qb_export_events` present; legacy `qb_jobs` is intact. The latest `sales_order_items.tax_code` is `VARCHAR(40) NULL`, while the source migration now targets nullable `ENUM('Non','Tax')`. A grouped read-only query found nine Sales Order TAX values that are empty strings; no other value was returned. This schema likely reflects an older nullable-text draft, but its change history is unknown. The current enum migration narrows the accepted values and must not be applied to this database until the target schema and every stored value have been reviewed. No configured-database migration was applied during this QA.
- Sales Order U/M previously trusted a submitted field even though the documented rule is Product Master automation. The save path now takes U/M from the selected active product, and both preparation screens display it as read-only. Sales Order Site remains required; Lot Number, Expiration Date, and TAX remain optional local values. None of these fields is verified as a QuickBooks mapping.
- Client invoice history retry previously toggled the accordion rather than retrying the request, and page-fetch errors left the panel on “Loading”. Retry now directly reloads the failed page, and pagination errors show a retry control. The detail amount label is “Line Amount Subtotal” because it sums stored line amounts and does not include verified tax calculation.
- Verification results for 2026-10-26 (all exit code 0): `C:\xampp\php\php.exe tests\quickbooks_exports_test.php`; `C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php`; `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php`; `C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php`; `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php`; and `node tests\client_history_ui_test.js`. The Sales Order test exercises the enum migration against a disposable MariaDB database, confirms preservation of an empty enum sentinel and existing labels, tests nullable-text conversion with `Non`, `Tax`, empty string, and SQL `NULL`, and tests controller saves for blank/Non/Tax Sales Order values plus invoice validation/rollback. Both migration tests create uniquely named disposable databases and remove them on completion; a read-only check after completion found no leftover Sales Order test database. The client-history UI test uses a lightweight DOM fixture to verify retry and failed-pagination behavior.
- `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }` checked 40 PHP files with zero syntax failures; the updated workflow test was linted again separately after its final test additions. The Windows bridge HTTP test compiled `Program.cs` with the x64 .NET Framework compiler and passed its compiled HTTP protocol tests. A separate temporary x64 compilation and `--self-test` also passed. These tests exercise local signed protocol/rejection paths, not a QuickBooks company session.
- Browser visual testing and full browser end-to-end tests for invoice/withdrawal workflows were not run. Tests use local mocks/fixtures or disposable data; none establishes live QuickBooks compatibility.
- Git executable and `.git` metadata were unavailable at `C:\xampp\htdocs\MFC-Operations-main`, so origin, branch, and worktree status could not be verified.
- No configured-database records or schema were changed. No live QuickBooks SDK company session or company-file QBXML request was made; local bridge HTTP tests exercised health/reporting and rejection paths, and self-tests used mocked CompanyQueryRs data only. No transaction write was used. Customer, item, tax, Site, U/M, Lot Number, and Expiration Date mappings remain unverified against the intended company file; transaction posting remains disabled.

### QuickBooks identity and export hardening (2026-10-27)

- PHP export tests, disposable MariaDB export/migration tests, bridge server compile/self-tests, and localhost signed HTTP tests all passed. PHP syntax checks passed for all five modified PHP files. The MariaDB test did not touch the configured database.
### Offline implementation verification

- `C:\xampp\php\php.exe tests\quickbooks_exports_test.php` passed, including mapping-candidate and field-limit checks.
- `C:\xampp\php\php.exe tests\quickbooks_invoice_posting_test.php` passed all mocked lifecycle scenarios: multi-line success with persisted TxnID, sanitized rejection, timeout/unknown without retry, duplicate and competing claims, company mismatch, missing references, unsupported expiration, tax/site/U/M gates, and both disabled-write gates. The fixture also verifies local Item Code is omitted from the InvoiceAdd payload.
- `C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php` passed signed localhost transport checks. `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php` passed against a disposable database, confirming the Sales Order workflow remains unaffected.
- `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php` compiled the C# bridge and passed signed localhost protocol tests. A separate x64 build using `C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe /nologo /target:exe /platform:x64 /reference:System.Web.Extensions.dll /reference:System.Xml.dll /reference:Microsoft.CSharp.dll /out:<temp>\QuickBooksReadOnlyBridge.exe backend\quickbooks-bridge\Program.cs`, followed by `QuickBooksReadOnlyBridge.exe --self-test`, passed InvoiceAdd QBXML generation, mock SDK preflight, response parsing, request-ID correlation, and fail-closed cases. No live QuickBooks process was used.
- `C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php` passed against a generated disposable MariaDB database, including simultaneous claim contention and rollback checks. The configured database was not changed.
- PHP syntax checks passed for the modified controllers, core services, UI, and test files. No schema migration was required or added. These checks are offline software evidence only; no live invoice was created, no production posting was attempted, and no browser session or live company diagnostic was used.
- The Admin Export page now shows PHP-side configuration readiness without exposing secret values, distinguishes signed bridge health from a company session, and reports current local invoice tax-choice counts separately from unverified QuickBooks tax compatibility.

### Local connection readiness check (2026-10-30)

- At initial inspection the Windows QuickBooks process and bridge listener were absent. After the administrator reported opening the intended company, a QuickBooks Enterprise Solutions 24.0 window titled `MET FOOD CORPORATION` was observed. This window title agrees with the reported company name but is not SDK-confirmed identity; the legal name and company fingerprint remain unverified.
- `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_VERIFICATION_SECRET` remained absent from the inspected Process, User, and Machine environments, and no listener was bound to `127.0.0.1:8765`. An authenticated Admin page independently reported `BRIDGE_NOT_CONFIGURED`, bridge authentication missing/too short, expected fingerprint missing/invalid, verification key missing/too short, and PHP writes disabled. The administrator reports secure configuration, but the live PHP page did not receive it. No secret values were read or displayed; no RequestProcessor call was made.
- The latest bridge source was successfully compiled directly with the .NET Framework x64 compiler into the existing LocalAppData bridge install directory. Invoking `build.ps1` directly was blocked by the current PowerShell execution policy; no policy was changed. The bridge has not been started because its required secret is unavailable in this process.
- Read-only inspection confirmed the required export/reference tables exist and are empty: no exports, attempts, audit events, or active signed customer/item/tax-code evidence. Local master data has 816 active clients, 92 active products, and 7 active sites.
- The configured database has two invoices; both fail local invoice-preview validation. Their blockers are missing customer mappings (2 occurrences), item mappings (8), and tax mappings (8). All eight invoice-line tax selections currently read `Non`; this alone does not prove a matching active QuickBooks sales-tax code or accounting treatment. No invoice or QuickBooks data was changed.
- No RequestProcessor session was established, and no SDK-verified company identity/fingerprint or company-specific customer/item/site/U/M/tax/lot diagnostics were obtained. The administrator must make the protected bridge secret available to the interactive bridge process and verify PHP runtime configuration before read-only SDK diagnostics can proceed.

### Company identity diagnostic error detail (2026-10-30)

- `QuickBooksReadOnlyBridge.exe --identify-current-company` now reports a sanitized COM HRESULT (`SDK_COM_XXXXXXXX`) and failing SDK stage instead of collapsing every exception into `COMPANY_SESSION_UNAVAILABLE`. It also distinguishes QuickBooks `CompanyQueryRs` status codes and malformed/missing identity responses. It never echoes exception text, qbXML status messages, or company paths. Session cleanup failures are reported if identity was otherwise read successfully; cleanup does not mask an earlier failure.
- The identity command opens a RequestProcessor connection, begins a session against the currently open company (`BeginSession` mode 2), gets that session's current-file path only in process, selects a negotiated qbXML version, sends a read-only `CompanyQueryRq` requesting `CompanyName` and `LegalCompanyName`, validates/parses `CompanyQueryRs`, ends the session, closes the connection, and releases the COM object. Path is used only as HMAC fingerprint input and is never returned.
- The actual COM/QBXML cause of the reported `COMPANY_SESSION_UNAVAILABLE` was **not established** in this session: QuickBooks was running, but `WQ_QB_BRIDGE_SECRET` and `WQ_QB_COMPANY_FINGERPRINT` were absent from Process/User/Machine environments, so the identify command could not be invoked through the bridge entry point. The supplied generic result contains no HRESULT and cannot distinguish `BeginSession` failure from prior bridge build/version or runtime/environment mismatch. Required next evidence: run the rebuilt executable from the interactive QuickBooks user's protected environment with the bridge secret available; capture only the new sanitized diagnostic code/message. If it reports a QBXML status, retain the numeric status/severity; if it reports an HRESULT, retain the hex value and stage. Do not send secrets, full exception text, qbXML payloads, or company path.
- Final validation: the .NET Framework x64 compiler built the bridge from `Program.cs`; `QuickBooksReadOnlyBridge.exe --self-test` passed, including nested-COM-HRESULT sanitization, QBXML status-code and malformed-response diagnostics, CompanyQuery request/response parsing, and session-cleanup cases. `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php` also passed. No live SDK session or transaction was used.

### Follow-up mapping readiness note

- The user reports that customer and product mappings are business-confirmed as correct. That confirmation is not represented as application verification evidence. The earlier read-only aggregate check found 816 active local customers and 92 active products, with zero populated customer/product QuickBooks ListIDs and no active verification records or signed company-bound evidence. Do not enable posting based on that historical count or user statement alone; run the explicit Admin mapping workflow against the intended company and require fresh evidence.
- Tax code/tax item, inventory Site, U/M, Lot Number, Expiration Date, and transaction reference destinations remain technically unverified. Neither UI values nor successful mocked bridge tests establish QuickBooks field support.
Run the added regression test with `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php`. Like the export migration test, it creates and removes a uniquely named disposable database.

## 12. Keep this document current

Update this file whenever a significant change affects:

- folder structure or entry points;
- withdrawal or invoice workflow;
- database tables, columns, indexes, or migrations;
- roles and permissions;
- product/site/client mapping rules;
- invoice calculation rules;
- QuickBooks integration status;
- known bugs, test procedures, or deployment steps.

Prefer concise, verified statements. Mark assumptions and untested behavior explicitly.