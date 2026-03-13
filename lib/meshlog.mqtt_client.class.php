<?php

class MeshLogMqttClient {
    const READ_BUFFER_SIZE = 4096;
    const POLL_SLEEP_MICROSECONDS = 50000;

    private $config;
    private $socket = null;
    private $buffer = '';
    private $connected = false;
    private $websocket = false;
    private $timeout = 5;

    public function __construct($config) {
        $this->config = $config;
        $this->timeout = intval($this->config['timeout'] ?? 5);
        if ($this->timeout < 1) $this->timeout = 5;
    }

    public function connect() {
        $transport = strtolower($this->config['transport'] ?? 'tcp');
        $host = $this->config['host'] ?? '';
        $port = intval($this->config['port'] ?? 1883);
        if (!$host) {
            throw new RuntimeException("MQTT host missing");
        }

        $isSecure = in_array($transport, array('ssl', 'tls', 'wss'));
        $scheme = $isSecure ? 'ssl' : 'tcp';
        $ctx = stream_context_create();
        if ($isSecure) {
            stream_context_set_option($ctx, 'ssl', 'verify_peer', $this->config['verify_peer'] ?? false);
            stream_context_set_option($ctx, 'ssl', 'verify_peer_name', $this->config['verify_peer_name'] ?? false);
            stream_context_set_option($ctx, 'ssl', 'allow_self_signed', $this->config['allow_self_signed'] ?? true);
        }

        $this->socket = stream_socket_client(
            "$scheme://$host:$port",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$this->socket) {
            throw new RuntimeException("MQTT connect failed: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $this->websocket = in_array($transport, array('ws', 'wss', 'websocket'));
        if ($this->websocket) {
            $this->wsHandshake();
        }

        $this->sendConnect();
        $packetType = $this->readPacketType();
        if ($packetType != 2) {
            throw new RuntimeException("Expected CONNACK, got type $packetType");
        }
        $payload = $this->readPacketPayload();
        if (strlen($payload) < 2 || ord($payload[1]) !== 0) {
            throw new RuntimeException("MQTT CONNACK failed");
        }

        $topic = $this->config['topic'] ?? 'meshcore/+/+/packets';
        $this->sendSubscribe($topic);
        $packetType = $this->readPacketType();
        if ($packetType != 9) {
            throw new RuntimeException("Expected SUBACK, got type $packetType");
        }
        $this->readPacketPayload();
        $this->connected = true;
    }

    public function loop($onMessage) {
        if (!$this->connected) {
            throw new RuntimeException("MQTT not connected");
        }

        $keepalive = intval($this->config['keepalive'] ?? 30);
        $lastPing = time();

        while (!feof($this->socket)) {
            $headerByte = $this->readPacketHeaderByte(1);
            if ($headerByte === null) {
                if ((time() - $lastPing) >= $keepalive) {
                    $this->sendRaw(chr(0xC0) . chr(0x00));
                    $lastPing = time();
                }
                continue;
            }
            $packetType = ($headerByte >> 4) & 0x0F;
            $flags = $headerByte & 0x0F;

            $payload = $this->readPacketPayload();
            $lastPing = time();

            if ($packetType == 3) {
                $this->handlePublish($payload, $flags, $onMessage);
            } else if ($packetType == 13) {
                // PINGRESP
            }
        }
    }

    private function handlePublish($payload, $flags, $onMessage) {
        if (strlen($payload) < 2) return;
        $topicLen = unpack('n', substr($payload, 0, 2))[1];
        $offset = 2;
        if (strlen($payload) < ($offset + $topicLen)) return;
        $topic = substr($payload, $offset, $topicLen);
        $offset += $topicLen;

        $qos = ($flags >> 1) & 0x03;
        if ($qos > 0) {
            if (strlen($payload) < ($offset + 2)) return;
            $offset += 2;
        }

        $message = substr($payload, $offset);
        $onMessage($topic, $message);
    }

    private function sendConnect() {
        $clientId = $this->config['client_id'] ?? ('meshlog_' . substr(md5(uniqid('', true)), 0, 8));
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $keepalive = intval($this->config['keepalive'] ?? 30);

        $flags = 0x02; // clean session
        $payload = $this->encodeString($clientId);
        if ($username !== '') {
            $flags |= 0x80;
            $payload .= $this->encodeString($username);
        }
        if ($password !== '') {
            $flags |= 0x40;
            $payload .= $this->encodeString($password);
        }

        $variable = $this->encodeString('MQTT') . chr(0x04) . chr($flags) . pack('n', $keepalive);
        $packet = chr(0x10) . $this->encodeLength(strlen($variable) + strlen($payload)) . $variable . $payload;
        $this->sendRaw($packet);
    }

    private function sendSubscribe($topic) {
        $qos = intval($this->config['qos'] ?? 0);
        $packetId = 1;
        $payload = $this->encodeString($topic) . chr($qos);
        $variable = pack('n', $packetId);
        $packet = chr(0x82) . $this->encodeLength(strlen($variable) + strlen($payload)) . $variable . $payload;
        $this->sendRaw($packet);
    }

    private function readPacketHeaderByte($timeoutSeconds = null) {
        if ($timeoutSeconds === null) $timeoutSeconds = $this->timeout;
        $byte = $this->readByte($timeoutSeconds);
        if ($byte === null) return null;
        return ord($byte);
    }

    private function readPacketPayload() {
        $remainingLength = $this->decodeLength();
        if ($remainingLength <= 0) return '';
        return $this->readExact($remainingLength);
    }

    private function decodeLength() {
        $multiplier = 1;
        $value = 0;
        do {
            $encodedByte = ord($this->readExact(1));
            $value += ($encodedByte & 127) * $multiplier;
            $multiplier *= 128;
        } while (($encodedByte & 128) != 0);
        return $value;
    }

    private function encodeLength($length) {
        $out = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) $digit |= 0x80;
            $out .= chr($digit);
        } while ($length > 0);
        return $out;
    }

    private function encodeString($value) {
        return pack('n', strlen($value)) . $value;
    }

    private function readByte($timeoutSeconds = null) {
        if ($timeoutSeconds === null) $timeoutSeconds = $this->timeout;
        $data = $this->readFromBuffer(1, $timeoutSeconds);
        if ($data === '') return null;
        return $data;
    }

    private function readExact($len) {
        $data = $this->readFromBuffer($len, $this->timeout);
        if (strlen($data) !== $len) {
            throw new RuntimeException("MQTT read timeout");
        }
        return $data;
    }

    private function readFromBuffer($len, $timeoutSeconds) {
        $start = time();
        while (strlen($this->buffer) < $len) {
            if ((time() - $start) >= $timeoutSeconds) {
                break;
            }

            $chunk = $this->websocket ? $this->readWebSocketFrame() : fread($this->socket, static::READ_BUFFER_SIZE);
            if ($chunk === false || $chunk === '') {
                usleep(static::POLL_SLEEP_MICROSECONDS);
                continue;
            }
            $this->buffer .= $chunk;
        }

        $data = substr($this->buffer, 0, $len);
        $this->buffer = substr($this->buffer, $len);
        return $data;
    }

    private function sendRaw($bytes) {
        if ($this->websocket) {
            $bytes = $this->encodeWebSocketFrame($bytes);
        }
        fwrite($this->socket, $bytes);
    }

    private function wsHandshake() {
        $host = $this->config['host'];
        $path = $this->config['path'] ?? '/mqtt';
        $key = base64_encode(random_bytes(16));

        $headers = "GET $path HTTP/1.1\r\n";
        $headers .= "Host: $host\r\n";
        $headers .= "Upgrade: websocket\r\n";
        $headers .= "Connection: Upgrade\r\n";
        $headers .= "Sec-WebSocket-Key: $key\r\n";
        $headers .= "Sec-WebSocket-Version: 13\r\n";
        $headers .= "Sec-WebSocket-Protocol: mqtt\r\n\r\n";

        fwrite($this->socket, $headers);

        $response = '';
        while (strpos($response, "\r\n\r\n") === false) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
        }

        if (strpos($response, ' 101 ') === false) {
            throw new RuntimeException("WebSocket upgrade failed: $response");
        }
    }

    private function encodeWebSocketFrame($payload) {
        $len = strlen($payload);
        $frame = chr(0x82);
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } else if ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        return $frame;
    }

    private function readWebSocketFrame() {
        $h = fread($this->socket, 2);
        if (!$h || strlen($h) < 2) return '';
        $b1 = ord($h[0]);
        $b2 = ord($h[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) != 0;
        $len = $b2 & 0x7F;

        if ($len == 126) {
            $len = unpack('n', fread($this->socket, 2))[1];
        } else if ($len == 127) {
            $l = unpack('N2', fread($this->socket, 8));
            $len = $l[2];
        }

        $mask = $masked ? fread($this->socket, 4) : '';
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->socket, $len - strlen($payload));
            if ($chunk === false || $chunk === '') break;
            $payload .= $chunk;
        }

        if ($masked && strlen($mask) == 4) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        if ($opcode == 0x8) {
            $this->connected = false;
            fclose($this->socket);
            $this->socket = null;
            return '';
        }

        return $payload;
    }
}

?>
