<?php

/** A valid device exception is distinct from a malformed or incomplete frame. */
class LuxtronikModbusException extends RuntimeException
{
}

/** Modbus TCP transport: FC02/03/04 reads and FC16 holding-register writes. */
final class LuxtronikModbusTCP
{
    private mixed $connection = null;
    private int $transaction = 0;

    public function __construct(private string $ip, private int $port, private int $unit, private int $timeoutMs)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $port < 1 || $port > 65535 || $unit < 1 || $unit > 247
            || $timeoutMs < 100 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('Ungültige SHI-Verbindungseinstellungen.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->connection)) fclose($this->connection);
        $this->connection = null;
    }

    public function readInputRegisters(int $address, int $count): array
    {
        return $this->readRegisters(4, $address, $count);
    }

    public function readHoldingRegisters(int $address, int $count): array
    {
        return $this->readRegisters(3, $address, $count);
    }

    public function readDiscreteInputs(int $address, int $count): array
    {
        $this->validateRange($address, $count, 2000);
        $pdu = $this->exchange(2, pack('nn', $address, $count));
        $bytes = (int)ceil($count / 8);
        if (strlen($pdu) !== 2 + $bytes || ord($pdu[1]) !== $bytes) {
            $this->invalidReply();
        }
        $values = [];
        for ($i = 0; $i < $count; ++$i) $values[] = (ord($pdu[2 + intdiv($i, 8)]) >> ($i % 8)) & 1;
        return $values;
    }

    private function readRegisters(int $function, int $address, int $count): array
    {
        $this->validateRange($address, $count, 125);
        $pdu = $this->exchange($function, pack('nn', $address, $count));
        if (strlen($pdu) !== 2 + $count * 2 || ord($pdu[1]) !== $count * 2) $this->invalidReply();
        return array_values(unpack('n*', substr($pdu, 2)));
    }

    /** The caller must whitelist addresses, ranges and dependencies before calling. */
    public function writeHoldingRegisters(int $address, array $words): void
    {
        $this->validateRange($address, count($words), 123);
        foreach ($words as $word) {
            if (!is_int($word) || $word < 0 || $word > 65535) throw new InvalidArgumentException('Ungültiges Modbus-Datenwort.');
        }
        $pdu = $this->exchange(16, pack('nnC', $address, count($words), count($words) * 2) . pack('n*', ...$words));
        if ($pdu !== pack('Cnn', 16, $address, count($words))) $this->invalidReply();
    }

    private function validateRange(int $address, int $count, int $max): void
    {
        if ($address < 0 || $address > 65535 || $count < 1 || $count > $max || $address + $count > 65536) {
            throw new InvalidArgumentException('Ungültiger Modbus-Registerbereich.');
        }
    }

    private function invalidReply(): never
    {
        $this->close();
        throw new RuntimeException('Ungültige oder unvollständige Modbus-Antwort.');
    }

    private function exchange(int $function, string $payload): string
    {
        $deadline = microtime(true) + $this->timeoutMs / 1000;
        try {
            if (!is_resource($this->connection)) {
                $this->connection = @stream_socket_client("tcp://{$this->ip}:{$this->port}", $error, $message, $this->timeoutMs / 1000);
                if ($this->connection === false) throw new RuntimeException("SHI-Verbindung fehlgeschlagen ($error): $message");
                stream_set_blocking($this->connection, false);
            }
            $this->transaction = ($this->transaction + 1) & 0xffff;
            $request = pack('nnnCC', $this->transaction, 0, strlen($payload) + 2, $this->unit, $function) . $payload;
            while ($request !== '') {
                $this->wait(false, $deadline);
                $written = @fwrite($this->connection, $request);
                if ($written === false || $written === 0) throw new RuntimeException('SHI-Anfrage konnte nicht gesendet werden.');
                $request = substr($request, $written);
            }
            $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->read(7, $deadline));
            if ($header['transaction'] !== $this->transaction || $header['protocol'] !== 0 || $header['unit'] !== $this->unit
                || $header['length'] < 2 || $header['length'] > 254) $this->invalidReply();
            $pdu = $this->read($header['length'] - 1, $deadline);
            if (ord($pdu[0]) === ($function | 0x80) && strlen($pdu) === 2) {
                throw new LuxtronikModbusException('SHI-Ausnahmeantwort: ' . ord($pdu[1]), ord($pdu[1]));
            }
            if (ord($pdu[0]) !== $function) $this->invalidReply();
            return $pdu;
        } catch (LuxtronikModbusException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function wait(bool $read, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('Zeitüberschreitung bei der SHI-Abfrage.');
        $seconds = (int)$remaining;
        $readers = $read ? [$this->connection] : [];
        $writers = $read ? [] : [$this->connection];
        $except = [];
        $ready = @stream_select($readers, $writers, $except, $seconds, (int)(($remaining - $seconds) * 1000000));
        if ($ready === false || $ready === 0) throw new RuntimeException($ready === 0 ? 'Zeitüberschreitung bei der SHI-Abfrage.' : 'SHI-Abfrage fehlgeschlagen.');
    }

    private function read(int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->wait(true, $deadline);
            $part = @fread($this->connection, $length - strlen($data));
            if ($part === false || $part === '') throw new RuntimeException('SHI-Antwort unvollständig oder Verbindung unterbrochen.');
            $data .= $part;
        }
        return $data;
    }
}
