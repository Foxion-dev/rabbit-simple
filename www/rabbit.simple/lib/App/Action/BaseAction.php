<?php

namespace App\Action;

class BaseAction
{
    protected $postData = false;

    public function __construct()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $this->postData = $_GET ?: $input ?: $_POST;
    }
}
