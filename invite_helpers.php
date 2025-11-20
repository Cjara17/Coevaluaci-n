<?php

function invite_credentials_path(): string
{
    return __DIR__ . '/tools/invite_credentials.json';
}

function load_invite_credentials(): array
{
    $path = invite_credentials_path();
    if (!file_exists($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function save_invite_credentials(array $data): void
{
    $path = invite_credentials_path();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $json);
}

function upsert_invite_credential(int $user_id, string $username, ?string $password = null): void
{
    $data = load_invite_credentials();
    if (!isset($data[$user_id])) {
        $data[$user_id] = [];
    }
    $data[$user_id]['username'] = $username;
    if ($password !== null) {
        $data[$user_id]['password'] = $password;
    } elseif (!isset($data[$user_id]['password'])) {
        $data[$user_id]['password'] = '';
    }
    save_invite_credentials($data);
}

function remove_invite_credential(int $user_id): void
{
    $data = load_invite_credentials();
    if (isset($data[$user_id])) {
        unset($data[$user_id]);
        save_invite_credentials($data);
    }
}

