<?php

function lc(?string $s): ?string
{
    return $s === null ? null : mb_strtolower(trim($s));
}


function array_filter_recursive($input)
{
    foreach ($input as $k => $v) {
        if (is_array($v)) {
            $input[$k] = array_filter_recursive($v);
            if ($input[$k] === []) unset($input[$k]);
        } elseif ($v === null || $v === '') unset($input[$k]);
    }
    return $input;
}

function ksort_recursive(&$array)
{
    if (!is_array($array)) return;
    ksort($array);
    foreach ($array as &$v) ksort_recursive($v);
}
function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
}

function normalizeToTenDigits($input)
{
    // Remove anything not 0–9
    $digits = preg_replace('/\D/', '', $input);

    // If more than 10 digits, take the LAST 10 (most common for phone formatting)
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}
