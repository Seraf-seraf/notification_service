<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Http\Controllers\Controller;
use App\Observability\MetricsRegistry;
use Illuminate\Http\Response;

final class MetricsController extends Controller
{
    public function __invoke(MetricsRegistry $metrics): Response
    {
        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
