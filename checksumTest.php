<?php

function checkSum($message) {
    $sum=0;
    foreach (str_split($message) as $char) {
        //echo $char."\r";
        $sum += ord($char);
    }

    //echo $sum;

    $sum = ($sum & hexdec('3F')) + hexdec('20');

    echo chr($sum);
}

checkSum("ADPS 000");