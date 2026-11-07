<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Customer tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lists_fifteen_customers_per_page_newest_first(): void
    {
        $customers = Customer::factory()->count(16)->sequence(fn ($sequence) => ['name' => 'Customer '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();

        $this->get(route('customers.index'))->assertOk()
            ->assertSee($customers->last()->name)->assertSee($customers->last()->email)->assertSee($customers->last()->phone)
            ->assertDontSee($customers->first()->name)
            ->assertViewHas('customers', fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('customers.index', ['page' => 2]))->assertOk()->assertSee($customers->first()->name);
    }

    public function test_displays_friendly_empty_message(): void
    {
        $this->get(route('customers.index'))->assertOk()->assertSee('No customers yet. Add your first customer to get started.');
    }

    public function test_displays_customer_details_and_registration_and_update_dates(): void
    {
        $customer = Customer::factory()->create(['name' => 'Aina Tan', 'address' => "12 Gift Street\nKuala Lumpur", 'created_at' => '2026-01-02 10:30:00', 'updated_at' => '2026-02-03 11:45:00']);

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Aina Tan')
            ->assertSee($customer->email)->assertSee($customer->phone)->assertSee($customer->address)
            ->assertSee('02 Jan 2026, 10:30')->assertSee('03 Feb 2026, 11:45');
        $this->get(route('customers.index'))->assertOk()->assertSee('02 Jan 2026');
    }

    public function test_create_and_edit_forms_preserve_values_and_include_csrf_and_methods(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.create'))->assertOk()->assertSee('Create customer')->assertSee('name="_token"', false);
        $this->get(route('customers.edit', $customer))->assertOk()->assertSee('Save changes')
            ->assertSee($customer->name)->assertSee($customer->email)->assertSee($customer->phone)->assertSee($customer->address)
            ->assertSee('value="PUT"', false)->assertSee('name="_token"', false);
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Confirm deletion')
            ->assertSee('value="DELETE"', false)->assertSee('name="_token"', false);
    }

    public function test_creates_customer_with_normalized_input_and_ignores_unexpected_fields(): void
    {
        $data = array_replace($this->customerData(), ['name' => ' Aina Tan ', 'email' => ' AINA@EXAMPLE.COM ', 'phone' => ' +60 12-345 6789 ', 'address' => ' 12 Gift Street ']);

        $response = $this->post(route('customers.store'), $data + ['id' => 999, 'deleted_at' => '2026-01-01']);

        $customer = Customer::sole();
        $response->assertRedirect(route('customers.show', $customer))->assertSessionHas('success', 'Customer created successfully.');
        $this->assertDatabaseHas('customers', ['name' => 'Aina Tan', 'email' => 'aina@example.com', 'phone' => '+60 12-345 6789', 'address' => '12 Gift Street', 'deleted_at' => null]);
        $this->assertNotSame(999, $customer->id);
    }

    public function test_creates_customer_without_address_and_preserves_leading_zero_phone(): void
    {
        $data = $this->customerData();
        unset($data['address']);
        $data['phone'] = '0123456789';

        $this->post(route('customers.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('customers', ['email' => 'aina@example.com', 'phone' => '0123456789', 'address' => null]);
    }

    public function test_shows_address_placeholder_when_address_is_missing(): void
    {
        $customer = Customer::factory()->create(['address' => null]);

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('No address provided.');
    }

    public function test_rejects_missing_required_fields_without_creating_customer(): void
    {
        $this->from(route('customers.create'))->post(route('customers.store'), [])
            ->assertRedirect(route('customers.create'))->assertSessionHasErrors([
                'name' => 'The name field is required.',
                'email' => 'The email field is required.',
                'phone' => 'The phone field is required.',
            ]);

        $this->assertDatabaseCount('customers', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_profile_fields(string $field, mixed $value): void
    {
        $this->post(route('customers.store'), array_replace($this->customerData(), [$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('customers', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'blank name' => ['name', '   '],
            'long name' => ['name', str_repeat('a', 256)],
            'non-string name' => ['name', ['invalid']],
            'invalid email' => ['email', 'invalid'],
            'blank email' => ['email', '   '],
            'long email' => ['email', str_repeat('a', 250).'@example.com'],
            'non-string email' => ['email', ['invalid']],
            'blank phone' => ['phone', '   '],
            'non-string phone' => ['phone', 1234567],
            'long phone' => ['phone', str_repeat('1', 31)],
            'phone with letters' => ['phone', 'call me'],
            'phone with no digits' => ['phone', '+()- .'],
            'long address' => ['address', str_repeat('a', 2001)],
            'non-string address' => ['address', ['invalid']],
        ];
    }

    #[DataProvider('deletionStates')]
    public function test_email_remains_unique_for_active_and_deleted_customers(bool $deleted): void
    {
        $existing = Customer::factory()->create(['email' => 'aina@example.com']);
        if ($deleted) {
            $existing->delete();
        }

        $this->post(route('customers.store'), array_replace($this->customerData(), ['email' => ' AINA@EXAMPLE.COM ']))
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);

        $this->assertDatabaseCount('customers', 1);
    }

    public static function deletionStates(): array
    {
        return ['active' => [false], 'soft deleted' => [true]];
    }

    public function test_updates_customer_with_unchanged_email(): void
    {
        $customer = Customer::factory()->create(['email' => 'aina@example.com']);

        $this->put(route('customers.update', $customer), $this->customerData())
            ->assertRedirect(route('customers.show', $customer))->assertSessionHas('success', 'Customer updated successfully.');

        $this->assertDatabaseHas('customers', ['id' => $customer->id] + $this->customerData());
    }

    public function test_changes_and_normalizes_email_and_clears_optional_address(): void
    {
        $customer = Customer::factory()->create();

        $this->patch(route('customers.update', $customer), array_replace($this->customerData(), ['email' => ' NEW@EXAMPLE.COM ', 'address' => '   ']))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'email' => 'new@example.com', 'address' => null]);
    }

    #[DataProvider('deletionStates')]
    public function test_update_cannot_take_another_customer_email_or_spoof_the_ignored_id(bool $deleted): void
    {
        $owner = Customer::factory()->create(['email' => 'reserved@example.com']);
        $customer = Customer::factory()->create(['email' => 'original@example.com']);
        if ($deleted) {
            $owner->delete();
        }

        $this->put(route('customers.update', $customer), array_replace($this->customerData(), ['email' => $owner->email, 'id' => $owner->id]))
            ->assertSessionHasErrors('email');

        $this->assertSame('original@example.com', $customer->fresh()->email);
    }

    public function test_invalid_update_preserves_existing_values(): void
    {
        $customer = Customer::factory()->create(['name' => 'Original']);

        $this->put(route('customers.update', $customer), array_replace($this->customerData(), ['phone' => 'invalid']))
            ->assertSessionHasErrors('phone');

        $this->assertSame('Original', $customer->fresh()->name);
    }

    public function test_soft_deletes_customer_while_retaining_profile_and_email(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hidden customer']);

        $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Customer deleted successfully.');

        $this->assertSoftDeleted($customer);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'email' => $customer->email, 'name' => 'Hidden customer']);
        $this->get(route('customers.index'))->assertOk()->assertDontSee('Hidden customer');
    }

    public function test_database_unique_constraint_includes_deleted_customer_email(): void
    {
        $customer = Customer::factory()->create(['email' => 'reserved@example.com']);
        $customer->delete();

        $this->expectException(UniqueConstraintViolationException::class);

        Customer::factory()->create(['email' => 'reserved@example.com']);
    }

    #[DataProvider('searchFields')]
    public function test_searches_each_contact_field_and_excludes_deleted_matches(string $field, string $value, string $search): void
    {
        $customer = Customer::factory()->create([$field => $value]);
        Customer::factory()->create(['name' => 'Unrelated person', 'email' => 'unrelated@example.net', 'phone' => '9999999']);
        Customer::factory()->create(['name' => 'Hidden person', $field => $field === 'email' ? 'prefix.'.$value : $value])->delete();

        $this->get(route('customers.index', ['search' => $search]))->assertOk()->assertSee($customer->email)
            ->assertDontSee('Unrelated person')->assertDontSee('Hidden person')
            ->assertViewHas('customers', fn ($page) => $page->total() === 1);
    }

    public static function searchFields(): array
    {
        return [
            'name' => ['name', 'Aina Tan', 'aina'],
            'email' => ['email', 'aina@example.com', 'aina@'],
            'phone' => ['phone', '+60 12-3456789', '3456789'],
        ];
    }

    public function test_preserves_search_filter_in_pagination(): void
    {
        Customer::factory()->count(16)->create(['name' => 'Matching customer']);
        Customer::factory()->create(['name' => 'Other customer']);

        $this->get(route('customers.index', ['search' => 'Matching']))->assertOk()
            ->assertSee('search=Matching', false)->assertViewHas('customers', fn ($page) => $page->total() === 16);
        $this->get(route('customers.index', ['search' => 'Matching', 'page' => 2]))->assertOk()
            ->assertDontSee('Other customer')->assertViewHas('customers', fn ($page) => $page->count() === 1);
    }

    public function test_displays_no_match_message_and_binds_search_values(): void
    {
        Customer::factory()->create();

        $this->get(route('customers.index', ['search' => "' OR 1=1 --"]))->assertOk()
            ->assertSee('No customers match your search.')->assertViewHas('customers', fn ($page) => $page->total() === 0);
    }

    public function test_rejects_invalid_search_input(): void
    {
        $this->from(route('customers.index'))->get(route('customers.index', ['search' => ['invalid']]))
            ->assertRedirect(route('customers.index'))->assertSessionHasErrors('search');
    }

    #[DataProvider('deletionStates')]
    public function test_returns_404_for_missing_or_deleted_customer_actions(bool $deleted): void
    {
        $id = 999;
        if ($deleted) {
            $customer = Customer::factory()->create();
            $customer->delete();
            $id = $customer->id;
        }

        $this->get('/customers/'.$id)->assertNotFound();
        $this->get('/customers/'.$id.'/edit')->assertNotFound();
        $this->put('/customers/'.$id, $this->customerData())->assertNotFound();
        $this->delete('/customers/'.$id)->assertNotFound();
    }

    public function test_escapes_customer_text_and_search_input(): void
    {
        $unsafe = '<script>alert(1)</script>';
        $customer = Customer::factory()->create(['name' => $unsafe, 'address' => $unsafe]);

        foreach ([route('customers.index'), route('customers.show', $customer), route('customers.edit', $customer), route('customers.index', ['search' => $unsafe])] as $url) {
            $this->get($url)->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        }
    }

    public function test_displays_field_errors_and_preserves_submitted_values(): void
    {
        $errors = ['default' => ['format' => ':message', 'messages' => ['email' => ['The email has already been taken.']]]];

        $this->withSession(['errors' => $errors, '_old_input' => ['name' => 'Submitted name']])
            ->get(route('customers.create'))->assertOk()->assertSee('Submitted name')
            ->assertSee('The email has already been taken.')->assertSee('id="email-error"', false);
    }

    public function test_shared_navigation_marks_customers_as_current(): void
    {
        $this->get(route('customers.index'))->assertOk()
            ->assertSee('Customers')->assertSee('Products')->assertSee('aria-current="page"', false);
    }

    #[DataProvider('writeFailures')]
    public function test_failed_write_reports_error_without_changing_customer(string $operation, string $event): void
    {
        $customer = Customer::factory()->create(['name' => 'Original']);
        Event::listen('eloquent.'.$event.': '.Customer::class, function (): void {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            $response = match ($operation) {
                'create' => $this->from(route('customers.create'))->post(route('customers.store'), $this->customerData()),
                'update' => $this->from(route('customers.edit', $customer))->put(route('customers.update', $customer), $this->customerData()),
                'delete' => $this->from(route('customers.show', $customer))->delete(route('customers.destroy', $customer)),
            };
            $response->assertSessionHas('error', 'Unable to '.$operation.' the customer. Please try again.');
        } finally {
            Event::forget('eloquent.'.$event.': '.Customer::class);
        }

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Original', 'deleted_at' => null]);
    }

    public static function writeFailures(): array
    {
        return ['create' => ['create', 'creating'], 'update' => ['update', 'updating'], 'delete' => ['delete', 'deleting']];
    }

    private function customerData(): array
    {
        return ['name' => 'Aina Tan', 'email' => 'aina@example.com', 'phone' => '+60 12-345 6789', 'address' => '12 Gift Street'];
    }
}
