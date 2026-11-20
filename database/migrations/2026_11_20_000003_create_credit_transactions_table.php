<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A ledger: a user's balance is the sum of their rows. Rows are never updated.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Unique, so the database itself refuses to charge a generation twice.
            $table->foreignId('generation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('type', 20);
            // Nanodollars: positive adds credit, negative spends it.
            $table->bigInteger('amount_nanodollars');
            $table->string('description');
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
