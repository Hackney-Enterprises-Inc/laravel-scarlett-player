<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Testing\Fakes\BusFake;
use RuntimeException;

/**
 * A bus fake whose next N dispatches throw (a broker outage), and which records the
 * transaction depth at every dispatch so tests can prove dispatch runs after commit.
 */
class FlakyBus extends BusFake
{
    public int $failNext = 0;

    /** @var list<int> */
    public array $transactionLevels = [];

    public static function install(int $failNext = 0): self
    {
        $bus = new self(app(QueueingDispatcher::class));
        $bus->failNext = $failNext;

        Bus::swap($bus);

        return $bus;
    }

    public function dispatch($command)
    {
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->failNext > 0) {
            $this->failNext--;

            throw new RuntimeException('Broker unavailable.');
        }

        return parent::dispatch($command);
    }
}
