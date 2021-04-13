<?php

function getFrame($portcom, $portcom_config) {
    $fd = dio_open($portcom, O_RDWR | O_NOCTTY | O_NONBLOCK);

    dio_fcntl($fd, F_SETFL, O_SYNC);
    dio_tcsetattr($fd, $portcom_config);

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

function decodeFrame($rawdataframe, $arrayLabels, $separator) {
    $data = array();
    $data['DATETIME']=date(DATETIMEFORMAT);

    $frames = explode(chr(10), $rawdataframe); // Convert the rawdata frame into array

    foreach ($frames as $id => $rawframe) {
        var_dump($rawframe);

        $frame = explode ($separator, $rawframe, 3);

        $tag = $frame[0];
        $value = $frame[1];
        $checksum = $frame[2][0];

        switch ($separator) {
            case ' ':
                //Mode Historique
                $checksumOK = checkSum($tag.$separator.$value, $checksum);
                break;
            case "\t":
                //Mode Standard
                $checksumOK = checkSum($tag.$separator.$value.$separator, $checksum);
                break;
            default:
                $checksumOK = false;
        }

        if ($checksumOK) {
            if(!empty($tag) && !empty($value)) {

                if (in_array($tag, $arrayLabels)) {
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

function checkSum($message, $checksum) {
var_dump($message);
var_dump($checksum);


    $sum=0;
    foreach (str_split($message) as $char) {
        $sum += ord($char);
    }

    $sum = ($sum & hexdec('3F')) + hexdec('20');

    var_dump(chr($sum));
    //die();

    if (chr($sum)===$checksum) {
        return true;
    }
    else {
        return false;
    }
}