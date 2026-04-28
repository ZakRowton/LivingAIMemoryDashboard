<?php
/**
 * Fish Audio settings storage.
 */

function fish_audio_settings_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . DIRECTORY_SEPARATOR . 'fish_audio_settings.json';
}

function fish_audio_default_settings(): array {
    return [
        'enabled' => true,
        'muted' => false,
        'autoSpeak' => true,
        'apiKey' => '8edb1a59719e49daba8a56a76318dd0e',
        'endpoint' => 'https://fishaudio.net/api/open/v2/speech/tts',
        'modelId' => 'fishaudio-s2pro',
        'voiceStyle' => 'jarvis',
        'format' => 'mp3',
        'speed' => 1.0,
        'volume' => 0.0,
        'stability' => 1.0,
        'similarity' => 1.0,
        // These are user-editable presets; replace with your preferred Fish voices.
        'voicePresets' => [
            'jarvis' => '00a1b221-6137-4b73-ad62-b0cbce134167',
            'eagle_eye' => '00a1b221-6137-4b73-ad62-b0cbce134167',
            'custom' => '',
        ],
    ];
}

function fish_audio_load_settings(): array {
    $defaults = fish_audio_default_settings();
    $path = fish_audio_settings_path();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $settings = array_merge($defaults, $decoded);
    if (!isset($settings['voicePresets']) || !is_array($settings['voicePresets'])) {
        $settings['voicePresets'] = $defaults['voicePresets'];
    } else {
        $settings['voicePresets'] = array_merge($defaults['voicePresets'], $settings['voicePresets']);
    }

    return fish_audio_sanitize_settings($settings);
}

function fish_audio_sanitize_settings(array $settings): array {
    $settings['enabled'] = !empty($settings['enabled']);
    $settings['muted'] = !empty($settings['muted']);
    $settings['autoSpeak'] = !empty($settings['autoSpeak']);
    $settings['apiKey'] = trim((string) ($settings['apiKey'] ?? ''));
    $settings['endpoint'] = trim((string) ($settings['endpoint'] ?? ''));
    $settings['modelId'] = trim((string) ($settings['modelId'] ?? 'fishaudio-s2pro'));
    $settings['voiceStyle'] = trim((string) ($settings['voiceStyle'] ?? 'jarvis'));
    $settings['format'] = strtolower(trim((string) ($settings['format'] ?? 'mp3')));
    if (!in_array($settings['format'], ['mp3', 'wav', 'ogg'], true)) {
        $settings['format'] = 'mp3';
    }
    $settings['speed'] = max(0.5, min(2.0, (float) ($settings['speed'] ?? 1.0)));
    $settings['volume'] = max(-20.0, min(20.0, (float) ($settings['volume'] ?? 0.0)));
    $settings['stability'] = max(0.5, min(1.5, (float) ($settings['stability'] ?? 1.0)));
    $settings['similarity'] = max(0.5, min(1.5, (float) ($settings['similarity'] ?? 1.0)));
    $vp = isset($settings['voicePresets']) && is_array($settings['voicePresets']) ? $settings['voicePresets'] : [];
    $settings['voicePresets'] = [
        'jarvis' => trim((string) ($vp['jarvis'] ?? '')),
        'eagle_eye' => trim((string) ($vp['eagle_eye'] ?? '')),
        'custom' => trim((string) ($vp['custom'] ?? '')),
    ];
    if (!in_array($settings['voiceStyle'], ['jarvis', 'eagle_eye', 'custom'], true)) {
        $settings['voiceStyle'] = 'jarvis';
    }

    return $settings;
}

function fish_audio_save_settings(array $partial): array {
    $current = fish_audio_load_settings();
    $next = array_merge($current, $partial);
    if (isset($partial['voicePresets']) && is_array($partial['voicePresets'])) {
        $next['voicePresets'] = array_merge($current['voicePresets'], $partial['voicePresets']);
    }
    $next = fish_audio_sanitize_settings($next);
    $ok = @file_put_contents(fish_audio_settings_path(), json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) {
        return ['error' => 'Failed to save Fish Audio settings'];
    }

    return ['ok' => true, 'settings' => $next];
}

function fish_audio_resolve_voice_id(array $settings): string {
    $style = (string) ($settings['voiceStyle'] ?? 'jarvis');
    $vp = isset($settings['voicePresets']) && is_array($settings['voicePresets']) ? $settings['voicePresets'] : [];
    $voiceId = trim((string) ($vp[$style] ?? ''));
    if ($voiceId === '' && $style !== 'custom') {
        $voiceId = trim((string) ($vp['custom'] ?? ''));
    }

    return $voiceId;
}

