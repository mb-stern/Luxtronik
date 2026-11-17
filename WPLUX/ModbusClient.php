<?php

class LuxtronikModbusException extends RuntimeException {}

/** Word-level operations for the direct Modbus TCP connection. */
interface LuxtronikModbusClient
{
    public function readInputRegisters(int $address,int $count): array;
    public function readHoldingRegisters(int $address,int $count): array;
    public function readDiscreteInputs(int $address,int $count): array;
    public function writeHoldingRegisters(int $address,array $words): void;
    public function close(): void;
}
