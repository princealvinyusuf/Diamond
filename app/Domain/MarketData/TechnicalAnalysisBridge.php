<?php

namespace App\Domain\MarketData;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

final class TechnicalAnalysisBridge
{
    private const TIMEFRAMES = ['D1' => 'P1D', 'H4' => 'PT4H', 'H1' => 'PT1H'];
    private const MAX_PAYLOAD_BYTES = 2_000_000;

    /**
     * @param array<string, list<array<string, mixed>>> $series
     * @return array{ok: bool, analysis?: array<string, mixed>, error?: array{code: string, message: string}}
     */
    public function analyze(array $series, DateTimeImmutable $asOf): array
    {
        try {
            $normalized = [];
            foreach (self::TIMEFRAMES as $timeframe => $duration) {
                $normalized[$timeframe] = $this->normalize($series[$timeframe] ?? [], $timeframe, $duration, $asOf);
            }
            $input = json_encode([
                'contractVersion' => '1.0.0',
                'symbol' => 'XAUUSD',
                'asOf' => $asOf->format(DATE_ATOM),
                'series' => $normalized,
            ], JSON_THROW_ON_ERROR);
            if (strlen($input) > self::MAX_PAYLOAD_BYTES) {
                return $this->failure('ANALYSIS_PAYLOAD_TOO_LARGE', 'Normalized candle payload exceeds the bridge limit.');
            }

            $result = Process::path(base_path())
                ->env(['PYTHONPATH' => base_path('quant/src')])
                ->input($input)
                ->timeout((int) config('diamond.analysis.timeout_seconds', 10))
                ->run([
                    (string) config('diamond.analysis.python', 'python'),
                    '-m',
                    'diamond_quant.analysis_cli',
                ]);
            if (! $result->successful()) {
                return $this->failure('TECHNICAL_ENGINE_FAILED', 'Technical analysis is unavailable.');
            }
            if (strlen($result->output()) > 500_000) {
                return $this->failure('TECHNICAL_ENGINE_INVALID_OUTPUT', 'Technical engine returned an invalid response.');
            }
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! $this->validOutput($payload)) {
                return $this->failure('TECHNICAL_ENGINE_INVALID_OUTPUT', 'Technical engine returned an invalid response.');
            }

            return ['ok' => true, 'analysis' => $payload];
        } catch (Throwable) {
            return $this->failure('TECHNICAL_ANALYSIS_UNAVAILABLE', 'Technical analysis is unavailable.');
        }
    }

    /** @return list<array{openTime: string, open: float, high: float, low: float, close: float, volume: float|null, isClosed: true}> */
    private function normalize(array $candles, string $timeframe, string $duration, DateTimeImmutable $asOf): array
    {
        if (count($candles) > 500) {
            throw new \InvalidArgumentException('Too many candles.');
        }
        $result = [];
        $previous = null;
        foreach ($candles as $candle) {
            foreach (['openTime', 'open', 'high', 'low', 'close'] as $field) {
                if (! array_key_exists($field, $candle)) {
                    throw new \InvalidArgumentException("Candle {$field} is missing.");
                }
            }
            $openedAt = new DateTimeImmutable((string) $candle['openTime']);
            if ($previous !== null && $openedAt <= $previous) {
                throw new \InvalidArgumentException('Candles must be unique and ascending.');
            }
            $previous = $openedAt;
            if (($candle['isClosed'] ?? false) !== true || $openedAt->add(new DateInterval($duration)) > $asOf) {
                continue;
            }
            $open = filter_var($candle['open'], FILTER_VALIDATE_FLOAT);
            $high = filter_var($candle['high'], FILTER_VALIDATE_FLOAT);
            $low = filter_var($candle['low'], FILTER_VALIDATE_FLOAT);
            $close = filter_var($candle['close'], FILTER_VALIDATE_FLOAT);
            if ($open === false || $high === false || $low === false || $close === false
                || $open <= 0 || $low <= 0 || $high < max($open, $close) || $low > min($open, $close)) {
                throw new \InvalidArgumentException('Candle OHLC is invalid.');
            }
            $volume = $candle['volume'] ?? null;
            if ($volume !== null && (! is_numeric($volume) || (float) $volume < 0)) {
                throw new \InvalidArgumentException('Candle volume is invalid.');
            }
            $result[] = [
                'openTime' => $openedAt->format(DATE_ATOM),
                'open' => (float) $open,
                'high' => (float) $high,
                'low' => (float) $low,
                'close' => (float) $close,
                'volume' => $volume === null ? null : (float) $volume,
                'isClosed' => true,
            ];
        }

        return $result;
    }

    private function validOutput(array $payload): bool
    {
        $topLevel = ['contractVersion', 'symbol', 'asOf', 'features', 'structure', 'alignment', 'regime', 'bias', 'setup'];
        if (array_diff(array_keys($payload), $topLevel) !== [] || array_diff($topLevel, array_keys($payload)) !== []
            || ($payload['contractVersion'] ?? null) !== '1.0.0'
            || ($payload['symbol'] ?? null) !== 'XAUUSD'
            || ! is_string($payload['asOf'] ?? null) || strtotime($payload['asOf']) === false
            || ! isset($payload['features'], $payload['structure'], $payload['alignment'], $payload['setup'])
            || ! is_array($payload['features'])
            || array_diff(array_keys($payload['features']), ['D1', 'H4', 'H1']) !== []
            || array_diff(['D1', 'H4', 'H1'], array_keys($payload['features'])) !== []
            || ! is_array($payload['structure'])
            || ! is_array($payload['alignment'])
            || ! is_array($payload['setup'])
            || ! in_array($payload['bias'] ?? null, ['BULLISH', 'BEARISH', 'NEUTRAL'], true)
            || ! in_array($payload['regime'] ?? null, ['TREND', 'RANGE'], true)
            || ! is_int($payload['setup']['qualityScore'] ?? null)
            || $payload['setup']['qualityScore'] < 0 || $payload['setup']['qualityScore'] > 100
            || ! in_array($payload['setup']['direction'] ?? null, ['BUY', 'SELL', null], true)
            || ! is_bool($payload['setup']['confirmed'] ?? null)) {
            return false;
        }
        $featureKeys = ['ema20', 'ema50', 'ema200', 'rsi14', 'macd', 'macdSignal', 'macdHistogram', 'atr14', 'adx14'];
        foreach (['D1', 'H4', 'H1'] as $timeframe) {
            $features = $payload['features'][$timeframe] ?? null;
            if (! is_array($features)
                || array_diff(array_keys($features), $featureKeys) !== []
                || array_diff($featureKeys, array_keys($features)) !== []) {
                return false;
            }
            foreach ($features as $value) {
                if ($value !== null && (! is_int($value) && ! is_float($value) || ! is_finite((float) $value))) {
                    return false;
                }
            }
        }
        if (array_diff(array_keys($payload['structure']), ['labels', 'zones']) !== []
            || array_diff(['labels', 'zones'], array_keys($payload['structure'])) !== []
            || array_diff(array_keys($payload['alignment']), ['biases', 'aligned', 'direction']) !== []
            || array_diff(['biases', 'aligned', 'direction'], array_keys($payload['alignment'])) !== []
            || array_diff(array_keys($payload['setup']), ['direction', 'family', 'confirmed', 'evidence', 'qualityScore']) !== []
            || array_diff(['direction', 'family', 'confirmed', 'evidence', 'qualityScore'], array_keys($payload['setup'])) !== []
            || ! is_array($payload['structure']['labels'] ?? null)
            || ! is_array($payload['structure']['zones'] ?? null)
            || ! is_array($payload['alignment']['biases'] ?? null)
            || ! is_bool($payload['alignment']['aligned'] ?? null)
            || ! in_array($payload['alignment']['direction'] ?? null, ['BULLISH', 'BEARISH', 'NEUTRAL'], true)
            || ! is_array($payload['setup']['evidence'] ?? null)
            || ! in_array($payload['setup']['family'] ?? null, ['BREAKOUT_RETEST', 'TREND_PULLBACK', 'STRUCTURE_REVERSAL', 'NONE'], true)) {
            return false;
        }
        foreach ($payload['structure']['labels'] as $label) {
            if (! in_array($label, ['HH', 'HL', 'LH', 'LL'], true)) {
                return false;
            }
        }
        foreach ($payload['structure']['zones'] as $zone) {
            if (! is_array($zone)
                || array_diff(array_keys($zone), ['kind', 'low', 'high']) !== []
                || array_diff(['kind', 'low', 'high'], array_keys($zone)) !== []
                || ! in_array($zone['kind'], ['support', 'resistance'], true)
                || ! is_numeric($zone['low']) || ! is_numeric($zone['high'])) {
                return false;
            }
        }
        if (array_diff(array_keys($payload['alignment']['biases']), ['D1', 'H4', 'H1']) !== []
            || array_diff(['D1', 'H4', 'H1'], array_keys($payload['alignment']['biases'])) !== []) {
            return false;
        }
        foreach ($payload['alignment']['biases'] as $bias) {
            if (! in_array($bias, ['BULLISH', 'BEARISH', 'NEUTRAL'], true)) {
                return false;
            }
        }
        $evidenceKeys = ['alignment', 'trend', 'momentum', 'structure', 'breakout', 'retest', 'warmedUp'];
        if (array_diff(array_keys($payload['setup']['evidence']), $evidenceKeys) !== []
            || array_diff($evidenceKeys, array_keys($payload['setup']['evidence'])) !== []) {
            return false;
        }
        foreach ($payload['setup']['evidence'] as $evidence) {
            if (! is_bool($evidence)) {
                return false;
            }
        }

        return true;
    }

    private function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
