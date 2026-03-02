<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Enom API',
    version: '1.0.0',
    description: 'Enom REST API documentation'
)]
#[OA\Server(
    url: '/enom/public',
    description: 'Local XAMPP server'
)]
abstract class Controller
{
    //
}
