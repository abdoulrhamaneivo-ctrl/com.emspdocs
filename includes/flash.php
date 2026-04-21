<?php
/**
 * Messages flash EMSP
 * Usage :
 *   flash_set('success', 'Titre', 'Description', 'action_url', 'Bouton');
 *   flash_set('error', 'Titre', 'Description');
 *   flash_set('warning', 'Titre', 'Description');
 *   flash_set('info', 'Titre', 'Description');
 */
include_once __DIR__ . '/bootstrap.php';

function flash_set(
    string $type,
    string $title,
    string $message = '',
    string $action_url = '',
    string $action_label = ''
): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = compact(
        'type',
        'title',
        'message',
        'action_url',
        'action_label'
    );
}

function flash_render(): void
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return;
    }

    $rawFlashes = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $payload = [];
    foreach ($rawFlashes as $flash) {
        $payload[] = [
            'type' => (string) ($flash['type'] ?? 'info'),
            'title' => emsp_fix_mojibake((string) ($flash['title'] ?? '')),
            'message' => emsp_fix_mojibake((string) ($flash['message'] ?? '')),
            'action_url' => (string) ($flash['action_url'] ?? ''),
            'action_label' => emsp_fix_mojibake((string) ($flash['action_label'] ?? '')),
        ];
    }

    echo '<script type="application/json" id="emsp-flash-payload">'
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        . '</script>';

    echo '<noscript><div class="container mt-3">';
    foreach ($payload as $flash) {
        $type = strtolower(trim((string) ($flash['type'] ?? 'info')));
        $class = match ($type) {
            'success' => 'alert-success',
            'error', 'danger' => 'alert-danger',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };

        echo '<div class="alert ' . $class . '">';
        echo '<strong>' . h((string) ($flash['title'] ?? '')) . '</strong>';
        if (!empty($flash['message'])) {
            echo '<div class="small mt-1">' . h((string) $flash['message']) . '</div>';
        }
        if (!empty($flash['action_url']) && !empty($flash['action_label'])) {
            echo '<div class="mt-2"><a class="alert-link" href="' . h((string) $flash['action_url']) . '">'
                . h((string) $flash['action_label']) . '</a></div>';
        }
        echo '</div>';
    }
    echo '</div></noscript>';
}


