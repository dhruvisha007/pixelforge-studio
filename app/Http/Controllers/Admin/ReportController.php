<?php

namespace App\Http\Controllers\Admin;

use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController
{
    public function __invoke(Request $request, ProfitReport $report): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Defaults to the last 14 days. Dates are UTC, like Pixelforge's billing days.
        $to = CarbonImmutable::parse($validated['to'] ?? now('UTC')->toDateString(), 'UTC');
        $from = CarbonImmutable::parse($validated['from'] ?? $to->subDays(13)->toDateString(), 'UTC');

        // Pixelforge's usage endpoint covers at most 92 days.
        if ($from->diffInDays($to) > 91) {
            $from = $to->subDays(91);
        }

        return Inertia::render('admin/Report', [
            'report' => $report->build($from, $to),
        ]);
    }
}
