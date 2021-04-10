<?php
require __DIR__.'/config.php';

date_default_timezone_set(TIMEZONE);


function sendFrame($papp, $ptec) {
    $fd = dio_open(DEVICE, O_RDWR | O_NOCTTY | O_NONBLOCK);

    dio_fcntl($fd, F_SETFL, O_SYNC);
    dio_tcsetattr($fd, DEVICE_CONFIG);

    $message = "PAPP ".$papp;
    $message = $message . " " . checkSum($message);
    echo $message."\r\n";
    $message = chr(2).$message."\r".chr(3);

    dio_write($fd, $message);

    $message = "PTEC ".$ptec;
    $message = $message . " " . checkSum($message);
    echo $message."\r\n";
    $message = chr(2).$message."\r".chr(3);

    dio_write($fd, $message);

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

while (true) {
    sendFrame("08000", "HP..");
}
