<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationVisibilityTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = config('database.default');
        config()->set('database.connections.notification_visibility_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('notification_visibility_test');
        config()->set('database.default', 'notification_visibility_test');

        $schema = Schema::connection('notification_visibility_test');
        $schema->create('orders', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->json('co_agents')->nullable();
        });
        $schema->create('properties', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->json('co_agents')->nullable();
        });
        $schema->create('notifications', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('source')->nullable();
            $table->string('source_id')->nullable();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('agent_uuid')->nullable();
            $table->json('vendor_uuids')->nullable();
            $table->string('role')->nullable();
            $table->json('diff_data')->nullable();
            $table->json('meta_data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('notification_visibility_test');
        config()->set('database.default', $this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_agent_only_sees_own_and_shared_order_or_property_notifications(): void
    {
        DB::table('properties')->insert([
            ['id' => 1, 'uuid' => 'shared-property', 'organization_id' => null, 'agent_id' => 10, 'co_agents' => json_encode(['co@example.com'])],
            ['id' => 2, 'uuid' => 'private-property', 'organization_id' => null, 'agent_id' => 10, 'co_agents' => json_encode([])],
            ['id' => 3, 'uuid' => 'same-org-private-property', 'organization_id' => 3, 'agent_id' => 10, 'co_agents' => json_encode([])],
        ]);
        DB::table('orders')->insert([
            [
                'id' => 1,
                'uuid' => 'shared-order',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 1,
                'co_agents' => json_encode([[
                    'agent_id' => 20,
                    'agent_uuid' => 'co-agent-uuid',
                    'email' => 'co@example.com',
                ]]),
            ],
            [
                'id' => 2,
                'uuid' => 'private-order',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 2,
                'co_agents' => json_encode([]),
            ],
        ]);

        $now = now();
        DB::table('notifications')->insert([
            ['id' => 1, 'uuid' => 'own-notification', 'organization_id' => null, 'source' => 'Order', 'source_id' => 'private-order', 'user_uuid' => null, 'agent_uuid' => 'co-agent-uuid', 'vendor_uuids' => null, 'role' => 'agent', 'diff_data' => null, 'meta_data' => null, 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'shared-order-notification', 'organization_id' => null, 'source' => 'Order', 'source_id' => 'shared-order', 'user_uuid' => null, 'agent_uuid' => 'primary-agent-uuid', 'vendor_uuids' => null, 'role' => 'agent', 'diff_data' => null, 'meta_data' => json_encode(['order_uuid' => 'shared-order']), 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'uuid' => 'private-order-notification', 'organization_id' => null, 'source' => 'Order', 'source_id' => 'private-order', 'user_uuid' => null, 'agent_uuid' => 'primary-agent-uuid', 'vendor_uuids' => null, 'role' => 'agent', 'diff_data' => null, 'meta_data' => json_encode(['order_uuid' => 'private-order']), 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'uuid' => 'shared-property-notification', 'organization_id' => null, 'source' => 'Property', 'source_id' => 'shared-property', 'user_uuid' => null, 'agent_uuid' => 'primary-agent-uuid', 'vendor_uuids' => null, 'role' => 'agent', 'diff_data' => null, 'meta_data' => json_encode(['property_uuid' => 'shared-property']), 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'uuid' => 'unassigned-agent-notification', 'organization_id' => null, 'source' => 'Order', 'source_id' => 'private-order', 'user_uuid' => null, 'agent_uuid' => null, 'vendor_uuids' => null, 'role' => 'agent', 'diff_data' => null, 'meta_data' => null, 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'uuid' => 'shared-admin-notification', 'organization_id' => null, 'source' => 'Order', 'source_id' => 'shared-order', 'user_uuid' => null, 'agent_uuid' => 'primary-agent-uuid', 'vendor_uuids' => null, 'role' => 'admin', 'diff_data' => null, 'meta_data' => null, 'is_read' => false, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $coAgent = new Agent();
        $coAgent->id = 20;
        $coAgent->uuid = 'co-agent-uuid';
        $coAgent->email = 'co@example.com';
        $coAgent->organization_id = 3;
        app()->instance('current_organization_id', 3);

        $notificationIds = Notification::visibleToAgent($coAgent)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 4], $notificationIds);
        $this->assertSame([1], \App\Models\Property::forAgent($coAgent)->pluck('id')->all());
    }
}
