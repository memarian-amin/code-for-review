<?php

use App\Constants\ConnectionConstants;
use App\Models\Company\User\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(ConnectionConstants::TENANT_CONNECTION)
            ->create(Customer::TABLE, function (Blueprint $table) {
                $table->id();
                $table->uuid()->nullable()->index();
                $table->string(Customer::CODE)->nullable();
                $table->string(Customer::NAME)->nullable();
                $table->string(Customer::FIRST_NAME)->nullable();
                $table->string(Customer::LAST_NAME)->nullable();
                $table->string(Customer::EMAIL)->nullable();
                $table->timestamp(Customer::EMAIL_VERIFIED_AT)->nullable();
                $table->unsignedBigInteger(Customer::COUNTRY_ID)->nullable();
                $table->string(Customer::PHONE)->nullable();
                $table->timestamp(Customer::PHONE_VERIFIED_AT)->nullable();
                $table->date(Customer::BIRTH_DAY)->nullable();
                $table->foreignId(Customer::PROFILE_ID)->nullable();
                $table->string(Customer::DELETION_REASON)->nullable();
                $table->string(Customer::IMPROVE_SUGGESTION)->nullable();
                $table->boolean(Customer::IS_TAX_EXEMPT)->default(false);
                $table->string(Customer::COMMENT)->nullable();
                $table->softDeletes();
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
        Schema::dropIfExists(Customer::TABLE);
    }
};
