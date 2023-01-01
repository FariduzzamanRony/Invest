<?php

namespace App\Providers;

use App\Facades\Txn\Txn;
use Illuminate\Support\ServiceProvider;

class TxnProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('txn', function () {
            return new Txn();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

    }
}
