<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\SupplierStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Supplier tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lists_fifteen_suppliers_newest_first(): void
    {
        $suppliers = Supplier::factory()->count(16)->sequence(fn ($sequence) => ['name' => 'Supplier '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();

        $this->get(route('suppliers.index'))->assertOk()->assertSee($suppliers->last()->name)
            ->assertSee($suppliers->last()->contact_person)->assertSee($suppliers->last()->email)->assertSee($suppliers->last()->phone)
            ->assertDontSee($suppliers->first()->name)->assertViewHas('suppliers', fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('suppliers.index', ['page' => 2]))->assertOk()->assertSee($suppliers->first()->name);
    }

    public function test_renders_empty_state_and_create_form_with_active_default(): void
    {
        $this->get(route('suppliers.index'))->assertOk()->assertSee('No suppliers yet.');
        $this->get(route('suppliers.create'))->assertOk()->assertSee('Create supplier')
            ->assertSee('value="active" selected', false)->assertSee('name="_token"', false);
    }

    public function test_creates_normalized_supplier_and_ignores_unexpected_fields(): void
    {
        $data = array_replace($this->supplierData(), ['name' => ' Gift Wholesale ', 'contact_person' => ' Aina Tan ', 'email' => ' SALES@EXAMPLE.COM ', 'phone' => ' 0123456789 ', 'address' => ' 12 Gift Street ']);

        $response = $this->post(route('suppliers.store'), $data + ['id' => 999, 'deleted_at' => '2026-01-01']);

        $supplier = Supplier::sole();
        $response->assertRedirect(route('suppliers.show', $supplier))->assertSessionHas('success', 'Supplier created successfully.');
        $this->assertDatabaseHas('suppliers', $this->supplierData() + ['deleted_at' => null]);
        $this->assertNotSame(999, $supplier->id);
        $this->assertSame(SupplierStatus::Active, $supplier->status);
    }

    public function test_optional_address_can_be_omitted(): void
    {
        $data = $this->supplierData();
        unset($data['address']);

        $this->post(route('suppliers.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNull(Supplier::sole()->address);
        $this->get(route('suppliers.show', Supplier::sole()))->assertOk()->assertSee('No address provided.');
    }

    public function test_displays_details_dates_and_existing_edit_values(): void
    {
        $supplier = Supplier::factory()->inactive()->create(['created_at' => '2026-01-02 10:30:00', 'updated_at' => '2026-02-03 11:45:00']);

        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee($supplier->name)->assertSee($supplier->contact_person)
            ->assertSee($supplier->email)->assertSee($supplier->phone)->assertSee($supplier->address)
            ->assertSee('Inactive')->assertSee('02 Jan 2026, 10:30')->assertSee('03 Feb 2026, 11:45')
            ->assertSee('Confirm deletion')->assertSee('value="DELETE"', false)->assertSee('name="_token"', false);
        $this->get(route('suppliers.edit', $supplier))->assertOk()->assertSee($supplier->name)->assertSee($supplier->contact_person)
            ->assertSee($supplier->email)->assertSee($supplier->phone)->assertSee($supplier->address)
            ->assertSee('value="inactive" selected', false)->assertSee('value="PUT"', false);
    }

    public function test_rejects_missing_required_fields_without_creating_supplier(): void
    {
        $this->from(route('suppliers.create'))->post(route('suppliers.store'), [])->assertRedirect(route('suppliers.create'))
            ->assertSessionHasErrors(['name', 'contact_person', 'email', 'phone', 'status']);

        $this->assertDatabaseCount('suppliers', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_fields(string $field, mixed $value): void
    {
        $this->post(route('suppliers.store'), array_replace($this->supplierData(), [$field => $value]))->assertSessionHasErrors($field);

        $this->assertDatabaseCount('suppliers', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'blank name' => ['name', '   '],
            'long name' => ['name', str_repeat('a', 256)],
            'name type' => ['name', ['invalid']],
            'blank contact' => ['contact_person', '   '],
            'long contact' => ['contact_person', str_repeat('a', 256)],
            'contact type' => ['contact_person', ['invalid']],
            'invalid email' => ['email', 'invalid'],
            'blank email' => ['email', '   '],
            'long email' => ['email', str_repeat('a', 250).'@example.com'],
            'email type' => ['email', ['invalid']],
            'blank phone' => ['phone', '   '],
            'phone type' => ['phone', 1234567],
            'long phone' => ['phone', str_repeat('1', 31)],
            'phone letters' => ['phone', 'call me'],
            'phone without digits' => ['phone', '+()- .'],
            'long address' => ['address', str_repeat('a', 2001)],
            'address type' => ['address', ['invalid']],
            'invalid status' => ['status', 'archived'],
            'blank status' => ['status', ''],
            'status type' => ['status', ['active']],
        ];
    }

    #[DataProvider('deletionStates')]
    public function test_email_uniqueness_includes_deleted_suppliers(bool $deleted): void
    {
        $existing = Supplier::factory()->create(['email' => 'sales@example.com']);
        if ($deleted) {
            $existing->delete();
        }

        $this->post(route('suppliers.store'), array_replace($this->supplierData(), ['email' => ' SALES@EXAMPLE.COM ']))
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);

        $this->assertDatabaseCount('suppliers', 1);
    }

    public static function deletionStates(): array
    {
        return ['existing' => [false], 'soft deleted' => [true]];
    }

    public function test_updates_profile_with_unchanged_email_and_inactive_status(): void
    {
        $supplier = Supplier::factory()->create(['email' => 'sales@example.com']);
        $data = array_replace($this->supplierData(), ['status' => 'inactive']);

        $this->put(route('suppliers.update', $supplier), $data)->assertRedirect(route('suppliers.show', $supplier))
            ->assertSessionHas('success', 'Supplier updated successfully.');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id] + $data);
        $this->assertSame(SupplierStatus::Inactive, $supplier->fresh()->status);
    }

    public function test_reactivates_supplier_normalizes_new_email_and_clears_address(): void
    {
        $supplier = Supplier::factory()->inactive()->create();

        $this->patch(route('suppliers.update', $supplier), array_replace($this->supplierData(), ['email' => ' NEW@EXAMPLE.COM ', 'address' => '   ']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'email' => 'new@example.com', 'address' => null, 'status' => 'active']);
    }

    #[DataProvider('deletionStates')]
    public function test_update_cannot_take_another_email_or_spoof_ignored_supplier(bool $deleted): void
    {
        $owner = Supplier::factory()->create(['email' => 'reserved@example.com']);
        $supplier = Supplier::factory()->create(['email' => 'original@example.com']);
        if ($deleted) {
            $owner->delete();
        }

        $this->put(route('suppliers.update', $supplier), array_replace($this->supplierData(), ['email' => $owner->email, 'id' => $owner->id]))->assertSessionHasErrors('email');

        $this->assertSame('original@example.com', $supplier->fresh()->email);
    }

    public function test_invalid_update_preserves_original_profile(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Original']);

        $this->put(route('suppliers.update', $supplier), array_replace($this->supplierData(), ['status' => 'invalid']))->assertSessionHasErrors('status');

        $this->assertSame('Original', $supplier->fresh()->name);
    }

    public function test_soft_deletes_supplier_without_removing_profile(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Hidden supplier']);

        $this->delete(route('suppliers.destroy', $supplier))->assertRedirect(route('suppliers.index'))->assertSessionHas('success', 'Supplier deleted successfully.');

        $this->assertSoftDeleted($supplier);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'email' => $supplier->email, 'name' => 'Hidden supplier']);
        $this->get(route('suppliers.index'))->assertOk()->assertDontSee('Hidden supplier');
    }

    public function test_database_unique_constraint_retains_deleted_email(): void
    {
        $supplier = Supplier::factory()->create(['email' => 'reserved@example.com']);
        $supplier->delete();

        $this->expectException(UniqueConstraintViolationException::class);

        Supplier::factory()->create(['email' => 'reserved@example.com']);
    }

    #[DataProvider('searchFields')]
    public function test_searches_each_contact_field_without_deleted_matches(string $field, string $value, string $search): void
    {
        $supplier = Supplier::factory()->create([$field => $value]);
        Supplier::factory()->create(['name' => 'Other supplier', 'contact_person' => 'Other contact', 'email' => 'unrelated@example.net', 'phone' => '9999999']);
        Supplier::factory()->create(['name' => 'Hidden supplier', $field => $field === 'email' ? 'prefix.'.$value : $value])->delete();

        $this->get(route('suppliers.index', ['search' => $search]))->assertOk()->assertSee($supplier->email)
            ->assertDontSee('Other supplier')->assertDontSee('Hidden supplier')->assertViewHas('suppliers', fn ($page) => $page->total() === 1);
    }

    public static function searchFields(): array
    {
        return ['name' => ['name', 'Gift Wholesale', 'wholesale'], 'contact' => ['contact_person', 'Aina Tan', 'aina'], 'email' => ['email', 'sales@example.com', 'sales@'], 'phone' => ['phone', '+60 12-3456789', '3456789']];
    }

    public function test_active_and_inactive_suppliers_remain_visible_and_searchable(): void
    {
        $active = Supplier::factory()->create(['name' => 'Active supplier']);
        $inactive = Supplier::factory()->inactive()->create(['name' => 'Inactive supplier']);

        $this->get(route('suppliers.index'))->assertOk()->assertSee($active->name)->assertSee($inactive->name)->assertSee('Active')->assertSee('Inactive');
        $this->get(route('suppliers.index', ['search' => 'Inactive supplier']))->assertOk()->assertSee($inactive->email);
    }

    public function test_preserves_search_during_pagination(): void
    {
        Supplier::factory()->count(16)->create(['name' => 'Matching supplier']);
        Supplier::factory()->create(['name' => 'Other supplier']);

        $this->get(route('suppliers.index', ['search' => 'Matching']))->assertOk()->assertSee('search=Matching', false)->assertViewHas('suppliers', fn ($page) => $page->total() === 16);
        $this->get(route('suppliers.index', ['search' => 'Matching', 'page' => 2]))->assertOk()->assertDontSee('Other supplier')->assertViewHas('suppliers', fn ($page) => $page->count() === 1);
    }

    public function test_search_binds_input_and_shows_no_match_message(): void
    {
        Supplier::factory()->create();

        $this->get(route('suppliers.index', ['search' => "' OR 1=1 --"]))->assertOk()->assertSee('No suppliers match your search.')->assertViewHas('suppliers', fn ($page) => $page->total() === 0);
    }

    public function test_rejects_invalid_search_input(): void
    {
        $this->from(route('suppliers.index'))->get(route('suppliers.index', ['search' => ['invalid']]))->assertRedirect(route('suppliers.index'))->assertSessionHasErrors('search');
    }

    #[DataProvider('deletionStates')]
    public function test_returns_404_for_missing_or_deleted_supplier_actions(bool $deleted): void
    {
        $id = 999;
        if ($deleted) {
            $supplier = Supplier::factory()->create();
            $supplier->delete();
            $id = $supplier->id;
        }

        $this->get('/suppliers/'.$id)->assertNotFound();
        $this->get('/suppliers/'.$id.'/edit')->assertNotFound();
        $this->put('/suppliers/'.$id, $this->supplierData())->assertNotFound();
        $this->delete('/suppliers/'.$id)->assertNotFound();
    }

    public function test_escapes_supplier_fields_and_search_input(): void
    {
        $unsafe = '<script>alert(1)</script>';
        $supplier = Supplier::factory()->create(['name' => $unsafe, 'contact_person' => $unsafe, 'address' => $unsafe]);

        foreach ([route('suppliers.index'), route('suppliers.show', $supplier), route('suppliers.edit', $supplier), route('suppliers.index', ['search' => $unsafe])] as $url) {
            $this->get($url)->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        }
    }

    public function test_shows_validation_feedback_and_preserves_old_values(): void
    {
        $errors = ['default' => ['format' => ':message', 'messages' => ['email' => ['The email has already been taken.']]]];

        $this->withSession(['errors' => $errors, '_old_input' => ['name' => 'Submitted name', 'status' => 'inactive']])->get(route('suppliers.create'))
            ->assertOk()->assertSee('Submitted name')->assertSee('value="inactive" selected', false)
            ->assertSee('The email has already been taken.')->assertSee('id="email-error"', false);
    }

    public function test_shared_navigation_and_flash_feedback_are_displayed(): void
    {
        $this->withSession(['success' => 'Supplier saved.', 'error' => 'Please try again.'])->get(route('suppliers.index'))
            ->assertOk()->assertSee('Suppliers')->assertSee('Products')->assertSee('Customers')->assertSee('Orders')
            ->assertSee('aria-current="page"', false)->assertSee('Supplier saved.')->assertSee('Please try again.');
    }

    #[DataProvider('writeFailures')]
    public function test_failed_write_preserves_supplier_and_reports_error(string $operation, string $event): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Original']);
        Event::listen('eloquent.'.$event.': '.Supplier::class, function (): void {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            $response = match ($operation) {
                'create' => $this->from(route('suppliers.create'))->post(route('suppliers.store'), $this->supplierData()),
                'update' => $this->from(route('suppliers.edit', $supplier))->put(route('suppliers.update', $supplier), $this->supplierData()),
                'delete' => $this->from(route('suppliers.show', $supplier))->delete(route('suppliers.destroy', $supplier)),
            };
            $response->assertSessionHas('error', 'Unable to '.$operation.' the supplier. Please try again.');
        } finally {
            Event::forget('eloquent.'.$event.': '.Supplier::class);
        }

        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Original', 'deleted_at' => null]);
    }

    public static function writeFailures(): array
    {
        return ['create' => ['create', 'creating'], 'update' => ['update', 'updating'], 'delete' => ['delete', 'deleting']];
    }

    private function supplierData(): array
    {
        return ['name' => 'Gift Wholesale', 'contact_person' => 'Aina Tan', 'email' => 'sales@example.com', 'phone' => '0123456789', 'address' => '12 Gift Street', 'status' => 'active'];
    }
}
