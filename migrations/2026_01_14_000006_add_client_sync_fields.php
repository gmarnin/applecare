<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddClientSyncFields extends Migration
{
    private $tableName = 'applecare';

    public function up()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            // Add sync_in_progress column if it doesn't exist
            if (!$capsule::schema()->hasColumn($this->tableName, 'sync_in_progress')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->boolean('sync_in_progress')->default(0)->after('last_fetched');
                });
            }
        }
    }

    public function down()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            if ($capsule::schema()->hasColumn($this->tableName, 'sync_in_progress')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->dropColumn('sync_in_progress');
                });
            }
        }
    }
}
