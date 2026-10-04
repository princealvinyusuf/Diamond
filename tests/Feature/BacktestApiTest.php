<?php

namespace Tests\Feature;

use App\Jobs\RunBacktest;
use App\Models\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BacktestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_backtest_is_validated_persisted_and_queued(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/backtests', $this->request())
            ->assertAccepted()
            ->assertJsonPath('status', 'QUEUED')
            ->assertJsonPath('engineVersion', 'diamond-replay/1.0.0')
            ->assertJsonPath('seed', 7)
            ->assertJsonPath('walkForward.trainBars', 100);

        $run = BacktestRun::query()->where('run_key', $response->json('id'))->firstOrFail();
        self::assertSame('xauusd-h4-demo-v1', $run->dataset_key);
        self::assertSame(64, strlen($run->configuration_hash));
        Queue::assertPushed(RunBacktest::class, fn (RunBacktest $job) => $job->backtestRunId === $run->id);

        $this->getJson('/api/v1/backtests/'.$run->run_key)
            ->assertOk()
            ->assertJsonPath('id', $run->run_key)
            ->assertJsonPath('results', null);
    }

    public function test_backtest_rejects_missing_dataset_and_invalid_partitions(): void
    {
        $request = $this->request();
        $request['partitions']['validationEnd'] = '2024-01-01T00:00:00Z';

        $this->postJson('/api/v1/backtests', $request)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['partitions.validationEnd']);

        $request = $this->request();
        $request['datasetKey'] = 'missing';
        $this->postJson('/api/v1/backtests', $request)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['datasetKey']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'datasetKey' => 'xauusd-h4-demo-v1',
            'strategyVersion' => 'reference-breakout/1.0.0',
            'seed' => 7,
            'initialEquity' => 10000,
            'costModel' => ['spread' => 0.4, 'commissionRoundTurn' => 7],
            'partitions' => [
                'trainEnd' => '2025-01-01T12:00:00Z',
                'validationEnd' => '2025-01-02T04:00:00Z',
            ],
            'walkForward' => ['trainBars' => 100, 'testBars' => 25, 'stepBars' => 25],
        ];
    }
}
