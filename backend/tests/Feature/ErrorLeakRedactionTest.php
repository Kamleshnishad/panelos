<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Support\SafeSort;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PB-043: error bodies must not carry SQL, hosts, file paths or internal class names, while the deliberate domain
 * messages the screens show ("Rates are pending") keep working. And a junk sort_by must not be a 500 in the first place.
 */
class ErrorLeakRedactionTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    private function route(string $path, callable $action): void
    {
        Route::middleware('api')->get($path, $action);
    }

    public function test_a_database_error_text_is_replaced_by_a_reference_and_the_original_is_logged(): void
    {
        $sql = "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nonexistent' in 'order clause' (Connection: mysql, Host: 172.30.0.1, Port: 3306, Database: panelos, SQL: select * from `quotations` order by `nonexistent` desc)";
        $this->route('/api/__leak', fn () => response()->json(['success' => false, 'message' => $sql], 400));
        Log::shouldReceive('error')->once()->withArgs(fn ($m, $ctx) => str_contains($m, 'withheld') && $ctx['original'] === [$sql]);

        $res = $this->getJson('/api/__leak')->assertStatus(500)->assertJsonPath('error_code', 'SERVER_ERROR');

        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('172.30.0.1', $res->getContent());
        $this->assertMatchesRegularExpression('/reference [0-9a-f]{8}/', $res->json('message'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $res->json('reference'));
    }

    public function test_leaks_inside_the_errors_array_are_redacted_but_a_clean_message_is_kept(): void
    {
        $this->route('/api/__leak2', fn () => response()->json([
            'success' => false, 'message' => 'Failed to create accessory', 'errors' => ['error' => 'Call to undefined method App\Models\Accessory::nope()'],
        ], 400));

        $res = $this->getJson('/api/__leak2')->assertStatus(400);

        $this->assertSame('Failed to create accessory', $res->json('message'));
        $this->assertStringNotContainsString('App\Models', $res->getContent());
        $this->assertStringContainsString('server log', $res->json('errors.error'));
    }

    public function test_deliberate_domain_messages_pass_through_untouched(): void
    {
        foreach (['Rates are pending for this quotation.', 'Insufficient stock: need 12 kg, have 3 kg.', 'Customer credit limit exceeded (₹5,00,000).'] as $msg) {
            $this->route('/api/__ok', fn () => response()->json(['success' => false, 'message' => $msg], 400));
            $res = $this->getJson('/api/__ok')->assertStatus(400);
            $this->assertSame($msg, $res->json('message'));
            $this->assertArrayNotHasKey('reference', $res->json());
        }
    }

    public function test_a_missing_record_does_not_name_the_internal_model(): void
    {
        $admin = $this->createAdminUser();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/quotations/999999')->assertStatus(404);

        $this->assertStringNotContainsString('App\Models', $res->getContent());
        $this->assertStringNotContainsString('No query results', $res->getContent());
    }

    public function test_validation_errors_are_never_rewritten(): void
    {
        $this->route('/api/__v', fn () => response()->json(['success' => false, 'message' => 'The file must be a .php file.', 'errors' => ['f' => ['The file must be a .php file.']]], 422));

        $this->getJson('/api/__v')->assertStatus(422)->assertJsonPath('message', 'The file must be a .php file.');
    }

    public function test_local_development_keeps_the_real_detail(): void
    {
        config(['app.debug' => true]);
        $this->route('/api/__dev', fn () => response()->json(['success' => false, 'message' => 'SQLSTATE[HY000]: boom'], 500));

        $this->getJson('/api/__dev')->assertStatus(500)->assertJsonPath('message', 'SQLSTATE[HY000]: boom');
    }

    public function test_a_junk_sort_by_on_every_list_screen_is_a_normal_list_not_a_500(): void
    {
        $admin = $this->createAdminUser();

        foreach (['quotations', 'orders', 'accessories', 'batches', 'quality-control'] as $path) {
            $this->actingAs($admin, 'sanctum')->getJson("/api/{$path}?sort_by=nonexistent&sort_order=sideways")
                ->assertOk();
            $this->actingAs($admin, 'sanctum')->getJson("/api/{$path}?sort_by=id%3B%20drop%20table%20users")
                ->assertOk();
        }
    }

    public function test_safe_sort_honours_real_columns_and_falls_back_for_anything_else(): void
    {
        $sql = fn (array $f) => SafeSort::apply(Quotation::query(), $f, 'created_at', 'desc')->toSql();

        $this->assertStringContainsString('order by `id` asc', $sql(['sort_by' => 'id', 'sort_order' => 'ASC']));
        $this->assertStringContainsString('order by `created_at` desc', $sql([]));
        $this->assertStringContainsString('order by `created_at` desc', $sql(['sort_by' => 'nonexistent']));
        $this->assertStringContainsString('order by `created_at` desc', $sql(['sort_by' => ['x'], 'sort_order' => 'sideways']));
        $this->assertStringContainsString('order by `status` desc', $sql(['sort_by' => 'status', 'sort_order' => 'sideways']));
    }
}
