<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';
require __DIR__.'/functions.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Http\HttpClientOptions;

date_default_timezone_set(TIMEZONE);

function getTeleinfo () {
    $frame = getFrame(DEVICE_CONSO, DEVICE_CONFIG_CONSO);

    $data = decodeFrame($frame, array('ADCO', 'ISOUSC', 'HCHC', 'HCHP', 'IINST', 'IMAX', 'PAPP'), ' ');

    return $data;
}

function insertIntoFirebase(array $data, $database) {
    if (empty($data) || !isset($data)) { return FALSE; }
    $database->getReference()->getChild('HOMETIC')->set($data);
    return TRUE;
}

function measure(&$database, &$memcacheD, &$statsd) {
    try {
        $arrayValues = getTeleinfo();
        if (DEBUG) var_dump($arrayValues);

        $statsd->gauge('PAPP', $arrayValues['PAPP']);
        $statsd->gauge('IINST', $arrayValues['IINST']);

        $return = insertIntoFirebase($arrayValues,$database);
        if (DEBUG) echo 'Insert:'.$return.PHP_EOL;

        $memcacheD->set('HOMETIC', $arrayValues);

    }
    catch (Exception $e) {
        echo($e->getMessage());
    }
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

$connection = new \Domnikl\Statsd\Connection\UdpSocket(STATSD_SERVER, STATSD_PORT);
$statsd = new \Domnikl\Statsd\Client($connection, 'HOMETIC');

$loopCount=0;
while ($loopCount++<MAX_MAIN_LOOP) {
    measure($database, $memcacheD, $statsd);
}
