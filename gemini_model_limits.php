<?php
/**
 * Gemini API quota hints (RPM / TPM / RPD) from Google AI Studio style tables (free / paid).
 * UI meters use local counters + these caps. Rows here are models with free RPD > 0, paid RPD > 0,
 * or unlimited RPD per the user's table (0/0-only models are omitted).
 */

/**
 * @return array<string, array{rpm_free:int,rpm_paid:int,tpm_free:int,tpm_paid:?int,tpm_unlimited?:bool,rpd_free:int,rpd_paid:?int,rpd_unlimited?:bool}>
 */
function memory_graph_gemini_model_limits_table(): array {
    return [
        'gemini-3-flash' => ['rpm_free' => 3, 'rpm_paid' => 5, 'tpm_free' => 1430, 'tpm_paid' => 250000, 'rpd_free' => 6, 'rpd_paid' => 20],
        'gemini-2.5-flash' => ['rpm_free' => 2, 'rpm_paid' => 5, 'tpm_free' => 1430, 'tpm_paid' => 250000, 'rpd_free' => 3, 'rpd_paid' => 20],
        'gemini-3-flash-preview' => ['rpm_free' => 3, 'rpm_paid' => 5, 'tpm_free' => 1430, 'tpm_paid' => 250000, 'rpd_free' => 6, 'rpd_paid' => 20],
        'gemini-2.5-flash-preview-tts' => ['rpm_free' => 0, 'rpm_paid' => 3, 'tpm_free' => 0, 'tpm_paid' => 10000, 'rpd_free' => 0, 'rpd_paid' => 10],
        'gemini-2.5-flash-lite' => ['rpm_free' => 0, 'rpm_paid' => 10, 'tpm_free' => 0, 'tpm_paid' => 250000, 'rpd_free' => 0, 'rpd_paid' => 20],
        'gemini-3.1-flash-lite-preview' => ['rpm_free' => 0, 'rpm_paid' => 15, 'tpm_free' => 0, 'tpm_paid' => 250000, 'rpd_free' => 0, 'rpd_paid' => 500],
        'gemini-3.1-flash-preview' => ['rpm_free' => 0, 'rpm_paid' => 15, 'tpm_free' => 0, 'tpm_paid' => 250000, 'rpd_free' => 0, 'rpd_paid' => 500],
        'gemini-3.1-flash-tts-preview' => ['rpm_free' => 0, 'rpm_paid' => 3, 'tpm_free' => 0, 'tpm_paid' => 10000, 'rpd_free' => 0, 'rpd_paid' => 10],
        'gemini-2.5-flash-native-audio-preview-12-2025' => ['rpm_free' => 0, 'rpm_paid' => 0, 'tpm_free' => 0, 'tpm_paid' => 1000000, 'tpm_unlimited' => true, 'rpd_free' => 0, 'rpd_paid' => null, 'rpd_unlimited' => true],
        'gemini-3.1-flash-live-preview' => ['rpm_free' => 0, 'rpm_paid' => 0, 'tpm_free' => 0, 'tpm_paid' => 65000, 'rpd_free' => 0, 'rpd_paid' => null, 'rpd_unlimited' => true],
        'gemma-3-1b-it' => ['rpm_free' => 0, 'rpm_paid' => 30, 'tpm_free' => 0, 'tpm_paid' => 15000, 'rpd_free' => 0, 'rpd_paid' => 14400],
        'gemma-3-2b-it' => ['rpm_free' => 0, 'rpm_paid' => 30, 'tpm_free' => 0, 'tpm_paid' => 15000, 'rpd_free' => 0, 'rpd_paid' => 14400],
        'gemma-3-4b-it' => ['rpm_free' => 0, 'rpm_paid' => 30, 'tpm_free' => 0, 'tpm_paid' => 15000, 'rpd_free' => 0, 'rpd_paid' => 14400],
        'gemma-3-12b-it' => ['rpm_free' => 0, 'rpm_paid' => 30, 'tpm_free' => 0, 'tpm_paid' => 15000, 'rpd_free' => 0, 'rpd_paid' => 14400],
        'gemma-3-27b-it' => ['rpm_free' => 0, 'rpm_paid' => 30, 'tpm_free' => 0, 'tpm_paid' => 15000, 'rpd_free' => 0, 'rpd_paid' => 14400],
        'gemma-2-27b-it' => ['rpm_free' => 0, 'rpm_paid' => 15, 'tpm_free' => 0, 'tpm_paid' => null, 'tpm_unlimited' => true, 'rpd_free' => 0, 'rpd_paid' => 1500],
        'gemma-2-9b-it' => ['rpm_free' => 0, 'rpm_paid' => 15, 'tpm_free' => 0, 'tpm_paid' => null, 'tpm_unlimited' => true, 'rpd_free' => 0, 'rpd_paid' => 1500],
        'imagen-4.0-generate-001' => ['rpm_free' => 0, 'rpm_paid' => 0, 'tpm_free' => 0, 'tpm_paid' => null, 'rpd_free' => 0, 'rpd_paid' => 25],
        'imagen-4.0-ultra-generate-001' => ['rpm_free' => 0, 'rpm_paid' => 0, 'tpm_free' => 0, 'tpm_paid' => null, 'rpd_free' => 0, 'rpd_paid' => 25],
        'imagen-4.0-fast-generate-001' => ['rpm_free' => 0, 'rpm_paid' => 0, 'tpm_free' => 0, 'tpm_paid' => null, 'rpd_free' => 0, 'rpd_paid' => 25],
        'gemini-embedding-001' => ['rpm_free' => 0, 'rpm_paid' => 100, 'tpm_free' => 0, 'tpm_paid' => 30000, 'rpd_free' => 0, 'rpd_paid' => 1000],
        'gemini-embedding-2' => ['rpm_free' => 0, 'rpm_paid' => 100, 'tpm_free' => 0, 'tpm_paid' => 30000, 'rpd_free' => 0, 'rpd_paid' => 1000],
        'gemini-robotics-er-1.5-preview' => ['rpm_free' => 0, 'rpm_paid' => 10, 'tpm_free' => 0, 'tpm_paid' => 250000, 'rpd_free' => 0, 'rpd_paid' => 20],
        'gemini-robotics-er-1.6-preview' => ['rpm_free' => 0, 'rpm_paid' => 5, 'tpm_free' => 0, 'tpm_paid' => 250000, 'rpd_free' => 0, 'rpd_paid' => 20],
    ];
}

/** Model ids for the Gemini provider dropdown (same keys as limits table). */
function memory_graph_gemini_model_ids_for_builtin_ui(): array {
    $ids = array_keys(memory_graph_gemini_model_limits_table());
    usort($ids, static function (string $a, string $b): int {
        $rank = static function (string $m): int {
            if (strpos($m, 'gemini-3-flash') === 0 && strpos($m, 'preview') === false) {
                return 0;
            }
            if ($m === 'gemini-2.5-flash') {
                return 1;
            }
            if (strpos($m, 'gemini-3') === 0) {
                return 2;
            }
            if (strpos($m, 'gemini-2.5') === 0) {
                return 3;
            }
            if (strpos($m, 'gemini-embedding') === 0) {
                return 4;
            }
            if (strpos($m, 'gemma-') === 0) {
                return 5;
            }
            if (strpos($m, 'imagen-') === 0) {
                return 6;
            }

            return 10;
        };

        return $rank($a) <=> $rank($b) ?: strcmp($a, $b);
    });

    return $ids;
}

/** @return array<string, mixed>|null */
function memory_graph_gemini_limits_for_model(string $modelId): ?array {
    $t = memory_graph_gemini_model_limits_table();

    return $t[$modelId] ?? null;
}

/**
 * Parse quota-adjacent response headers from generativelanguage.googleapis.com (rare; best-effort).
 *
 * @return array<string, string>
 */
function memory_graph_parse_gemini_response_headers(string $rawHeaderBlob): array {
    $out = [];
    if ($rawHeaderBlob === '') {
        return $out;
    }
    foreach (preg_split("/\r\n|\n|\r/", $rawHeaderBlob) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $lk = strtolower(trim($k));
        $v = trim($v);
        if ($lk === '' || $v === '') {
            continue;
        }
        if (strpos($lk, 'goog') !== false || strpos($lk, 'quota') !== false || strpos($lk, 'ratelimit') !== false) {
            $out[$lk] = $v;
        }
    }

    return $out;
}
