<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Node;
use App\Models\ResourceAllocation;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Nodes\PortAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private PortAllocator $ports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ports = app(PortAllocator::class);
    }

    private function makeServer(Node $node): Server
    {
        return Server::factory()
            ->ownedBy(
                User::factory()->create(),
                Game::factory()->create(),
                $node,
                Tariff::factory()->create(),
            )
            ->create();
    }

    public function test_allocate_assigns_three_ports_from_distinct_ranges(): void
    {
        $node = Node::factory()->create();
        $server = $this->makeServer($node);

        $gamePort = $this->ports->allocate($server);

        $server->refresh();

        $this->assertSame(25000, $gamePort);
        $this->assertSame(25000, $server->game_port);
        $this->assertSame(26000, $server->query_port);
        $this->assertSame(27000, $server->rcon_port);
    }

    public function test_allocate_sets_human_address_from_flagship(): void
    {
        $node = Node::factory()->create(['flagship' => 'play.example.com']);
        $server = $this->makeServer($node);

        $this->ports->allocate($server);
        $server->refresh();

        $this->assertSame('play.example.com:25000', $server->address);
        $this->assertSame('play.example.com:26000', $server->query_address);
    }

    public function test_address_falls_back_to_host_when_no_flagship(): void
    {
        $node = Node::factory()->create(['flagship' => null, 'host' => '10.0.0.5']);
        $server = $this->makeServer($node);

        $this->ports->allocate($server);
        $server->refresh();

        $this->assertSame('10.0.0.5:25000', $server->address);
    }

    public function test_second_server_gets_the_next_free_ports(): void
    {
        $node = Node::factory()->create();

        $first = $this->makeServer($node);
        $second = $this->makeServer($node);

        $this->ports->allocate($first);
        $this->ports->allocate($second);

        $second->refresh();

        $this->assertSame(25001, (int) $second->game_port);
        $this->assertSame(26001, (int) $second->query_port);
        $this->assertSame(27001, (int) $second->rcon_port);
    }

    public function test_port_is_never_handed_out_twice_on_the_same_node(): void
    {
        $node = Node::factory()->create();

        $servers = collect(range(1, 5))->map(fn () => $this->makeServer($node));
        foreach ($servers as $server) {
            $this->ports->allocate($server);
        }

        $gamePorts = ResourceAllocation::where('node_id', $node->id)
            ->where('kind', PortAllocator::KIND_GAME)
            ->pluck('port')
            ->map(fn ($p) => (int) $p)
            ->all();

        $this->assertSame([25000, 25001, 25002, 25003, 25004], $gamePorts);
        $this->assertCount(5, array_unique($gamePorts));
    }

    public function test_allocations_are_unique_per_node_and_port(): void
    {
        $nodeA = Node::factory()->create();
        $nodeB = Node::factory()->create();

        $this->ports->allocate($this->makeServer($nodeA));
        $this->ports->allocate($this->makeServer($nodeB));

        // Одна и та же цифра порта на разных нодах — это нормально
        $this->assertSame(1, ResourceAllocation::where('node_id', $nodeA->id)->where('port', 25000)->count());
        $this->assertSame(1, ResourceAllocation::where('node_id', $nodeB->id)->where('port', 25000)->count());
    }

    public function test_released_port_is_reused(): void
    {
        $node = Node::factory()->create();

        $first = $this->makeServer($node);
        $second = $this->makeServer($node);

        $this->ports->allocate($first);
        $this->ports->allocate($second);

        $this->ports->releaseAll($first);

        $third = $this->makeServer($node);
        $this->ports->allocate($third);
        $third->refresh();

        $this->assertSame(25000, (int) $third->game_port);
    }

    public function test_preferred_port_can_be_purchased_in_a_known_band(): void
    {
        $node = Node::factory()->create();
        $server = $this->makeServer($node);

        $port = $this->ports->allocate($server, PortAllocator::KIND_GAME, 25565);

        $this->assertSame(25565, $port);
        $this->assertSame(500.0, $this->ports->purchasePrice(25565));
    }

    public function test_preferred_port_outside_allowed_bands_is_refused(): void
    {
        $node = Node::factory()->create();
        $server = $this->makeServer($node);

        // 9999 не входит ни в один покупаемый диапазон и не входит в авто-диапазон
        $this->expectException(\RuntimeException::class);

        $this->ports->allocate($server, PortAllocator::KIND_GAME, 9999);
    }

    public function test_preferred_port_inside_auto_range_is_allowed(): void
    {
        $node = Node::factory()->create();
        $server = $this->makeServer($node);

        $this->assertSame(25999, $this->ports->allocate($server, PortAllocator::KIND_GAME, 25999));
    }

    public function test_preferred_port_already_taken_is_refused(): void
    {
        $node = Node::factory()->create();

        $this->ports->allocate($this->makeServer($node), PortAllocator::KIND_GAME, 25565);

        $second = $this->makeServer($node);

        $this->expectException(\RuntimeException::class);
        $this->ports->allocate($second, PortAllocator::KIND_GAME, 25565);
    }

    public function test_purchase_price_is_null_for_unlisted_port(): void
    {
        $this->assertNull($this->ports->purchasePrice(25000));
        $this->assertNull($this->ports->purchasePrice(12345));
    }

    public function test_is_available_respects_the_ignore_argument(): void
    {
        $node = Node::factory()->create();
        $server = $this->makeServer($node);

        $this->ports->allocate($server);

        $this->assertFalse($this->ports->isAvailable($node, 25000));
        $this->assertTrue($this->ports->isAvailable($node, 25000, $server->id));
        $this->assertTrue($this->ports->isAvailable($node, 25999));
    }

    public function test_allocate_fails_when_pool_is_exhausted(): void
    {
        config()->set('hosting.ports.range.game', [25000, 25000]);

        $node = Node::factory()->create();
        $this->ports->allocate($this->makeServer($node));

        $this->expectException(\RuntimeException::class);
        $this->ports->allocate($this->makeServer($node));
    }

    public function test_allocate_requires_a_node(): void
    {
        $server = Server::factory()
            ->ownedBy(User::factory()->create(), Game::factory()->create())
            ->create();

        $this->assertNull($server->node);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/нода/u');

        $this->ports->allocate($server);
    }

    public function test_move_port_reallocates_on_the_new_node(): void
    {
        $nodeA = Node::factory()->create(['flagship' => 'a.example.com']);
        $nodeB = Node::factory()->create(['flagship' => 'b.example.com']);

        $server = $this->makeServer($nodeA);
        $this->ports->allocate($server);

        $this->ports->movePort($server, $nodeB);
        $server->refresh();

        $this->assertSame($nodeB->id, $server->node_id);
        $this->assertSame(25000, (int) $server->game_port);
        $this->assertSame('b.example.com:25000', $server->address);

        // На старой ноде порт освобождён
        $this->assertSame(0, ResourceAllocation::where('node_id', $nodeA->id)->count());
    }

    public function test_purchasable_ranges_expose_free_slot_count(): void
    {
        $ranges = $this->ports->purchasableRanges();

        $this->assertCount(3, $ranges);
        $this->assertSame('3000–3049', $ranges[0]['range']);
        $this->assertSame(50, $ranges[0]['free']);
    }

    public function test_purchasable_free_count_drops_after_purchase(): void
    {
        $node = Node::factory()->create();
        $this->ports->allocate($this->makeServer($node), PortAllocator::KIND_GAME, 25565);

        $ranges = $this->ports->purchasableRanges();
        $high = collect($ranges)->firstWhere('range', '25565–25575');

        $this->assertSame(10, $high['free']);
    }

    public function test_seed_pool_creates_reserved_placeholder_rows(): void
    {
        $node = Node::factory()->create();

        $created = $this->ports->seedPool($node, 5);

        $this->assertSame(15, $created); // 5 игровых + 5 query + 5 rcon
        $this->assertSame(15, ResourceAllocation::where('node_id', $node->id)->where('is_reserved', true)->count());

        // Повторный запуск не дублирует
        $this->assertSame(0, $this->ports->seedPool($node, 5));
    }

    public function test_node_pool_usage_splits_reserved_and_used(): void
    {
        $node = Node::factory()->create();
        // 4 резерва в каждом из трёх диапазонов = 12 строк
        $this->ports->seedPool($node, 4);
        // allocate() берёт первые дыры за пределами резервов и создаёт 3 новые строки
        $this->ports->allocate($this->makeServer($node));

        $usage = $this->ports->nodePoolUsage($node);

        $this->assertSame(15, $usage['total']);
        $this->assertSame(3, $usage['used']);
        $this->assertSame(12, $usage['free']);
        $this->assertSame(5, $usage['by_kind'][PortAllocator::KIND_GAME]);
    }

    public function test_compose_address_handles_missing_port_and_missing_host(): void
    {
        $node = Node::factory()->create(['flagship' => null, 'host' => null]);

        $this->assertNull($this->ports->composeAddress($node, null));
        $this->assertSame('25000', $this->ports->composeAddress($node, 25000));
    }
}
