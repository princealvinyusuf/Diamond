<?php

namespace Tests\Unit;

use App\Domain\MarketData\TechnicalAnalysisBridge;
use DateTimeImmutable;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class TechnicalAnalysisBridgeTest extends TestCase
{
    public function test_bridge_invokes_python_with_normalized_closed_candles(): void
    {
        Process::fake(['*' => Process::result(output: json_encode($this->validOutput(), JSON_THROW_ON_ERROR))]);

        $result = (new TechnicalAnalysisBridge)->analyze($this->series(), new DateTimeImmutable('2026-10-04T00:00:00Z'));

        self::assertTrue($result['ok']);
        self::assertSame('NEUTRAL', $result['analysis']['bias']);
        Process::assertRan(fn ($process) => str_contains($process->command, 'diamond_quant.analysis_cli'));
    }

    public function test_bridge_fails_closed_on_invalid_engine_output(): void
    {
        Process::fake(['*' => Process::result(output: '{"bias":"BULLISH"}')]);

        $result = (new TechnicalAnalysisBridge)->analyze($this->series(), new DateTimeImmutable('2026-10-04T00:00:00Z'));

        self::assertFalse($result['ok']);
        self::assertSame('TECHNICAL_ENGINE_INVALID_OUTPUT', $result['error']['code']);
    }

    private function series(): array
    {
        return [
            'D1' => [$this->candle('D1', '2026-10-02T00:00:00Z')],
            'H4' => [$this->candle('H4', '2026-10-03T16:00:00Z')],
            'H1' => [$this->candle('H1', '2026-10-03T22:00:00Z')],
        ];
    }

    private function candle(string $timeframe, string $openTime): array
    {
        return [
            'provider' => 'fixture', 'symbol' => 'XAUUSD', 'timeframe' => $timeframe,
            'openTime' => $openTime, 'open' => 2640, 'high' => 2652, 'low' => 2638,
            'close' => 2649, 'volume' => null, 'isClosed' => true, 'isMock' => true,
        ];
    }

    private function validOutput(): array
    {
        $features = array_fill_keys(
            ['ema20', 'ema50', 'ema200', 'rsi14', 'macd', 'macdSignal', 'macdHistogram', 'atr14', 'adx14'],
            null,
        );

        return [
            'contractVersion' => '1.0.0', 'symbol' => 'XAUUSD', 'asOf' => '2026-10-04T00:00:00+00:00',
            'features' => ['D1' => $features, 'H4' => $features, 'H1' => $features],
            'structure' => ['labels' => [], 'zones' => []],
            'alignment' => [
                'biases' => ['D1' => 'NEUTRAL', 'H4' => 'NEUTRAL', 'H1' => 'NEUTRAL'],
                'aligned' => false, 'direction' => 'NEUTRAL',
            ],
            'regime' => 'RANGE', 'bias' => 'NEUTRAL',
            'setup' => [
                'direction' => null, 'family' => 'NONE', 'confirmed' => false,
                'evidence' => [
                    'alignment' => false, 'trend' => false, 'momentum' => false,
                    'structure' => false, 'breakout' => false, 'retest' => false,
                    'warmedUp' => false,
                ],
                'qualityScore' => 0,
            ],
        ];
    }
}
