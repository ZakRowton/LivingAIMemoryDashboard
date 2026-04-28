<?php
/**
 * Groq published rate limits (RPM, RPD, TPM, TPD, ASH, ASD). Dash (—) → null.
 * TPD varies per model; used for UI caps and local daily-token estimates.
 */

function memory_graph_groq_model_limits_table(): array {
    return [
        'allam-2-7b' => ['rpm' => 30, 'rpd' => 7000, 'tpm' => 6000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'canopylabs/orpheus-arabic-saudi' => ['rpm' => 10, 'rpd' => 100, 'tpm' => 1200, 'tpd' => 3600, 'ash' => null, 'asd' => null],
        'canopylabs/orpheus-v1-english' => ['rpm' => 10, 'rpd' => 100, 'tpm' => 1200, 'tpd' => 3600, 'ash' => null, 'asd' => null],
        'groq/compound' => ['rpm' => 30, 'rpd' => 250, 'tpm' => 70000, 'tpd' => null, 'ash' => null, 'asd' => null],
        'groq/compound-mini' => ['rpm' => 30, 'rpd' => 250, 'tpm' => 70000, 'tpd' => null, 'ash' => null, 'asd' => null],
        'llama-3.1-8b-instant' => ['rpm' => 30, 'rpd' => 14400, 'tpm' => 6000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'llama-3.3-70b-versatile' => ['rpm' => 30, 'rpd' => 1000, 'tpm' => 12000, 'tpd' => 100000, 'ash' => null, 'asd' => null],
        'meta-llama/llama-4-scout-17b-16e-instruct' => ['rpm' => 30, 'rpd' => 1000, 'tpm' => 30000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'meta-llama/llama-prompt-guard-2-22m' => ['rpm' => 30, 'rpd' => 14400, 'tpm' => 15000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'meta-llama/llama-prompt-guard-2-86m' => ['rpm' => 30, 'rpd' => 14400, 'tpm' => 15000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'openai/gpt-oss-120b' => ['rpm' => 30, 'rpd' => 1000, 'tpm' => 8000, 'tpd' => 200000, 'ash' => null, 'asd' => null],
        'openai/gpt-oss-20b' => ['rpm' => 30, 'rpd' => 1000, 'tpm' => 8000, 'tpd' => 200000, 'ash' => null, 'asd' => null],
        'openai/gpt-oss-safeguard-20b' => ['rpm' => 30, 'rpd' => 1000, 'tpm' => 8000, 'tpd' => 200000, 'ash' => null, 'asd' => null],
        'qwen/qwen3-32b' => ['rpm' => 60, 'rpd' => 1000, 'tpm' => 6000, 'tpd' => 500000, 'ash' => null, 'asd' => null],
        'whisper-large-v3' => ['rpm' => 20, 'rpd' => 2000, 'tpm' => null, 'tpd' => null, 'ash' => 7200, 'asd' => 28800],
        'whisper-large-v3-turbo' => ['rpm' => 20, 'rpd' => 2000, 'tpm' => null, 'tpd' => null, 'ash' => 7200, 'asd' => 28800],
    ];
}

/** @return array<string, mixed>|null */
function memory_graph_groq_limits_for_model(string $modelId): ?array {
    $t = memory_graph_groq_model_limits_table();
    return $t[$modelId] ?? null;
}
