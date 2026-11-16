<?php

require_once __DIR__.'/ModbusClient.php';

/** Native Symcon Modbus gateway: connection, MBAP framing and transactions belong to the parent. */
final class LuxtronikModbusGateway implements LuxtronikModbusClient
{
    private Closure $send;

    public function __construct(callable $send) {$this->send=Closure::fromCallable($send);}
    public function close(): void {} // The parent owns the connection, possibly shared by other children.

    private function range(int $address,int $count,int $limit): void
    {
        if($address<0 || $count<1 || $count>$limit || $address+$count>65536) throw new InvalidArgumentException('Ungültiger Modbus-Registerbereich.');
    }

    private function request(int $function,int $address,int $count,string $data=''): string
    {
        $response=($this->send)(['DataID'=>'{E310B701-4AE7-458E-B618-EC13A1A6F6A8}',
            'Function'=>$function,'Address'=>$address,'Quantity'=>$count,'Data'=>$data]);
        if(!is_string($response) || $response==='') throw new RuntimeException('Das Symcon-Modbus-Gateway hat keine Antwort geliefert. Gateway und Client Socket prüfen.');
        if(ord($response[0])===($function|128) && strlen($response)===2) throw new LuxtronikModbusException('Modbus-Ausnahme '.ord($response[1]).' vom Regler.');
        if(ord($response[0])!==$function) throw new RuntimeException('Unerwartete Antwort vom Symcon-Modbus-Gateway.');
        return $response;
    }

    private function registers(int $function,int $address,int $count): array
    {
        $this->range($address,$count,125);
        $reply=$this->request($function,$address,$count);
        if(strlen($reply)!==2+2*$count || ord($reply[1])!==2*$count) throw new RuntimeException('Unvollständige Registerantwort vom Symcon-Modbus-Gateway.');
        return array_values(unpack('n*',substr($reply,2)));
    }

    public function readInputRegisters(int $address,int $count): array {return $this->registers(4,$address,$count);}
    public function readHoldingRegisters(int $address,int $count): array {return $this->registers(3,$address,$count);}

    public function readDiscreteInputs(int $address,int $count): array
    {
        $this->range($address,$count,2000);$reply=$this->request(2,$address,$count);$bytes=(int)ceil($count/8);
        if(strlen($reply)!==2+$bytes || ord($reply[1])!==$bytes) throw new RuntimeException('Unvollständige Freigabeantwort vom Symcon-Modbus-Gateway.');
        $values=[];
        for($i=0;$i<$count;++$i) $values[]=(ord($reply[2+intdiv($i,8)])>>($i%8))&1;
        return $values;
    }

    public function writeHoldingRegisters(int $address,array $words): void
    {
        $this->range($address,count($words),123);
        foreach($words as $word) if(!is_int($word) || $word<0 || $word>65535) throw new InvalidArgumentException('Ungültiges 16-Bit-Modbus-Schreibwort.');
        $reply=$this->request(16,$address,count($words),bin2hex(pack('n*',...array_values($words))));
        if($reply!==pack('Cnn',16,$address,count($words))) throw new RuntimeException('Schreibbestätigung vom Symcon-Modbus-Gateway stimmt nicht überein.');
    }
}
