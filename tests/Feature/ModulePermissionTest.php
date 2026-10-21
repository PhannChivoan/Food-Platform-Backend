<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class ModulePermissionTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_a_page_that_is_switched_off_blocks_its_api(): void
    {
        $user = $this->makeUser('kitchen_staff');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/zones')->assertForbidden()->assertJson(['error' => 'module_disabled']);
    }

    public function test_view_create_update_and_delete_are_checked_separately(): void
    {
        $user = $this->makeUser('restaurant_owner');
        $this->grantPage($user, 'promotions', ['view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/promotions')->assertOk();
        $this->postJson('/api/v1/promotions', [])->assertForbidden()->assertJson(['error' => 'permission_denied', 'action' => 'create']);
        $this->putJson('/api/v1/promotions/1', [])->assertForbidden()->assertJson(['action' => 'update']);
        $this->deleteJson('/api/v1/promotions/1')->assertForbidden()->assertJson(['action' => 'delete']);

        Role::findByName('restaurant_owner', 'web')->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'promotions.create', 'guard_name' => 'web']));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user->fresh()); // a new request loads the role's permissions again
        $this->postJson('/api/v1/promotions', [])->assertStatus(422); // allowed through, then fails validation
    }

    public function test_without_view_the_page_data_is_refused(): void
    {
        $user = $this->makeUser('restaurant_owner');
        $this->grantPage($user, 'promotions', ['create']); // page on, View off
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/promotions')->assertForbidden()->assertJson(['action' => 'view']);
    }

    public function test_shared_lookups_follow_the_pages_that_use_them(): void
    {
        $this->makeUser('customer'); // the customers list needs this role to exist
        $user = $this->makeUser('kitchen_staff');
        $this->grantPage($user, 'pending-orders', ['view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/customers')->assertOk(); // the kitchen reads customers as a lookup
        $this->postJson('/api/v1/customers', [])->assertForbidden(); // but may not create them
    }

    public function test_super_admin_is_never_restricted(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->getJson('/api/v1/zones')->assertOk();
    }

    public function test_changing_permissions_is_written_to_the_audit_log(): void
    {
        $admin = $this->makeUser('super_admin');
        $role = Role::firstOrCreate(['name' => 'kitchen_staff', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'orders.delete', 'guard_name' => 'web']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/roles/{$role->id}/permissions/sync", ['permissions' => ['orders.delete']])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['event' => 'permissions_changed', 'user_id' => $admin->id]);
    }
}
