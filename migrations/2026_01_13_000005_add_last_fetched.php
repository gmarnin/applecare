<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddLastFetched extends Migration
{
    private $tableName = 'applecare';

    public function up()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            // Add last_fetched column if it doesn't exist
            if (!$capsule::schema()->hasColumn($this->tableName, 'last_fetched')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->bigInteger('last_fetched')->nullable()->after('last_updated');
                });
            }
        }
    }

    public function down()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            if ($capsule::schema()->hasColumn($this->tableName, 'last_fetched')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->dropColumn('last_fetched');
                });
            }
        }
    }
}
