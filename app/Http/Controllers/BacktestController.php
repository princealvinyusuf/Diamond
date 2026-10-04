<?php

namespace App\Http\Controllers;

use App\Jobs\RunBacktest;
use App\Models\BacktestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BacktestController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datasetKey' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:100'],
            'strategyVersion' => ['required', 'string', 'max:100'],
            'seed' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'initialEquity' => ['required', 'numeric', 'gt:0'],
            'costModel.spread' => ['required', 'numeric', 'min:0'],
            'costModel.commissionRoundTurn' => ['required', 'numeric', 'min:0'],
            'partitions.trainEnd' => ['required', 'date'],
            'partitions.validationEnd' => ['required', 'date', 'after:partitions.trainEnd'],
            'walkForward' => ['sometimes', 'array'],
            'walkForward.trainBars' => ['required_with:walkForward', 'integer', 'min:1'],
            'walkForward.testBars' => ['required_with:walkForward', 'integer', 'min:1'],
            'walkForward.stepBars' => ['sometimes', 'integer', 'min:1'],
        ]);

        $dataset = base_path('quant/fixtures/'.$data['datasetKey'].'.json');
        if (! is_file($dataset)) {
            throw ValidationException::withMessages([
                'datasetKey' => ['The selected versioned historical dataset is unavailable.'],
            ]);
        }

        $canonical = [
            'datasetKey' => $data['datasetKey'],
            'strategyVersion' => $data['strategyVersion'],
            'seed' => (int) $data['seed'],
            'initialEquity' => (string) $data['initialEquity'],
            'costModel' => $data['costModel'],
            'partitions' => $data['partitions'],
            'walkForward' => $data['walkForward'] ?? null,
        ];
        $run = BacktestRun::query()->create([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'run_key' => (string) Str::uuid(),
            'strategy_version' => $data['strategyVersion'],
            'dataset_key' => $data['datasetKey'],
            'configuration_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'parameters' => ['initialEquity' => (string) $data['initialEquity']],
            'cost_model' => $data['costModel'],
            'partitions' => $data['partitions'],
            'walk_forward' => $data['walkForward'] ?? null,
            'seed' => $data['seed'],
            'engine_version' => 'diamond-replay/1.0.0',
            'state' => 'QUEUED',
        ]);

        RunBacktest::dispatch($run->id);

        return response()->json($this->resource($run), 202)
            ->header('Location', route('backtests.show', $run));
    }

    public function show(Request $request, BacktestRun $backtestRun): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_if($backtestRun->user_id === null, 403, 'Backtest runs require an owner outside local/testing.');
            abort_unless($request->user()?->getAuthIdentifier() === $backtestRun->user_id, 403);
        }
        return response()->json($this->resource($backtestRun->fresh()));
    }

    /** @return array<string, mixed> */
    private function resource(BacktestRun $run): array
    {
        return [
            'id' => $run->run_key,
            'status' => $run->state,
            'datasetKey' => $run->dataset_key,
            'strategyVersion' => $run->strategy_version,
            'engineVersion' => $run->engine_version,
            'configurationHash' => $run->configuration_hash,
            'seed' => $run->seed,
            'partitions' => $run->partitions,
            'walkForward' => $run->walk_forward,
            'results' => $run->results_summary,
            'error' => $run->state === 'FAILED' ? $run->error_message : null,
            'queuedAt' => $run->created_at?->toISOString(),
            'startedAt' => $run->started_at?->toISOString(),
            'finishedAt' => $run->finished_at?->toISOString(),
        ];
    }
}
