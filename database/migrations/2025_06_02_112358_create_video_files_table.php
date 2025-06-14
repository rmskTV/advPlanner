<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('video_files', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('original_name');
            $table->string('hash')->unique();
            $table->enum('status', [
                'in_queue_analyzing',
                'analyzing',
                'in_queue_converting',
                'converting',
                'fail_analyzing',
                'fail_converting',
                'done',
            ])->default('in_queue_analyzing');

            $table->string('preview_file_location')->nullable();
            $table->string('original_file_location')->nullable();

            $table->jsonb('media_info')->nullable();
            $table->integer('height')->nullable();
            $table->integer('width')->nullable();
            $table->float('duration')->nullable();
            $table->integer('size')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('video_files');
    }
};
