<?php

namespace App\Jobs;

use App\Models\BacktestRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class RunBacktest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public readonly int $backtestRunId)
    {
        $this->onQueue('backtests');
    }

    public function handle(): void
    {
        $run = BacktestRun::query()->findOrFail($this->backtestRunId);
        $run->update(['state' => 'RUNNING', 'started_at' => now(), 'error_message' => null]);
        $requestPath = storage_path("app/private/backtest-{$run->run_key}.json");

        try {
            file_put_contents($requestPath, json_encode([
                'datasetKey' => $run->dataset_key,
                'strategyVersion' => $run->strategy_version,
                'seed' => $run->seed,
                'initialEquity' => $run->parameters['initialEquity'],
                'partitions' => $run->partitions,
                'walkForward' => $run->walk_forward,
                'costModel' => $run->cost_model,
            ], JSON_THROW_ON_ERROR));

            $result = Process::path(base_path())
                ->env(['PYTHONPATH' => base_path('quant/src')])
                ->timeout($this->timeout)
                ->run([
                    config('diamond.backtest.python', 'python'),
                    '-m',
                    'diamond_quant.backtest_cli',
                    '--request',
                    $requestPath,
                    '--root',
                    base_path('quant'),
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput()) ?: 'Backtest process failed.');
            }
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            if (! isset($payload['trades'], $payload['metrics'], $payload['engineVersion'], $payload['strategyVersion'])
                || ! is_array($payload['trades'])
                || ! is_array($payload['metrics'])) {
                throw new RuntimeException('Backtest process returned an invalid result shape.');
            }

            DB::transaction(function () use ($run, $payload): void {
                $run->trades()->delete();
                foreach ($payload['trades'] as $trade) {
                    $run->trades()->create([
                        'setup_snapshot' => [
                            'side' => $trade['side'],
                            'stop' => $trade['stop'],
                            'target' => $trade['target'],
                            'score' => $trade['score'],
                            'setupFamily' => $trade['setup_family'],
                            'regime' => $trade['regime'],
                            'alignment' => $trade['alignment'],
                            'engineVersion' => $payload['engineVersion'],
                            'strategyVersion' => $payload['strategyVersion'],
                        ],
                        'entered_at' => $trade['entered_at'],
                        'exited_at' => $trade['exited_at'],
                        'entry_price' => $trade['entry'],
                        'exit_price' => $trade['exit'],
                        'realized_pl' => $trade['pnl'],
                        'r_multiple' => $trade['r_multiple'],
                        'partition' => $trade['partition'],
                        'setup_family' => $trade['setup_family'],
                        'regime' => $trade['regime'],
                        'alignment' => $trade['alignment'],
                        'score' => $trade['score'],
                        'exit_reason' => $trade['exit_reason'],
                        'bars_held' => $trade['bars_held'],
                    ]);
                }
                $run->update([
                    'state' => 'COMPLETED',
                    'engine_version' => $payload['engineVersion'],
                    'results_summary' => [
                        ...$payload['metrics'],
                        'walkForward' => $payload['walkForward'] ?? [],
                    ],
                    'finished_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $run->update([
                'state' => 'FAILED',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
            throw $exception;
        } finally {
            if (is_file($requestPath)) {
                unlink($requestPath);
            }
        }
    }
}
