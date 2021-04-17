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
        if ($separator===' ')
            $frame = explode ($separator, $rawframe, 3);
        else
            $frame = explode ($separator, $rawframe);

        if (count($frame)==3) {
            //trame sans horodatage
            $tag = $frame[0];
            $value = $frame[1];
            $checksum = $frame[2][0];

            $trame_horodatee = false;
        }
        else {
            $tag = $frame[0];
            $horodatage = $frame[1];
            $value = $frame[2];
            $checksum = $frame[3][0];

            $trame_horodatee = true;
        }

        switch ($separator) {
            case ' ':
                //Mode Historique
                $checksumOK = checkSum($tag.$separator.$value, $checksum);
                break;
            case "\t":
                //Mode Standard
                if ($trame_horodatee)
                    $checksumOK = checkSum($tag.$separator.$horodatage.$separator.$value.$separator, $checksum);
                else
                    $checksumOK = checkSum($tag.$separator.$value.$separator, $checksum);
                break;
            default:
                $checksumOK = false;
        }

        if ($checksumOK) {

            if ($trame_horodatee) {
                if(!empty($tag)) {
                    $data[$tag] = array($horodatage, $value);
                }

            }
            else {

                if(!empty($tag) && !empty($value)) {

                    if (in_array($tag, $arrayLabels)) {
                        $value = intval($value);
                    }

                    $data[$tag] = $value;
                }

            }
        }
        else {
            echo 'Error with checksum on:'.$tag.PHP_EOL;
        }
    }

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