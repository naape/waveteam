<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\NotificationTemplate;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_complete_and_idempotent(): void
    {
        $this->seed();
        $this->seed(); // second run must not duplicate anything or create a 2nd admin

        $this->assertSame(25, District::count());
        $this->assertSame(10, ServiceCategory::count());
        $this->assertSame(7, Role::count());
        $this->assertSame(1, User::staff()->count());
        $this->assertTrue(User::staff()->first()->hasRole('Super Admin'));
        $this->assertGreaterThanOrEqual(13, NotificationTemplate::count());
        $this->assertSame([30, 14, 7], Settings::get('pcc.reminder_days'));
    }
}
