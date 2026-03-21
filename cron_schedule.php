<?php
/**
 * Minimal 5-field cron matching (minute hour dom month dow) with optional IANA timezone.
 * Day-of-month vs day-of-week: when both are restricted (not *), either may match (Vixie-style).
 */

function mg_cron_norm_field(string $field): string {
    $field = trim($field);
    return ($field === '?') ? '*' : $field;
}

function mg_cron_value_in_field(int $value, string $field, int $lo, int $hi): bool {
    $field = mg_cron_norm_field($field);
    if ($field === '*') {
        return true;
    }
    foreach (explode(',', $field) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '*') {
            return true;
        }
        if (preg_match('/^(\d+)-(\d+)(?:\/(\d+))?$/', $part, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $step = isset($m[3]) && $m[3] !== '' ? max(1, (int) $m[3]) : 1;
            if ($value < $a || $value > $b) {
                continue;
            }
            if (($value - $a) % $step === 0) {
                return true;
            }
        } elseif (preg_match('/^\*\/(\d+)$/', $part, $m)) {
            $step = max(1, (int) $m[1]);
            if ($value >= $lo && $value <= $hi && ($value - $lo) % $step === 0) {
                return true;
            }
        } elseif (preg_match('/^(\d+)$/', $part, $m)) {
            if ((int) $m[1] === $value) {
                return true;
            }
        }
    }
    return false;
}

/** PHP w: 0=Sunday..6=Saturday. Cron: 0 and 7 = Sunday. */
function mg_cron_dow_matches(int $phpDow, string $field): bool {
    $field = mg_cron_norm_field(trim($field));
    if ($field === '*') {
        return true;
    }
    foreach (explode(',', $field) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '*') {
            return true;
        }
        if (preg_match('/^(\d+)-(\d+)(?:\/(\d+))?$/', $part, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $step = isset($m[3]) && $m[3] !== '' ? max(1, (int) $m[3]) : 1;
            if ($a === 7) {
                $a = 0;
            }
            if ($b === 7) {
                $b = 0;
            }
            $from = min($a, $b);
            $to = max($a, $b);
            for ($v = $from; $v <= $to; $v += $step) {
                if (($v % 7) === $phpDow) {
                    return true;
                }
            }
            continue;
        }
        if (preg_match('/^\*\/(\d+)$/', $part, $m)) {
            $step = max(1, (int) $m[1]);
            if ($step > 0 && ($phpDow % $step) === 0) {
                return true;
            }
            continue;
        }
        if (preg_match('/^(\d+)$/', $part, $m)) {
            $v = (int) $m[1];
            if ($v === 7) {
                $v = 0;
            }
            if ($v === $phpDow) {
                return true;
            }
        }
    }
    return false;
}

function mg_cron_expression_valid(string $expression): bool {
    $expression = trim(preg_replace('/\s+/', ' ', $expression));
    $parts = explode(' ', $expression);
    return count($parts) === 5;
}

function mg_cron_matches_local_time(DateTimeImmutable $local, string $expression): bool {
    $expression = trim(preg_replace('/\s+/', ' ', $expression));
    $parts = explode(' ', $expression);
    if (count($parts) !== 5) {
        return false;
    }
    [$min, $hour, $dom, $mon, $dow] = $parts;
    $iMin = (int) $local->format('i');
    $iHour = (int) $local->format('G');
    $iDom = (int) $local->format('j');
    $iMon = (int) $local->format('n');
    $iDow = (int) $local->format('w');

    if (!mg_cron_value_in_field($iMin, $min, 0, 59)) {
        return false;
    }
    if (!mg_cron_value_in_field($iHour, $hour, 0, 23)) {
        return false;
    }
    if (!mg_cron_value_in_field($iMon, $mon, 1, 12)) {
        return false;
    }

    $domF = mg_cron_norm_field($dom);
    $dowF = mg_cron_norm_field($dow);
    if ($domF === '*' && $dowF === '*') {
        return true;
    }
    if ($domF === '*') {
        return mg_cron_dow_matches($iDow, $dow);
    }
    if ($dowF === '*') {
        return mg_cron_value_in_field($iDom, $dom, 1, 31);
    }
    return mg_cron_value_in_field($iDom, $dom, 1, 31) || mg_cron_dow_matches($iDow, $dow);
}

function mg_cron_parse_timezone(string $tz): ?DateTimeZone {
    $tz = trim($tz);
    if ($tz === '') {
        try {
            return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }
    try {
        return new DateTimeZone($tz);
    } catch (Throwable $e) {
        return null;
    }
}

function mg_cron_minute_key(DateTimeImmutable $local): string {
    return $local->format('Y-m-d H:i');
}

function mg_cron_parse_at_iso(string $iso): ?int {
    $iso = trim($iso);
    if ($iso === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}
