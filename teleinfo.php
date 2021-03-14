<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Http\HttpClientOptions;

date_default_timezone_set(TIMEZONE);

function getFrame() {
    $fd = dio_open(DEVICE, O_RDWR | O_NOCTTY | O_NONBLOCK);

    dio_fcntl($fd, F_SETFL, O_SYNC);
    dio_tcsetattr($fd, DEVICE_CONFIG);

    while (dio_read($fd, 1) != chr(2)); // Waiting for the end of the frame to start with the next one

    $char  = '';
    $frame = '';

    while ($char != chr(2)) { // Reading all characters until the end of the frame
        $char = dio_read($fd, 1);
        if ($char != chr(2)){
        $frame .= $char;
        }
    }
    dio_close ($fd);

    $frame = chop(substr($frame,1,-1)); //Removing starting & ending characters

    return $frame;
}

function decodeFrame($rawdataframe) {
    $data = array();
    $data['DATETIME']=date(DATETIMEFORMAT);

    $frames = explode(chr(10), $rawdataframe); // Convert the rawdata frame into array

    foreach ($frames as $id => $rawframe) {

        $frame = explode (' ', $rawframe, 3);

        $tag = $frame[0];
        $value = $frame[1];
        $checksum = $frame[2][0];

        if (checkSum($tag.' '.$value, $checksum)) {
            if(!empty($tag) && !empty($value)) {

                if (in_array($tag, array('ADCO', 'ISOUSC', 'HCHC', 'HCHP', 'IINST', 'IMAX', 'PAPP'))) {
                    $value = intval($value);
                }

                $data[$tag] = $value;
            }
        }
        else {
            echo 'Error with checksum on:'.$tag.PHP_EOL;
        }
    }

    return $data;
}

function getTeleinfo () {
    $frame = getFrame();
    $data = decodeFrame($frame);

    return $data;
}

function checkSum($message, $checksum) {
    $sum=0;
    foreach (str_split($message) as $char) {
        $sum += ord($char);
    }

    $sum = ($sum & hexdec('3F')) + hexdec('20');

    if (chr($sum)===$checksum) {
        return true;
    }
    else {
        return false;
    }
}

function insertIntoFirebase(array $data, $database) {
    if (empty($data) || !isset($data)) { return FALSE; }
    foreach ($data as $key => $value){
        $database->getReference()->getChild('HOMETIC')->getChild($key)->set($value);
    }
    return TRUE;
}

$database = (new Factory)
   ->withServiceAccount(FIREBASEJSON)
   ->withDatabaseUri(FIREBASE_URI)
   ->withHttpClientOptions(
    HttpClientOptions::default()->withTimeout(FIREBASETIMEOUT)
   )
   ->createDatabase();

while (true) {
    try {
        $arrayValues = getTeleinfo();
        if (DEBUG) var_dump($arrayValues);
        $return = insertIntoFirebase($arrayValues,$database);
        if (DEBUG) echo 'Insert:'.$return.PHP_EOL;
    }
    catch (Exception $e) {
        echo($e->getMessage());
    }
}