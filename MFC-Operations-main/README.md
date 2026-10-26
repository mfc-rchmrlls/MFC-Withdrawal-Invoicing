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

Back up `withdrawal_qb` first. For an existing database, apply `20261015_invoice_number.sql` if it has not already been applied, then follow `backend/database/migrations/20261015_product_site_permissions.sql` in phpMyAdmin with `withdrawal_qb` selected. Apply `20261019_whole_number_quantities.sql` after reviewing its preflight behavior. It stops without changing quantity data if fractional historical quantities exist; reconcile those rows against authoritative records before rerunning. Apply `20261019_invoice_memo.sql` once to add the nullable invoice memo field. Apply `20261019_sales_order_preparation.sql` once to add separate local Sales Order draft tables. Do not import `schema.sql` over an existing database.

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

QuickBooks connectivity is not implemented or verified in this package. The planned design uses a local interactive bridge; there is no Web Connector service, local bridge, qbXML transaction submission, or successful/rejected transaction handling here. Verify the correct QuickBooks company file, SDK compatibility, all mappings, and test-company behavior before enabling any posting.

Sales Order Preparation is a separate local draft workflow. It uses the additive `20261019_sales_order_preparation.sql` migration and does not modify invoice records or submit anything to QuickBooks. CLASS, STATUS, FULFILLED QUANTITY, and TAX are retained as local preparation values pending verified company-file mapping.

Invoice drafts retain local site, lot, item code, product identity/description, unit, price, amount, expiration, and local tax choice. These values are not proof of QuickBooks mappings. In particular, SITE, LOT NUMBER, ITEM CODE, U/M, EXPIRATION DATE, and the local `Non`/`Tax` values need company-file field/configuration decisions and qbXML verification before posting. `Exempt` is not a supported preparation choice: invoice storage is `ENUM('Non','Tax')`, and neither preparation UI offers `Exempt`. Existing Sales Order rows containing that legacy string are retained but blocked by export validation; no data is rewritten. Customer and product `qb_list_id` values must also be populated from verified QuickBooks records. Posting remains disabled until the local bridge and explicit supported mappings are implemented.

### Safe export foundation (2026-10-23)

Apply `backend/database/migrations/20261023_typed_qb_exports.sql` and then `backend/database/migrations/20261023_typed_qb_exports_hardening.sql` to create the typed queue, attempt history, audited state events, and explicit QuickBooks reference-verification records. If the original Phase 3A migration was already applied, apply only the hardening migration. Neither migration alters or deletes legacy `qb_jobs`. Existing verification rows are preserved by the hardening migration but fail closed until they have signed evidence. New accepted verification records require an active Admin approver, a diagnostic request ID, a company-file fingerprint matching `WQ_QB_COMPANY_FINGERPRINT`, and a keyed evidence signature validated with `WQ_QB_VERIFICATION_SECRET`; both settings remain outside source control. A future workflow must derive the fingerprint/evidence from a read-only diagnostic of the actually open QuickBooks company and record explicit human approval. These migrations remain unapplied to the configured production database in this phase. To roll back both migrations, stop the application and preserve any records needed for audit, then drop `qb_export_attempts`, `qb_export_events`, `qb_exports`, and `qb_export_reference_verifications` in that order. This permanently deletes all new export data. To roll back only hardening on a system retaining the Phase 3A tables, first drop `qb_export_events`, then drop `evidence_hmac`, `diagnostic_request_id`, and `company_file_fingerprint` from `qb_export_reference_verifications`; this leaves original queue/history intact.

The Admin-only QuickBooks Export Preview page performs local validation and can store an eligible immutable snapshot as `pending`; it never submits to QuickBooks. Customer/item mappings must have a matching active verification record, and tax mappings must be explicitly verified. Site destination, non-empty lot/expiration values, non-empty U/M, and tax-total behavior remain blocking until the company-file mapping is verified. No interface in this phase can mark a mapping verified.

Queue states are per transaction, not per batch: `pending` may be claimed once as `processing`, which may end as `succeeded`, `rejected`, or `unknown`. Terminal states have no automatic outgoing transition. An expired processing lease is not proof that QuickBooks did not receive a request; processing records are never automatically reset or reclaimed and must be reconciled manually.

The optional PHP bridge client only supports authenticated loopback health and validation-only requests. Configure `WQ_QB_BRIDGE_URL` (literal loopback HTTP only), a random `WQ_QB_BRIDGE_SECRET` of at least 32 bytes, `WQ_QB_VERIFICATION_SECRET`, and `WQ_QB_COMPANY_FINGERPRINT` outside source control. The request HMAC covers the timestamp, operation, fresh request ID, and body digest. A future bridge must enforce a short timestamp window and an atomic replay cache keyed by request ID, and must sign the response body; the client rejects unsigned, tampered, or stale responses. There is no bridge implementation in this phase; unavailable status is expected until one is installed. No InvoiceAdd or SalesOrderAdd operation exists. Run unit checks with `C:\xampp\php\php.exe tests\quickbooks_exports_test.php` and the isolated MariaDB migration/constraint test with `C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php`.

## Print

Open a saved withdrawal and use **Print form**. The print page hides its own Print/Close controls and prints only the form content. The print template is an initial browser-print layout based on the known Excel fields; compare it with the Excel print preview and adjust page size/margins/column widths before production rollout.
