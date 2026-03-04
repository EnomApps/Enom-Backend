<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Enom API',
    version: '1.0.0',
    description: 'Enom REST API documentation'
)]
#[OA\Server(
    url: 'https://api.enom.ai',
    description: 'Production server'
)]
#[OA\Server(
    url: 'http://localhost/enom/public',
    description: 'Local XAMPP server'
)]
abstract class Controller
{
    //
}
