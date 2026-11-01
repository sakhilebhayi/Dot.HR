<?php

namespace App\Services\Intelligence;

use App\Models\Application;
use App\Models\User;

/**
 * The Insights page as one tidy table (Section, Item, Measure, Value, Period), so the figures can
 * leave Dot.HR for a spreadsheet or a board pack without retyping. The same numbers as the page,
 * worked out the same way; nothing is added that the page does not show.
 *
 * Text cells that a spreadsheet would read as a formula (=, +, -, @, tab, return) are written with
 * a leading apostrophe, so a role title or skill name can never run as a formula when opened.
 */
final class InsightsExport
{
    public const SECTIONS = [
        'all' => 'Everything',
        'people' => 'People',
        'skills' => 'Skills the business needs',
        'hiring' => 'Hiring',
        'leave' => 'Leave taken',
        'development' => 'Training, certificates and contractors',
    ];

    public const HEADER = ['Section', 'Item', 'Measure', 'Value', 'Period'];

    public function __construct(private Insights $insights) {}

    /** @return list<list<string|int|float|null>> rows, header first */
    public function rows(string $section, User $viewer): array
    {
        $rows = [self::HEADER];
        $want = fn (string $key) => $section === 'all' || $section === $key;

        if ($want('people')) {
            $w = $this->insights->workforce();
            $rows[] = ['People', 'Everyone', 'Working here', $w['active'], 'Now'];
            foreach ($w['byType'] as $type => $n) {
                $rows[] = ['People', str_replace('_', ' ', (string) $type), 'Working here', $n, 'Now'];
            }
            $rows[] = ['People', 'Everyone', 'Joined', $w['joiners'], 'Last 12 months'];
            $rows[] = ['People', 'Everyone', 'Left', $w['leavers'], 'Last 12 months'];
            $rows[] = ['People', 'Everyone', 'Average headcount', $w['averageHeadcount'], 'Now and 12 months ago'];
            $rows[] = ['People', 'Everyone', 'Turnover (%)', $w['turnover'], 'Last 12 months'];
        }

        if ($want('skills')) {
            foreach ($this->insights->coverage($viewer) as $row) {
                $rows[] = ['Skills', $row['skill'], 'Needed by', implode('; ', $row['needed_by']), 'Now'];
                $rows[] = ['Skills', $row['skill'], 'People holding it', count($row['holders']), 'Now'];
                $rows[] = ['Skills', $row['skill'], 'Held by', implode('; ', array_map(fn ($h) => $h['name'].' ('.$h['source'].')', $row['holders'])), 'Now'];
            }
        }

        if ($want('hiring')) {
            $r = $this->insights->recruitment();
            $rows[] = ['Hiring', 'All roles', 'Open roles', $r['open'], 'Now'];
            $rows[] = ['Hiring', 'All roles', 'Applications', $r['applications90'], 'Last 90 days'];
            $rows[] = ['Hiring', 'All roles', 'Hires', $r['hires'], 'Last 12 months'];
            $rows[] = ['Hiring', 'All roles', 'Days to hire (median)', $r['medianDaysToHire'], 'Last 12 months'];
            foreach ($r['costs'] as $currency => $total) {
                $rows[] = ['Hiring', 'All roles', 'Recorded hiring costs ('.$currency.')', $total, 'Last 12 months'];
                $rows[] = ['Hiring', 'All roles', 'Cost per hire ('.$currency.')', $r['costPerHire'][$currency] ?? null, 'Last 12 months'];
            }
            foreach ($r['stages'] as $stage => $n) {
                $rows[] = ['Hiring', 'Applications now at: '.(Application::STAGES[$stage] ?? $stage), 'Applications', $n, 'Received in the last 12 months'];
            }
            foreach ($r['vacancies'] as $v) {
                $title = $v['vacancy']->title;
                $rows[] = ['Hiring', $title, 'Days open', $v['days'], 'Since published'];
                $rows[] = ['Hiring', $title, 'Applications', $v['applications'], 'Since published'];
                $rows[] = ['Hiring', $title, 'Shortlisted or further', $v['progressed'], 'Since published'];
                $rows[] = ['Hiring', $title, 'Hired', $v['hired'], 'Since published'];
            }
        }

        if ($want('leave')) {
            $l = $this->insights->leave();
            foreach ($l['months'] as $month => $days) {
                $rows[] = ['Leave', 'All approved leave', 'Working days', $days, $month];
            }
            foreach ($l['byType'] as $type => $days) {
                $rows[] = ['Leave', $type, 'Working days', $days, 'Last 6 months'];
            }
        }

        if ($want('development')) {
            $d = $this->insights->development();
            $rows[] = ['Training', 'Completed courses', 'Count', $d['completed'], 'Last 12 months'];
            $rows[] = ['Training', 'Completed courses', 'Hours', $d['hours'], 'Last 12 months'];
            $rows[] = ['Training', 'Booked', 'Count', $d['planned'], 'Now'];
            $rows[] = ['Training', 'Asked for', 'Count', $d['requested'], 'Now'];
            $rows[] = ['Certificates', 'Expired', 'Count', $d['expired'], 'Now'];
            $rows[] = ['Certificates', 'Lapsing soon', 'Count', $d['lapsing'], 'Next 30 days'];
            $c = $this->insights->contractors();
            $rows[] = ['Contractors', 'Engagements', 'Active', $c['active'], 'Now'];
            foreach ($c['spend'] as $currency => $amount) {
                $rows[] = ['Contractors', 'Approved or paid invoices', 'Spend ('.$currency.')', round($amount, 2), 'Last 12 months'];
            }
            $rows[] = ['Contractors', 'Invoices', 'Disputed', $c['disputed'], 'Now'];
        }

        return $rows;
    }

    /** One CSV line; text that could run as a formula is neutralised. @param list<string|int|float|null> $row */
    public static function line(array $row): string
    {
        $cells = array_map(function ($cell) {
            if ($cell === null) {
                return '';
            }
            if (is_string($cell)) {
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $cell = "'".$cell;
                }
                $cell = str_replace(["\r\n", "\r", "\n"], ' ', $cell);

                return preg_match('/[",;]/', $cell) || str_contains($cell, ' ') ? '"'.str_replace('"', '""', $cell).'"' : $cell;
            }

            return is_float($cell) ? rtrim(rtrim(number_format($cell, 2, '.', ''), '0'), '.') : (string) $cell;
        }, $row);

        return implode(',', $cells)."\r\n";
    }
}
