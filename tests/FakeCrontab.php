<?php

namespace Porro\Laravel\Tests;

use Porro\Core\CrontabIo;

class FakeCrontab extends CrontabIo
{
    public function __construct(public string $contents = '', public bool $works = true) {}

    public function available(): bool
    {
        return $this->works;
    }

    public function read(): string
    {
        return $this->contents;
    }

    public function write(string $contents): bool
    {
        $this->contents = $contents;

        return true;
    }
}
