<?php

namespace App\Domain\PaperTrading;

use App\Models\TradeSetup;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SetupLifecycle
{
    private const TRANSITIONS = [
        'WATCHING' => ['ARMED', 'BLOCKED', 'EXPIRED'],
        'ARMED' => ['WATCHING', 'VALID', 'BLOCKED', 'EXPIRED'],
        'VALID' => ['ARMED', 'BLOCKED', 'EXPIRED', 'OPEN'],
        'BLOCKED' => ['WATCHING', 'ARMED', 'EXPIRED'],
        'EXPIRED' => [],
        'OPEN' => ['CLOSED'],
        'CLOSED' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function transition(TradeSetup $setup, string $to, array $reasons = [], ?string $requestId = null): TradeSetup
    {
        $to = strtoupper($to);

        return DB::transaction(function () use ($setup, $to, $reasons, $requestId): TradeSetup {
            /** @var TradeSetup $locked */
            $locked = TradeSetup::query()->lockForUpdate()->findOrFail($setup->getKey());
            $from = $locked->state;

            if ($from === $to) {
                return $locked;
            }
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("Invalid setup transition from {$from} to {$to}.");
            }
            if ($to === 'VALID') {
                $this->assertExecutableShape($locked);
            }

            $before = $locked->toArray();
            $locked->forceFill([
                'state' => $to,
                'reasons' => $reasons,
                'version' => $locked->version + 1,
                'state_changed_at' => now(),
            ])->save();
            $this->audit->record('setup.transitioned', $locked, $before, $locked->fresh()->toArray(), $requestId);

            return $locked->fresh();
        });
    }

    private function assertExecutableShape(TradeSetup $setup): void
    {
        if ($setup->entry_low === null || $setup->entry_high === null || $setup->stop_price === null || empty($setup->targets)) {
            throw new DomainException('A VALID setup requires an entry range, stop and at least one target.');
        }
        if ($setup->expires_at?->isPast()) {
            throw new DomainException('An expired setup cannot become VALID.');
        }
        if ((float) $setup->entry_low > (float) $setup->entry_high) {
            throw new DomainException('The entry range is inverted.');
        }
        if (
            ($setup->direction === 'BUY' && (float) $setup->stop_price >= (float) $setup->entry_low)
            || ($setup->direction === 'SELL' && (float) $setup->stop_price <= (float) $setup->entry_high)
        ) {
            throw new DomainException('The stop must be beyond the adverse side of the entry range.');
        }
    }
}
