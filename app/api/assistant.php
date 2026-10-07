<?php
declare(strict_types=1);

// AI assistant: chat for every logged-in institution user; API connections for the institution admin.
// Every institution has its own connections (also in multi-institution mode) — nothing is shared.

function ai_admin(): array
{
    require_institution();
    return require_role('admin');
}

/**
 * Connection values for test / model listing: the saved connection (if id given) with the unsaved
 * form values laid over it. A saved key goes only to the URL it was saved for.
 */
function ai_form_connection(array $b): array
{
    $c = !empty($b['id']) ? Assistant::connection((int)$b['id']) : ['provider' => 'custom', 'base_url' => '', 'api_key' => '', 'temperature' => 0.3];
    $savedBase = $c['base_url'];
    if (isset($b['provider']) && isset(Assistant::PROVIDERS[$b['provider']])) $c['provider'] = $b['provider'];
    if (isset($b['base_url'])) $c['base_url'] = rtrim(trim((string)$b['base_url']), '/') ?: Assistant::PROVIDERS[$c['provider']]['base_url'];
    if (!empty($b['clear_key']) || $c['base_url'] !== $savedBase) $c['api_key'] = '';
    if (trim((string)($b['api_key'] ?? '')) !== '') $c['api_key'] = trim((string)$b['api_key']);
    if (isset($b['temperature'])) $c['temperature'] = (float)$b['temperature'];
    if ($c['base_url'] === '') fail('กรุณาระบุ Base URL');
    return $c;
}

return [
    'GET connections' => function () {
        ai_admin();
        return ['connections' => Assistant::connections(), 'options' => Assistant::options(), 'providers' => Assistant::PROVIDERS,
            'curl' => function_exists('curl_init')];
    },

    'POST connection_save' => function () {
        ai_admin();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $before = $id ? Assistant::connection($id) : null;
        $c = ai_form_connection($b);
        if (!preg_match('#^https?://[^/\s]+#i', $c['base_url'])) fail('URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 100);
        if ($name === '') fail('กรุณาตั้งชื่อการเชื่อมต่อ');
        $models = [];
        foreach ((array)($b['models'] ?? []) as $m) {
            $m = mb_substr(trim((string)$m), 0, 200);
            if ($m !== '' && !in_array($m, $models, true)) $models[] = $m;
        }
        $enabled = !empty($b['enabled']);
        if ($enabled && !$models) fail('การเปิดใช้งานต้องเลือกโมเดลอย่างน้อย 1 โมเดล');
        $default = (string)($b['default_model'] ?? '');
        if (!in_array($default, $models, true)) $default = $models[0] ?? '';
        $temp = (float)($b['temperature'] ?? 0.3);
        if ($temp < 0 || $temp > 2) fail('Temperature ต้องอยู่ระหว่าง 0 ถึง 2');
        $vals = [$name, $c['provider'], mb_substr($c['base_url'], 0, 500), $enabled ? 1 : 0, json_encode($models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $default ?: null, round($temp, 2), (int)($b['sort'] ?? 0)];
        // The key changes when typed, cleared, or when the URL moved (ai_form_connection blanked it then).
        $keyChanged = !$before || !empty($b['clear_key']) || trim((string)($b['api_key'] ?? '')) !== '' || $c['base_url'] !== $before['base_url'];
        $pdo = db();
        if ($id) {
            $pdo->prepare('UPDATE ai_connections SET name = ?, provider = ?, base_url = ?, enabled = ?, models = ?, default_model = ?, temperature = ?, sort = ?'
                . ($keyChanged ? ', api_key = ?' : '') . ' WHERE id = ? AND institution_id = ?')
                ->execute(array_merge($vals, $keyChanged ? [Assistant::encrypt($c['api_key'])] : [], [$id, current_institution_id()]));
        } else {
            $pdo->prepare('INSERT INTO ai_connections (name, provider, base_url, enabled, models, default_model, temperature, sort, api_key, institution_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [Assistant::encrypt($c['api_key']), current_institution_id()]));
            $id = (int)$pdo->lastInsertId();
        }
        unset($before['api_key']);
        audit($before ? 'assistant.connection_update' : 'assistant.connection_create', 'ai_connection', $id, $before,
            ['name' => $name, 'provider' => $c['provider'], 'base_url' => $c['base_url'], 'enabled' => $enabled, 'models' => $models, 'api_key_changed' => $keyChanged]);
        return ['ok' => true, 'id' => $id];
    },

    'POST connection_delete' => function () {
        ai_admin();
        $id = (int)(body()['id'] ?? 0);
        $before = Assistant::connection($id, false);
        db()->prepare('DELETE FROM ai_connections WHERE id = ? AND institution_id = ?')->execute([$id, current_institution_id()]);
        audit('assistant.connection_delete', 'ai_connection', $id, $before, null);
        return ['ok' => true];
    },

    // Test the connection by listing the models it offers (GET {base}/models).
    'POST connection_test' => function () {
        ai_admin();
        $c = ai_form_connection(body());
        session_write_close();
        $t = microtime(true);
        $models = Assistant::models($c);
        return ['ok' => true, 'models' => $models, 'ms' => (int)round((microtime(true) - $t) * 1000)];
    },

    // Ask one model a short question: does it answer, and does it accept tools?
    'POST model_test' => function () {
        ai_admin();
        $b = body();
        $c = ai_form_connection($b);
        $c['model'] = trim((string)($b['model'] ?? ''));
        if ($c['model'] === '') fail('กรุณาเลือกโมเดล');
        session_write_close();
        $t = microtime(true);
        $msg = Assistant::complete($c, [
            ['role' => 'system', 'content' => 'ตอบสั้น ๆ เป็นภาษาไทยไม่เกินหนึ่งประโยค'],
            ['role' => 'user', 'content' => 'ทดสอบการเชื่อมต่อ ช่วยแนะนำตัวสั้น ๆ'],
        ], Assistant::schema(array_intersect_key(Assistant::toolsFor(request_fy(), false), ['get_dashboard' => 1])));
        return ['ok' => true, 'reply' => trim((string)($msg['content'] ?? '')) ?: (!empty($msg['tool_calls']) ? '(เรียกเครื่องมือได้)' : ''),
            'ms' => (int)round((microtime(true) - $t) * 1000)];
    },

    'POST options_save' => function () {
        ai_admin();
        $b = body();
        $vals = ['ai_allow_write' => !empty($b['allow_write']) ? '1' : '0', 'ai_instructions' => mb_substr(trim((string)($b['instructions'] ?? '')), 0, 4000)];
        foreach ($vals as $k => $v) set_setting($k, $v);
        audit('assistant.options', 'settings', null, null, $vals);
        return ['ok' => true];
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
        return Assistant::chat((array)($b['messages'] ?? []), $decisions, (array)($b['context'] ?? []),
            !empty($b['connection_id']) ? (int)$b['connection_id'] : null, isset($b['model']) ? (string)$b['model'] : null);
    },
];
