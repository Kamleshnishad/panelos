<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleDataSeeder extends Seeder
{
    private $company;
    private $panelTypes;
    private $customers;

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $this->company = \App\Models\Company::where('subdomain', 'demo')->first();
        if (!$this->company) {
            $this->command->error('Run RoleUserSeeder first!');
            return;
        }

        $this->command->info('🌱 Seeding sample data for company: ' . $this->company->name);

        $this->seedPanelTypes();
        $this->seedCustomers();
        $this->seedProductionStages();
        $this->seedQuotationsAndOrders();
        $this->seedProductionBatches();
        $this->seedDispatches();
        $this->seedInvoicesAndPayments();
        $this->seedStock();
        $this->seedSalesMetrics();
        $this->seedTaxConfig();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command->info('');
        $this->command->info('✅ Sample data seeded!');
        $this->command->info('   Panel Types      : ' . \App\Models\PanelType::where('company_id', $this->company->id)->count());
        $this->command->info('   Customers        : ' . \App\Models\Customer::where('company_id', $this->company->id)->count());
        $this->command->info('   Quotations       : ' . \App\Models\Quotation::where('company_id', $this->company->id)->count());
        $this->command->info('   Orders           : ' . \App\Models\Order::where('company_id', $this->company->id)->count());
        $this->command->info('   Production Batches: ' . \App\Models\ProductionBatch::where('company_id', $this->company->id)->count());
        $this->command->info('   Dispatches       : ' . \App\Models\Dispatch::where('company_id', $this->company->id)->count());
        $this->command->info('   Invoices         : ' . \App\Models\Invoice::where('company_id', $this->company->id)->count());
        $this->command->info('   Payments         : ' . DB::table('payment_transactions')->where('company_id', $this->company->id)->count());
    }

    // ── Panel Types ─────────────────────────────────────────────────────────

    private function seedPanelTypes(): void
    {
        $types = [
            ['name' => 'Puff Panel 50mm',  'code' => 'PP50MM',  'description' => 'Standard 50mm insulated panel',  'base_price' => 850,  'thickness' => 50],
            ['name' => 'Puff Panel 75mm',  'code' => 'PP75MM',  'description' => '75mm high-insulation panel',     'base_price' => 1050, 'thickness' => 75],
            ['name' => 'Puff Panel 100mm', 'code' => 'PP100MM', 'description' => '100mm cold-room panel',          'base_price' => 1280, 'thickness' => 100],
            ['name' => 'Roofing Panel',    'code' => 'ROOFPNL', 'description' => 'Standing seam roofing panel',    'base_price' => 720,  'thickness' => 40],
            ['name' => 'Wall Panel',       'code' => 'WALLPNL', 'description' => 'Exterior cladding wall panel',   'base_price' => 650,  'thickness' => 40],
        ];

        $this->panelTypes = collect();
        foreach ($types as $t) {
            $pt = \App\Models\PanelType::firstOrCreate(
                ['company_id' => $this->company->id, 'name' => $t['name']],
                [
                    'code'               => $t['code'],
                    'description'        => $t['description'],
                    'base_price'         => $t['base_price'],
                    'thickness'          => $t['thickness'],
                    'width'              => 1000,
                    'length'             => 3000,
                    'thermal_resistance' => round(rand(20,50) / 10, 1),
                    'is_active'          => true,
                ]
            );
            $this->panelTypes->push($pt);
        }
    }

    // ── Customers ───────────────────────────────────────────────────────────

    private function seedCustomers(): void
    {
        $list = [
            ['name'=>'Arjun Cold Storage Pvt Ltd',  'city'=>'Mumbai',     'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'corporate',   'email'=>'arjun@coldstorage.com',   'phone'=>'9821001001'],
            ['name'=>'Krishna Refrigeration Works',  'city'=>'Pune',       'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'wholesale',   'email'=>'info@krishnarefrig.com',  'phone'=>'9822002002'],
            ['name'=>'Shree Poultry Farm',           'city'=>'Nashik',     'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'retail',      'email'=>'shree@poultryfarm.com',   'phone'=>'9823003003'],
            ['name'=>'Global Pharma Warehouse',      'city'=>'Ahmedabad',  'state'=>'Gujarat',        'state_code'=>'GJ', 'type'=>'corporate',   'email'=>'global@pharmawh.com',     'phone'=>'9824004004'],
            ['name'=>'Patel Food Processing',        'city'=>'Surat',      'state'=>'Gujarat',        'state_code'=>'GJ', 'type'=>'wholesale',   'email'=>'patel@foodprocess.in',    'phone'=>'9825005005'],
            ['name'=>'Tech Construction Pvt Ltd',    'city'=>'Bangalore',  'state'=>'Karnataka',      'state_code'=>'KA', 'type'=>'corporate',   'email'=>'tech@construction.in',    'phone'=>'9826006006'],
            ['name'=>'Sunrise Dairy Products',       'city'=>'Hyderabad',  'state'=>'Telangana',      'state_code'=>'TG', 'type'=>'retail',      'email'=>'sunrise@dairy.com',       'phone'=>'9827007007'],
            ['name'=>'Metro Fresh Mart',             'city'=>'Chennai',    'state'=>'Tamil Nadu',     'state_code'=>'TN', 'type'=>'distributor', 'email'=>'metro@freshmart.in',      'phone'=>'9828008008'],
            ['name'=>'Rajasthan Cold Chain Co',      'city'=>'Jaipur',     'state'=>'Rajasthan',      'state_code'=>'RJ', 'type'=>'wholesale',   'email'=>'raj@coldchain.in',        'phone'=>'9829009009'],
            ['name'=>'North India Agri Storage',     'city'=>'Lucknow',    'state'=>'Uttar Pradesh',  'state_code'=>'UP', 'type'=>'corporate',   'email'=>'north@agristorage.in',    'phone'=>'9830010010'],
            ['name'=>'Himalayan Ice Cream Co',       'city'=>'Chandigarh', 'state'=>'Punjab',         'state_code'=>'PB', 'type'=>'retail',      'email'=>'himalayan@icecream.com',  'phone'=>'9831011011'],
            ['name'=>'Eastern Seafood Pvt Ltd',      'city'=>'Kolkata',    'state'=>'West Bengal',    'state_code'=>'WB', 'type'=>'corporate',   'email'=>'eastern@seafood.com',     'phone'=>'9832012012'],
            ['name'=>'Deccan Beverages Ltd',         'city'=>'Pune',       'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'corporate',   'email'=>'deccan@beverages.com',    'phone'=>'9833013013'],
            ['name'=>'Vinayak Super Stores',         'city'=>'Nagpur',     'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'retail',      'email'=>'vinayak@superstores.in',  'phone'=>'9834014014'],
            ['name'=>'Capital City Logistics',       'city'=>'Delhi',      'state'=>'Delhi',          'state_code'=>'DL', 'type'=>'distributor', 'email'=>'capital@logistics.in',    'phone'=>'9835015015'],
            ['name'=>'Apex Frozen Foods',            'city'=>'Mumbai',     'state'=>'Maharashtra',    'state_code'=>'MH', 'type'=>'wholesale',   'email'=>'apex@frozenfoods.com',    'phone'=>'9836016016'],
            ['name'=>'South Star Fisheries',         'city'=>'Kochi',      'state'=>'Kerala',         'state_code'=>'KL', 'type'=>'retail',      'email'=>'southstar@fisheries.com', 'phone'=>'9837017017'],
            ['name'=>'Wonder Meat Processing',       'city'=>'Bhopal',     'state'=>'Madhya Pradesh', 'state_code'=>'MP', 'type'=>'corporate',   'email'=>'wonder@meatprocess.in',   'phone'=>'9838018018'],
            ['name'=>'Agro Fresh Exports',           'city'=>'Coimbatore', 'state'=>'Tamil Nadu',     'state_code'=>'TN', 'type'=>'distributor', 'email'=>'agro@freshexports.in',    'phone'=>'9839019019'],
            ['name'=>'Pinnacle Infrastructure',      'city'=>'Noida',      'state'=>'Uttar Pradesh',  'state_code'=>'UP', 'type'=>'corporate',   'email'=>'pinnacle@infra.in',        'phone'=>'9840020020'],
        ];

        $this->customers = collect();
        $firstNames = ['Rajesh','Suresh','Priya','Amit','Deepak','Neha','Vijay','Anita','Rohit','Sunita'];
        $lastNames  = ['Sharma','Patel','Gupta','Singh','Kumar','Joshi','Shah','Mehta','Rao','Verma'];

        foreach ($list as $c) {
            $contact = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
            $cust = \App\Models\Customer::firstOrCreate(
                ['company_id' => $this->company->id, 'email' => $c['email']],
                [
                    'name'           => $c['name'],
                    'code'           => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $c['name']), 0, 4)) . rand(100,999),
                    'type'           => $c['type'],
                    'contact_person' => $contact,
                    'phone'          => $c['phone'],
                    'whatsapp_no'    => $c['phone'],
                    'address_line1'  => rand(1,999) . ' Industrial Area',
                    'city'           => $c['city'],
                    'state'          => $c['state'],
                    'state_code'     => $c['state_code'],
                    'pincode'        => rand(100000, 999999),
                    'country'        => 'India',
                    'credit_limit'   => rand(5, 50) * 10000,
                    'outstanding_balance' => 0,
                    'payment_terms_days'  => [15, 30, 45, 60][rand(0,3)],
                    'is_active'      => true,
                ]
            );
            $this->customers->push($cust);
        }
    }

    // ── Production Stages ────────────────────────────────────────────────────

    private function seedProductionStages(): void
    {
        $stages = [
            ['name'=>'Raw Material Inspection','sequence'=>1],
            ['name'=>'Sheet Cutting',          'sequence'=>2],
            ['name'=>'Foam Injection',         'sequence'=>3],
            ['name'=>'Curing',                 'sequence'=>4],
            ['name'=>'Quality Check',          'sequence'=>5],
            ['name'=>'Packaging',              'sequence'=>6],
        ];
        foreach ($stages as $s) {
            \App\Models\ProductionStage::firstOrCreate(
                ['company_id' => $this->company->id, 'name' => $s['name']],
                ['sequence' => $s['sequence'], 'is_active' => true]
            );
        }
    }

    // ── Quotations & Orders ──────────────────────────────────────────────────

    private function seedQuotationsAndOrders(): void
    {
        $statuses = ['draft','draft','sent','sent','accepted','accepted','accepted','rejected','draft','sent',
                     'accepted','accepted','sent','accepted','draft','accepted','sent','rejected','accepted','sent'];

        foreach (range(1, 20) as $i) {
            $customer  = $this->customers[$i - 1];  // one-to-one mapping for clean data
            $panelType = $this->panelTypes[($i - 1) % $this->panelTypes->count()];
            $qty       = rand(50, 500);
            $unitPrice = $panelType->base_price;
            $subtotal  = $qty * $unitPrice;
            $taxAmount = round($subtotal * 0.18, 2);
            $total     = $subtotal + $taxAmount;
            $status    = $statuses[$i - 1];

            $quot = \App\Models\Quotation::firstOrCreate(
                ['company_id' => $this->company->id, 'quotation_no' => 'Q-2026-' . str_pad($i, 4, '0', STR_PAD_LEFT)],
                [
                    'customer_id'  => $customer->id,
                    'status'       => $status,
                    'subtotal'     => $subtotal,
                    'tax_amount'   => $taxAmount,
                    'total_amount' => $total,
                    'quoted_on'    => now()->subDays(rand(5, 90))->toDateString(),
                    'valid_until'  => now()->addDays(rand(10, 30))->toDateString(),
                    'sent_at'      => in_array($status, ['sent','accepted','rejected']) ? now()->subDays(rand(2,20)) : null,
                    'accepted_at'  => $status === 'accepted' ? now()->subDays(rand(1,10)) : null,
                    'notes'        => $i % 4 === 0 ? 'Urgent delivery required.' : null,
                ]
            );

            // Quotation item
            \App\Models\QuotationItem::create([
                'quotation_id'  => $quot->id,
                'panel_type_id' => $panelType->id,
                'quantity'      => $qty,
                'unit_price'    => $unitPrice,
                'amount'        => $subtotal,
            ]);

            // Create order for accepted quotations
            if ($status === 'accepted') {
                $oStatus = ['pending','in_production','in_production','completed','pending'][rand(0,4)];
                \App\Models\Order::create([
                    'company_id'   => $this->company->id,
                    'quotation_id' => $quot->id,
                    'customer_id'  => $customer->id,
                    'order_no'     => 'ORD-2026-' . str_pad($quot->id, 4, '0', STR_PAD_LEFT),
                    'status'       => $oStatus,
                    'subtotal'     => $subtotal,
                    'tax_amount'   => $taxAmount,
                    'total_amount' => $total,
                    'order_date'   => now()->subDays(rand(2,30))->toDateString(),
                    'expected_delivery_date' => now()->addDays(rand(7,30))->toDateString(),
                ]);
            }
        }
    }

    // ── Production Batches ───────────────────────────────────────────────────

    private function seedProductionBatches(): void
    {
        $orders   = \App\Models\Order::where('company_id', $this->company->id)->take(10)->get();
        $bStatuses= ['draft','in_progress','in_progress','completed','completed','completed','draft','in_progress','completed','in_progress'];

        foreach ($orders as $idx => $order) {
            $panelType = $this->panelTypes[$idx % $this->panelTypes->count()];
            $status    = $bStatuses[$idx] ?? 'pending';

            \App\Models\ProductionBatch::create([
                'company_id'       => $this->company->id,
                'order_id'         => $order->id,
                'batch_no'         => 'BATCH-2026-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'planned_quantity' => rand(50, 300),
                'completed_quantity'=> $status === 'completed' ? rand(50, 300) : 0,
                'status'           => $status,
                'started_at'       => now()->subDays(rand(5,30)),
                'completed_at'     => $status === 'completed' ? now()->subDays(rand(1,5)) : null,
                'notes'            => null,
            ]);
        }
    }

    // ── Dispatches ───────────────────────────────────────────────────────────

    private function seedDispatches(): void
    {
        $batches = \App\Models\ProductionBatch::where('company_id', $this->company->id)
            ->whereIn('status', ['completed','in_progress'])->take(8)->get();

        foreach ($batches as $idx => $batch) {
            $status = $idx < 5 ? 'delivered' : 'pending';
            \App\Models\Dispatch::create([
                'company_id'   => $this->company->id,
                'batch_id'     => $batch->id,
                'dispatch_no'  => 'DSP-2026-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'status'       => $status,
                'dispatch_date'=> now()->subDays(rand(1, 20))->toDateString(),
                'expected_delivery_date' => now()->addDays(rand(2,7))->toDateString(),
                'actual_delivery_date'   => $status === 'completed' ? now()->subDays(rand(0,5))->toDateString() : null,
                'tracking_number'        => 'TRK' . rand(100000,999999),
                'notes'        => null,
            ]);
        }
    }

    // ── Invoices & Payments ──────────────────────────────────────────────────

    private function seedInvoicesAndPayments(): void
    {
        $dispatches = \App\Models\Dispatch::where('company_id', $this->company->id)->get();
        $orders     = \App\Models\Order::where('company_id', $this->company->id)->get();
        $invStatuses= ['draft','sent','sent','accepted','paid','paid','paid','paid','sent','cancelled',
                       'paid','sent','accepted','paid','draft','paid','sent','accepted','paid','paid'];

        foreach (range(1, 20) as $i) {
            $customer  = $this->customers[$i - 1];
            $panelType = $this->panelTypes[($i - 1) % $this->panelTypes->count()];
            $dispatch  = $dispatches->count() ? $dispatches[($i - 1) % $dispatches->count()] : null;
            $order     = $orders->count() ? $orders[($i - 1) % $orders->count()] : null;
            $qty       = rand(50, 400);
            $unitPrice = $panelType->base_price;
            $amount    = $qty * $unitPrice;
            $taxAmount = round($amount * 0.18, 2);
            $total     = $amount + $taxAmount;
            $status    = $invStatuses[$i - 1];
            $dueDate   = now()->subDays(rand(-10, 40));

            $invoice = \App\Models\Invoice::create([
                'company_id'   => $this->company->id,
                'dispatch_id'  => $dispatch?->id,
                'order_id'     => $order?->id,
                'invoice_no'   => 'INV-2026-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'status'       => $status,
                'subtotal'     => $amount,
                'tax_amount'   => $taxAmount,
                'total_amount' => $total,
                'invoice_date' => now()->subDays(rand(5, 60))->toDateString(),
                'due_date'     => $dueDate->toDateString(),
                'paid_date'    => $status === 'paid' ? now()->subDays(rand(0,10))->toDateString() : null,
                'notes'        => $i % 5 === 0 ? 'GST invoice required.' : null,
            ]);

            \App\Models\InvoiceItem::create([
                'invoice_id'    => $invoice->id,
                'panel_type_id' => $panelType->id,
                'quantity'      => $qty,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
                'tax_rate'      => 18,
                'tax_amount'    => $taxAmount,
                'total_with_tax'=> $total,
            ]);

            \App\Models\TaxCalculation::create([
                'invoice_id'   => $invoice->id,
                'tax_rate'     => 18,
                'taxable_amount'=> $amount,
                'tax_amount'   => $taxAmount,
                'cgst_amount'  => round($taxAmount / 2, 2),
                'sgst_amount'  => round($taxAmount / 2, 2),
                'igst_amount'  => 0,
            ]);

            if ($status === 'paid') {
                \App\Models\PaymentTransaction::create([
                    'company_id'     => $this->company->id,
                    'invoice_id'     => $invoice->id,
                    'amount'         => $total,
                    'payment_method' => ['bank_transfer','upi','cheque','cash'][rand(0,3)],
                    'reference_no'   => 'REF' . rand(100000, 999999),
                    'transaction_date'=> now()->subDays(rand(0,10)),
                    'created_by_user_id' => null,
                ]);
            }
        }
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    private function seedStock(): void
    {
        foreach ($this->panelTypes as $pt) {
            $stock   = rand(100, 2000);
            $reorder = rand(50, 200);

            \App\Models\CoilStock::updateOrCreate(
                ['company_id' => $this->company->id, 'panel_type_id' => $pt->id],
                ['coil_id' => null, 'quantity_in_stock' => $stock, 'reorder_level' => $reorder,
                 'last_stock_in' => now()->subDays(rand(1,10)), 'last_stock_out' => now()->subDays(rand(0,5))]
            );

            if ($stock <= $reorder * 1.2) {
                \App\Models\LowStockAlert::firstOrCreate(
                    ['company_id' => $this->company->id, 'item_type' => 'coil', 'item_id' => $pt->id, 'alert_type' => 'low_stock', 'status' => 'active'],
                    ['current_quantity' => $stock, 'reorder_level' => $reorder, 'alert_sent_at' => now()]
                );
            }
        }
    }

    // ── Sales Metrics ────────────────────────────────────────────────────────

    private function seedSalesMetrics(): void
    {
        foreach ($this->panelTypes as $pt) {
            $base = rand(20, 80);
            for ($d = 60; $d >= 1; $d--) {
                $date      = now()->subDays($d)->toDateString();
                $dayOfWeek = now()->subDays($d)->dayOfWeek;
                $mult      = in_array($dayOfWeek, [0,6]) ? 0.4 : 1.0;
                if (rand(1,10) === 1) $mult *= 3;
                $qty       = max(0, (int)($base * $mult * (0.7 + rand(0,60)/100)));
                $revenue   = $qty * $pt->base_price;

                \App\Models\SalesMetric::firstOrCreate(
                    ['company_id' => $this->company->id, 'panel_type_id' => $pt->id, 'metric_date' => $date],
                    ['quantity_sold' => $qty, 'revenue' => $revenue, 'average_price' => $pt->base_price, 'invoice_count' => max(1, (int)($qty/50))]
                );
            }
        }
    }

    // ── Tax Config ────────────────────────────────────────────────────────────

    private function seedTaxConfig(): void
    {
        DB::table('tax_configurations')->insertOrIgnore([
            'company_id'       => $this->company->id,
            'gst_number'       => '27PANELOS2026H1ZP',
            'tax_type'         => 'GST',
            'default_tax_rate' => 18,
            'is_active'        => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
