<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Credit spend, provider cost and profit per UTC day and per user.
 *
 * Days are the day a generation completed, not the day it was created, because
 * that is how Pixelforge's GET /v1/usage counts them. That way the provider cost
 * column can be checked against Pixelforge's own numbers, day by day.
 */
final class ProfitReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->aggregate($from, $to);
        $pixelforge = $this->pixelforgeUsage($from, $to);
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        $days = collect(CarbonPeriod::create($from, $to))->map(function ($date) use ($rows, $pixelforge) {
            $day = $date->toDateString();
            $dayRows = $rows->where('day', $day);
            $totals = $this->totals($dayRows);
            $theirs = $pixelforge['days'][$day] ?? null;

            return ['date' => $day] + $this->present($totals) + [
                'pixelforge' => $theirs === null ? null : [
                    'generations' => $theirs['generations'],
                    'cost' => Money::toUsd($theirs['cost']),
                    'matches' => $theirs['cost'] === $totals['provider_cost'],
                    'difference' => Money::toUsd($totals['provider_cost'] - $theirs['cost']),
                ],
            ];
        })->reverse()->values();

        $perUser = $rows->groupBy('user_id')->map(fn (Collection $userRows, $userId) => [
            'user' => $this->userLabel($users->get($userId)),
        ] + $this->present($this->totals($userRows)))->sortByDesc('charged_nanodollars')->values();

        $detail = $rows->sortByDesc('day')->map(fn ($row) => [
            'date' => $row->day,
            'user' => $this->userLabel($users->get($row->user_id)),
        ] + $this->present($this->totals(collect([$row]))))->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->present($this->totals($rows)),
            'days' => $days,
            'users' => $perUser,
            'detail' => $detail,
            'pixelforge_error' => $pixelforge['error'],
        ];
    }

    /** One row per (day, user), summed in the database. Money columns are nanodollars. */
    private function aggregate(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<', $to->addDay()->startOfDay())
            ->selectRaw('date(completed_at) as day, user_id')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as succeeded', [GenerationStatus::Succeeded->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('coalesce(sum(charge_nanodollars), 0) as charged')
            ->selectRaw('coalesce(sum(provider_cost_nanodollars), 0) as provider_cost')
            ->groupByRaw('date(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(function ($row) {
                // Postgres returns bigint sums as numeric strings.
                $row->day = substr((string) $row->day, 0, 10);
                foreach (['user_id', 'succeeded', 'failed', 'charged', 'provider_cost'] as $column) {
                    $row->{$column} = (int) $row->{$column};
                }

                return $row;
            });
    }

    /** @return array{succeeded: int, failed: int, charged: int, provider_cost: int} */
    private function totals(Collection $rows): array
    {
        return [
            'succeeded' => (int) $rows->sum('succeeded'),
            'failed' => (int) $rows->sum('failed'),
            'charged' => (int) $rows->sum('charged'),
            'provider_cost' => (int) $rows->sum('provider_cost'),
        ];
    }

    private function present(array $totals): array
    {
        return [
            'succeeded' => $totals['succeeded'],
            'failed' => $totals['failed'],
            'charged' => Money::toUsd($totals['charged']),
            'charged_nanodollars' => $totals['charged'],
            'provider_cost' => Money::toUsd($totals['provider_cost']),
            'profit' => Money::toUsd($totals['charged'] - $totals['provider_cost']),
        ];
    }

    /** @return array{days: array<string, array{generations: int, cost: int}>, error: ?string} */
    private function pixelforgeUsage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $usage = $this->pixelforge->usage($from->toDateString(), $to->toDateString());
        } catch (PixelforgeException $e) {
            return ['days' => [], 'error' => 'Could not load Pixelforge usage: '.$e->getMessage()];
        }

        $days = [];
        foreach ($usage['data'] ?? [] as $day) {
            $days[$day['date']] = [
                'generations' => (int) $day['generations'],
                'cost' => Money::fromUsd((string) $day['cost_usd']),
            ];
        }

        return ['days' => $days, 'error' => null];
    }

    private function userLabel(?User $user): string
    {
        return $user === null ? 'Deleted user' : "{$user->name} ({$user->email})";
    }
}
