<?php
declare(strict_types=1);

// Outbound mail (slice 4-C). PHP mail() driver now; external-SMTP-ready config
// keys (mail.driver=php|smtp plus host/port/user/pass/from) with a dependency-
// free SMTP client (AUTH LOGIN, STARTTLS on 587). Returns success flags instead
// of throwing — callers (cron) record done/failed jobs. Never logs secrets.
final class Mailer
{
    /** @return array{ok:bool,error:string} */
    public static function send(string $to, string $subject, string $textBody): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'destinatario inválido'];
        }
        $from = trim((string) setting('mail.from', ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'remitente no configurado (ajustes > correo)'];
        }
        if (trim($subject) === '' || trim($textBody) === '') {
            return ['ok' => false, 'error' => 'asunto o cuerpo vacío'];
        }
        $driver = strtolower(trim((string) setting('mail.driver', 'php')));
        if ($driver === 'smtp') {
            return self::sendSmtp($to, $from, $subject, $textBody);
        }
        if (!function_exists('mail')) {
            return ['ok' => false, 'error' => 'mail() no disponible'];
        }
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
        $headers = 'From: ' . $from . "\r\n"
            . 'Reply-To: ' . $from . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'X-Mailer: tienda-base';
        $sent = mail($to, (string) $encodedSubject, $textBody, $headers);
        if (!$sent) {
            error_log('Mailer: php mail() failed.');
        }
        return $sent ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'mail() falló'];
    }

    /** @return array{ok:bool,error:string} */
    private static function sendSmtp(string $to, string $from, string $subject, string $body): array
    {
        $host = trim((string) setting('mail.host', ''));
        $port = (int) setting('mail.port', '587');
        $user = (string) setting('mail.user', '');
        $pass = (string) setting('mail.pass', '');
        if ($host === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'SMTP no configurado'];
        }
        $smtpSay = static function ($socket, string $cmd, array $expect): array {
            if ($cmd !== '') {
                fwrite($socket, $cmd . "\r\n");
            }
            $line = '';
            $deadline = time() + 15;
            while (time() < $deadline) {
                $chunk = fgets($socket, 512);
                if ($chunk === false) {
                    break;
                }
                $line .= $chunk;
                if (preg_match('/^\d{3} /', $chunk)) {
                    break;
                }
            }
            $code = (int) substr($line, 0, 3);
            if (!in_array($code, $expect, true)) {
                return ['ok' => false, 'code' => $code];
            }
            return ['ok' => true, 'code' => $code];
        };
        try {
            $socket = @stream_socket_client(
                'tcp://' . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT
            );
            if ($socket === false) {
                error_log('Mailer: SMTP connect failed.');
                return ['ok' => false, 'error' => 'no se pudo conectar al SMTP'];
            }
            stream_set_timeout($socket, 15);
            $steps = [
                ['', [220]],
                ['EHLO ' . $host, [250]],
            ];
            if ($port === 587) {
                $steps[] = ['STARTTLS', [220]];
            }
            foreach ($steps as [$cmd, $expect]) {
                $res = $smtpSay($socket, $cmd, $expect);
                if (!$res['ok']) {
                    fclose($socket);
                    error_log('Mailer: SMTP greeting failed.');
                    return ['ok' => false, 'error' => 'SMTP no respondió'];
                }
            }
            if ($port === 587) {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return ['ok' => false, 'error' => 'no se pudo iniciar TLS'];
                }
                $res = $smtpSay($socket, 'EHLO ' . $host, [250]);
                if (!$res['ok']) {
                    fclose($socket);
                    return ['ok' => false, 'error' => 'SMTP no respondió tras TLS'];
                }
            }
            if ($user !== '') {
                $res = $smtpSay($socket, 'AUTH LOGIN', [334]);
                $okAuth = $res['ok']
                    && $smtpSay($socket, base64_encode($user), [334])['ok']
                    && $smtpSay($socket, base64_encode($pass), [235])['ok'];
                if (!$okAuth) {
                    fclose($socket);
                    error_log('Mailer: SMTP auth failed.');
                    return ['ok' => false, 'error' => 'autenticación SMTP falló'];
                }
            }
            $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
            $data = 'From: ' . $from . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $encodedSubject . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                . "\r\n" . $body;
            $flow = [
                ['MAIL FROM:<' . $from . '>', [250]],
                ['RCPT TO:<' . $to . '>', [250, 251]],
                ['DATA', [354]],
                [$data . "\r\n.", [250]],
                ['QUIT', [221]],
            ];
            foreach ($flow as [$cmd, $expect]) {
                $res = $smtpSay($socket, $cmd, $expect);
                if (!$res['ok']) {
                    fclose($socket);
                    error_log('Mailer: SMTP send failed.');
                    return ['ok' => false, 'error' => 'envío SMTP falló'];
                }
            }
            fclose($socket);
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            error_log('Mailer: SMTP exception.');
            return ['ok' => false, 'error' => 'excepción SMTP'];
        }
    }
}
