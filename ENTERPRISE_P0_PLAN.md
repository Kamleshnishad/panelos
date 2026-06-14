# Enterprise P0 — execution plan (foundation & compliance)

Each sub-phase: additive, verified (authed test + build), own git commit + push.

- **P0-A · Audit Trail** ✅ DONE — `audit_logs` table + `AuditLog` (plain Model) + `Auditable` trait on 10 key models (Quotation/Order/Invoice/PaymentTransaction/Customer/Lead/PurchaseOrder/ProductionRun/Supplier/PanelType) capturing created/updated(changed-only)/deleted with user+before/after; `GET /audit-logs` (admin-only) + AuditLog.vue viewer (filters/pager) + admin-gated nav. Verified.
- **P0-B · RBAC + cost/margin masking** ✅ buildable — permissions matrix per role, enforce on API + UI, mask cost/margin from non-admins.
- **P0-C · Material availability + auto-PO suggestion** ✅ buildable — pre-schedule stock check (extend existing BOM), low-stock → one-click draft PO.
- **P0-D · Credit-limit block + payment reminders** ✅ logic buildable; WhatsApp send needs keys — block new order if outstanding+order > credit_limit (override w/ approval); reminder scheduler (SMS now, WhatsApp when keys).
- **P0-E · e-Invoice (IRN/QR) + e-Way Bill** ⚠ scaffolding now (model + manual/stub + pluggable GSP service); live = GSP keys.
- **P0-F · Security & reliability** ✅ mostly buildable — 2FA (email OTP), API rate-limiting, encrypt sensitive fields (bank/GSTIN), password policy; backup artisan command + scheduler; (staging/CI = infra, documented).

Sequence: A → B → (C, D) → F → E (E last since it needs external GSP).
