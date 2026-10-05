<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$u = App\Models\User::where('name', 'like', '%Ahmad Faizal%')->first();

echo $u->createToken('debug')->plainTextToken.PHP_EOL;