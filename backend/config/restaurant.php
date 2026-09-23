<?php

return [
    // Cancellation at or above this line amount requires orders.cancel_high_value.
    'high_value_cancellation_threshold' => (float) env('HIGH_VALUE_CANCELLATION_THRESHOLD', 1000),
];
