<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AksaraConfig;
use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __construct(
        private readonly AksaraConfig $config
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'worker' => 'ready',
            'concurrency' => $this->config->concurrency,
        ]);
    }
}
