<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Http\HttpClientOptions;

date_default_timezone_set(TIMEZONE);

function getProduction() {
    $json = json_decode(file_get_contents(ENPHASE_URI), true);

    $json['DATETIME']=date(DATETIMEFORMAT);
    return $json;
}

function insertIntoFirebase(array $data, $database) {
    if (empty($data) || !isset($data)) { return FALSE; }
    $database->getReference()->getChild('ENPHASE')->set($data);
    return TRUE;
}

$database = (new Factory)
   ->withServiceAccount(FIREBASEJSON)
   ->withDatabaseUri(FIREBASE_URI)
   ->withHttpClientOptions(
    HttpClientOptions::default()->withTimeout(FIREBASETIMEOUT)
   )
   ->createDatabase();

$memcacheD = new Memcached;
$memcacheD->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

while (true) {
    try {
        $arrayValues = getProduction();
        if (DEBUG) var_dump($arrayValues);
        $return = insertIntoFirebase($arrayValues,$database);
        if (DEBUG) echo 'Insert:'.$return.PHP_EOL;

        $memcacheD->set('ENPHASE', $arrayValues);
        sleep(ENPHASEPAUSE);
    }
    catch (Exception $e) {
        echo($e->getMessage());
    }
}
