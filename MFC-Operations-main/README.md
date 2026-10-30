# Withdrawal & QuickBooks Invoicing System — Phase 2 (Separated Frontend/Backend)

## Folder structure

```text
withdrawal-qb/
├── index.php
├── frontend/
│   ├── assets/              # CSS and JavaScript
│   ├── includes/            # Shared visual layout only
│   ├── login.php
│   ├── dashboard.php
│   ├── withdrawal_form.php
│   ├── withdrawal_print.php
│   ├── withdrawals.php
│   ├── invoice_preparation.php
│   ├── products_sites.php
│   ├── settings.php
│   └── quickbooks_status.php
└── backend/
    ├── config/app.php       # Application bootstrap/configuration
    ├── core/                # Database, auth and shared business helpers
    ├── controllers/         # POST actions (save withdrawal/invoice, logout)
    ├── api/                 # JSON endpoints
    ├── exports/              # CSV export endpoint
    └── database/schema.sql  # MySQL schema
```

## Install in XAMPP

1. Extract this folder as `C:\xampp\htdocs\withdrawal-qb`.
2. Start Apache and MySQL.
3. Import `backend/database/schema.sql` in phpMyAdmin. It creates `withdrawal_qb` and example master records. Replace example clients/products/sites with the real company data before production.
4. Confirm database credentials in `backend/core/database.php` or set `WQ_DB_HOST`, `WQ_DB_NAME`, `WQ_DB_USER`, and `WQ_DB_PASS`.
5. If you install under a different folder, set `WQ_BASE_URL` to that URL prefix (for example `/my-folder`). Default is `/withdrawal-qb`.
6. Open `http://localhost/withdrawal-qb/frontend/setup.php` and create the first administrator account. Delete `frontend/setup.php` after initial setup.
7. Sign in at `http://localhost/withdrawal-qb/frontend/login.php`.

### Upgrade an existing database

Back up `withdrawal_qb` first. For an existing database, apply `20261015_invoice_number.sql` if it has not already been applied, then follow `backend/database/migrations/20261015_product_site_permissions.sql` in phpMyAdmin with `withdrawal_qb` selected. Apply `20261019_whole_number_quantities.sql` after reviewing its preflight behavior. It stops without changing quantity data if fractional historical quantities exist; reconcile those rows against authoritative records before rerunning. Apply `20261019_invoice_memo.sql` once to add the nullable invoice memo field. Apply `20261019_sales_order_preparation.sql` once to add separate local Sales Order draft tables. Review and apply `20261026_sales_order_optional_tax.sql` after the Sales Order tables exist; it changes only the local Sales Order `tax_code` column from `ENUM('Non','Tax') NOT NULL` to `ENUM('Non','Tax') NULL`, preserving the allowed labels and existing values while allowing an intentionally blank choice. It does not rewrite historical empty-string enum values. Test it against a disposable database first and verify the target schema before applying it. Do not import `schema.sql` over an existing database.

The product/site migration is intentionally staged:

1. Run the `ALTER`/`UPDATE` statements through creation of `employee_permissions`. Existing `item_code` and `description` are copied to the QB mapping fields; `product_name` is copied to the initial HT description because it is the existing withdrawal display text. The legacy columns and all product/site/transaction IDs remain intact.
2. Review the duplicate-code queries and the incomplete-mapping report. Resolve QB conflicts against the real item list. Supply each HT Code from the authoritative HT master list; the migration never manufactures one. Products without an HT Code cannot be selected for new withdrawals. Populate descriptions/mappings and set products active only when the required fields are confirmed.
3. Deploy the updated PHP files. Admins can complete legacy mappings in Products & Sites. Admins can grant Employee access in Settings using **Manage Products & Sites**.
4. After confirming the running code no longer reads `products.default_site_id`, inspect `SHOW CREATE TABLE products`, then manually run the commented FK and column drop statements at the end of the migration. Invoice line `site_id` is preserved. Only after reviewing/fixing duplicates, add the two commented unique indexes. Existing DB collation is case-insensitive in the supplied schema.

The migration file includes the review queries and commented final DDL. Keep a copy of the pre-migration backup until the new withdrawal, invoice and employee permission paths are verified in the local database.

## Phase 2 scope

- Local MySQL records for withdrawals and invoice preparation.
- Employee login, role checks, CSRF protection and activity log.
- Withdrawal create/edit/search/register and print view.
- Invoice draft separated from the source withdrawal; supports invoice-only additional lines.
- Product/site/client setup and CSV export.
- QuickBooks status view and queue schema.

## Important scope boundary

Live QuickBooks transaction posting is not implemented or enabled. The package includes an authenticated, IPv4-loopback Windows bridge for health checks and bounded, read-only qbXML diagnostics, plus a PHP client. The bridge can query by supplied ListID or bounded exact-name lookup; it does not enumerate master lists or create, edit, or delete transactions. Health reports SDK registration and process presence only, not the open company file. A successful diagnostic is not proof that the returned company is the intended company until an authorized administrator checks its identity.

Sales Order Preparation is a separate local draft workflow. It uses the additive `20261019_sales_order_preparation.sql` migration and does not modify invoice records or submit anything to QuickBooks. U/M is displayed from Product Master and enforced by the save controller; users cannot override it. SITE is required, while LOT NUMBER and EXPIRATION DATE may be blank. CLASS, STATUS, FULFILLED QUANTITY, and TAX are retained only as local preparation values pending verified company-file mapping. A blank Sales Order TAX is saved as SQL `NULL`; databases created with a non-null enum version of this column must apply the reviewed `20261026_sales_order_optional_tax.sql` migration first.

Invoice drafts retain local site, lot, item code, product identity/description, unit, price, amount, expiration, and local tax choice. These values are not proof of QuickBooks mappings. In particular, SITE, LOT NUMBER, ITEM CODE, U/M, EXPIRATION DATE, and the local `Non`/`Tax` values need company-file field/configuration decisions and qbXML verification before posting. `products.item_code` and `products.qb_code` are aliases for the same business product code; Product Master writes both together and blocks edits to conflicting stored values unless a manager explicitly confirms the shared code. Invoice and Sales Order saves also reject conflicting product-code fields. `Exempt` is not a supported preparation choice: invoice storage is `ENUM('Non','Tax')`, and neither preparation UI offers `Exempt`. Existing Sales Order rows containing that legacy string are retained but blocked by export validation; no data is rewritten. Customer and product `qb_list_id` values must also be populated from verified QuickBooks records; staff do not enter ListIDs. Posting remains disabled until the intended company, all required mappings, and live transaction behavior have been explicitly verified.

### Safe export foundation and read-only bridge (2026-10-26)

`20261023_typed_qb_exports.sql` creates the typed export queue, attempts, and initial reference-verification table without changing legacy `qb_jobs`. `20261023_typed_qb_exports_hardening.sql` additively adds `company_file_fingerprint`, `diagnostic_request_id`, and `evidence_hmac`, and creates `qb_export_events`; it preserves existing rows and is safe to rerun on MariaDB versions supporting `ADD COLUMN IF NOT EXISTS`. Check the target schema first. Test changes on a disposable database before any operational deployment. A read-only schema inspection of the currently configured `withdrawal_qb` database on 2026-10-26 found the three hardening columns and `qb_export_events` present, along with the Phase 3A tables and legacy `qb_jobs`. This work did not apply a migration, so the time/source of that schema change is unknown. Do not assume this local snapshot describes another deployment.

The current source for `20261026_sales_order_optional_tax.sql` changes the Sales Order TAX column to nullable `ENUM('Non','Tax')`. Older draft versions used nullable text; inspect the target column and every stored TAX value before applying the current migration, because narrowing text to an enum can reject or coerce values outside the two supported labels. Do not rewrite historical TAX values automatically.

The Admin-only Export Preview page can save an eligible immutable snapshot as `pending`; it never sends a transaction. An export can be claimed once, and an ambiguous result is terminal `unknown`; it must be reconciled manually and is never retried automatically. The legacy `qb_jobs` workflow is unchanged. An Admin-only exact-name lookup and explicit customer/item verification interface exists, but no customer or item has been verified against the intended company file. Tax remains blocked until its mapping and tax calculation are verified. Site destination, U/M, Lot Number, Expiration Date, and related transaction fields are unresolved and block export.

`backend/quickbooks-bridge/Program.cs` is a Windows .NET Framework x64 bridge using the registered `QBXMLRP2.RequestProcessor` COM component. It binds to `127.0.0.1:8765`, permits only `health`, `diagnose`, and validation-only `validate`, and its diagnostics send bounded ListID-filtered query requests only. It opens a RequestProcessor connection/session for a diagnostic and closes both in cleanup. It reports unavailable when QuickBooks is not running, the session/company is unavailable, SDK operations fail, or a worker times out. Health does not open a company session. The bridge's fingerprint is an HMAC of the opened company-file path, not a company name or proof of intended identity; an administrator must independently confirm the open company before treating diagnostic results as evidence.

The PHP client uses `WQ_QB_BRIDGE_URL` (exactly `http://127.0.0.1:8765`, no path/query), and the bridge and PHP processes must receive the same random `WQ_QB_BRIDGE_SECRET` of at least 32 UTF-8 bytes. `WQ_QB_VERIFICATION_SECRET` is a separate secret used by PHP to sign human-approved mapping evidence; it must not be reused as the bridge secret. The bridge and PHP client read these values from their process environments. They do not read Windows Credential Manager or a DPAPI secret store.

For a local XAMPP deployment, use a dedicated, access-controlled Windows account. Generate both secrets with a trusted password manager or secret manager (at least 32 random bytes each), then enter them directly in that account's Windows **Environment Variables** settings as user variables; do not put values in source files, command lines, scripts, terminal output, or logs. Set `WQ_QB_BRIDGE_URL` to `http://127.0.0.1:8765`. User environment variables are not an encrypted secret vault: processes running as that account and local administrators can access them. Do not use this arrangement on a shared Windows account. If Apache runs as a Windows service or another account, these user variables will not be inherited; do not copy secrets into public Apache configuration. Use a deployment-approved, access-controlled process-environment mechanism for that service account or keep this integration unconfigured. Restart XAMPP Control Panel/Apache and open a new bridge PowerShell process after environment changes; running processes retain their original environment. Do not silently rotate either secret: evidence signatures and bridge authentication depend on stable configured values.

Request HMAC-SHA256 input is `timestamp + LF + operation + LF + requestId + LF + lowercase-hex SHA-256(raw-body)`; timestamps are 10-digit Unix seconds with 30-second maximum skew and request IDs are GUID nonces. The bridge rejects replays using its LocalAppData replay cache. Response HMAC-SHA256 input is `timestamp + LF + response + LF + requestId + LF + HTTP-status + LF + lowercase-hex SHA-256(raw-response-body)`. PHP authenticates response bytes and timestamp, does not follow redirects, ignores proxies, and applies request/response limits and timeouts. Neither endpoint exposes a credential or signature in application responses.

### Starting the bridge and establishing expected company identity

Run QuickBooks Desktop interactively as the authorized Windows user and open the intended company file. After configuring the process environment as described above, open a new PowerShell window as that user and build the bridge:

```powershell
Set-Location C:\xampp\htdocs\MFC-Operations-main\backend\quickbooks-bridge
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\build.ps1
```

Start it in the same interactive user session:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start.ps1
```

The console must say the listener is ready; this indicates only that the loopback HTTP listener started. It does not indicate that a company session or intended company is available. The bridge listens only at `127.0.0.1:8765`; verify with `Get-NetTCPConnection -State Listen -LocalPort 8765`. A port/URL ACL conflict is reported without stopping another process. Stop the manually launched bridge with Ctrl+C in its own console; do not terminate unrelated processes. Do not install it as a Windows service: SDK access may require the interactive user session and QuickBooks authorization prompt.

Before setting `WQ_QB_COMPANY_FINGERPRINT`, confirm visually in QuickBooks that the intended company is open. Then explicitly request a candidate identity in that interactive session:

```powershell
$bridge = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\QuickBooksReadOnlyBridge.exe'
& $bridge --identify-current-company
```

The command opens and closes a read-only RequestProcessor session. QuickBooks may display its normal SDK authorization prompt; review and approve only for the intended company and user. The command prints a candidate HMAC fingerprint, not the company path, and does not configure or change the expected value. If the output is unavailable, do not set an expected identity. After independently confirming the open company, configure that exact fingerprint as `WQ_QB_COMPANY_FINGERPRINT` for both the bridge process and the PHP/Apache process. The bridge recomputes identity from the actual SDK session and refuses reference queries if the expected value is missing, malformed, or mismatched; PHP independently checks the signed response against its own expected value. The identity is based on the canonicalized company-file path HMAC and therefore identifies the SDK-reported path, not a content-level immutable file ID. A company-file replacement at the same path is a residual limitation; re-confirm identity against QuickBooks and do not rely on the fingerprint alone as business approval.

### Admin name-based customer and product verification

The Admin-only QuickBooks Export Preview now supports explicit name lookups; opening the page does not start an SDK company session. Select a local customer or product and request a lookup. The signed bridge query runs only after the expected company fingerprint is configured and the bridge independently verifies the open company. It uses a qbXML `NameFilter` with `MatchCriterion=Equal`, caps returned candidates at 50 (requesting one extra to detect overflow), and never enumerates the master list.

Customer search uses the exact local customer name as QuickBooks `Name`; results show QuickBooks `Name`, `FullName`, and `CompanyName` separately. `CompanyName` is returned for administrator context but is not a QuickBooks query filter. If the intended customer is discoverable only by a different `CompanyName`, this bounded lookup cannot find it; do not claim that `Name` proves `CompanyName`.

Product lookup uses bounded exact QuickBooks `NameFilter`/`FullName` searches based on the local product name and configured invoice description. These results are diagnostic only: the original QuickBooks source field or import process for local `Item Code` has not been established. QBXML 16.0 exposes item `Name`, `FullName`, `SalesDesc`, and `ManufacturerPartNumber`, but no generic `SKU` field or SKU query filter. `ManufacturerPartNumber` is not assumed to be the Item Code, and invoice-line `Desc` is not assumed to equal item `SalesDesc`. Even one exact active item-name candidate cannot be saved as item verification until an authoritative local-to-QuickBooks Item Code relationship is established. The lookup does not enumerate unrestricted item lists.

For customer verification, the lookup returns the internal `ListID` from QuickBooks and does not expose it as a manual input. The server repeats the exact name lookup and performs a second exact-ListID read-only diagnostic; it saves customer evidence only if company identity, name, explicit active status, and internal ID still match. Item results never receive a verify action and a crafted item verification POST is rejected. Missing/unknown active status, missing ID, no candidate, more than 50 candidates, or company mismatch fails closed. Customer comparisons are exact and are not fuzzy-normalized. Customer evidence does not approve tax, site, U/M, Lot Number, Expiration Date, or transaction field mappings and does not enable posting.

The existing product/site migration `20261015_product_site_permissions.sql` copies legacy `products.item_code` into `products.qb_code` and legacy `products.description` into `products.qb_description`; product CRUD, invoice save, and Sales Order save now consistently treat Item Code and QB Code as the same local business value. A read-only check of the configured database on 2026-10-26 found 94 products: 75 nonblank code pairs matched exactly, 18 pairs were both NULL, and one pair was both blank. There were no null/blank asymmetries, conflicts, raw differences, or duplicates in either code column. The schema has a unique index on `qb_code`, but not on legacy `item_code`. This confirms local consistency, not which QuickBooks item field or import/synchronization process originally supplied the code. Item verification therefore remains unresolved. Preserve current product values and any existing signed evidence; do not infer or write a ListID.

`products.qb_list_id` is an internal application value, not entered or displayed to staff. Signed reference verification writes it; export eligibility checks it against signed evidence; and Sales Order drafts snapshot it into `sales_order_items.qb_item_list_id`. The configured database has no populated product ListIDs and no ListID index/constraint, but the column is still used by these workflows. Keep it until a reviewed replacement updates the dependencies and preserves existing snapshots; no column-removal migration is proposed or applied.

To perform a read-only diagnostic after configuring both processes, call the PHP client from server-side PHP/CLI using an exact known candidate ListID. `health()` checks bridge/SDK registration and process presence only. `diagnose()` opens a session, compares the actual session's fingerprint to the independently configured expected fingerprint, and only then queries supplied ListIDs. The authenticated result is not mapping approval; customer, item, tax, and site references still require explicit administrator review. The bridge does not enumerate company records. For company-session failures, check QuickBooks is open in the same interactive Windows session and respond to any authorization prompt; the SDK may not distinguish a cancelled/required prompt from other session failures, which are reported generically. Stop diagnostics and resolve such failures rather than assuming access.

`WQ_QB_VERIFICATION_SECRET` is separate from the bridge HMAC secret and is for future signed human-approved mapping evidence. It is not used to establish company identity. No diagnostic endpoint or UI automatically approves customer/item/tax/site mappings.

No customer, item, tax-code/tax-item, or inventory-site mapping has been approved against the intended company file. The bridge does not enumerate candidates; a server-side caller must provide exact candidate ListIDs (up to 50 per diagnostic). Site, U/M, Lot Number, Expiration Date, tax-code/item behavior and rounding still need company-specific field/configuration verification. Diagnostics never approve mappings. Do not enable posting or add transaction-writing qbXML operations in this phase.

For an authorized read-only check, an administrator first opens the intended company file in QuickBooks Desktop and approves the SDK integration prompt if displayed. Start the bridge with the secret configured in its process environment and configure the same secret and loopback URL in PHP. From the project root, the PHP client can be invoked server-side using an exact candidate ListID, for example:

```powershell
C:\xampp\php\php.exe -r "require 'backend/core/quickbooks_bridge_client.php'; var_export((new QuickBooksBridgeClient())->health());"
C:\xampp\php\php.exe -r "require 'backend/core/quickbooks_bridge_client.php'; var_export((new QuickBooksBridgeClient())->diagnose('customer', ['KNOWN-CUSTOMER-LISTID']));"
```

Replace the example with an authoritative candidate ListID; this call does not search by customer name. Check the returned `companyFingerprint` against the company identity independently confirmed by the administrator before considering any record. These calls are server/CLI-side only, not for browser JavaScript. Do not paste secrets into command history or source files.

Validation commands (run from the project root):

```powershell
C:\xampp\php\php.exe tests\quickbooks_exports_test.php
C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php
C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php
C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php
C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php
node tests\client_history_ui_test.js
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

The MariaDB test creates and removes a uniquely named disposable test database. The HTTP test uses a mock bridge. The server test compiles and exercises the local C# bridge protocol, replay rejection, and self-tests; passing these tests does not prove access to an authorized QuickBooks company file.

## Print

Open a saved withdrawal and use **Print form**. The print page hides its own Print/Close controls and prints only the form content. The print template is an initial browser-print layout based on the known Excel fields; compare it with the Excel print preview and adjust page size/margins/column widths before production rollout.
