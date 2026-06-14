# Enterprise P0 — execution plan (foundation & compliance)

Each sub-phase: additive, verified (authed test + build), own git commit + push.

- **P0-A · Audit Trail** ✅ DONE — `audit_logs` table + `AuditLog` (plain Model) + `Auditable` trait on 10 key models (Quotation/Order/Invoice/PaymentTransaction/Customer/Lead/PurchaseOrder/ProductionRun/Supplier/PanelType) capturing created/updated(changed-only)/deleted with user+before/after; `GET /audit-logs` (admin-only) + AuditLog.vue viewer (filters/pager) + admin-gated nav. Verified.
- **P0-B · RBAC + cost/margin masking** ✅ DONE — `config/permissions.php` registry (6 groups); User helpers `isAdmin/effectivePermissions/hasPermission/canViewCost`; `me()` exposes `is_admin/role/permissions`; `GET /permissions` + `PUT /roles/{id}/permissions` (admin-only); cost masking (`unit_cost` in StockController coil/chemical/consumable, `base_price` in PanelTypeController, valuation in ProcurementController) for non-`costing.view` users; AppShell nav gated by `can(key)`/`isAdmin`; Roles & Permissions checkbox-matrix tab in UserManagement.vue. Verified (authed admin + non-admin tinker test + build).
- **P0-C · Material availability + auto-PO suggestion** ✅ DONE — run material panel already shows shortage (BOM); added `GET /production/runs/{id}/po-suggestion` (resolves short BOM lines → PO item rows at last cost; flags unresolved) + "🛒 Create draft PO for shortage" button in ProductionRuns material panel; added `GET /procurement/reorder-suggestion` (coil+chemical+consumable at/below reorder → top-up-to-2× PO rows) + "⚠ Suggest Reorder" button in ProcurementManager that prefills the New-PO modal. Verified (reorderSuggestion authed test returns 4 items; lint + build clean).
- **P0-D · Credit-limit block + payment reminders** ✅ logic buildable; WhatsApp send needs keys — block new order if outstanding+order > credit_limit (override w/ approval); reminder scheduler (SMS now, WhatsApp when keys).
- **P0-E · e-Invoice (IRN/QR) + e-Way Bill** ⚠ scaffolding now (model + manual/stub + pluggable GSP service); live = GSP keys.
- **P0-F · Security & reliability** ✅ mostly buildable — 2FA (email OTP), API rate-limiting, encrypt sensitive fields (bank/GSTIN), password policy; backup artisan command + scheduler; (staging/CI = infra, documented).

Sequence: A → B → (C, D) → F → E (E last since it needs external GSP).
