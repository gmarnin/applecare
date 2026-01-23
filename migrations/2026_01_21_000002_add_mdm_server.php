<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddMdmServer extends Migration
{
    public function up()
    {
        $capsule = new Capsule();
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            // MDM server assignment from Apple Business Manager API
            $table->string('mdm_server', 255)->nullable()->after('device_assignment_status');
            
            // Index for filtering
            $table->index('mdm_server');
        });
    }

    public function down()
    {
        $capsule = new Capsule();
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            $table->dropIndex(['mdm_server']);
            $table->dropColumn('mdm_server');
        });
    }
}
