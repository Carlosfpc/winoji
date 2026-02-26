<?php
/**
 * Minimal SMTP mailer — no dependencies.
 * Supports STARTTLS (port 587) and plain (port 25/1025).
 */
function send_email(string $to, string $subject, string $htmlBody): bool {
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    // In development with no SMTP configured, just log and return true
    if (empty($host) || $host === 'localhost' && $port === 1025 && empty($user)) {
        error_log("[MAILER] To: $to | Subject: $subject");
        return true;
    }

    try {
        $errno = 0; $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$sock) {
            error_log("SMTP connect failed to $host:$port — $errstr");
            return false;
        }

        $read = function() use ($sock) { return fgets($sock, 512); };
        $send = function(string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

        $read(); // greeting
        $send("EHLO " . gethostname());
        while (($line = $read()) && substr($line, 3, 1) === '-') {}

        // STARTTLS for port 587
        if ($port == 587) {
            $send("STARTTLS");
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send("EHLO " . gethostname());
            while (($line = $read()) && substr($line, 3, 1) === '-') {}
        }

        // Auth LOGIN
        if ($user) {
            $send("AUTH LOGIN");
            $read();
            $send(base64_encode($user));
            $read();
            $send(base64_encode($pass));
            $read();
        }

        $boundary = md5(uniqid((string)mt_rand(), true));
        $send("MAIL FROM:<$from>");  $read();
        $send("RCPT TO:<$to>");      $read();
        $send("DATA");               $read();

        $plainText = strip_tags($htmlBody);
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n"
                 . "To: $to\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

        $body = "$headers\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
              . "$plainText\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
              . "$htmlBody\r\n"
              . "--$boundary--\r\n";

        $send($body . ".");
        $read();
        $send("QUIT");
        fclose($sock);
        return true;
    } catch (\Throwable $e) {
        error_log("Mailer error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send a notification email to a single recipient.
 * Wraps send_email() — non-fatal.
 */
function send_notification_email(string $to_email, string $to_name, string $subject, string $body_html): void {
    if (empty($to_email)) return;

    $from      = defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@localhost';
    $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Team App';

    $full_body = "<!DOCTYPE html><html><body style=\"font-family:sans-serif;color:#374151;max-width:600px;margin:0 auto;padding:1rem;\">"
               . $body_html
               . "</body></html>";

    send_email($to_email, $subject, $full_body);
}

/**
 * Look up a user by ID, build a notification email, and send it.
 * Completely non-fatal — wrapped in try/catch.
 */
function maybe_send_email_notification(int $user_id, string $type, string $entity_type, ?string $entity_title, ?string $actor_name): void {
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return;

        $type_labels = [
            'issue_created'  => 'Nueva issue creada',
            'issue_updated'  => 'Issue actualizada',
            'issue_deleted'  => 'Issue eliminada',
            'comment_added'  => 'Nuevo comentario',
            'page_created'   => 'Nueva página en Wiki',
            'page_updated'   => 'Página Wiki actualizada',
            'mention'        => 'Te han mencionado',
        ];
        $type_label = $type_labels[$type] ?? $type;

        $title   = htmlspecialchars($entity_title ?? '', ENT_QUOTES, 'UTF-8');
        $actor   = htmlspecialchars($actor_name  ?? 'Alguien', ENT_QUOTES, 'UTF-8');
        $app_url = defined('APP_URL') ? APP_URL : 'http://localhost/teamapp/public';

        $subject = "[Team App] {$type_label}" . ($entity_title ? ": {$entity_title}" : '');

        $body = "<h3 style=\"color:#34BF1F;margin-top:0;\">{$type_label}</h3>"
              . "<p><strong>{$actor}</strong> ha realizado una acción"
              . ($title ? " sobre <em>{$title}</em>" : "")
              . ".</p>"
              . "<p><a href=\"{$app_url}\" style=\"color:#34BF1F;\">Ir a Team App</a></p>"
              . "<hr style=\"border:none;border-top:1px solid #e5e7eb;margin:1rem 0;\">"
              . "<p style=\"color:#9ca3af;font-size:0.8rem;\">Notificación automática de Team App. "
              . "Este mensaje fue generado por una acción de {$actor}.</p>";

        send_notification_email($user['email'], $user['name'], $subject, $body);
    } catch (Exception $e) {
        // Non-fatal: never break main operation due to email failure
    }
}
