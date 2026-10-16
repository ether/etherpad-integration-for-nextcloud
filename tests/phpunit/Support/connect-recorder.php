<?php

declare(strict_types=1);

// A stand-in proxy for one connection: prints its port, then the first
// line it is asked, and refuses the tunnel.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
	fwrite(STDERR, $error . "\n");
	exit(1);
}
$address = (string)stream_socket_get_name($server, false);
echo substr($address, strrpos($address, ':') + 1), "\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 10);
if ($client === false) {
	exit(1);
}
echo trim((string)fgets($client)), "\n";
fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
fclose($client);
