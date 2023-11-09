<?php

require_once '../vendor/autoload.php';

use App\Action\Queue;

$action = new Queue();
$action->add();