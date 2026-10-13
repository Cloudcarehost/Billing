<?php

return [
    'enabled' => (bool) env('GOOGLE_SHEETS_ENABLED', false),
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH', storage_path('app/google-service-account.json')),
    'open_tables_tab' => 'Open tables',
    'lines_tab' => 'Lines',
];
