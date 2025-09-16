<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class ExchangeRateService
{
    public function getRate(string $base, string $quote): ?float
    {
        $base  = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === $quote) return 1.0;

        $cacheKey = "fx:v6:$base:$quote";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $key = config('services.exchangerate_v6.key');
        if (!$key) {
            return null;
        }

        $url = "https://v6.exchangerate-api.com/v6/{$key}/pair/{$base}/{$quote}";

        $resp = Http::retry(2, 250)
            ->timeout(10)
            ->get($url);

        if (!$resp->ok()) {
            return null;
        }

        $data = $resp->json();

        if (($data['result'] ?? null) !== 'success') {
            return null;
        }

        $rate = (float) ($data['conversion_rate'] ?? 0);
        if ($rate <= 0) {
            return null;
        }

        $nextUnix = (int) ($data['time_next_update_unix'] ?? 0);
        $expires  = $nextUnix > 0
            ? Carbon::createFromTimestamp($nextUnix)
            : now()->addHours(6);

        Cache::put($cacheKey, $rate, $expires);

        return $rate;
    }
}
