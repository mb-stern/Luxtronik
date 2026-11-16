<?php

class LuxtronikModbusException extends RuntimeException {}

/** Word-level operations shared by the native gateway and the existing direct connection. */
interface LuxtronikModbusClient
{
    public function readInputRegisters(int $address,int $count): array;
    public function readHoldingRegisters(int $address,int $count): array;
    public function readDiscreteInputs(int $address,int $count): array;
    public function writeHoldingRegisters(int $address,array $words): void;
    public function close(): void;
}
