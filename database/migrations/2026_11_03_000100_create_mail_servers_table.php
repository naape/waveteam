<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_servers', function (Blueprint $table) {
            $table->id();
            $table->string('description', 150);
            $table->string('host', 255);                 // name or IP address
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption', 10)->default('tls'); // tls | ssl | auto | none
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();        // encrypted with APP_KEY
            $table->string('from_address', 190);
            $table->string('from_name', 150);
            $table->string('reply_to_address', 190)->nullable();
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->string('local_domain', 255)->nullable(); // EHLO name
            $table->boolean('verify_peer')->default(true);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_servers');
    }
};
