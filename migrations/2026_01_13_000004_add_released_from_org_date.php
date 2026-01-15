<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddReleasedFromOrgDate extends Migration
{
    private $tableName = 'applecare';

    public function up()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            // Add released_from_org_date column if it doesn't exist
            if (!$capsule::schema()->hasColumn($this->tableName, 'released_from_org_date')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->dateTime('released_from_org_date')->nullable()->after('added_to_org_date');
                });
            }
        }
    }

    public function down()
    {
        $capsule = new Capsule();
        
        if ($capsule::schema()->hasTable($this->tableName)) {
            if ($capsule::schema()->hasColumn($this->tableName, 'released_from_org_date')) {
                $capsule::schema()->table($this->tableName, function (Blueprint $table) {
                    $table->dropColumn('released_from_org_date');
                });
            }
        }
    }
}
