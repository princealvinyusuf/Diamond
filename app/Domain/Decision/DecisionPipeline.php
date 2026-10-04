<?php

namespace App\Domain\Decision;

/**
 * Pure ordered decision pipeline. It neither places nor models orders.
 */
final class DecisionPipeline
{
    private const ORDER = ['data', 'risk', 'event', 'regime', 'bias', 'setup', 'economics', 'sizing'];

    /** @return array<string, mixed> */
    public function decide(array $input): array
    {
        $reasons = [];
        $trace = [];
        foreach (self::ORDER as $stage) {
            $result = $this->{$stage}($input);
            $trace[] = ['stage' => $stage, 'passed' => $result['passed'], 'reasons' => $result['reasons']];
            array_push($reasons, ...$result['reasons']);
            if (!$result['passed']) {
                return $this->output('NO_TRADE', $reasons, $trace, $input);
            }
        }

        $direction = $input['setup']['direction'] ?? null;
        $confirmed = (bool) ($input['setup']['confirmed'] ?? false);
        if (!$confirmed) {
            return $this->output('WAIT_FOR_CONFIRMATION', ['SETUP_CONFIRMATION_PENDING'], $trace, $input);
        }
        if (!in_array($direction, ['BUY', 'SELL'], true)) {
            return $this->output('NO_TRADE', ['SETUP_DIRECTION_INVALID'], $trace, $input);
        }
        return $this->output($direction, [], $trace, $input);
    }

    private function data(array $input): array
    {
        $quality = $input['data']['quality'] ?? 'UNAVAILABLE';
        return $this->gate($quality === 'FRESH', $quality === 'FRESH' ? [] : ["DATA_{$quality}"]);
    }

    private function risk(array $input): array
    {
        return $this->gate((bool) ($input['risk']['allowed'] ?? false), $input['risk']['reasons'] ?? [] ?: ['RISK_PERMISSION_BLOCKED']);
    }

    private function event(array $input): array
    {
        return $this->gate(!(bool) ($input['event']['blocking'] ?? true), $input['event']['reasons'] ?? [] ?: ['EVENT_STATE_UNKNOWN']);
    }

    private function regime(array $input): array
    {
        $value = $input['regime'] ?? 'UNKNOWN';
        return $this->gate(in_array($value, ['TREND', 'RANGE'], true), in_array($value, ['TREND', 'RANGE'], true) ? [] : ['REGIME_UNSUPPORTED']);
    }

    private function bias(array $input): array
    {
        $value = $input['bias'] ?? 'NEUTRAL';
        return $this->gate(in_array($value, ['BULLISH', 'BEARISH', 'STRONG_BULLISH', 'STRONG_BEARISH'], true), ['BIAS_NOT_ACTIONABLE']);
    }

    private function setup(array $input): array
    {
        $score = (int) ($input['setup']['qualityScore'] ?? 0);
        $minimum = (int) ($input['setup']['minimumScore'] ?? 60);
        return $this->gate($score >= $minimum, $score >= $minimum ? [] : ['SETUP_QUALITY_TOO_LOW']);
    }

    private function economics(array $input): array
    {
        $rr = (float) ($input['economics']['netRiskReward'] ?? 0);
        $minimum = (float) ($input['economics']['minimumRiskReward'] ?? 2);
        return $this->gate($rr >= $minimum, $rr >= $minimum ? [] : ['NET_RISK_REWARD_TOO_LOW']);
    }

    private function sizing(array $input): array
    {
        $volume = (float) ($input['sizing']['safeVolume'] ?? 0);
        return $this->gate($volume > 0, $volume > 0 ? [] : ['SAFE_VOLUME_UNAVAILABLE']);
    }

    private function gate(bool $passed, array $reasons): array
    {
        return ['passed' => $passed, 'reasons' => $passed ? [] : array_values($reasons)];
    }

    private function output(string $action, array $reasons, array $trace, array $input): array
    {
        return [
            'contractVersion' => '1.0.0',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H4',
            'asOf' => $input['asOf'] ?? gmdate(DATE_ATOM),
            'marketBias' => $input['bias'] ?? 'NEUTRAL',
            'tradeSetup' => $action === 'NO_TRADE' ? 'BLOCKED' : ($action === 'WAIT_FOR_CONFIRMATION' ? 'ARMED' : 'VALID'),
            'riskPermission' => (bool) ($input['risk']['allowed'] ?? false) ? 'ALLOWED' : 'BLOCKED',
            'finalAction' => $action,
            'newsRisk' => $input['event']['state'] ?? 'HIGH',
            'dataQuality' => $input['data']['quality'] ?? 'UNAVAILABLE',
            'technicalScore' => $input['setup']['qualityScore'] ?? null,
            'fundamentalScore' => $input['fundamentalScore'] ?? null,
            'entry' => $input['setup']['entry'] ?? null,
            'stopLoss' => $input['setup']['stopLoss'] ?? null,
            'targets' => $input['setup']['targets'] ?? [],
            'safeVolume' => $input['sizing']['safeVolume'] ?? null,
            'blockReasons' => array_values(array_unique($reasons)),
            'pipelineTrace' => $trace,
            'strategyVersion' => $input['strategyVersion'] ?? 'h4-gold-v1',
            'configurationHash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            'executionMode' => 'PAPER',
            'isMock' => (bool) ($input['isMock'] ?? true),
            'technicalAnalysis' => $input['technicalAnalysis'] ?? [
                'status' => 'UNAVAILABLE',
                'error' => ['code' => 'TECHNICAL_ANALYSIS_NOT_PROVIDED', 'message' => 'Technical analysis was not provided.'],
            ],
        ];
    }
}
