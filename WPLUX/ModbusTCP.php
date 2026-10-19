<?php

/** A Modbus exception reply is a valid frame, unlike a transport/protocol failure. */
class LuxtronikModbusException extends RuntimeException
{
}

/** Read-only Modbus TCP client. No holding-register or write operations exist here. */
final class LuxtronikModbusTCP
{
    private mixed $connection = null;
    private int $transaction = 0;

    public function __construct(
        private string $ip,
        private int $port,
        private int $unit,
        private int $timeoutMs
    ) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $port < 1 || $port > 65535 || $unit < 1 || $unit > 247
            || $timeoutMs < 100 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('Ungültige SHI-Verbindungseinstellungen.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }
        $this->connection = null;
    }

    /** Addresses are literal SHI addresses, offset 0 (e.g. 10108), FC04 only. */
    public function readInputRegisters(int $address, int $count): array
    {
        if ($address < 0 || $address > 65535 || $count < 1 || $count > 125 || $address + $count > 65536) {
            throw new InvalidArgumentException('Ungültiger Modbus-Lesebereich.');
        }
        $deadline = microtime(true) + $this->timeoutMs / 1000;
        try {
            if (!is_resource($this->connection)) {
                $this->connection = @stream_socket_client(
                    "tcp://{$this->ip}:{$this->port}", $error, $message, $this->timeoutMs / 1000
                );
                if ($this->connection === false) {
                    throw new RuntimeException("SHI-Verbindung fehlgeschlagen ($error): $message");
                }
                stream_set_blocking($this->connection, false);
            }
            $this->transaction = ($this->transaction + 1) & 0xffff;
            $request = pack('nnnCCnn', $this->transaction, 0, 6, $this->unit, 4, $address, $count);
            while ($request !== '') {
                $this->wait(false, $deadline);
                $written = @fwrite($this->connection, $request);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('SHI-Leseanfrage konnte nicht gesendet werden.');
                }
                $request = substr($request, $written);
            }
            $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->read(7, $deadline));
            if ($header['transaction'] !== $this->transaction || $header['protocol'] !== 0
                || $header['unit'] !== $this->unit || $header['length'] < 2 || $header['length'] > 254) {
                throw new RuntimeException('Ungültiger Modbus-TCP-Antwortkopf.');
            }
            $pdu = $this->read($header['length'] - 1, $deadline);
            $function = ord($pdu[0]);
            if ($function === 0x84 && strlen($pdu) === 2) {
                throw new LuxtronikModbusException('SHI-Ausnahmeantwort: ' . ord($pdu[1]), ord($pdu[1]));
            }
            if ($function !== 4 || strlen($pdu) !== 2 + $count * 2 || ord($pdu[1]) !== $count * 2) {
                throw new RuntimeException('Ungültige oder unvollständige Modbus-Registerantwort.');
            }
            return array_values(unpack('n*', substr($pdu, 2)));
        } catch (LuxtronikModbusException $error) {
            // Complete exception frames leave the connection aligned for the next read.
            throw $error;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function wait(bool $read, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Zeitüberschreitung bei der SHI-Abfrage.');
        }
        $seconds = (int)$remaining;
        $readers = $read ? [$this->connection] : [];
        $writers = $read ? [] : [$this->connection];
        $except = [];
        $ready = @stream_select($readers, $writers, $except, $seconds, (int)(($remaining - $seconds) * 1000000));
        if ($ready === false || $ready === 0) {
            throw new RuntimeException($ready === 0 ? 'Zeitüberschreitung bei der SHI-Abfrage.' : 'SHI-Abfrage fehlgeschlagen.');
        }
    }

    private function read(int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->wait(true, $deadline);
            $part = @fread($this->connection, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('SHI-Antwort unvollständig oder Verbindung unterbrochen.');
            }
            $data .= $part;
        }
        return $data;
    }
}
