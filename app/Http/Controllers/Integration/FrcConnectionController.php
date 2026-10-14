<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Services\Frc\FrcApiClient;
use Illuminate\Http\RedirectResponse;

class FrcConnectionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(FrcApiClient $client): RedirectResponse
    {
        $result = $client->connectionCheck();

        return back()->with(
            $result->successful ? 'integration_success' : 'integration_error',
            $result->message,
        );
    }
}
