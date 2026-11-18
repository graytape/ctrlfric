<?php
function mailSettings(mysqli $conn): array {
    $row = $conn->query("SELECT setting_value FROM cf_app_settings WHERE setting_key='smtp'")->fetch_assoc();
    return array_merge(['host'=>'','port'=>587,'security'=>'tls','username'=>'','password'=>'','from'=>'','base_url'=>''], json_decode($row['setting_value'] ?? '{}', true) ?: []);
}
function sendRecoveryMail(array $settings, string $to, string $link): void {
    $from = $settings['from'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure a valid sender address.');
    $subject = 'Ctrl Fric - Password reset';
    $body = "Open this link to reset your password (valid for one hour):\r\n\r\n$link\r\n\r\nIf you did not request this, ignore this email.\r\n";
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    if ($settings['host'] !== '') {
        try { smtpRecoveryMail($settings, $from, $to, $subject, $body, $headers); return; }
        catch (Throwable $e) { error_log('Ctrl Fric SMTP delivery failed: '.$e->getMessage()); }
    }
    if (!function_exists('mail') || !mail($to, $subject, $body, $headers)) throw new RuntimeException('Email delivery failed.');
}
function smtpRecoveryMail(array $s, string $from, string $to, string $subject, string $body, string $headers): void {
    $socket = stream_socket_client(($s['security']==='ssl'?'ssl://':'tcp://').$s['host'].':'.(int)$s['port'], $errno, $error, 10);
    if (!$socket) throw new RuntimeException('Cannot connect to SMTP server.');
    stream_set_timeout($socket, 10);
    $expect = static function (array $codes) use ($socket) {
        do {
            $line = fgets($socket, 4096);
            if ($line === false) throw new RuntimeException('SMTP timeout or closed connection.');
            $code = (int)substr($line,0,3);
        } while (isset($line[3]) && $line[3]==='-');
        if (!in_array($code,$codes,true)) throw new RuntimeException('SMTP response '.$code);
    };
    $command = static function(string $line, array $codes) use ($socket, $expect) {
        if (strpbrk($line,"\r\n") !== false) throw new RuntimeException('Invalid SMTP command.');
        fwrite($socket,$line."\r\n"); $expect($codes);
    };
    try {
        $expect([220]); $command('EHLO localhost',[250]);
        if ($s['security']==='tls') {
            $command('STARTTLS',[220]);
            if (!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS failed.');
            $command('EHLO localhost',[250]);
        }
        if ($s['username'] !== '') {
            if ($s['security']==='none') throw new RuntimeException('SMTP authentication requires TLS.');
            $command('AUTH LOGIN',[334]); $command(base64_encode($s['username']),[334]); $command(base64_encode($s['password']),[235]);
        }
        $command('MAIL FROM:<'.$from.'>',[250]); $command('RCPT TO:<'.$to.'>',[250,251]); $command('DATA',[354]);
        $data = $headers."\r\nTo: $to\r\nSubject: $subject\r\n\r\n".$body;
        $data = preg_replace('/^\./m','..',$data);
        $remaining = $data."\r\n.\r\n";
        while ($remaining !== '') { $written = fwrite($socket,$remaining); if (!$written) throw new RuntimeException('SMTP write failed.'); $remaining = substr($remaining,$written); }
        $expect([250]);
        fwrite($socket,"QUIT\r\n");
    } finally { fclose($socket); }
}
