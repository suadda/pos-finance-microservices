<?php

return [
    // PPN rate applied to (subtotal - discount). Kept as a string for bcmath.
    'tax_rate' => (string) env('TAX_RATE', '0.11'),
];
