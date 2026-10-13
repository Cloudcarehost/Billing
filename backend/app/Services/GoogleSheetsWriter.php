<?php

namespace App\Services;

interface GoogleSheetsWriter
{
    /**
     * Replace the given tabs with these rows. Creates a tab if it is missing.
     *
     * @param  array<string, list<list<scalar|null>>>  $tabs
     */
    public function rewriteWorkbook(string $spreadsheetId, array $tabs): void;
}
