<?php

namespace plugins\Start;

use Swoole\Table;

class tableServer extends Table
{
    public function __construct()
    {
        parent::__construct(100000);
        $this->column('identifier', self::TYPE_STRING, 32);
        $this->column('data', self::TYPE_STRING, 32);
        $this->create();
    }
}