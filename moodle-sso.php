<?php

/**
 * Moodle → WordPress/Laravel SSO (универсальный SSO)
 * 
 * Этот файл должен быть размещен в корневой директории Moodle:
 * /var/www/www-root/data/www/class.russianseminary.org/moodle-sso.php
 * 
 * Использование:
 * WordPress: https://class.russianseminary.org/moodle-sso.php?target=wordpress
 * Laravel:   https://class.russianseminary.org/moodle-sso.php?target=laravel
 * 
 * Пользователь должен быть авторизован в Moodle.
 */

// Загружаем конфигурацию Moodle
require_once(__DIR__ . '/config.php');

// Проверяем, что пользователь авторизован в Moodle
require_login();

// Получаем текущего пользователя Moodle
global $USER;

if (!$USER || !$USER->id) {
    redirect(new moodle_url('/login/index.php'), 'Пользователь Moodle не авторизован', null, \core\output\notification::NOTIFY_ERROR);
}

// Проверяем, что у пользователя есть email
if (empty($USER->email)) {
    redirect(new moodle_url('/'), 'У вашего аккаунта Moodle не указан email. Обратитесь к администратору.', null, \core\output\notification::NOTIFY_ERROR);
}

// Определяем целевую систему
$target = optional_param('target', 'wordpress', PARAM_ALPHA); // wordpress или laravel

// Настройки SSO для разных систем
$sso_config = [
    'wordpress' => [
        'url' => 'https://mbs.russianseminary.org',
        'api_key' => 'HsVcZKzyCFZ4NzQ0n3KqoYDfjYV9SREKxeC3yXO3uFE9LmZmCx2uMF1WRhUAlK8o',
        'endpoint' => '/wp-admin/admin-ajax.php',
        'action' => 'sso_login_from_moodle',
    ],
    'laravel' => [
        'url' => 'https://m.dekan.pro', // Замените на ваш Laravel URL
        'api_key' => 'YOUR_LARAVEL_SSO_SECRET_KEY', // Замените на ваш секретный ключ Laravel
        'endpoint' => '/api/sso/moodle',
        'action' => null, // Laravel использует другой формат
    ],
];

// Проверяем, что целевая система настроена
if (!isset($sso_config[$target])) {
    redirect(new moodle_url('/'), 'Неизвестная целевая система: ' . $target, null, \core\output\notification::NOTIFY_ERROR);
}

$config = $sso_config[$target];

if (empty($config['url']) || empty($config['api_key']) || $config['api_key'] === 'YOUR_LARAVEL_SSO_SECRET_KEY') {
    redirect(new moodle_url('/'), 'SSO для ' . $target . ' не настроен. Обратитесь к администратору.', null, \core\output\notification::NOTIFY_ERROR);
}

// Генерируем токен
$timestamp = time();
$data = $USER->id . '|' . $USER->email . '|' . $timestamp;
$token_hash = hash_hmac('sha256', $data, $config['api_key']);
$sso_token = base64_encode($USER->id . ':' . $USER->email . ':' . $timestamp . ':' . $token_hash);

// Формируем URL для перенаправления
if ($target === 'wordpress') {
    // WordPress формат
    $redirect_url = rtrim($config['url'], '/') . $config['endpoint'] . '?' . http_build_query([
        'action' => $config['action'],
        'token' => $sso_token,
        'moodle_api_key' => $config['api_key'],
    ]);
} else {
    // Laravel формат
    $redirect_url = rtrim($config['url'], '/') . $config['endpoint'] . '?' . http_build_query([
        'token' => $sso_token,
        'email' => $USER->email,
        'user_id' => $USER->id,
        'timestamp' => $timestamp,
    ]);
}

// Логируем попытку входа
error_log('Moodle SSO: Пользователь ' . $USER->email . ' (ID: ' . $USER->id . ') переходит в ' . $target);
error_log('Moodle SSO: URL редиректа: ' . $redirect_url);

// Перенаправляем
header('Location: ' . $redirect_url);
exit;
