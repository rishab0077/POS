<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class NepaliDateService
{
    private const BS_MONTHS = [
        1 => 'Baisakh',
        2 => 'Jestha',
        3 => 'Ashadh',
        4 => 'Shrawan',
        5 => 'Bhadra',
        6 => 'Ashwin',
        7 => 'Kartik',
        8 => 'Mangsir',
        9 => 'Poush',
        10 => 'Magh',
        11 => 'Falgun',
        12 => 'Chaitra',
    ];

    private const YEAR_STARTS = [
        2080 => '2023-04-14',
        2081 => '2024-04-13',
        2082 => '2025-04-14',
        2083 => '2026-04-14',
        2084 => '2027-04-14',
        2085 => '2028-04-13',
        2086 => '2029-04-14',
        2087 => '2030-04-14',
        2088 => '2031-04-14',
        2089 => '2032-04-13',
        2090 => '2033-04-14',
    ];

    private const MONTH_DAYS = [
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2081 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2082 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2083 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2084 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 29, 31],
        2086 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2087 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2088 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2089 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 29, 31],
        2090 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    ];

    public function toBs(CarbonInterface $date): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $year = $this->resolveBsYear($date);
        $daysIntoYear = $this->bsYearStartAd($year)->diffInDays($date);

        foreach (self::MONTH_DAYS[$year] as $index => $daysInMonth) {
            if ($daysIntoYear < $daysInMonth) {
                $month = $index + 1;

                return [
                    'year' => $year,
                    'month' => $month,
                    'day' => $daysIntoYear + 1,
                    'month_name' => self::BS_MONTHS[$month],
                ];
            }

            $daysIntoYear -= $daysInMonth;
        }

        return [
            'year' => $year + 1,
            'month' => 1,
            'day' => 1,
            'month_name' => self::BS_MONTHS[1],
        ];
    }

    public function format(CarbonInterface $date): string
    {
        $bs = $this->toBs($date);

        return sprintf('%04d-%02d-%02d', $bs['year'], $bs['month'], $bs['day']);
    }

    public function shrawanOneAdDate(int $bsYear): CarbonImmutable
    {
        if (!isset(self::MONTH_DAYS[$bsYear])) {
            throw new InvalidArgumentException("BS year {$bsYear} is outside the configured date conversion range.");
        }

        return $this->bsYearStartAd($bsYear)->addDays(array_sum(array_slice(self::MONTH_DAYS[$bsYear], 0, 3)));
    }

    private function resolveBsYear(CarbonImmutable $date): int
    {
        $candidate = null;

        foreach (self::YEAR_STARTS as $year => $adStart) {
            if ($date->greaterThanOrEqualTo(CarbonImmutable::parse($adStart, config('app.timezone'))->startOfDay())) {
                $candidate = $year;
            }
        }

        if ($candidate === null) {
            throw new InvalidArgumentException('Date is before the configured BS conversion range.');
        }

        return $candidate;
    }

    private function bsYearStartAd(int $bsYear): CarbonImmutable
    {
        if (!isset(self::YEAR_STARTS[$bsYear])) {
            throw new InvalidArgumentException("BS year {$bsYear} is outside the configured date conversion range.");
        }

        return CarbonImmutable::parse(self::YEAR_STARTS[$bsYear], config('app.timezone'))->startOfDay();
    }
}
