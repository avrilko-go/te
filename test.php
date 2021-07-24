<?php

register_shutdown_function(
    function () {
        $e = error_get_last();
        var_dump($e);
    }
);

class A
{
    public function echo(): array
    {
    }
}

$a = new A();

$a->echo();

var_dump(3333);