<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';

date_default_timezone_set(TIMEZONE);

function message($fd, $label, $value) {
    $message = $label.$value;
    $message = $message . " " . checkSum($message);
    //echo $message."\r\n";
    $message = chr(2).$message."\r".chr(3);

    dio_write($fd, $message);
}


function sendFrame($papp, $ptec, $iinst, $isousc, $adps) {
    $fd = dio_open(DEVICE_ARDUINO, O_RDWR | O_NOCTTY | O_NONBLOCK);

    dio_fcntl($fd, F_SETFL, O_SYNC);
    dio_tcsetattr($fd, DEVICE_CONFIG);

    message($fd, "PAPP ",$papp);
    message($fd, "PTEC ",$ptec);
    message($fd, "IINST ",$iinst);
    message($fd, "ISOUSC ",$isousc);
    message($fd, "ADPS ",$adps);

    dio_close ($fd);

    return true;
}


function checkSum($message) {
    $sum=0;
    foreach (str_split($message) as $char) {
        $sum += ord($char);
    }

    $sum = ($sum & hexdec('3F')) + hexdec('20');

    return chr($sum);

}

$memcacheD = new Memcached;
$memcacheD->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

$connection = new \Domnikl\Statsd\Connection\UdpSocket(STATSD_SERVER, STATSD_PORT);
$statsd = new \Domnikl\Statsd\Client($connection, 'HOMETIC');

$count=0;
$ISOUSC=MIN_POWER_XEV;
$oldState='UNKNOWN';
$newState='UNKNOWN';

while (true) {
    if (DEBUGAUTOPILOT) echo "----------".PHP_EOL;
    $oldState = $newState;

    $count++;
    if (DEBUGAUTOPILOT) echo "Count: $count".PHP_EOL;

    $teleinfoArray = $memcacheD->get('HOMETIC');
//    var_dump($teleinfoArray);

    $PAPP = intval($teleinfoArray['PAPP']);
    $IINST = intval($teleinfoArray['IINST']);
    $PTEC = $teleinfoArray['PTEC'];

    $statsd->gauge('PAPP', $PAPP);
    $statsd->gauge('IINST', $IINST);

    $enphaseArray = $memcacheD->get('ENPHASE');

    $PRODUCTION = intval($enphaseArray['production'][0]['wNow']);
    $PRODUCTIONA = intval($PRODUCTION / VOLTAGE);

    $statsd->gauge('PRODUCTION', $PRODUCTION);
    $statsd->gauge('PRODUCTIONA', $PRODUCTIONA);

    if (DEBUGAUTOPILOT) echo "Production: $PRODUCTIONA A".PHP_EOL;

    if ($PAPP===0) {
        if (DEBUGAUTOPILOT) echo "Injection: $IINST A".PHP_EOL;
        $statsd->gauge('EXPORTA', $IINST);
        $statsd->gauge('EXPORT', $IINST*VOLTAGE);
        $statsd->gauge('IMPORTA', 0);
        $statsd->gauge('CONSOMMATION', $PRODUCTION-($IINST*VOLTAGE));
        $statsd->gauge('CONSOMMATIONA', $PRODUCTIONA-$IINST);
    }
    else {
        if (DEBUGAUTOPILOT) echo "Import: $IINST A - $PAPP VA".PHP_EOL;
        $statsd->gauge('EXPORTA', 0);
        $statsd->gauge('EXPORT', 0);
        $statsd->gauge('IMPORTA', $IINST);
        $statsd->gauge('CONSOMMATION', $PAPP+$PRODUCTION);
        $statsd->gauge('CONSOMMATIONA', $PRODUCTIONA+$IINST);
    }

    $TRAME_PAPP = "00000$PAPP";
    $TRAME_PAPP = substr($TRAME_PAPP, strlen($TRAME_PAPP)-5);

    $STOP = false;

    if ($PRODUCTIONA>=MIN_POWER_XEV || ($PAPP===0 && $IINST>=MIN_POWER_XEV)) {

        if ($PAPP===0) {
            if (DEBUGAUTOPILOT) echo "Production avec Injection".PHP_EOL;
            $newState = 'INJECTION';
            if ($oldState!=$newState) $count = 0;


            if ($count>=MAX_LOOPS_BEFORE_DECISION) {
                $count = 0;

                if ($ISOUSC<min(MAX_ISOUSC,$PRODUCTIONA*2) && $IINST>MIN_INJECTION) {
                    $ISOUSC++;
                }
                elseif ($IINST<MIN_INJECTION && $ISOUSC>1) {
                    $ISOUSC--;
                }
                elseif ($ISOUSC>min(MAX_ISOUSC,$PRODUCTIONA*2)) {
                    $ISOUSC--;
                }
            }
        }
        else {
            if (DEBUGAUTOPILOT) echo "Production + Import".PHP_EOL;
            $newState = 'IMPORT';
            if ($oldState!=$newState) $count = 0;

            if ($count>=MAX_LOOPS_BEFORE_DECISION) {
                $count = 0;

                if ($ISOUSC>1) {
                    $ISOUSC--;
                }
            }

        }
    }
    else {
        if (DEBUGAUTOPILOT) echo "Peu ou Pas de Production > STOP".PHP_EOL;
        $newState = 'NO-PRODUCTION';
        $count = 0;

        $STOP=true;
    }

    if (DEBUGAUTOPILOT) echo "ISOUSC: $ISOUSC".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "STATE: $newState".PHP_EOL;

    if ($STOP || $ISOUSC<=MIN_POWER_XEV) {
        $TRAME_ISOUSC = "01";
        $TRAME_IINST = "003";
        $TRAME_ADPS = "003";
        $TRAME_PTEC = "HP..";

        $ISOUSC=MIN_POWER_XEV;
    }
    else {
        $TRAME_ISOUSC = "00$ISOUSC";
        $TRAME_ISOUSC = substr($TRAME_ISOUSC, strlen($TRAME_ISOUSC)-2);

        $TRAME_IINST = "000";
        $TRAME_ADPS = "000";
        $TRAME_PTEC = "HC..";
    }

    $statsd->gauge('ISOUSC', intval($TRAME_ISOUSC));

    if (DEBUGAUTOPILOT) echo "TRAME_PAPP: $TRAME_PAPP".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_ISOUSC: $TRAME_ISOUSC".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_IINST: $TRAME_IINST".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_ADPS: $TRAME_ADPS".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_PTEC: $TRAME_PTEC".PHP_EOL;

    sendFrame($TRAME_PAPP, $TRAME_PTEC, $TRAME_IINST, $TRAME_ISOUSC, $TRAME_ADPS);

    sleep(1);
}
