<?php

namespace App\Http\Controllers;

use App\Domain\PaperTrading\PaperTradingService;
use App\Domain\MarketData\MarketDataProvider;
use App\Models\PaperOrder;
use App\Models\Position;
use App\Models\Trade;
use App\Models\TradeSetup;
use App\Models\TradingAccount;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PaperTradingController
{
    public function create(Request $request, PaperTradingService $service): JsonResponse
    {
        $data = $request->validate([
            'trading_account_id' => ['required', 'integer', 'exists:trading_accounts,id'],
            'trade_setup_id' => ['required', 'integer', 'exists:trade_setups,id'],
            'order_type' => ['required', 'in:MARKET,LIMIT,STOP'],
            'volume' => ['required', 'numeric', 'gt:0'],
            'requested_price' => ['nullable', 'numeric', 'gt:0'],
        ]);
        if ($data['order_type'] !== 'MARKET' && empty($data['requested_price'])) {
            throw ValidationException::withMessages(['requested_price' => 'A requested price is required for LIMIT and STOP orders.']);
        }
        $account = TradingAccount::query()->findOrFail($data['trading_account_id']);
        $this->authorizeAccount($request, $account);

        return $this->domain(fn () => response()->json(
            $service->createOrder(
                $account, TradeSetup::query()->findOrFail($data['trade_setup_id']),
                $data, $this->idempotencyKey($request), $request->user()?->getAuthIdentifier(),
            ),
            201,
        ));
    }

    public function confirm(
        Request $request,
        PaperOrder $paperOrder,
        PaperTradingService $service,
        MarketDataProvider $market,
    ): JsonResponse
    {
        $request->validate([]);
        $this->authorizeAccount($request, $paperOrder->account);
        $quote = $market->quote($paperOrder->setup->analysisRun->symbol);

        return $this->domain(fn () => response()->json(
            $service->confirm($paperOrder, $quote, $this->idempotencyKey($request), $request->user()?->getAuthIdentifier()),
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trading_account_id' => ['required', 'integer', 'exists:trading_accounts,id'],
            'state' => ['sometimes', 'in:PENDING,FILLED,CANCELLED,REJECTED'],
        ]);
        $account = TradingAccount::query()->findOrFail($data['trading_account_id']);
        $this->authorizeAccount($request, $account);
        $orders = $account->paperOrders()->with(['setup', 'position'])->when(
            $data['state'] ?? null,
            fn ($query, $state) => $query->where('state', $state),
        )->latest()->paginate(min((int) $request->query('per_page', 25), 100));
        return response()->json($orders);
    }

    public function cancel(Request $request, PaperOrder $paperOrder, PaperTradingService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->authorizeAccount($request, $paperOrder->account);
        return $this->domain(fn () => response()->json(
            $service->cancel($paperOrder, $data['reason'], $this->idempotencyKey($request), $request->user()?->getAuthIdentifier()),
        ));
    }

    public function close(
        Request $request,
        Position $position,
        PaperTradingService $service,
        MarketDataProvider $market,
    ): JsonResponse
    {
        $data = $request->validate([
            'exit_reason' => ['required', 'in:STOP_LOSS,TARGET,MANUAL,SETUP_INVALIDATED,SESSION_END'],
        ]);
        $this->authorizeAccount($request, $position->account);
        $data = [...$data, ...$market->quote($position->symbol)];
        return $this->domain(fn () => response()->json(
            $service->close($position, $data, $this->idempotencyKey($request), $request->user()?->getAuthIdentifier()),
        ));
    }

    public function positions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trading_account_id' => ['required', 'integer', 'exists:trading_accounts,id'],
            'state' => ['sometimes', 'in:OPEN,CLOSED'],
        ]);
        $account = TradingAccount::query()->findOrFail($data['trading_account_id']);
        $this->authorizeAccount($request, $account);
        return response()->json($account->positions()->with(['trade', 'order.setup'])->when(
            $data['state'] ?? null,
            fn ($query, $state) => $query->where('state', $state),
        )->latest()->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function journal(Request $request): JsonResponse
    {
        $data = $request->validate(['trading_account_id' => ['required', 'integer', 'exists:trading_accounts,id']]);
        $account = TradingAccount::query()->findOrFail($data['trading_account_id']);
        $this->authorizeAccount($request, $account);
        return response()->json(Trade::query()
            ->whereHas('position', fn ($query) => $query->where('trading_account_id', $account->id))
            ->with(['position.order.setup'])
            ->latest('opened_at')
            ->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key header (1-128 safe characters) is required.']);
        }
        return $key;
    }

    private function authorizeAccount(Request $request, TradingAccount $account): void
    {
        abort_if($account->mode !== 'PAPER', 403, 'Only PAPER accounts are accessible.');
        abort_if(
            $account->user_id === null && ! app()->environment(['local', 'testing']),
            403,
            'Paper accounts must have an authenticated owner outside local/testing.',
        );
        if ($account->user_id !== null) {
            abort_unless($request->user()?->getAuthIdentifier() === $account->user_id, 403);
        }
    }

    private function domain(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            abort(409, $exception->getMessage());
        }
    }
}
