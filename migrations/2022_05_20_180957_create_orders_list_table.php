<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders_list', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->dateTime('time');
            $table->string('market');
            $table->enum('type', [1, 2]);
            $table->enum('role', [1, 2]);
            $table->double('order_price');
            $table->double('avg_price');
            $table->integer('amount');
            $table->integer('total_price');
            $table->double('current_wage');
            $table->double('toman_wage');
            $table->integer('filled');
            $table->enum('status', [-1, 0, 1, 2]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders_list');
    }
}
