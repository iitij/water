<?php

namespace Tests\Feature;

use App\Filament\Resources\SquareResource\Pages\CreateSquare;
use App\Filament\Resources\SquareResource\Pages\EditSquare;
use App\Models\Invoice;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SquareCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_codes_are_optional_and_never_generated_or_used_to_rename_squares(): void
    {
        $legacy = Square::create(['name' => 'اسم المالك القديم']);
        $this->getJson('/api/squares/'.$legacy->id)->assertOk()
            ->assertJsonPath('name', 'اسم المالك القديم')->assertJsonPath('code', null);

        foreach (['مربع بلا معرّف', 'مربع آخر'] as $name) {
            $this->postJson('/api/squares', ['name' => $name])->assertCreated()
                ->assertJsonPath('square.name', $name)->assertJsonPath('square.code', null);
        }
        $this->assertDatabaseCount('squares', 3);
        $this->assertSame(0, Square::whereNotNull('code')->count());
    }

    public function test_owner_can_create_and_edit_arbitrary_codes_preserving_case_and_name(): void
    {
        $response = $this->postJson('/api/squares', [
            'name' => 'اسم يختاره المالك', 'code' => '  aB 12 / الحارة  ',
        ])->assertCreated()->assertJsonPath('square.code', 'aB 12 / الحارة');
        $id = $response->json('square.id');
        $this->putJson('/api/squares/'.$id, [
            'name' => 'اسم يختاره المالك', 'code' => '  B7  ',
        ])->assertOk()->assertJsonPath('square.code', 'B7')
            ->assertJsonPath('square.name', 'اسم يختاره المالك');
        $this->assertDatabaseHas('squares', ['id' => $id, 'code' => 'B7']);
    }

    public function test_omitted_code_preserves_it_and_explicit_empty_code_clears_it(): void
    {
        $square = Square::create(['name' => 'اختبار', 'code' => 'a1']);
        $this->putJson('/api/squares/'.$square->id, ['name' => 'اسم جديد'])
            ->assertOk()->assertJsonPath('square.code', 'a1');
        $this->putJson('/api/squares/'.$square->id, ['name' => 'اسم جديد', 'code' => '   '])
            ->assertOk()->assertJsonPath('square.code', null);
        $this->putJson('/api/squares/'.$square->id, ['name' => 'اسم جديد', 'code' => 'b1'])->assertOk();
        $this->putJson('/api/squares/'.$square->id, ['name' => 'اسم جديد', 'code' => null])
            ->assertOk()->assertJsonPath('square.code', null);
    }

    public function test_duplicate_codes_are_rejected_but_unchanged_code_can_be_saved(): void
    {
        $first = Square::create(['name' => 'الأول', 'code' => 'a1']);
        $second = Square::create(['name' => 'الثاني', 'code' => 'b1']);
        $this->postJson('/api/squares', ['name' => 'ثالث', 'code' => ' a1 '])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/squares/'.$second->id, ['name' => 'الثاني', 'code' => 'a1'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/squares/'.$first->id, ['name' => 'الأول', 'code' => 'a1'])
            ->assertOk()->assertJsonPath('square.code', 'a1');
    }

    public function test_code_validation_has_a_bounded_string_length_without_example_based_restrictions(): void
    {
        foreach ([str_repeat('a', 101), ['a1']] as $invalid) {
            $this->postJson('/api/squares', ['name' => 'اختبار', 'code' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/squares', ['name' => 'اختبار', 'code' => str_repeat('a', 100)])
            ->assertCreated()->assertJsonPath('square.code', str_repeat('a', 100));
    }

    public function test_search_and_exact_filter_use_code_before_pagination_and_sorting(): void
    {
        $first = Square::create(['name' => 'اسم مختلف', 'code' => 'a1']);
        Square::create(['name' => 'اسم ثان', 'code' => 'a10']);
        Square::create(['name' => 'بدون معرّف']);
        $this->getJson('/api/squares?search=a1&sort_by=code&sort_direction=asc&per_page=1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.code', 'a1');
        $this->getJson('/api/squares?square_code=a1&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/squares?square_code=missing')->assertOk()->assertExactJson([]);
    }

    public function test_existing_permissions_still_guard_code_mutation(): void
    {
        $square = Square::create(['name' => 'محمي', 'code' => 'a1']);
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum');
        $this->postJson('/api/squares', ['name' => 'جديد', 'code' => 'b1'])->assertForbidden();
        $this->putJson('/api/squares/'.$square->id, ['name' => 'محمي', 'code' => 'b1'])->assertForbidden();
        $this->assertDatabaseHas('squares', ['id' => $square->id, 'code' => 'a1']);
    }

    public function test_square_code_filters_related_lists_and_is_available_to_global_search(): void
    {
        $matching = Square::create(['name' => 'اسم مستقل', 'code' => 'a1']);
        $other = Square::create(['name' => 'اسم آخر', 'code' => 'a10']);
        foreach ([$matching, $other] as $square) {
            $subscriber = $square->subscribers()->create(['name' => 'مشترك '.$square->id, 'status' => 'active']);
            $meter = $subscriber->meters()->create(['unique_code' => 'M-'.$square->id, 'path_number' => 7, 'status' => 'active']);
            Invoice::withoutEvents(fn () => Invoice::create([
                'subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
                'invoice_type' => 'manual', 'bill_number' => 'CODE-'.$square->id,
                'bill_date' => '2026-11-04', 'total_amount' => 50, 'status' => 'issued',
            ]));
        }
        foreach (['subscribers' => 'square', 'meters' => 'subscriber.square', 'invoices' => 'subscriber.square'] as $resource => $squarePath) {
            $this->getJson('/api/'.$resource.'?square_code=a1&per_page=1')
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.'.$squarePath.'.code', 'a1')
                ->assertJsonPath('data.0.'.$squarePath.'.name', 'اسم مستقل');
            $this->getJson('/api/'.$resource.'?square_code=missing')
                ->assertOk()->assertJsonPath('total', 0);
        }
        $response = $this->getJson('/api/search?q=a1')->assertOk();
        $squares = collect($response->json('groups'))->firstWhere('type', 'square');
        $this->assertNotNull($squares);
        $item = collect($squares['items'])->firstWhere('id', $matching->id);
        $this->assertNotNull($item);
        $this->assertStringContainsString('a1', $item['title']);
        $this->assertStringContainsString('اسم مستقل', $item['title']);
    }

    public function test_admin_form_validates_trimmed_unique_codes_and_allows_unchanged_edit(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['ViewAny:Square', 'Create:Square', 'Update:Square', 'View:Square'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $square = Square::create(['name' => 'الاسم الأصلي', 'code' => 'Ab 1']);
        Livewire::test(CreateSquare::class)->fillForm([
            'name' => 'اسم جديد', 'code' => '  Ab 1  ',
        ])->call('create')->assertHasFormErrors(['code' => 'unique']);
        $this->assertDatabaseCount('squares', 1);
        Livewire::test(CreateSquare::class)->fillForm([
            'name' => 'اسم يحدده المالك', 'code' => '  B2 / الحارة  ',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('squares', ['name' => 'اسم يحدده المالك', 'code' => 'B2 / الحارة']);
        Livewire::test(EditSquare::class, ['record' => $square->getRouteKey()])
            ->fillForm(['name' => 'الاسم الأصلي', 'code' => '  Ab 1  '])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Ab 1', $square->fresh()->code);
    }
}
