<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';
require __DIR__.'/functions.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Http\HttpClientOptions;

date_default_timezone_set(TIMEZONE);

function getTeleinfo () {
    $frame = getFrame(DEVICE_PRODUCTION, DEVICE_CONFIG_PRODUCTION);

    $data = decodeFrame($frame, array('ADSC', 'VTIC', 'EAST', 'EASF01', 'EASF02', 'EASF03', 'EASF04',
    'EASF05', 'EASF06', 'EASF07', 'EASF08', 'EASF09', 'EASF10', 'EASD01', 'EASD02', 'EASD03', 'EASD04',
    'EAIT', 'ERQ1', 'ERQ2', 'ERQ3', 'ERQ4', 'IRMS1', 'URMS1', 'PREF', 'PCOUP', 'SINSTS', 'SINSTI', 'PRM'), "\t");

    unset($frame);

    return $data;
}

function insertIntoFirebase(array $data, $database) {
    if (empty($data) || !isset($data)) { return FALSE; }
    $database->getReference()->getChild('HOMETIC-PRODUCTION')->set($data);
    return TRUE;
}

function measure(&$database, &$memcacheD, &$statsd) {
    try {
        $arrayValues = getTeleinfo();
        if (DEBUG) var_dump($arrayValues);

        $statsd->gauge('SINSTI', $arrayValues['SINSTI']);

        $return = insertIntoFirebase($arrayValues,$database);
        if (DEBUG) echo 'Insert:'.$return.PHP_EOL;

        $memcacheD->set('HOMETIC-PRODUCTION', $arrayValues);

        unset($arrayValues);
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
$statsd = new \Domnikl\Statsd\Client($connection, 'HOMETIC-PRODUCTION');

while (true) {
    measure($database, $memcacheD, $statsd);
}
