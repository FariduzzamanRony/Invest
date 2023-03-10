<?php

namespace Coderflex\LaravelTicket\Database\Factories;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        $tableName = config('laravel_ticket.table_names.messages', 'messages');

        Schema::create($tableName['table'], function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->enum('model', ['user', 'admin'])->default('user');
            $table->foreignId('user_id');
            $table->foreignId($tableName['columns']['ticket_foreing_id']);
            $table->text('message');
            $table->string('attach')->nullable();
            $table->timestamps();
        });
    }
};
