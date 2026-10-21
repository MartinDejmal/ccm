<?php

use App\Models\User;
use App\Services\Csv\ImportWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user']);
$request = Request::create('/', 'POST');
$request->setUserResolver(fn () => $user);
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[1]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($argv[1])) {
    exit(3);
}
try {
    $app->make(ImportWorkflow::class)->confirm($request, $input['vehicle'], $input['batch'], $input['data']);
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
