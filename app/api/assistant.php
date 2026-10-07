<?php
declare(strict_types=1);

// AI assistant: chat for every logged-in institution user; provider settings for administrators.
// Settings scope: the central admin edits the system default (institution 0), an institution admin
// edits their own institution (which overrides the default in multi-institution mode).

function ai_config_scope(): int
{
    $u = require_login();
    if (is_super_admin($u)) return 0;
    require_institution();
    if (!has_role('admin', null, $u)) fail('เฉพาะผู้ดูแลระบบสถานศึกษาเท่านั้น', 403);
    return (int)$u['institution_id'];
}

/** Saved settings of the scope, with unsaved form values from the settings page laid over them. */
function ai_form_config(int $scope, array $b): array
{
    $c = Assistant::config($scope);
    $savedBase = $c['base_url'];
    if (isset($b['provider']) && isset(Assistant::PROVIDERS[$b['provider']])) $c['provider'] = $b['provider'];
    if (isset($b['base_url'])) $c['base_url'] = rtrim(trim((string)$b['base_url']), '/') ?: Assistant::PROVIDERS[$c['provider']]['base_url'];
    if (isset($b['model'])) $c['model'] = trim((string)$b['model']);
    // A saved key goes only to the URL it was saved for; a new URL needs the key typed again.
    if (!empty($b['clear_key']) || $c['base_url'] !== $savedBase) $c['api_key'] = '';
    if (trim((string)($b['api_key'] ?? '')) !== '') $c['api_key'] = trim((string)$b['api_key']);
    return $c;
}

return [
    'GET config' => function () {
        $scope = ai_config_scope();
        $c = Assistant::config($scope);
        $st = db()->prepare("SELECT COUNT(*) FROM settings WHERE institution_id = ? AND skey LIKE 'ai\\_%'");
        $st->execute([$scope]);
        $own = (int)$st->fetchColumn() > 0;
        $key = $c['api_key'];
        return [
            'enabled' => $c['enabled'], 'provider' => $c['provider'], 'base_url' => $c['base_url'], 'model' => $c['model'],
            'temperature' => $c['temperature'], 'allow_write' => $c['allow_write'], 'instructions' => $c['instructions'],
            'has_key' => $key !== '', 'key_hint' => $key !== '' ? '••••' . mb_substr($key, -4) : '',
            'providers' => Assistant::PROVIDERS,
            'scope' => $scope ? 'institution' : 'system',
            // An institution in multi mode that has no settings of its own uses the system default.
            'inherited' => $scope > 0 && is_multi() && !$own,
            'can_reset' => $scope > 0 && is_multi() && $own,
            'curl' => function_exists('curl_init'),
        ];
    },

    'POST config_save' => function () {
        $scope = ai_config_scope();
        $b = body();
        $c = ai_form_config($scope, $b);
        $enabled = !empty($b['enabled']);
        if ($c['base_url'] !== '' && !preg_match('#^https?://[^/\s]+#i', $c['base_url'])) fail('URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        if ($enabled && ($c['base_url'] === '' || $c['model'] === '')) fail('การเปิดใช้งานต้องระบุ URL และชื่อโมเดล');
        $temp = (float)($b['temperature'] ?? 0.3);
        if ($temp < 0 || $temp > 2) fail('Temperature ต้องอยู่ระหว่าง 0 ถึง 2');
        $values = [
            'ai_enabled' => $enabled ? '1' : '0',
            'ai_provider' => $c['provider'],
            'ai_base_url' => mb_substr($c['base_url'], 0, 500),
            'ai_model' => mb_substr($c['model'], 0, 200),
            'ai_temperature' => (string)round($temp, 2),
            'ai_allow_write' => !empty($b['allow_write']) ? '1' : '0',
            'ai_instructions' => mb_substr(trim((string)($b['instructions'] ?? '')), 0, 4000),
        ];
        // A new URL without a new key drops the saved key (ai_form_config already blanked it).
        $keyChanged = !empty($b['clear_key']) || trim((string)($b['api_key'] ?? '')) !== '' || $c['base_url'] !== Assistant::config($scope)['base_url'];
        if ($keyChanged) $values['ai_api_key'] = Assistant::encrypt($c['api_key']);
        foreach ($values as $k => $v) set_setting($k, $v, $scope);
        unset($values['ai_api_key']);
        audit('assistant.config', 'settings', null, null, $values + ['api_key_changed' => $keyChanged, 'scope' => $scope ?: 'system']);
        return ['ok' => true];
    },

    // Drop an institution's own settings so it follows the system default again (multi mode).
    'POST config_reset' => function () {
        $scope = ai_config_scope();
        if (!$scope) fail('ค่าของระบบกลางรีเซ็ตไม่ได้');
        db()->prepare("DELETE FROM settings WHERE institution_id = ? AND skey LIKE 'ai\\_%'")->execute([$scope]);
        unset($GLOBALS['__settings']);
        audit('assistant.config_reset', 'settings', null, null, null);
        return ['ok' => true];
    },

    'POST test' => function () {
        $scope = ai_config_scope();
        $c = ai_form_config($scope, body());
        if ($c['model'] === '') fail('กรุณาระบุชื่อโมเดล');
        session_write_close();
        $t = microtime(true);
        $msg = Assistant::complete($c, [
            ['role' => 'system', 'content' => 'ตอบสั้น ๆ เป็นภาษาไทยไม่เกินหนึ่งประโยค'],
            ['role' => 'user', 'content' => 'ทดสอบการเชื่อมต่อ ช่วยแนะนำตัวสั้น ๆ'],
        ]);
        return ['ok' => true, 'reply' => trim((string)($msg['content'] ?? '')), 'ms' => (int)round((microtime(true) - $t) * 1000)];
    },

    'POST models' => function () {
        $scope = ai_config_scope();
        $c = ai_form_config($scope, body());
        session_write_close();
        return ['models' => Assistant::models($c)];
    },

    'POST chat' => function () {
        require_login();
        require_institution();
        $b = body();
        // The model may take a while: release the session lock so the rest of the app stays usable.
        session_write_close();
        @set_time_limit(300);
        $decisions = [];
        foreach ((array)($b['decisions'] ?? []) as $id => $ok) $decisions[(string)$id] = $ok === true;
        return Assistant::chat((array)($b['messages'] ?? []), $decisions, (array)($b['context'] ?? []));
    },
];
