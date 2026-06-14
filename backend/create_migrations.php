<?php
/**
 * Migration Generator for PanelOS Database Schema
 * Generates all 51 table migrations based on 02_DATABASE_SCHEMA.md
 */

$tables = [
    'companies' => "
        \$table->id();
        \$table->string('name');
        \$table->string('subdomain')->unique();
        \$table->string('logo')->nullable();
        \$table->string('gstin', 20)->nullable();
        \$table->string('pan', 20)->nullable();
        \$table->string('address_line1')->nullable();
        \$table->string('city', 100)->nullable();
        \$table->string('state', 100)->nullable();
        \$table->string('state_code', 5)->nullable();
        \$table->string('pincode', 10)->nullable();
        \$table->string('phone', 20)->nullable();
        \$table->string('email')->nullable();
        \$table->string('bank_name')->nullable();
        \$table->string('bank_account_no', 50)->nullable();
        \$table->string('bank_ifsc', 20)->nullable();
        \$table->string('bank_branch')->nullable();
        \$table->string('authorized_signatory')->nullable();
        \$table->string('signatory_phone', 20)->nullable();
        \$table->string('primary_color')->default('#1a237e');
        \$table->string('secondary_color')->default('#f57f17');
        \$table->string('quotation_prefix')->default('SCP');
        \$table->string('invoice_prefix')->default('INV');
        \$table->string('order_prefix')->default('ORD');
        \$table->string('challan_prefix')->default('CH');
        \$table->tinyInteger('financial_year_start')->default(4);
        \$table->boolean('e_invoice_applicable')->default(false);
        \$table->boolean('tcs_applicable')->default(false);
        \$table->enum('subscription_plan', ['starter', 'growth', 'pro', 'enterprise'])->default('growth');
        \$table->enum('subscription_status', ['active', 'trial', 'expired'])->default('trial');
        \$table->boolean('is_active')->default(true);
        \$table->json('settings')->nullable();
        \$table->timestamps();
        \$table->softDeletes();
    ",

    'users' => "
        \$table->id();
        \$table->unsignedBigInteger('company_id');
        \$table->string('name');
        \$table->string('email');
        \$table->string('phone', 20)->nullable();
        \$table->string('whatsapp_no', 20)->nullable();
        \$table->string('password');
        \$table->unsignedBigInteger('role_id')->nullable();
        \$table->boolean('is_super_admin')->default(false);
        \$table->boolean('is_company_admin')->default(false);
        \$table->boolean('is_active')->default(true);
        \$table->timestamp('last_login_at')->nullable();
        \$table->timestamps();
        \$table->softDeletes();
        \$table->unique(['email', 'company_id']);
        \$table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
    ",

    'roles' => "
        \$table->id();
        \$table->unsignedBigInteger('company_id');
        \$table->string('name');
        \$table->string('guard_name')->default('web');
        \$table->json('permissions')->nullable();
        \$table->text('description')->nullable();
        \$table->boolean('is_system_role')->default(false);
        \$table->timestamps();
        \$table->softDeletes();
        \$table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        \$table->unique(['company_id', 'name']);
    ",

    'customers' => "
        \$table->id();
        \$table->unsignedBigInteger('company_id');
        \$table->string('name');
        \$table->string('code')->unique();
        \$table->enum('type', ['retail', 'wholesale', 'distributor', 'corporate'])->default('retail');
        \$table->string('contact_person')->nullable();
        \$table->string('email')->nullable();
        \$table->string('phone', 20);
        \$table->string('whatsapp_no', 20)->nullable();
        \$table->string('gstin', 20)->nullable();
        \$table->string('pan', 20)->nullable();
        \$table->string('address_line1');
        \$table->string('address_line2')->nullable();
        \$table->string('city', 100);
        \$table->string('state', 100);
        \$table->string('state_code', 5);
        \$table->string('pincode', 10);
        \$table->string('country')->default('India');
        \$table->decimal('credit_limit', 12, 2)->default(0);
        \$table->decimal('outstanding_balance', 12, 2)->default(0);
        \$table->integer('payment_terms_days')->default(30);
        \$table->text('notes')->nullable();
        \$table->boolean('is_active')->default(true);
        \$table->timestamps();
        \$table->softDeletes();
        \$table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
    ",

    'panel_types' => "
        \$table->id();
        \$table->unsignedBigInteger('company_id');
        \$table->string('name');
        \$table->string('code', 50);
        \$table->enum('category', ['roof', 'wall', 'cold_room', 'ceiling', 'partition', 'other']);
        \$table->enum('core_material', ['puf', 'pir', 'rockwool', 'eps', 'glasswool'])->default('puf');
        \$table->string('hsn_code', 20);
        \$table->decimal('gst_rate', 5, 2)->default(18);
        \$table->integer('standard_width_mm')->default(1000);
        \$table->boolean('allow_custom_width')->default(false);
        \$table->json('available_thicknesses');
        \$table->json('available_densities_puf')->nullable();
        \$table->json('available_densities_pir')->nullable();
        \$table->enum('default_density_type', ['puf', 'pir'])->default('puf');
        \$table->decimal('default_density', 5, 2)->default(40);
        \$table->json('top_skin_materials')->nullable();
        \$table->json('bottom_skin_materials')->nullable();
        \$table->json('top_skin_thicknesses')->nullable();
        \$table->json('bottom_skin_thicknesses')->nullable();
        \$table->enum('default_top_surface', ['ribbed', 'plain'])->default('plain');
        \$table->enum('default_bottom_surface', ['ribbed', 'plain'])->default('plain');
        \$table->integer('min_production_length_mm')->default(2000);
        \$table->integer('delivery_days_standard')->default(14);
        \$table->integer('delivery_days_nonstandard')->default(21);
        \$table->integer('warranty_months')->default(12);
        \$table->string('product_image')->nullable();
        \$table->text('description')->nullable();
        \$table->boolean('is_active')->default(true);
        \$table->integer('sort_order')->default(0);
        \$table->timestamps();
        \$table->softDeletes();
        \$table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        \$table->unique(['company_id', 'code']);
    ",
];

$baseDir = __DIR__ . '/database/migrations';

// Create directory if doesn't exist
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
}

foreach ($tables as $tableName => $schema) {
    $timestamp = date('Y_m_d_His');
    $filename = $baseDir . '/' . $timestamp . '_create_' . $tableName . '_table.php';

    $migrationContent = <<<EOT
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            {$schema}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
EOT;

    file_put_contents($filename, $migrationContent);
    echo "Created: {$tableName}\n";
    usleep(100000); // 100ms delay to ensure unique timestamps
}

echo "\n✓ Migration files generated successfully!\n";
echo "Run: php artisan migrate\n";
