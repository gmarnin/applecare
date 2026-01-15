<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddDeviceInfo extends Migration
{
    public function up()
    {
        $capsule = new Capsule();
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            // Device information fields from Apple Business Manager API
            $table->string('model', 255)->nullable()->after('serial_number');
            $table->string('part_number', 255)->nullable()->after('model');
            $table->string('product_family', 255)->nullable()->after('part_number');
            $table->string('product_type', 255)->nullable()->after('product_family');
            $table->string('color', 255)->nullable()->after('product_type');
            $table->string('device_capacity', 255)->nullable()->after('color');
            $table->string('device_assignment_status', 255)->nullable()->after('device_capacity');
            $table->string('purchase_source_type', 255)->nullable()->after('device_assignment_status');
            $table->string('purchase_source_id', 255)->nullable()->after('purchase_source_type');
            $table->string('order_number', 255)->nullable()->after('purchase_source_id');
            $table->dateTime('order_date')->nullable()->after('order_number');
            $table->dateTime('added_to_org_date')->nullable()->after('order_date');
            $table->string('wifi_mac_address', 255)->nullable()->after('added_to_org_date');
            $table->string('ethernet_mac_address', 255)->nullable()->after('wifi_mac_address');
            $table->string('bluetooth_mac_address', 255)->nullable()->after('ethernet_mac_address');
            
            // Indexes for common queries
            $table->index('device_assignment_status');
            $table->index('purchase_source_type');
        });
    }

    public function down()
    {
        $capsule = new Capsule();
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            $table->dropColumn([
                'model',
                'part_number',
                'product_family',
                'product_type',
                'color',
                'device_capacity',
                'device_assignment_status',
                'purchase_source_type',
                'purchase_source_id',
                'order_number',
                'order_date',
                'added_to_org_date',
                'wifi_mac_address',
                'ethernet_mac_address',
                'bluetooth_mac_address'
            ]);
        });
    }
}
