<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('afip_credential', function (Blueprint $table) {
            $table->id();
            $table->text('token');
            $table->text('sign');
            $table->text('service');
            $table->dateTime('expirationTime');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afip_credential');
    }
};
