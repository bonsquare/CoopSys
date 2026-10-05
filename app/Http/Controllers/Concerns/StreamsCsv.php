<?php

namespace App\Http\Controllers\Concerns;

trait StreamsCsv
{
    /** Download rows as a CSV file that Excel opens directly. */
    protected function csv(string $name, array $rows)
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** 1234.5 => "1234.50" for spreadsheets. */
    protected function num($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
