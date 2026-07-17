<?php

namespace App\Helpers\Printers;

use Mike42\Escpos\PrintConnectors\PrintConnector;

class RawBufferPrintConnector implements PrintConnector
{
    private string $buffer = '';

    public function __destruct()
    {
        $this->finalize();
    }

    public function finalize()
    {
    }

    public function read($len)
    {
        return false;
    }

    public function write($data)
    {
        $this->buffer .= $data;
    }

    public function getData(): string
    {
        return $this->buffer;
    }
}
