<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Order;
use App\Models\Role;
use App\Models\SubAccount;
use App\Models\Tour;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TourVisibilityTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = config('database.default');
        config()->set('database.connections.tour_visibility_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('tour_visibility_test');
        config()->set('database.default', 'tour_visibility_test');

        $schema = Schema::connection('tour_visibility_test');
        $schema->create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('permissions', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('sub_accounts', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('primary_email')->nullable();
            $table->string('secondary_email')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $schema->create('properties', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->json('co_agents')->nullable();
        });
        $schema->create('orders', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->json('co_agents')->nullable();
        });
        $schema->create('tours', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('order_id');
            $table->json('slide_show')->nullable();
            $table->boolean('is_publish')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('roles')->insert([
            ['id' => 4, 'name' => 'Co-Agent', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => 'Assistant', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('properties')->insert([
            [
                'id' => 1,
                'uuid' => 'prop-shared-order',
                'organization_id' => 3,
                'agent_id' => 10,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 2,
                'uuid' => 'prop-primary-private-1',
                'organization_id' => 3,
                'agent_id' => 10,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 3,
                'uuid' => 'prop-primary-private-2',
                'organization_id' => 3,
                'agent_id' => 10,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 4,
                'uuid' => 'prop-shared-property',
                'organization_id' => 3,
                'agent_id' => 10,
                'co_agents' => json_encode(['co@example.com', 'subco@example.com']),
            ],
            [
                'id' => 5,
                'uuid' => 'prop-other-agent',
                'organization_id' => 3,
                'agent_id' => 30,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 6,
                'uuid' => 'prop-shared-with-primary',
                'organization_id' => 3,
                'agent_id' => 30,
                'co_agents' => json_encode([]),
            ],
        ]);

        DB::table('orders')->insert([
            [
                'id' => 1,
                'uuid' => 'order-shared-coagent',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 1,
                'co_agents' => json_encode([
                    ['agent_id' => 20, 'agent_uuid' => 'co-agent-uuid', 'email' => 'co@example.com'],
                    ['agent_uuid' => 'sub-co-uuid', 'email' => 'subco@example.com'],
                ]),
            ],
            [
                'id' => 2,
                'uuid' => 'order-primary-private-1',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 2,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 3,
                'uuid' => 'order-primary-private-2',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 3,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 4,
                'uuid' => 'order-shared-via-property',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 4,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 5,
                'uuid' => 'order-other-agent',
                'organization_id' => 3,
                'agent_id' => 30,
                'property_id' => 5,
                'co_agents' => json_encode([]),
            ],
            [
                'id' => 6,
                'uuid' => 'order-shared-with-primary',
                'organization_id' => 3,
                'agent_id' => 30,
                'property_id' => 6,
                'co_agents' => json_encode([
                    ['agent_id' => 10, 'agent_uuid' => 'primary-agent-uuid', 'email' => 'primary@example.com'],
                ]),
            ],
            [
                'id' => 7,
                'uuid' => 'order-shared-unpublished',
                'organization_id' => 3,
                'agent_id' => 10,
                'property_id' => 1,
                'co_agents' => json_encode([
                    ['agent_id' => 20, 'agent_uuid' => 'co-agent-uuid', 'email' => 'co@example.com'],
                ]),
            ],
        ]);

        DB::table('tours')->insert([
            ['id' => 1, 'uuid' => 'tour-1', 'order_id' => 1, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'tour-2', 'order_id' => 2, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'uuid' => 'tour-3', 'order_id' => 3, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'uuid' => 'tour-4', 'order_id' => 4, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'uuid' => 'tour-5', 'order_id' => 5, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'uuid' => 'tour-6', 'order_id' => 6, 'is_publish' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'uuid' => 'tour-7', 'order_id' => 7, 'is_publish' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        app()->instance('current_organization_id', 3);
    }

    protected function tearDown(): void
    {
        DB::purge('tour_visibility_test');
        config()->set('database.default', $this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_agent_co_agent_only_sees_tours_for_assigned_listings(): void
    {
        $coAgent = new Agent();
        $coAgent->id = 20;
        $coAgent->uuid = 'co-agent-uuid';
        $coAgent->email = 'co@example.com';
        $coAgent->organization_id = 3;
        $coAgent->agent_type = 'co_agent';
        $coAgent->parent_agent_id = 10;
        $coAgent->linked_agent_uuids = ['primary-agent-uuid'];

        $visiblePublishedIds = Tour::visibleToUser($coAgent)
            ->where('is_publish', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 4], $visiblePublishedIds);
    }

    public function test_primary_agent_sees_own_and_co_listed_tours_only(): void
    {
        $primaryAgent = new Agent();
        $primaryAgent->id = 10;
        $primaryAgent->uuid = 'primary-agent-uuid';
        $primaryAgent->email = 'primary@example.com';
        $primaryAgent->organization_id = 3;

        $visiblePublishedIds = Tour::visibleToUser($primaryAgent)
            ->where('is_publish', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 3, 4, 6], $visiblePublishedIds);
    }

    public function test_restricted_co_agent_sub_account_only_sees_assigned_listings(): void
    {
        $subCoAgent = new SubAccount();
        $subCoAgent->id = 101;
        $subCoAgent->uuid = 'sub-co-uuid';
        $subCoAgent->organization_id = 3;
        $subCoAgent->agent_id = 10;
        $subCoAgent->role_id = 4;
        $subCoAgent->primary_email = 'subco@example.com';
        $subCoAgent->permissions = [];
        $subCoAgent->setRelation('role', Role::find(4));

        $this->assertFalse($subCoAgent->canViewAllAgentOrders());

        $visiblePublishedIds = Tour::visibleToUser($subCoAgent)
            ->where('is_publish', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 4], $visiblePublishedIds);
    }

    public function test_assistant_sub_account_sees_parent_agent_tours_only(): void
    {
        $assistant = new SubAccount();
        $assistant->id = 102;
        $assistant->uuid = 'assistant-uuid';
        $assistant->organization_id = 3;
        $assistant->agent_id = 10;
        $assistant->role_id = 6;
        $assistant->primary_email = 'assistant@example.com';
        $assistant->permissions = [];
        $assistant->setRelation('role', Role::find(6));

        $this->assertTrue($assistant->canViewAllAgentOrders());

        $visiblePublishedIds = Tour::visibleToUser($assistant)
            ->where('is_publish', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 3, 4], $visiblePublishedIds);
    }

    public function test_restricted_sub_account_with_empty_email_and_uuid_sees_no_tours(): void
    {
        $emptySub = new SubAccount();
        $emptySub->id = 103;
        $emptySub->uuid = '';
        $emptySub->organization_id = 3;
        $emptySub->agent_id = 10;
        $emptySub->role_id = 4;
        $emptySub->primary_email = '';
        $emptySub->permissions = [];
        $emptySub->setRelation('role', Role::find(4));

        $visibleIds = Tour::visibleToUser($emptySub)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([], $visibleIds);
    }

    public function test_published_only_filter_excludes_unpublished_tours_while_preserving_visibility(): void
    {
        $coAgent = new Agent();
        $coAgent->id = 20;
        $coAgent->uuid = 'co-agent-uuid';
        $coAgent->email = 'co@example.com';
        $coAgent->organization_id = 3;
        $coAgent->agent_type = 'co_agent';

        $allVisibleIds = Tour::visibleToUser($coAgent)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $publishedVisibleIds = Tour::visibleToUser($coAgent)
            ->where('is_publish', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 4, 7], $allVisibleIds);
        $this->assertSame([1, 4], $publishedVisibleIds);
    }
}
