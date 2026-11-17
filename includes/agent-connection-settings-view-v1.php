<?php
declare(strict_types=1);

const VP3_CONNECTION_SETTINGS_APPS_V1 = ['gmail', 'youtube', 'research', 'transcripts', 'music'];

function vp3_connection_settings_app_v1(mixed $app, array $allowed = VP3_CONNECTION_SETTINGS_APPS_V1): string
{
    return is_string($app) && in_array($app, VP3_CONNECTION_SETTINGS_APPS_V1, true) && in_array($app, $allowed, true) ? $app : '';
}

function vp3_connection_settings_private_v1(array $user): bool
{
    return (int)$user['id'] > 0 && has_permission('chat.access', $user)
        && (!function_exists('vp3_participant_scope_v1') || vp3_participant_scope_v1() === null);
}

function vp3_connection_settings_post_allowed_v1(?array $user): bool
{
    if (!array_key_exists('settings_app', $_POST)) return true;
    return $user && vp3_connection_settings_app_v1($_POST['settings_app']) !== ''
        && ($_POST['settings_owner_user_id'] ?? null) === (string)$user['id']
        && vp3_connection_settings_private_v1($user);
}

/** This presentation choice cannot redirect outside the application's Connections page. */
function vp3_connection_settings_return_v1(mixed $app, array $allowed, string $defaultPath, array $query, string $section = ''): string
{
    $app = vp3_connection_settings_app_v1($app, $allowed);
    if ($app === '') return url($defaultPath);
    $path = '/connections.php?' . http_build_query(['app'=>$app, 'view'=>'specialist-settings'] + $query, '', '&', PHP_QUERY_RFC3986);
    return url($path . ($section === '' ? '' : '#'.$section));
}

function vp3_connection_settings_post_return_v1(array $allowed, string $defaultPath, array $query, string $section = ''): string
{
    $owner = $_POST['settings_owner_user_id'] ?? null;
    if (is_string($owner) && preg_match('/\A[1-9]\d*\z/', $owner)) $query['owner_user_id'] = $owner;
    return vp3_connection_settings_return_v1($_POST['settings_app'] ?? null, $allowed, $defaultPath, $query, $section);
}
