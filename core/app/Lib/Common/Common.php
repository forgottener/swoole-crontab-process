<?php
namespace App\Lib\Common;

class Common
{
    public static function generateRunId($length = 8)
    {
        $code = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $rand = $code[rand(0,25)] .strtoupper(dechex(date('m'))) .date('d').substr(time(),-5) .substr(microtime(),2,5) .sprintf('%02d',rand(0,99));
        for( $a = md5( $rand, true ), $s = '0123456789ABCDEFGHIJKLMNOPQRSTUV', $d = '', $f = 0; $f < $length; $g = ord( $a[ $f ] ), $d .= $s[ ( $g ^ ord( $a[ $f + 8 ] ) ) - $g & 0x1F ], $f++ );
        return $d;
    }
}