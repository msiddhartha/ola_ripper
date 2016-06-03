<?php
	function generateid($length = 6) {
        $characters = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $charlen = strlen($characters) - 1;
        $randomString = '';
        $randomInteger = array();
        for ($i = 0; $i < $length; $i++) {
            $randint = mt_rand(0, $charlen);
            $randomString .= $characters[$randint];
            $randomInteger[] = $randint;
        }

        $luhnValue = luhn_func($randomInteger);
        $randomString .= $characters[$luhnValue];
        return $randomString;
    }

    function luhn_func($randint) {
        $retVal = 0;
        $retVal = array_sum($randint);
        $retVal = $retVal * 9;
        $retVal = substr($retVal, -1);
        return $retVal;
    }
    
    echo "\n -------- \n";
    echo generateid();
	echo "\n -------- \n";
?>
