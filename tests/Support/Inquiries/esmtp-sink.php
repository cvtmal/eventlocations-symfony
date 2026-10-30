<?php

declare(strict_types=1);

/*
 * A minimal ESMTP server for tests, run in its own process by SmtpSink. Unlike Mailpit it offers
 * no extension, so no SMTPUTF8: this is the server F-M1 needs and development does not have.
 *
 * Prints `PORT <n>` once listening, and `DELIVERED <recipients>` before it acknowledges a
 * message. The first argument, if not empty, is a recipient refused with a 550. It exits after
 * a minute without a connection, so a killed test run leaves nothing behind.
 */

$refused = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0') ?: exit(1);
$name = (string) stream_socket_get_name($server, false);
fwrite(\STDOUT, 'PORT '.substr($name, (int) strrpos($name, ':') + 1)."\n");

while (false !== $client = @stream_socket_accept($server, 60)) {
    fwrite($client, "220 sink ESMTP\r\n");
    $recipients = [];
    $inData = false;

    while (false !== $line = fgets($client)) {
        $line = rtrim($line, "\r\n");

        if ($inData) {
            if ('.' === $line) {
                $inData = false;
                fwrite(\STDOUT, 'DELIVERED '.implode(',', $recipients)."\n");
                $recipients = [];
                fwrite($client, "250 Ok\r\n");
            }

            continue;
        }

        $verb = strtoupper(substr($line, 0, 4));

        if ('QUIT' === $verb) {
            fwrite($client, "221 Bye\r\n");

            break;
        }

        if ('DATA' === $verb) {
            $inData = true;
            fwrite($client, "354 Go ahead\r\n");

            continue;
        }

        if ('RCPT' === $verb) {
            $recipient = trim(substr($line, strlen('RCPT TO:')), '<> ');
            if ($recipient === $refused) {
                fwrite($client, "550 No such user\r\n");

                continue;
            }
            $recipients[] = $recipient;
        }

        fwrite($client, "250 Ok\r\n");
    }

    fclose($client);
}
