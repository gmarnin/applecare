#!/usr/bin/env php
<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * AppleCare Sync Command Line Tool
 * 
 * Uses Eloquent models (Applecare_model, Machine_model) for database operations
 * following MunkiReport module patterns.
 */

/**
 * Run this script to sync AppleCare data from Apple School Manager API
 * 
 * Usage:
 *   php sync_applecare.php
 *   OR
 *   ./sync_applecare.php
 * 
 * This script can be run:
 * - Manually from command line
 * - Via cron job
 * - From web server
 */

// Find MunkiReport root
// Allow passing MunkiReport path as a CLI argument
$default_root = '/usr/local/munkireport';
$munkireport_root = $argv[2] ?? null;

// If no CLI argument is given, try to locate vendor/autoload.php
if (empty($munkireport_root)) {
    $search_paths = [
        '/usr/local/munkireport',                 // Standard install location
        dirname(__DIR__, 4),                      // If module is inside munkireport
        '/var/www/munkireport',                   // Common web install location
    ];

    foreach ($search_paths as $path) {
        if (file_exists($path . '/vendor/autoload.php')) {
            $munkireport_root = $path;
            break;
        }
    }
}

// If still not found, fall back to default
if (empty($munkireport_root) || !file_exists($munkireport_root . '/vendor/autoload.php')) {
    die(
        "ERROR: Could not find MunkiReport installation.\n" .
        "Please provide the path as an argument.\n"
    );
}

echo "MunkiReport root: $munkireport_root\n";

// Bootstrap MunkiReport following the pattern from 'please' CLI
define('APP_ROOT', $munkireport_root . '/');
define('PUBLIC_ROOT', $munkireport_root . '/public');

require_once APP_ROOT . 'app/helpers/env_helper.php';
require_once APP_ROOT . 'app/helpers/site_helper.php';
require_once APP_ROOT . 'vendor/autoload.php';

spl_autoload_register('munkireport_autoload');

require_once APP_ROOT . 'app/helpers/config_helper.php';
initDotEnv();
initConfig();
configAppendFile(APP_ROOT . 'app/config/app.php');
configAppendFile(APP_ROOT . 'app/config/db.php', 'connection');

echo "================================================\n";
echo "AppleCare Sync Tool\n";
echo "================================================\n\n";

try {
    $connection = conf('connection');
    $capsule = new Capsule();
    $capsule->addConnection($connection);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    echo "Database connected\n\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Could not connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

require_once __DIR__ . '/lib/applecare_helper.php';
$helper = new \munkireport\module\applecare\Applecare_helper();

try {
    // CLI sees the whole fleet. The web sync applies the machine-group filter.
    $helper->syncAllDevices(null, false, false, false, null, false);
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
