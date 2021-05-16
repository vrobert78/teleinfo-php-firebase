<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/config.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Http\HttpClientOptions;

date_default_timezone_set(TIMEZONE);

class TICFrame {
    private $papp;
    private $ptec;
    private $iinst;
    private $isousc;
    private $adps;
    private $fd;
    private $statsd;

    function __construct($statsd) {
        $this->statsd=$statsd;

        $this->fd = dio_open(DEVICE_ARDUINO, O_RDWR | O_NOCTTY | O_NONBLOCK);

        dio_fcntl($this->fd, F_SETFL, O_SYNC);
        dio_tcsetattr($this->fd, DEVICE_CONFIG_ARDUINO);
    }

    function __destruct() {
        dio_close ($this->fd);
    }

    public function setHPFrame() {
        $this->papp = "99999";
        $this->isousc = "01";
        $this->iinst = "003";
        $this->adps = "003";
        $this->ptec = "HP..";
    }

    public function setHCFrame($PAPP,$ISOUSC, $IINST = null, $ADPS = null) {
        $this->papp = "00000$PAPP";
        $this->papp = substr($this->papp, strlen($this->papp)-5);

        $this->isousc = "00$ISOUSC";
        $this->isousc = substr($this->isousc, strlen($this->isousc)-2);

        if (is_null($IINST)) {
            $this->iinst = "000";
        }
        else {
            $this->iinst = "000$IINST";
            $this->iinst = substr($this->iinst, strlen($this->iinst)-3);
        }

        if (is_null($ADPS)) {
            $this->adps = "000";
        }
        else {
            $this->adps = "000$ADPS";
            $this->adps = substr($this->adps, strlen($this->adps)-3);
        }

        $this->ptec = "HC..";
    }


    public function sendFrame() {
        if (DEBUGAUTOPILOT) {
            echo "TRAME_PAPP: $this->papp".PHP_EOL;
            echo "TRAME_ISOUSC: $this->isousc".PHP_EOL;
            echo "TRAME_IINST: $this->iinst".PHP_EOL;
            echo "TRAME_ADPS: $this->adps".PHP_EOL;
            echo "TRAME_PTEC: $this->ptec".PHP_EOL;
        }

        $this->statsd->gauge('ISOUSC', intval($this->isousc));

        $this->message("PAPP ",$this->papp);
        $this->message("PTEC ",$this->ptec);
        $this->message("IINST ",$this->iinst);
        $this->message("ISOUSC ",$this->isousc);
        $this->message("ADPS ",$this->adps);

        return true;
    }

    private function message($label, $value) {
        $message = $label.$value;
        $message = $message . " " . $this->checkSum($message);
        //echo $message."\r\n";
        $message = chr(2).$message."\r".chr(3);

        dio_write($this->fd, $message);
    }

    private function checkSum($message) {
        $sum=0;
        foreach (str_split($message) as $char) {
            $sum += ord($char);
        }

        $sum = ($sum & hexdec('3F')) + hexdec('20');

        return chr($sum);

    }
}

function maxcharge($memcacheD, $statsd, $PAPP, $ISOUSC, $IINST, $ADPS) {
    if ($PAPP===0 && $IINST>0) {
        if (DEBUGAUTOPILOT) echo "Maxcharge avec Injection".PHP_EOL;
        $IINST = 0;
    }
    else {
        if (DEBUGAUTOPILOT) echo "Maxcharge sans Injection".PHP_EOL;
    }


    $TICFrame = new TICFrame($statsd);
    $TICFrame->setHCFrame($PAPP, $ISOUSC, $IINST, $ADPS);
    $TICFrame->sendFrame();
    unset($TICFrame);

}

function autopilot($memcacheD, $statsd, &$count, &$ORDER, &$oldState, &$newState, &$max_loop_before_decrease, $PAPP, $IINST, $PRODUCTIONA) {
    $oldState = $newState;

    $count++;
    if (DEBUGAUTOPILOT) echo "Count: $count".PHP_EOL;

    $STOP = false;

    if ($PRODUCTIONA>=MIN_POWER_XEV || ($PAPP===0 && $IINST>=MIN_POWER_XEV)) {

        if ($PAPP===0) {
            if (DEBUGAUTOPILOT) echo "Production avec Injection".PHP_EOL;
            $newState = 'INJECTION';
            if ($oldState!=$newState) $count = 0;


            if ($count>=MAX_LOOPS_BEFORE_DECISION) {
                $count = 0;

                if ($ORDER<MAX_ISOUSC && $IINST>MIN_INJECTION) {
                    $ORDER++;
                }
                elseif ($IINST<MIN_INJECTION && $ORDER>1) {
                    $ORDER--;
                }
            }
        }
        else {
            if (DEBUGAUTOPILOT) echo "Production + Import".PHP_EOL;
            $newState = 'IMPORT';
            if ($oldState!=$newState) {
                $count = 0;
                $max_loop_before_decrease = MAX_LOOPS_BEFORE_DECISION_DECREASE;
            }
            else {
                if ($max_loop_before_decrease>=1) $max_loop_before_decrease--;
            }

            if ($count>=$max_loop_before_decrease) {
                $count = 0;

                if ($ORDER>1) {
                    $ORDER--;
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

    if (DEBUGAUTOPILOT) echo "ORDER: $ORDER".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "STATE: $newState".PHP_EOL;

    $TICFrame = new TICFrame($statsd);
    if ($STOP || $ORDER<=MIN_POWER_XEV) {
        $TICFrame->setHPFrame();
        $ORDER=MIN_POWER_XEV;
    }
    else {
        $TICFrame->setHCFrame($PAPP, $ORDER);
    }

    $TICFrame->sendFrame();
    unset($TICFrame);

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

$count=0;
$ORDER=MIN_POWER_XEV;
$oldState='UNKNOWN';
$newState='UNKNOWN';
$XEVmode='AUTO';
$max_loop_before_decrease=MAX_LOOPS_BEFORE_DECISION_DECREASE;

while (true) {
    $XEVmode = $database->getReference('XEV101')->getChild('STATUS')->getValue() ?? 'AUTO';

    if (DEBUGAUTOPILOT) echo "----------".PHP_EOL;
    if (DEBUGAUTOPILOT) echo "XEV Mode: $XEVmode".PHP_EOL;


    $teleinfoArray = $memcacheD->get('HOMETIC');
//    var_dump($teleinfoArray);

    $PAPP = intval($teleinfoArray['PAPP']);
    $IINST = intval($teleinfoArray['IINST']);
    $ISOUSC = intval($teleinfoArray['ISOUSC']);
    if (array_key_exists('ADPS', $teleinfoArray)) {
        $ADPS = intval($teleinfoArray['ADPS']);
    }
    else {
        $ADPS = null;
    }

    $enphaseArray = $memcacheD->get('ENPHASE');

    if (array_key_exists('production', $enphaseArray)) {

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


        switch ($XEVmode) {
            case 'OFF':
                $TICFrame = new TICFrame($statsd);
                $TICFrame->setHPFrame();
                $TICFrame->sendFrame();
                unset($TICFrame);
                break;

            case 'MAX':
                maxcharge($memcacheD, $statsd, $PAPP, $ISOUSC, $IINST, $ADPS);
                break;

            case 'AUTO':
            default:
                autopilot($memcacheD, $statsd, $count, $ORDER, $oldState, $newState, $max_loop_before_decrease, $PAPP, $IINST, $PRODUCTIONA);
                break;
        }
    }

    sleep(1);
}
