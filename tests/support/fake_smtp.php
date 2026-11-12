<?php
/**
 * A tiny SMTP server for the mailer tests. Handles one connection at a time.
 *
 *   php fake_smtp.php <port> <mode: plain|ssl|starttls> <output dir> [user] [password]
 *
 * Every accepted message is written to <output dir>/message-N.eml and the envelope to
 * message-N.json. The file «ready» appears as soon as the server listens.
 */
list(, $port, $mode, $out) = $argv;
$user = $argv[4] ?? '';
$pass = $argv[5] ?? '';
@mkdir($out, 0700, true);

$pem = null;
if ($mode !== 'plain') {
    $cnf = $out . '/openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
    $opts = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($opts);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $opts);
    $crt = openssl_csr_sign($csr, null, $key, 2, $opts);
    openssl_x509_export($crt, $certPem);
    openssl_pkey_export($key, $keyPem, null, $opts);
    $pem = $out . '/server.pem';
    file_put_contents($pem, $certPem . $keyPem);
}

$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
file_put_contents($out . '/ready', (string) getmypid());

$count = 0;
$deadline = time() + 120;
while (time() < $deadline) {
    $conn = @stream_socket_accept($server, 2);
    if (!$conn) {
        if (is_file($out . '/stop')) {
            break;
        }
        continue;
    }
    stream_set_timeout($conn, 10);
    if ($mode === 'ssl' && !@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($conn);
        continue;
    }
    $send = function (string $line) use ($conn) {
        fwrite($conn, $line . "\r\n");
    };
    $send('220 fake.test ESMTP ready');
    $tls = $mode === 'ssl';
    $authed = $user === '';
    $from = '';
    $to = [];
    $log = [];
    while (($line = fgets($conn, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        $log[] = $line;
        $verb = strtoupper(strtok($line, ' '));
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $send('250-fake.test greets you');
            if ($mode === 'starttls' && !$tls) {
                $send('250-STARTTLS');
            }
            if ($user !== '' && ($mode === 'plain' || $tls)) {
                $send('250-AUTH LOGIN PLAIN');
            }
            $send('250 SIZE 52428800');
        } elseif ($verb === 'STARTTLS' && $mode === 'starttls' && !$tls) {
            $send('220 go ahead');
            if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                break;
            }
            $tls = true;
        } elseif ($line === 'AUTH LOGIN') {
            $send('334 VXNlcm5hbWU6');
            $u = base64_decode(rtrim((string) fgets($conn, 4096)));
            $send('334 UGFzc3dvcmQ6');
            $p = base64_decode(rtrim((string) fgets($conn, 4096)));
            if ($u === $user && $p === $pass) {
                $authed = true;
                $send('235 2.7.0 Authentication successful');
            } else {
                $send('535 5.7.8 Authentication credentials invalid');
            }
        } elseif (strncmp($line, 'AUTH PLAIN ', 11) === 0) {
            $parts = explode("\0", (string) base64_decode(substr($line, 11)));
            if (($parts[1] ?? '') === $user && ($parts[2] ?? '') === $pass) {
                $authed = true;
                $send('235 2.7.0 Authentication successful');
            } else {
                $send('535 5.7.8 Authentication credentials invalid');
            }
        } elseif (stripos($line, 'MAIL FROM:') === 0) {
            if (!$authed) {
                $send('530 5.7.0 Authentication required');
                continue;
            }
            $from = trim(substr($line, 10), '<> ');
            $send('250 OK');
        } elseif (stripos($line, 'RCPT TO:') === 0) {
            $rcpt = trim(substr($line, 8), '<> ');
            if (strpos($rcpt, 'reject') === 0) {
                $send('550 5.1.1 No such user here');
                continue;
            }
            $to[] = $rcpt;
            $send('250 OK');
        } elseif ($verb === 'DATA') {
            $send('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($conn, 8192)) !== false) {
                if ($l === ".\r\n") {
                    break;
                }
                if ($l !== '' && $l[0] === '.') {
                    $l = substr($l, 1); // undo dot-stuffing
                }
                $data .= $l;
            }
            $count++;
            file_put_contents($out . '/message-' . $count . '.eml', $data);
            file_put_contents($out . '/message-' . $count . '.json', json_encode(['from' => $from, 'to' => $to, 'tls' => $tls, 'authed' => $authed && $user !== '', 'log' => $log]));
            $send('250 OK queued');
        } elseif ($verb === 'QUIT') {
            $send('221 Bye');
            break;
        } elseif ($verb === 'RSET' || $verb === 'NOOP') {
            $send('250 OK');
        } else {
            $send('502 Command not implemented');
        }
    }
    fclose($conn);
}
fclose($server);
