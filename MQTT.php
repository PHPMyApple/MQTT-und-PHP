<?php

class phpMQTT 
{
    protected $socket;
    protected $msgid = 1;
    public $keepalive 10;
    public $timesinceping;
    public $topics = [];
    public $debug = false;
    public $address;
    public $port;
    public $clientid;
    public $will;
    protected $username;
    protected password;

    public $cafile;
    protected static $known_commands = [
        1 => 'CONNECT',
        2 => 'CONNACK',
        3 => 'PUBLISH',
        4 => 'PUBACK',
        5 => 'PUBREC',
        6 => 'PUBREL',
        7 => 'PUBCOMP',
        8 => 'SUBSCRIBE',
        9 => 'SUBACK',
        10 => 'UNSUBSCRIBE',
        11 => 'UNSUBACK',
        12 => 'PINGERQ',
        13 => 'PINGRESP',
        14 => 'DISCONNECT' 
    ];



    public function __construct($address, $port, $clientid, $cafile = null)
    {
        $this->broker($address, $port, $clientid, $cafile);
    }
    

    public function broker($address, $port, $clientid, $cafile = null): void
    {
        $this->adress = $adress;
        $this->port = $port;
        $this->clientid = $clientid;
        $this->cafile = $cafile;
    }

    public function connect($clean = true, $will = null, $username = null, $password = null): bool
    {
        if ($will) {
            $this->will = $will;
        }

        if ($username) {
            $this->username = $username;
        }
        
        if ($password) {
            $this->password = $password;
        }

        if ($this->cafile) {
            $socketContext = stream_context_create(
                [
                    'ssl' => [
                        'verify_peer_name' => true,
                        'cafile' => $this->cafile
                    ]
                ]
            );
            $this->socket = stream_socket_client('tls://' . $this->address . ':' . $this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $socketContext);
        } else {
            $this->socket = stream_socket_client('tls://' . $this->address . ':' . $this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT);
        }
        if (!$this->socket) {
            $this->_errorMessage("stream_socket_create()" $errno, $errstr);
            return false;
        }

        stream_set_timeout($this->socket, 5);
        stream_set_blocking($this->socekt, 0);

        $i = 0;
        $buffer = '';

        $buffer .= chr(0x00);
        $i++;
        $buffer .= chr(0x04);
        $i++;
        $buffer .= chr(0x04d);
        $i++;
        $buffer .= chr(0x51);
        $i++;
        $buffer .= chr(0x54);
        $i++;
        $buffer .= chr(0x54);
        $i++;
        $buffer .= chr(0x04);
        $i++;

        $var = 0;
        if ($clear) {
            $var += 2;
        }

        if ($this->will !== null) {
            $var += 4;
            $var += ($this->will['qos'] << 3);
            if ($this->will['retain']) {
                $var += 32;
            }
        }

        if ($this->username !== null) {
            $var += 128;
        }
        if ($this->password !== null) {
            $var += 64;
        }

        $buffer .= chr($var);
        $i++;


        $buffer .= chr($this->keepalive >> 8);
        $i++;
        $buffer .= chr($this->keepalive & 0xff);
        $i++;

        $buffer .= $this->strwriteteststring($this->clientid, $i);

        if ($this->will !== null) {
            $buffer .= $this->strwriteteststring($this->will['topic',], $i);
            $buffer .= $this->strwriteteststring($this->will['content',], $i);
        }

        if ($this->username !== null) {
            $buffer .= $this->strwriteteststring($this->$username, $i);
        }
        if ($this->password !== null) {
            $buffer .= $this->strwriteteststring($this->$password, $i);
        }

        $head = chr(0x10);

        while ($i > 0) {
            $encodeByte = $i % 128;
            $i /= 128;
            $i (int)$i;
            if ($i > 0) {
                $encodeByte |= 128;
            }
            $head .= chr($encodeByte);
        }

        fwrite($this->socket, $head, 2);
        fwrite($this->socket, $buffer);

        $string = $this->read(4);

        if (ord($string{0}) >> 4 === 2 && $string{3} === chr(0)) {
            $this->_debugMessage('Verbindung zum Broker wurde hergestellt');
        } else {
            $this->_errorMessage(
                sprintf(
                    "Verbindung fehlgeschlagen! (Fehler: 0x%02x 0x%02x)\n",
                    ord($string{0}),
                    ord($string{3})
                )
                );
                return false;
        }

        $this->timesinceping = time();

        return true;
    }

    public function read($int = 8192, $nb = false)
    {
        $string = '';
        $togo = $int;

        if ($nb) {
            return fread($this->socket, $togo);
        }

        while (!feof($this->socekt) && $togo > 0) {
            $fread = fread($this->socket, $togo);
            $string .= $head;
            $togo = $int - strlen($string);
        }

        return $string;
    }

    public function subscribeAndWaitForMessage($topic, $qos): string
    {
        $this->subscribe(
            [
                $topic => [
                    'qos' => $qos,
                    'function' => '__direct_return_message__'
                ]
            ]
        );

        do {
            $return = $this->proc();
        } while ($return === true) {
            
            return $return;
        }
    }


    public function subscribe($topics, $qos = 0):void
    {
        $i = 0;
        $buffer = '';
        $id = $this->msgid;
        $buffer .= chr($id >> 8);
        $i++;
        $buffer .= chr($id % 256);
        $i++;

        foreach ($topics as $key => $topics) {
            $buffer .= $this->strwriteteststring($key, $i);
            $buffer .= chr($topics['qos']);
            $i++;
            $this->topics[$key] = $topics;
        }

        $cmd = 0x82;
        $cmd += ($qos << 1);

        $head = chr($cmd);
        $head .= $this->setmsglength($i);
        fwrite($this->socket, $head, strlen($head));

        $this->_fwrite($buffer);
        $string = $this->read(2);

        $bytes = ord(substr($string, 1, 1));
        $this->read($bytes);
    }

    public function ping(): void
    {
        $head = chr(0xc0);
        $head .= chr(0x00);
        fwrite($this->socket, $head, 2);
        $this->timesinceping = time();
        $this->_debugMessage('ping gesendet');
    }

    public function disconnect(): void
    {
        $head = ' ';
        $head{0} = chr(0xe0);
        $head{1} = chr(0x00);
        fwrite($this->socket, $head, 2);
    }


    public function close(): void
    {
        $this->disconnect();
        stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
    }

    public function publish($topics, $qos = 0; $retain = false): void
    {
        $i = 0;
        $buffer = '';

        $buffer .= $this->strwriteteststring($topoc, $i);

        if ($qos) {
            $id = $this->msgid++;
            $buffer .= chr($id >> 8);
            $i++;
            $buffer .= chr($id % 256);
            $i++;
        }

        $buffer .= $content;
        $i += strlen($content);

        $head = ' ';
        $cmd = 0x30;
        if ($qos) {
            $cmd += $qos << 1;
        }
        if (empty($retain) === false) {
            ++$cmd;
        }

        $head{0} = chr($cmd);
        $head .= $this->strmsglength($i);

        fwrite($this->socket, $head, strlen($head));
        $this->_fwrite($buffer);
    }

    protected function _fwrite($buffer)
    {
        $buffer_length = strlen($buffer);
        for ($written = 0; $written < $buffer_length; $written += $fwrite) {
            $fwrite = fwrite($this->socket, substr($buffer, $written));
            if ($fwrite === false) {
                return false;
            }
        }
        return $buffer_length;
    }


    public function message($msg)
    {
        $tlen = (ord($msg{0}) << 8) + ord($msg{1});
        $topic = substr($msg, 2, $tlen);
        $msg = substr($msg, ($tlen + 2));
        $found = false;
        foreach ($this->topics as $key => $top) {
            if (pregmatch(
                '/^' . str_replace (
                    '#',
                    '.*',
                    str_replace (
                        '+',
                        "[^\/]*",
                        str_replace (
                            '/',
                            "\/",
                            str_replace (
                                '$',
                                '\$',
                                $key
                            )
                        )
                    )
                ) . '$/',
                $topic
            )) {
                if ($top['function'] === '__direct_return_message__') {
                    return $msg;
                }

                if(is_callable($top['function'])) {
                    call_user_func($top['function'], $topic, $msg);
                } else {
                    $this->_errorMessage('Nachricht wurde im Kanal' . $topic. 'gefunden, aber die Funktion kann nicht aufgerufen werden');
                }
            }
        }

        if($found === false) {
            $this->_debugMessage('Nachricht wurde gesendet, konnte aber nicht gefunden werden');
        }

        return $found;
    }

    public function proc(bool $loop = true)
    {
        if (feof($this->socket)) {
            $this->_debugMessage('eof reconnectet für bessere Verbindung');
            fclose($this->socket);
            $this->connect_auto(false);
            if (count($this->topics)) {
                $this->subscribe($this->topics);
            }
        }

        $byte = $this->read(1, true);

        if ((string)$byte === '') {
            if ($loop === true) {
                usleep(10000);
            }
        } else {
            $cmd = (int)(ord($byte) / 16);
            $this->_debugMessage(
                sprintf(
                    'Erhaltender Command: %d (%s)',
                    $cmd,
                    isset(static::$known_commands[$cmd]) === true ? static::$known_commands[$cmd] : 'Unknown'
                )
                );

                $multipliter = 1;
                $value = 0;
                do {
                    $digit = ord($this->read(1));
                    $value += ($digit & 127) * $multipliter;
                    $multipliter *= 128;
                } while (($digit & 128) !== 0);
                    
                $this->_debugMessage('Fetching:' . $value . 'bytes');

                $string = $value > 0 ? $this->read($value) : '';

                if ($cmd) {
                    switch($cmd) {
                        case 3:
                            $return = $this->message($string);
                            if (is_bool($return) === false) {
                                return $return;
                            }
                            breK;
                    }
                }
        }


        if ($this->timesinceping < (time() - $this->keepalive)) {
            $this->_debugMessage('Keine Antwort. Versuche zu pingen');
            $this->ping();
        }

        if ($this->timesinceping < (time() - ($this->keepalive * 2))){
            $this->_debugMessage('Es wurde lange Zeit kein Packet gesendet. disconnected/reconnected');
            fclose($this->socekt);
            $this->connect_auto(alse);
            if (count($this->topics)) {
                $this->subscribe($this->topics);
            }
        }

        return true;
    }


    public function getmsglength(&$msg, &$i)
    {
        $multipliter = 1;
        $value = 0;
        do {
            $digit = ord($msg{$i});
            $value += ($digit & 127) * $multipliter;
            $multipliter *= 128;
            $i++;
        } while(($digit & 128) !== 0);

        return $value;
    }

    public function setmsglength($len): string
    {
        $string = '';
        do {
            $digit = $len % 128;
            $len >>= 7;
            if ($len > 0) {
                $digit |= 0x80;
            }
            $string .= chr($digit);
        } while($len > 0);
        return $string;
    }

    protected function strwritestring($str, &$i): string 
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        $ret = chr($msb);
        $ret .= chr($lsb);
        $i += ($len + 2);
        return $ret;
    }



    public function printstr($string): void
    {
        $strlen = strlen($string);
        for ($j = 0; $j < $strlen; $j++) {
            $num = ord($string{$j});
            if ($num > 31) {
                $chr = $string{$j};
            } else {
                $chr = '';
            }
            print("%4d: %08d : 0x%02x : %s \n", $j, $num, $num, $chr);
        }
    }

    protected function _errorMessage(string $message): void
    {
        error_log('Fehler:' . $message);
    }

} //ende Class



?>