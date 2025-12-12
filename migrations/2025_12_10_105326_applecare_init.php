<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class ApplecareInit extends Migration
{
    public function up()
    {
        $capsule = new Capsule();
        $capsule::schema()->create('applecare', function (Blueprint $table) {
            // Primary key must be VARCHAR to store API IDs like "H2WH10KXXXXX"
            $table->string('id', 255)->primary();
            
            // Serial number - NOT unique because a device can have multiple coverage records
            $table->string('serial_number', 255)->index();
            

            $table->string('description', 255)->nullable();
            $table->string('status', 255)->nullable();
            
            // Dates - use datetime, not string
            $table->dateTime('startDateTime')->nullable();
            $table->dateTime('endDateTime')->nullable();
            $table->dateTime('contractCancelDateTime')->nullable();
            
            // Agreement and payment info
            $table->string('agreementNumber', 255)->nullable();
            $table->string('paymentType', 255)->nullable();
            
            // Boolean flags - use boolean, not string
            $table->boolean('isRenewable')->default(false);
            $table->boolean('isCanceled')->default(false);
            
            // Timestamp for tracking updates
            $table->timestamp('last_updated')->nullable()->useCurrent();
            
            // Indexes for common queries
            $table->index('status');
            $table->index('endDateTime');
            $table->index(['serial_number', 'status']);
        });
    }
    
    public function down()
    {
        $capsule = new Capsule();
        $capsule::schema()->dropIfExists('applecare');
    }
}
