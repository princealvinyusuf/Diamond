<?php

namespace App\Infrastructure\MarketData;

use App\Domain\MarketData\MarketDataProvider;
use App\Infrastructure\ProviderHealthRecorder;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class TwelveDataMarketDataProvider implements MarketDataProvider
{
    private array $health = [
        'provider' => 'twelve-data',
        'latency_ms' => null,
        'last_success_at' => null,
        'is_stale' => true,
        'error_code' => null,
        'error_message' => null,
        'isMock' => false,
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.twelvedata.com',
        private readonly ?ProviderHealthRecorder $healthRecorder = null,
    )
    {
        if ($apiKey === '') {
            throw new RuntimeException('TWELVE_DATA_API_KEY is required when the live read-only provider is selected.');
        }
    }

    public function quote(string $symbol): array
    {
        $started = hrtime(true);
        try {
            $payload = $this->get('/quote', ['symbol' => $this->symbol($symbol)]);
            $timestamp = isset($payload['timestamp']) ? gmdate(DATE_ATOM, (int) $payload['timestamp']) : null;
            if ($timestamp === null || !isset($payload['bid'], $payload['ask'])) {
                throw new RuntimeException('Provider quote omitted bid, ask, or timestamp.');
            }
            $this->success($started, $timestamp);
            return [
                'provider' => 'twelve-data', 'symbol' => 'XAUUSD',
                'bid' => (float) $payload['bid'], 'ask' => (float) $payload['ask'],
                'timestamp' => $timestamp, 'isMock' => false,
            ];
        } catch (Throwable $error) {
            $this->failure($started, $error);
            throw $error;
        }
    }

    public function candles(string $symbol, string $timeframe, int $limit = 250): array
    {
        $started = hrtime(true);
        try {
            $intervals = ['H1' => '1h', 'H4' => '4h', 'D1' => '1day'];
            if (!isset($intervals[$timeframe])) {
                throw new RuntimeException('Unsupported timeframe.');
            }
            $payload = $this->get('/time_series', [
                'symbol' => $this->symbol($symbol),
                'interval' => $intervals[$timeframe],
                'outputsize' => max(1, min($limit, 500)),
                'timezone' => 'UTC',
            ]);
            $rows = array_reverse($payload['values'] ?? []);
            $result = array_map(static fn (array $row): array => [
                'provider' => 'twelve-data', 'symbol' => 'XAUUSD', 'timeframe' => $timeframe,
                'openTime' => (new \DateTimeImmutable($row['datetime'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'open' => (float) $row['open'], 'high' => (float) $row['high'],
                'low' => (float) $row['low'], 'close' => (float) $row['close'],
                'volume' => isset($row['volume']) ? (float) $row['volume'] : null,
                'isClosed' => true, 'isMock' => false,
            ], $rows);
            $this->success($started, $result === [] ? null : end($result)['openTime']);
            return $result;
        } catch (Throwable $error) {
            $this->failure($started, $error);
            throw $error;
        }
    }

    public function health(): array
    {
        return $this->health;
    }

    private function get(string $path, array $query): array
    {
        $response = Http::acceptJson()
            ->connectTimeout((int) config('diamond.providers.connect_timeout_seconds', 3))
            ->timeout((int) config('diamond.providers.timeout_seconds', 10))
            ->retry(
                (int) config('diamond.providers.retry_attempts', 2),
                (int) config('diamond.providers.retry_delay_ms', 250),
                throw: false,
            )
            ->get(rtrim($this->baseUrl, '/').$path, $query + ['apikey' => $this->apiKey])
            ->throw();
        $payload = $response->json();
        if (!is_array($payload) || isset($payload['code'])) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Invalid provider response.'));
        }
        return $payload;
    }

    private function symbol(string $symbol): string
    {
        if ($symbol !== 'XAUUSD') {
            throw new RuntimeException('Only XAUUSD is supported.');
        }
        return 'XAU/USD';
    }

    private function success(int $started, ?string $at): void
    {
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->health = array_merge($this->health, [
            'latency_ms' => $latency,
            'last_success_at' => now()->toISOString(), 'source_at' => $at, 'is_stale' => false,
            'error_code' => null, 'error_message' => null,
        ]);
        $this->healthRecorder?->success('twelve-data', $latency, $at);
    }

    private function failure(int $started, Throwable $error): void
    {
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->health = array_merge($this->health, [
            'latency_ms' => $latency,
            'is_stale' => true, 'error_code' => 'PROVIDER_ERROR',
            'error_message' => $error->getMessage(),
        ]);
        $this->healthRecorder?->failure('twelve-data', $latency, $error);
    }
}
