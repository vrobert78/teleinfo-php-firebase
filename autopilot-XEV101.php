<?php
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

while (true) {
    if (DEBUGAUTOPILOT) echo "----------".PHP_EOL;

    $teleinfoArray = $memcacheD->get('HOMETIC');
//    var_dump($teleinfoArray);

    $PAPP = intval($teleinfoArray['PAPP']);
    $IINST = intval($teleinfoArray['IINST']);
    $PTEC = $teleinfoArray['PTEC'];

    $enphaseArray = $memcacheD->get('ENPHASE');

    $PRODUCTION = intval($enphaseArray['production'][0]['wNow']);
    $PRODUCTIONA = intval($PRODUCTION/230);

    if (DEBUGAUTOPILOT) echo "Production: $PRODUCTIONA A".PHP_EOL;

    if ($PAPP===0) {
        if (DEBUGAUTOPILOT) echo "Injection: $IINST A".PHP_EOL;
    }
    else {
        if (DEBUGAUTOPILOT) echo "Import: $IINST A - $PAPP VA".PHP_EOL;
    }

    $TRAME_PAPP = "00000$PAPP";
    $TRAME_PAPP = substr($TRAME_PAPP, strlen($TRAME_PAPP)-5);

    $STOP = false;

    if ($PRODUCTIONA>=8) {

        if ($PAPP===0) {
            if (DEBUGAUTOPILOT) echo "Production avec Injection".PHP_EOL;

            if ($IINST>=6) {
                $ISOUSC = $PRODUCTIONA;
            } else {
                $ISOUSC = $PRODUCTIONA - MARGE;
            }
        } else {
            if (DEBUGAUTOPILOT) echo "Production + Import".PHP_EOL;
            $ISOUSC = $PRODUCTIONA - MARGE - $IINST;
        }

        if ($ISOUSC<=0) {
            $STOP=true;
        }
        else {
            $TRAME_ISOUSC = "00$ISOUSC";
            $TRAME_ISOUSC = substr($TRAME_ISOUSC, strlen($TRAME_ISOUSC)-2);

            $TRAME_IINST = "000";
            $TRAME_ADPS = "000";
            $TRAME_PTEC = "HC..";
        }

    }
    else {
        if (DEBUGAUTOPILOT) echo "Peu ou Pas de Production > STOP".PHP_EOL;
        $STOP=true;
    }

    if ($STOP) {
        $TRAME_ISOUSC = "45";
        $TRAME_IINST = "047";
        $TRAME_ADPS = "047";
        $TRAME_PTEC = "HP..";
    }

    if (DEBUGAUTOPILOT) echo "TRAME_PAPP: $TRAME_PAPP".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_ISOUSC: $TRAME_ISOUSC".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_IINST: $TRAME_IINST".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_ADPS: $TRAME_ADPS".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "TRAME_PTEC: $TRAME_PTEC".PHP_EOL;

    sendFrame($TRAME_PAPP, $TRAME_PTEC, $TRAME_IINST, $TRAME_ISOUSC, $TRAME_ADPS);

    sleep(1);
}
