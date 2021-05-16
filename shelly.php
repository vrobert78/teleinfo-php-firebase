<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';


date_default_timezone_set(TIMEZONE);

function getConsumption() {
    $json = json_decode(file_get_contents(SHELLY_URI), true);

    $json['DATETIME']=date(DATETIMEFORMAT);
    return $json;
}

$connection = new \Domnikl\Statsd\Connection\UdpSocket(STATSD_SERVER, STATSD_PORT);
$statsd = new \Domnikl\Statsd\Client($connection, 'HOMETIC');

while (true) {
    try {
        $arrayValues = getConsumption();
        if (DEBUG) var_dump($arrayValues);

        $statsd->gauge('SHELLY', $arrayValues['power']);

        sleep(ENPHASEPAUSE);
    }
    catch (Exception $e) {
        echo($e->getMessage());
    }
}
