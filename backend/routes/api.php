<?php

use App\Http\Controllers\Api\AccessoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ProductionBatchController;
use App\Http\Controllers\Api\ProductionStageController;
use App\Http\Controllers\Api\BatchStageLogController;
use App\Http\Controllers\Api\QualityControlController;
use App\Http\Controllers\Api\CuttingScheduleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\ForecastingController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\GstController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PanelTypeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderController;

Route::prefix('auth')->group(function () {
    // Public routes — rate-limited to slow brute-force (10 attempts/min per IP)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/refresh-token', [AuthController::class, 'refreshToken'])->name('refresh-token');
        Route::post('/change-password', [AuthController::class, 'changePassword'])->name('change-password');
    });
});

// Protected routes — generous per-user throttle + tenant subscription guard
Route::middleware(['auth:sanctum', 'throttle:240,1', 'tenant.active'])->group(function () {
    // Home Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'index'])->name('dashboard');

    // Company Profile & Settings
    Route::get('/company', [\App\Http\Controllers\Api\CompanyController::class, 'show'])->name('company.show');
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update'])->name('company.update');
    Route::post('/company/logo', [\App\Http\Controllers\Api\CompanyController::class, 'uploadLogo'])->name('company.logo');

    // User Management
    Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index'])->name('users.index');
    Route::post('/users', [\App\Http\Controllers\Api\UserController::class, 'store'])->name('users.store');
    Route::put('/users/{id}', [\App\Http\Controllers\Api\UserController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/reset-password', [\App\Http\Controllers\Api\UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::get('/roles', [\App\Http\Controllers\Api\UserController::class, 'roles'])->name('roles.index');
    Route::get('/permissions', [\App\Http\Controllers\Api\UserController::class, 'permissionRegistry'])->name('permissions.registry');
    Route::put('/roles/{id}/permissions', [\App\Http\Controllers\Api\UserController::class, 'updateRolePermissions'])->name('roles.permissions');

    // Document Templates (PDF template library)
    Route::get('/document-templates', [\App\Http\Controllers\Api\DocumentTemplateController::class, 'index'])->name('doc-templates.index');
    Route::put('/document-templates', [\App\Http\Controllers\Api\DocumentTemplateController::class, 'update'])->name('doc-templates.update');
    Route::get('/document-templates/preview', [\App\Http\Controllers\Api\DocumentTemplateController::class, 'preview'])->name('doc-templates.preview');

    // Audit Log (admin only)
    Route::get('/audit-logs', [\App\Http\Controllers\Api\AuditLogController::class, 'index'])->name('audit.index');

    // Lead / Inquiry Management
    Route::get('/leads', [\App\Http\Controllers\Api\LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/dashboard', [\App\Http\Controllers\Api\LeadController::class, 'dashboard'])->name('leads.dashboard');
    Route::post('/leads', [\App\Http\Controllers\Api\LeadController::class, 'store'])->name('leads.store');
    Route::get('/leads/{id}', [\App\Http\Controllers\Api\LeadController::class, 'show'])->name('leads.show');
    Route::put('/leads/{id}', [\App\Http\Controllers\Api\LeadController::class, 'update'])->name('leads.update');
    Route::post('/leads/{id}/status', [\App\Http\Controllers\Api\LeadController::class, 'changeStatus'])->name('leads.status');
    Route::post('/leads/{id}/activities', [\App\Http\Controllers\Api\LeadController::class, 'addActivity'])->name('leads.activities');
    Route::post('/leads/{id}/convert', [\App\Http\Controllers\Api\LeadController::class, 'convert'])->name('leads.convert');
    Route::delete('/leads/{id}', [\App\Http\Controllers\Api\LeadController::class, 'destroy'])->name('leads.destroy');

    // Customer Management
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{id}/profile', [CustomerController::class, 'profile'])->name('customers.profile');
    Route::get('/customers/{id}/credit-status', [CustomerController::class, 'creditStatus'])->name('customers.credit');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->name('customers.show');
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    // Panel Types
    Route::get('/panel-types', [PanelTypeController::class, 'index'])->name('panel-types.index');
    Route::post('/panel-types', [PanelTypeController::class, 'store'])->name('panel-types.store');
    Route::put('/panel-types/{id}', [PanelTypeController::class, 'update'])->name('panel-types.update');
    Route::post('/panel-types/{id}/image', [PanelTypeController::class, 'uploadImage'])->name('panel-types.image');
    Route::delete('/panel-types/{id}', [PanelTypeController::class, 'destroy'])->name('panel-types.destroy');

    // Quotation CRUD
    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('/quotations/{id}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::put('/quotations/{id}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::delete('/quotations/{id}', [QuotationController::class, 'destroy'])->name('quotations.destroy');

    // Quotation actions
    Route::post('/quotations/{id}/send', [QuotationController::class, 'send'])->name('quotations.send');
    Route::post('/quotations/{id}/convert', [QuotationController::class, 'convert'])->name('quotations.convert');
    Route::post('/quotations/{id}/rates', [QuotationController::class, 'saveRates'])->name('quotations.rates');
    Route::post('/quotations/{id}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');
    Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
    Route::post('/quotations/{id}/create-order', [QuotationController::class, 'createOrder'])->name('quotations.create-order');
    Route::post('/quotations/{id}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');
    Route::post('/quotations/{id}/duplicate', [QuotationController::class, 'duplicate'])->name('quotations.duplicate');
    Route::post('/quotations/{id}/expire', [QuotationController::class, 'expire'])->name('quotations.expire');
    Route::get('/quotations/{id}/pdf', [QuotationController::class, 'downloadPdf'])->name('quotations.pdf');
    Route::get('/quotations/{id}/boq-pdf', [QuotationController::class, 'downloadBoqSheet'])->name('quotations.boq-pdf');
    Route::post('/quotations/suggested-rate', [QuotationController::class, 'getSuggestedRate'])->name('quotations.suggested-rate');

    // Quotation accessories
    Route::post('/quotations/{id}/accessories', [AccessoryController::class, 'addToQuotation'])->name('quotations.accessories.add');
    Route::delete('/quotations/{id}/accessories/{accessoryId}', [AccessoryController::class, 'removeFromQuotation'])->name('quotations.accessories.remove');

    // Accessories CRUD
    Route::get('/accessories', [AccessoryController::class, 'index'])->name('accessories.index');
    Route::post('/accessories', [AccessoryController::class, 'store'])->name('accessories.store');
    Route::get('/accessories/{id}', [AccessoryController::class, 'show'])->name('accessories.show');
    Route::put('/accessories/{id}', [AccessoryController::class, 'update'])->name('accessories.update');
    Route::post('/accessories/{id}/image', [AccessoryController::class, 'uploadImage'])->name('accessories.image');
    Route::delete('/accessories/{id}', [AccessoryController::class, 'destroy'])->name('accessories.destroy');

    // Orders CRUD
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');

    // Production Planning (advisory — run grouping + "take this first" alerts)
    Route::get('/production/planning', [\App\Http\Controllers\Api\ProductionPlanningController::class, 'index'])->name('production.planning');

    // Production Runs (multi-order grouped production)
    Route::get('/production/runs', [\App\Http\Controllers\Api\ProductionRunController::class, 'index'])->name('runs.index');
    Route::post('/production/runs', [\App\Http\Controllers\Api\ProductionRunController::class, 'store'])->name('runs.store');
    Route::get('/production/runs/{id}', [\App\Http\Controllers\Api\ProductionRunController::class, 'show'])->name('runs.show');
    Route::post('/production/runs/{id}/start', [\App\Http\Controllers\Api\ProductionRunController::class, 'start'])->name('runs.start');
    Route::post('/production/runs/{id}/complete', [\App\Http\Controllers\Api\ProductionRunController::class, 'complete'])->name('runs.complete');
    Route::get('/production/runs/{id}/material-requirement', [\App\Http\Controllers\Api\ProductionRunController::class, 'materialRequirement'])->name('runs.material');
    Route::get('/production/runs/{id}/material-usage', [\App\Http\Controllers\Api\ProductionRunController::class, 'materialUsage'])->name('runs.usage');
    Route::get('/production/runs/{id}/po-suggestion', [\App\Http\Controllers\Api\ProductionRunController::class, 'poSuggestion'])->name('runs.po.suggest');
    Route::get('/production/wastage-report', [\App\Http\Controllers\Api\ProductionRunController::class, 'wastageReport'])->name('production.wastage');
    Route::delete('/production/runs/{id}', [\App\Http\Controllers\Api\ProductionRunController::class, 'destroy'])->name('runs.cancel');

    // Material / BOM settings (per company)
    Route::get('/material-settings', [StockController::class, 'getMaterialSettings'])->name('material.settings.get');
    Route::put('/material-settings', [StockController::class, 'updateMaterialSettings'])->name('material.settings.update');

    // Procurement (suppliers, purchase orders, goods receipt, valuation)
    Route::get('/suppliers', [\App\Http\Controllers\Api\ProcurementController::class, 'suppliers'])->name('suppliers.list');
    Route::post('/suppliers', [\App\Http\Controllers\Api\ProcurementController::class, 'storeSupplier'])->name('suppliers.create');
    Route::put('/suppliers/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'updateSupplier'])->name('suppliers.update');
    Route::get('/procurement/purchasable', [\App\Http\Controllers\Api\ProcurementController::class, 'purchasable'])->name('procurement.purchasable');
    Route::get('/procurement/valuation', [\App\Http\Controllers\Api\ProcurementController::class, 'valuation'])->name('procurement.valuation');
    Route::get('/procurement/reorder-suggestion', [\App\Http\Controllers\Api\ProcurementController::class, 'reorderSuggestion'])->name('procurement.reorder');
    Route::get('/purchase-orders', [\App\Http\Controllers\Api\ProcurementController::class, 'index'])->name('po.list');
    Route::post('/purchase-orders', [\App\Http\Controllers\Api\ProcurementController::class, 'store'])->name('po.create');
    Route::get('/purchase-orders/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'show'])->name('po.show');
    Route::post('/purchase-orders/{id}/receive', [\App\Http\Controllers\Api\ProcurementController::class, 'receive'])->name('po.receive');
    Route::post('/purchase-orders/{id}/cancel', [\App\Http\Controllers\Api\ProcurementController::class, 'cancel'])->name('po.cancel');

    // Production Batches CRUD
    Route::get('/batches', [ProductionBatchController::class, 'index'])->name('batches.index');
    Route::post('/orders/{orderId}/batches', [ProductionBatchController::class, 'createFromOrder'])->name('batches.create');
    Route::get('/batches/{id}', [ProductionBatchController::class, 'show'])->name('batches.show');
    Route::put('/batches/{id}', [ProductionBatchController::class, 'update'])->name('batches.update');
    Route::delete('/batches/{id}', [ProductionBatchController::class, 'destroy'])->name('batches.destroy');

    // Batch actions
    Route::post('/batches/{id}/start', [ProductionBatchController::class, 'startProduction'])->name('batches.start');
    Route::post('/batches/{id}/complete', [ProductionBatchController::class, 'completeBatch'])->name('batches.complete');

    // Production Stages
    Route::get('/production-stages', [ProductionStageController::class, 'index'])->name('stages.index');
    Route::post('/production-stages', [ProductionStageController::class, 'store'])->name('stages.store');
    Route::get('/production-stages/{id}', [ProductionStageController::class, 'show'])->name('stages.show');
    Route::put('/production-stages/{id}', [ProductionStageController::class, 'update'])->name('stages.update');
    Route::delete('/production-stages/{id}', [ProductionStageController::class, 'destroy'])->name('stages.destroy');

    // Batch Stage Logs
    Route::get('/batches/{batchId}/timeline', [BatchStageLogController::class, 'getTimeline'])->name('batches.timeline');
    Route::get('/batches/{batchId}/progress', [BatchStageLogController::class, 'getProgress'])->name('batches.progress');
    Route::post('/batches/{batchId}/stages/{stageId}/start', [BatchStageLogController::class, 'startStage'])->name('batches.stages.start');
    Route::post('/batches/{batchId}/stages/{stageId}/complete', [BatchStageLogController::class, 'completeStage'])->name('batches.stages.complete');

    // Quality Control
    Route::get('/quality-control', [QualityControlController::class, 'index'])->name('qc.index');
    Route::get('/quality-control/statistics', [QualityControlController::class, 'statistics'])->name('qc.statistics');
    Route::get('/quality-control/{id}', [QualityControlController::class, 'show'])->name('qc.show');
    Route::post('/quality-control/{id}/approve', [QualityControlController::class, 'approve'])->name('qc.approve');
    Route::post('/batches/{id}/qc', [QualityControlController::class, 'createForBatch'])->name('qc.create');
    Route::get('/batches/{id}/qc', [QualityControlController::class, 'getForBatch'])->name('qc.get');

    // Cutting Schedule
    Route::post('/batches/{id}/calculate-cutting-schedule', [CuttingScheduleController::class, 'calculateSchedule'])->name('schedule.calculate');
    Route::get('/batches/{id}/cutting-schedule', [CuttingScheduleController::class, 'getInstructions'])->name('schedule.instructions');
    Route::get('/batches/{id}/cutting-schedule/json', [CuttingScheduleController::class, 'getScheduleJson'])->name('schedule.json');

    // Stock Management
    Route::get('/stock/coils', [StockController::class, 'getCoilInventory'])->name('stock.coils.list');
    Route::post('/stock/coils', [StockController::class, 'createCoil'])->name('stock.coils.create');
    Route::get('/stock/coils/{id}', [StockController::class, 'getCoilDetail'])->name('stock.coils.show');
    Route::post('/stock/coils/{id}/add', [StockController::class, 'addCoilStock'])->name('stock.coils.add');
    Route::post('/stock/coils/{id}/remove', [StockController::class, 'removeCoilStock'])->name('stock.coils.remove');
    Route::post('/stock/coils/{id}/adjust', [StockController::class, 'adjustCoilStock'])->name('stock.coils.adjust');
    Route::post('/stock/coils/{id}/reorder', [StockController::class, 'updateCoilReorder'])->name('stock.coils.reorder');

    Route::get('/stock/chemicals', [StockController::class, 'getChemicalInventory'])->name('stock.chemicals.list');
    Route::post('/stock/chemicals', [StockController::class, 'createChemical'])->name('stock.chemicals.create');
    Route::get('/stock/chemicals/{id}', [StockController::class, 'getChemicalDetail'])->name('stock.chemicals.show');
    Route::post('/stock/chemicals/{id}/add', [StockController::class, 'addChemicalStock'])->name('stock.chemicals.add');
    Route::post('/stock/chemicals/{id}/remove', [StockController::class, 'removeChemicalStock'])->name('stock.chemicals.remove');
    Route::post('/stock/chemicals/{id}/adjust', [StockController::class, 'adjustChemicalStock'])->name('stock.chemicals.adjust');
    Route::post('/stock/chemicals/{id}/reorder', [StockController::class, 'updateChemicalReorder'])->name('stock.chemicals.reorder');

    // Consumables (oil/film/tape/packaging)
    Route::get('/stock/consumables', [StockController::class, 'getConsumableInventory'])->name('stock.consumables.list');
    Route::post('/stock/consumables', [StockController::class, 'createConsumable'])->name('stock.consumables.create');
    Route::get('/stock/consumables/{id}', [StockController::class, 'getConsumableDetail'])->name('stock.consumables.show');
    Route::post('/stock/consumables/{id}/add', [StockController::class, 'addConsumableStock'])->name('stock.consumables.add');
    Route::post('/stock/consumables/{id}/remove', [StockController::class, 'removeConsumableStock'])->name('stock.consumables.remove');
    Route::post('/stock/consumables/{id}/adjust', [StockController::class, 'adjustConsumableStock'])->name('stock.consumables.adjust');
    Route::post('/stock/consumables/{id}/reorder', [StockController::class, 'updateConsumableReorder'])->name('stock.consumables.reorder');

    Route::get('/stock/transactions', [StockController::class, 'getTransactions'])->name('stock.transactions.list');
    Route::get('/stock/transactions/{id}', [StockController::class, 'getTransaction'])->name('stock.transactions.show');

    Route::get('/stock/alerts', [StockController::class, 'getAlerts'])->name('stock.alerts.list');
    Route::post('/stock/alerts/{id}/resolve', [StockController::class, 'resolveAlert'])->name('stock.alerts.resolve');

    Route::get('/stock/dashboard', [StockController::class, 'getDashboard'])->name('stock.dashboard');
    Route::get('/stock/report', [StockController::class, 'getInventoryReport'])->name('stock.report');

    // Dispatch Management
    Route::get('/dispatches', [DispatchController::class, 'index'])->name('dispatches.index');
    Route::post('/batches/{batchId}/dispatch', [DispatchController::class, 'store'])->name('dispatches.create');
    Route::get('/dispatches/{id}', [DispatchController::class, 'show'])->name('dispatches.show');
    Route::put('/dispatches/{id}', [DispatchController::class, 'update'])->name('dispatches.update');
    Route::delete('/dispatches/{id}', [DispatchController::class, 'destroy'])->name('dispatches.cancel');

    Route::post('/dispatches/{id}/allocate', [DispatchController::class, 'allocate'])->name('dispatches.allocate');
    Route::post('/dispatches/{id}/complete', [DispatchController::class, 'complete'])->name('dispatches.complete');

    Route::get('/dispatches/{id}/challan', [DispatchController::class, 'getChallan'])->name('dispatches.challan');
    Route::get('/dispatches/{id}/challan/pdf', [DispatchController::class, 'getChallanPdf'])->name('dispatches.challan.pdf');

    Route::get('/batches/{batchId}/dispatches', [DispatchController::class, 'getDispatchesByBatch'])->name('dispatches.by_batch');

    // Invoice Management
    Route::post('/invoices/from-dispatch', [InvoiceController::class, 'createFromDispatch'])->name('invoices.from-dispatch');
    Route::post('/invoices/from-order', [InvoiceController::class, 'createFromOrder'])->name('invoices.from-order');
    Route::get('/invoices', [InvoiceController::class, 'list'])->name('invoices.list');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::put('/invoices/{id}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{id}/items', [InvoiceController::class, 'addItem'])->name('invoices.items.add');
    Route::post('/invoices/{id}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{id}/accept', [InvoiceController::class, 'accept'])->name('invoices.accept');
    Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{id}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf.download');
    Route::get('/invoices/{id}/pdf-preview', [InvoiceController::class, 'generatePreview'])->name('invoices.pdf.preview');
    Route::post('/invoices/{id}/send-email', [InvoiceController::class, 'sendEmail'])->name('invoices.email.send');

    // e-Invoice (IRN / QR) + e-Way Bill
    Route::get('/invoices/{id}/einvoice/status',            [\App\Http\Controllers\Api\EInvoiceController::class, 'status'])->name('einvoice.status');
    Route::post('/invoices/{id}/einvoice/irn/generate',     [\App\Http\Controllers\Api\EInvoiceController::class, 'generateIrn'])->name('einvoice.irn.generate');
    Route::post('/invoices/{id}/einvoice/irn/manual',       [\App\Http\Controllers\Api\EInvoiceController::class, 'setIrnManual'])->name('einvoice.irn.manual');
    Route::post('/invoices/{id}/einvoice/irn/cancel',       [\App\Http\Controllers\Api\EInvoiceController::class, 'cancelIrn'])->name('einvoice.irn.cancel');
    Route::post('/invoices/{id}/einvoice/eway/generate',    [\App\Http\Controllers\Api\EInvoiceController::class, 'generateEwayBill'])->name('einvoice.eway.generate');
    Route::post('/invoices/{id}/einvoice/eway/manual',      [\App\Http\Controllers\Api\EInvoiceController::class, 'setEwayBillManual'])->name('einvoice.eway.manual');
    Route::post('/invoices/{id}/einvoice/eway/cancel',      [\App\Http\Controllers\Api\EInvoiceController::class, 'cancelEwayBill'])->name('einvoice.eway.cancel');
    Route::get('/invoices/{id}/email-preview/{type}', [InvoiceController::class, 'emailPreview'])->name('invoices.email.preview');

    // Payment Management
    Route::post('/payments/record', [PaymentController::class, 'recordPayment'])->name('payments.record');
    Route::get('/invoices/{id}/payments', [PaymentController::class, 'getPaymentHistory'])->name('payments.history');
    Route::get('/invoices/{id}/payment-status', [PaymentController::class, 'getPaymentStatus'])->name('payments.status');
    Route::post('/invoices/{id}/payment-reminder', [PaymentController::class, 'issueReminder'])->name('payments.reminder');
    Route::post('/invoices/{id}/write-off', [PaymentController::class, 'writeOff'])->name('payments.write-off');
    Route::post('/payments/reconcile', [PaymentController::class, 'reconcile'])->name('payments.reconcile');
    Route::get('/payments/unpaid', [PaymentController::class, 'getUnpaidInvoices'])->name('payments.unpaid');
    Route::post('/invoices/{id}/send-payment-reminder', [PaymentController::class, 'sendPaymentReminder'])->name('payments.reminder.send');
    Route::post('/invoices/{id}/send-payment-confirmation', [PaymentController::class, 'sendPaymentConfirmation'])->name('payments.confirmation.send');

    // Stripe Payment Gateway
    Route::post('/invoices/{id}/payment/checkout-session', [PaymentController::class, 'createCheckoutSession'])->name('payments.stripe.checkout');
    Route::post('/invoices/{id}/payment/intent', [PaymentController::class, 'createPaymentIntent'])->name('payments.stripe.intent');
    Route::post('/payments/intent/confirm', [PaymentController::class, 'confirmPaymentIntent'])->name('payments.stripe.confirm');
    Route::get('/invoices/{id}/payment-link', [PaymentController::class, 'getPaymentLink'])->name('payments.stripe.link');

    // Payment Reminders
    Route::post('/invoices/{id}/schedule-reminder', [PaymentController::class, 'scheduleReminder'])->name('payments.reminder.schedule');
    Route::get('/invoices/{id}/reminder-status', [PaymentController::class, 'getReminderStatus'])->name('payments.reminder.status');
    Route::post('/invoices/{id}/send-reminder', [PaymentController::class, 'sendManualReminder'])->name('payments.reminder.manual');
    Route::get('/reminders/stats', [PaymentController::class, 'getReminderStats'])->name('payments.reminder.stats');

    // Financial Reports
    Route::get('/reports/profit-loss', [ReportingController::class, 'profitLossStatement'])->name('reports.pl');
    Route::get('/reports/balance-sheet', [ReportingController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/cash-flow', [ReportingController::class, 'cashFlowStatement'])->name('reports.cash-flow');
    Route::get('/reports/accounts-receivable', [ReportingController::class, 'accountsReceivableAging'])->name('reports.ar');
    Route::get('/reports/sales', [ReportingController::class, 'salesReport'])->name('reports.sales');
    Route::get('/reports/tax', [ReportingController::class, 'taxReport'])->name('reports.tax');
    Route::get('/reports/accounting-dashboard', [ReportingController::class, 'accountingDashboard'])->name('reports.dashboard');
    Route::get('/reports/reconcile', [ReportingController::class, 'reconcileInvoices'])->name('reports.reconcile');
    Route::get('/reports/revenue-trend', [ReportingController::class, 'monthlyRevenueTrend'])->name('reports.revenue-trend');
    Route::get('/reports/top-customers', [ReportingController::class, 'topCustomers'])->name('reports.top-customers');
    Route::get('/reports/panel-type-mix', [ReportingController::class, 'panelTypeMix'])->name('reports.panel-mix');
    Route::get('/reports/mis',            [ReportingController::class, 'misReport'])->name('reports.mis');
    Route::get('/reports/tally/xml',      [ReportingController::class, 'tallyXml'])->name('reports.tally.xml');
    Route::get('/reports/tally/csv',      [ReportingController::class, 'tallyCsv'])->name('reports.tally.csv');

    // Forecasting & Prediction
    Route::post('/forecasts/inventory', [ForecastingController::class, 'generateInventoryForecast'])->name('forecasts.inventory.generate');
    Route::post('/forecasts/demand', [ForecastingController::class, 'generateDemandForecast'])->name('forecasts.demand.generate');
    Route::get('/forecasts/demand', [ForecastingController::class, 'getDemandForecast'])->name('forecasts.demand.list');
    Route::get('/forecasts/reorders', [ForecastingController::class, 'getUpcomingReorders'])->name('forecasts.reorders');

    // ML Forecasting
    Route::post('/forecasts/ml', [ForecastingController::class, 'generateMlForecast'])->name('forecasts.ml.generate');
    Route::post('/forecasts/ml/compare-models', [ForecastingController::class, 'compareModels'])->name('forecasts.ml.compare');
    Route::get('/forecasts/ml/anomalies', [ForecastingController::class, 'getAnomalyDetection'])->name('forecasts.ml.anomalies');
    Route::post('/forecasts/ml/record-actual', [ForecastingController::class, 'recordActual'])->name('forecasts.ml.record-actual');
    Route::get('/forecasts/ml/performance', [ForecastingController::class, 'getModelPerformance'])->name('forecasts.ml.performance');

    // Analytics & Trends
    Route::post('/analytics/metrics/sales', [AnalyticsController::class, 'recordSalesMetric'])->name('analytics.metrics.sales.record');
    Route::post('/analytics/trends', [AnalyticsController::class, 'generateTrendAnalysis'])->name('analytics.trends.generate');
    Route::get('/analytics/trends', [AnalyticsController::class, 'getTrendAnalysis'])->name('analytics.trends.list');
    Route::post('/analytics/snapshot', [AnalyticsController::class, 'createSnapshot'])->name('analytics.snapshot.create');
    Route::get('/analytics/snapshot', [AnalyticsController::class, 'getSnapshot'])->name('analytics.snapshot.get');

    // SMS Alerts & Notifications
    Route::post('/invoices/{id}/send-sms-reminder', [SmsController::class, 'sendPaymentReminder'])->name('sms.payment-reminder');
    Route::post('/sms/send', [SmsController::class, 'sendCustomSms'])->name('sms.send');
    Route::post('/sms/validate', [SmsController::class, 'validatePhoneNumber'])->name('sms.validate');
    Route::get('/sms/logs', [SmsController::class, 'getSmsLogs'])->name('sms.logs');
    Route::get('/sms/status', [SmsController::class, 'getSmsStatus'])->name('sms.status');

    // GST Configuration & Compliance
    Route::post('/gst/register', [GstController::class, 'registerConfiguration'])->name('gst.register');
    Route::post('/gst/hsn-code', [GstController::class, 'addHsnCode'])->name('gst.hsn.add');
    Route::post('/invoices/{id}/calculate-gst', [GstController::class, 'calculateGst'])->name('gst.calculate');
    Route::get('/invoices/{id}/gst-breakdown', [GstController::class, 'getGstBreakdown'])->name('gst.breakdown');
    Route::get('/gst/configurations', [GstController::class, 'getConfigurations'])->name('gst.configurations');
    Route::get('/gst/report', [GstController::class, 'generateReport'])->name('gst.report');
    Route::get('/gst/compliance', [GstController::class, 'getCompliance'])->name('gst.compliance');
    Route::post('/gst/validate-gstin', [GstController::class, 'validateGstin'])->name('gst.validate-gstin');
    Route::get('/gst/states', [GstController::class, 'getStatesList'])->name('gst.states');

    // Notification settings (Twilio SMS + WhatsApp credentials + triggers)
    Route::get('/settings/notifications',          [\App\Http\Controllers\Api\NotificationSettingsController::class, 'show'])->name('notif.settings.show');
    Route::put('/settings/notifications',          [\App\Http\Controllers\Api\NotificationSettingsController::class, 'update'])->name('notif.settings.update');
    Route::post('/settings/notifications/test',    [\App\Http\Controllers\Api\NotificationSettingsController::class, 'testSend'])->name('notif.settings.test');
});

// Public routes
Route::post('/webhooks/stripe', [PaymentController::class, 'handleStripeWebhook'])->name('webhooks.stripe');

// Health check endpoint
Route::get('/health', function () {
    return response()->json(['status' => 'OK', 'timestamp' => now()]);
});
