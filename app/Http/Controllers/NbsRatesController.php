<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class NbsRatesController extends Controller
{
    /**
     * Get today's middle exchange rates vs RSD (no API key).
     * Source: https://kurs.resenje.org/api/v1/rates/today
     *
     * @OA\Get(
     *   path="/api/rates/today",
     *   tags={"Rates"},
     *   summary="Get today's exchange rates vs RSD (EUR, USD, CHF, JPY)",
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="base", type="string", example="RSD"),
     *       @OA\Property(property="date", type="string", format="date", example="2025-09-04"),
     *       @OA\Property(
     *         property="rates",
     *         type="object",
     *         @OA\Property(property="EUR", type="number", example=117.1796),
     *         @OA\Property(property="USD", type="number", example=108.3200),
     *         @OA\Property(property="CHF", type="number", example=120.4500),
     *         @OA\Property(property="JPY", type="number", example=0.7250)
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=502,
     *     description="Failed to fetch rates",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="error", type="string", example="Failed to fetch rates")
     *     )
     *   )
     * )
     */
    public function today()
    {
        $resp = Http::timeout(10)->get('https://kurs.resenje.org/api/v1/rates/today');
        if (!$resp->ok()) {
            return response()->json(['error' => 'Failed to fetch rates'], 502);
        }

        $payload = $resp->json();
        if (!isset($payload['rates']) || !is_array($payload['rates'])) {
            return response()->json(['error' => 'Failed to fetch rates'], 502);
        }

        $wanted = ['EUR', 'USD', 'CHF', 'JPY'];
        $rates = collect($payload['rates'])
            ->whereIn('code', $wanted)
            ->mapWithKeys(fn($row) => [$row['code'] => (float) $row['exchange_middle']])
            ->all();

        $date = $payload['rates'][0]['date'] ?? now('Europe/Belgrade')->toDateString();

        return response()->json([
            'base' => 'RSD',
            'date' => $date,
            'rates' => $rates,
        ]);
    }
}
