<?php

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
        Schema::table('media', function (Blueprint $table) {
            // $table->foreign(['app_id'])->references(['id'])->on('apps')->onDelete('CASCADE'); deactivated because we do not have apps table yet
            $table->foreign(['user_id'])->references(['id'])->on('users')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('media', function (Blueprint $table) {
            // $table->dropForeign('media_app_id_foreign'); //deactivated because we do not have apps table yet
            $table->dropForeign('media_user_id_foreign');
        });
    }
};
