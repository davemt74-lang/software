<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';

function agent_connections_admin_save_gmail_v1(PDO $pdo, string $clientId, string $secret, string $callback, bool $clear = false): void
{
    if (($clientId === '') !== ($secret === '')) {
        throw new DomainException('Enter the OAuth client ID and matching secret together.');
    }
    if ($clientId !== '' && (strlen($clientId) > 255 || !preg_match('/\A[A-Za-z0-9._-]+\.apps\.googleusercontent\.com\z/', $clientId))) {
        throw new DomainException('Enter a Google web application OAuth client ID.');
    }
    if ($secret !== '' && (strlen($secret) > 512 || !preg_match('/\A[!-~]+\z/', $secret))) {
        throw new DomainException('Enter a valid OAuth client secret.');
    }
    if ($callback !== '') {
        try {
            $callback = agent_connections_callback_v1($callback);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw new DomainException('Enter a complete HTTPS callback URL with the VP3 callback path.');
        }
    }

    $pdo->beginTransaction();
    try {
        // Serialize settings changes and keep the two OAuth credentials in one commit.
        $pdo->query("SELECT setting_key FROM settings WHERE setting_key IN ('connections_gmail_client_id', 'connections_gmail_client_secret', 'connections_gmail_redirect_uri') FOR UPDATE")->fetchAll();
        $currentClientId = (string)(agent_connection_gmail_config_v1()['client_id'] ?? '');
        if ($clear || ($clientId !== '' && $clientId !== $currentClientId)) {
            $active = $pdo->query("SELECT 1 FROM agent_connections_v1 WHERE provider='gmail' AND status<>'disconnected' LIMIT 1 FOR UPDATE");
            if ($active->fetchColumn()) {
                throw new DomainException('Disconnect existing Gmail accounts before changing the OAuth application.');
            }
        }
        if ($clear) {
            $pdo->exec("DELETE FROM settings WHERE setting_key IN ('connections_gmail_client_id','connections_gmail_client_secret')");
        } elseif ($clientId !== '') {
            $ciphertext = ai_encrypt_secret($secret);
            save_setting('connections_gmail_client_id', $clientId);
            save_setting('connections_gmail_client_secret', $ciphertext);
        }
        if (!$clear) {
            save_setting('connections_gmail_redirect_uri', $callback);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function agent_connections_admin_mask_mailbox_v1(string $email): string
{
    $parts = explode('@', $email, 2);
    return count($parts) === 2 && $parts[0] !== '' ? mb_substr($parts[0], 0, 1).'…@'.$parts[1] : '—';
}
