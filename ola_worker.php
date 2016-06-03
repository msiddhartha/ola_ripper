<?php
echo "\nStarting..\n";
$olaWorker = new GearmanWorker();
$olaWorker->addServer();
$olaWorker->addFunction("addAnOlaAdjustmentTxn", "addAnOlaAdjustmentTxn");
$olaWorker->addFunction("addAnOlaIncentiveTxn", "addAnOlaIncentiveTxn");
$olaWorker->addFunction("addAnOlaBonusTxn", "addAnOlaBonusTxn");
$olaWorker->addFunction("addAnOlaMbgCalcTxn", "addAnOlaMbgCalcTxn");
$olaWorker->addFunction("addADDChargesTxn", "addADDChargesTxn");
$olaWorker->addFunction("addAPenaltyTxn", "addAPenaltyTxn");
$olaWorker->addFunction("addAShareCCTxn", "addAShareCCTxn");
$olaWorker->addFunction("addAShareEarningTxn", "addAShareEarningTxn");
$olaWorker->addFunction("addAnOlaTripTxn", "addAnOlaTripTxn");
$olaWorker->addFunction("addAnOlaTripFareTxn", "addAnOlaTripFareTxn");
$olaWorker->addFunction("addAnOlaTripTollsTxn", "addAnOlaTripTollsTxn");
$olaWorker->addFunction("addAnOlaTripTDSTxn", "addAnOlaTripTDSTxn");
$olaWorker->addFunction("addAnOlaTripServiceTaxTxn", "addAnOlaTripServiceTaxTxn");
$olaWorker->addFunction("addAnOlaTripCommissionTxn", "addAnOlaTripCommissionTxn");
$olaWorker->addFunction("addAnOlaTripCashCollectedTxn", "addAnOlaTripCashCollectedTxn");
print "Waiting for an Ola job...\n";
/**
 *
 * @var type
 */
$dbHandle;
/**
 *
 * @var type
 */
$envMap = ['dev' => ['db_handle' => ['u' => 'root', 'p' => 'abcd!234', 'd' => 'ops_live_20160501', 'h' => '127.0.0.1']], 'prod' => ['db_handle' => ['u' => '******', 'p' => '******', 'd' => '*****', 'h' => '******']], ];
/**
 *
 * @var type
 */
$env = 'dev';
$fin_transactions_type;
$fin_accounts;

function initDB() {
    global $env, $dbHandle, $envMap;
    
    if (!$dbHandle) {
        $h = $envMap[$env]['db_handle']['h'];
        $u = $envMap[$env]['db_handle']['u'];
        $p = $envMap[$env]['db_handle']['p'];
        $d = $envMap[$env]['db_handle']['d'];
        $dbHandle = new mysqli($h, $u, $p, $d);
        
        if (!$dbHandle) {
            throw new Exception("Couldn't get handler to the database!");
        }
    }
    return $dbHandle;
}
initDB();
while ($olaWorker->work()) {
    
    if ($olaWorker->returnCode() != GEARMAN_SUCCESS) {
        echo "return_code: " . $olaWorker->returnCode() . "\n";
        break;
    }
}

function issetAndIsNotEmpty($VV) {
    
    if (isset($VV) && !empty($VV)) {
        return true;
    }
    return false;
}

function initGlobalTimeZone() {
    date_default_timezone_set('UTC');
}

function initLocalTimeZone() {
    date_default_timezone_set('Asia/Kolkata');
}

function getFinTransactionsType() {
    global $fin_transactions_type;
    $dbHandle = initDB();
    $finTransactionsTypeQ = 'SELECT id, title, type_code, class FROM `fin_transactions_type` ORDER BY id desc';
    $finTransactionsTypeRes = $dbHandle->query($finTransactionsTypeQ) or die('Query: ' . $finTransactionsTypeQ . ' failed!!');
    $fin_transactions_type = [];
    
    if ($finTransactionsTypeRes->num_rows > 0) {
        while ($finTransactionsTypeRow = $finTransactionsTypeRes->fetch_assoc()) {
            array_push($fin_transactions_type, $finTransactionsTypeRow);
        }
    }
    return $fin_transactions_type;
}

function fetchTransTypeId($trans_type_code) {
    $fin_txn_type = getFinTransactionsType();
    $trans_type_id = array_filter($fin_txn_type, 
    function ($elem) use ($trans_type_code) {
        return $elem['type_code'] == $trans_type_code;
    });
    return $trans_type_id;
}

function getFinAccounts() {
    global $fin_accounts;
    $dbHandle = initDB();
    $finAccountsQ = 'SELECT id, account_type, account_id FROM `fin_accounts` ORDER BY id desc';
    $finAccountsRes = $dbHandle->query($finAccountsQ) or die('Query: ' . $finAccountsQ . ' failed!!');
    $fin_accounts = [];
    
    if ($finAccountsRes->num_rows > 0) {
        while ($finAccountsRow = $finAccountsRes->fetch_assoc()) {
            array_push($fin_accounts, $finAccountsRow);
        }
    }
    return $fin_accounts;
}

function fetchAccNumberBy($acc_val, $acc_attr = 'account_id') {
    $fin_acc = getFinAccounts();
    $accnt_number = array_filter($fin_acc, 
    function ($elem) use ($acc_val, $acc_attr) {
        return $elem[$acc_attr] == $acc_val;
    });
    return $accnt_number;
}
/*function getDCOYSGId($carNumber = '') {
    
    if (!empty($carNumber)) {
        $dbHandle = initDB();
        $dcoQ = 'SELECT `ownerId` FROM `car_table` WHERE `carNumber` = \'' . $carNumber . '\' LIMIT 0,1';
        $dcoRes = $dbHandle->query($dcoQ) or die('Query: ' . $dcoQ . ' failed!!');
        
        if ($dcoRes->num_rows > 0) {
            $dcoData = $dcoRes->fetch_assoc();
            $dcoYSG = $dcoData['ownerId'];
            
            if ($dcoYSG) {
                return $dcoYSG;
            }
        }
    }
}*/

function getDCOYSGId($carNumber = '', $txn_date = null) {
    
    if (!empty($carNumber) && !empty($txn_date)) {
        $dbHandle = initDB();
        $dcoQ = 'SELECT `dcon`.`dco` FROM `state_transitions` AS `st`, `dco_contracts` AS `dcon` WHERE `st`.`object_transition` = \'CAR_CONTRACT_' . $carNumber . '\' AND `st`.`start_time` <= ' . $txn_date . ' AND `st`.`end_time` >= ' . $txn_date . ' AND `st`.`current_state` = `dcon`.`id` LIMIT 0,1';
        $dcoRes = $dbHandle->query($dcoQ) or die('Query failed: ' . mysqli_error($dbHandle) . " !..\n"); //die('Query: ' . $dcoQ . ' failed!!');
        
        if ($dcoRes->num_rows > 0) {
            $dcoData = $dcoRes->fetch_assoc();
            $dcoYSG = $dcoData['dco'];
            
            if ($dcoYSG) {
                return $dcoYSG;
            }
        }
    }
}

function carInSystem($carNumber = '') {
    $carInSystem = false;
    
    if (!empty($carNumber)) {
        $dbHandle = initDB();
        $carQ = 'SELECT * FROM `car_table` WHERE `carNumber` = \'' . $carNumber . '\' LIMIT 0,1';
        $carRes = $dbHandle->query($carQ) or die('Query ' . $carQ . ' failed! Error: \n' . mysqli_error($dbHandle) . "\n");
        
        if ($carRes->num_rows > 0) {
            $carInSystem = true;
        }
    }
    return $carInSystem;
}

function isDuplicateTxn($ref_id = '') {
    
    if (!empty(trim($ref_id))) {
        $dbHandle = initDB();
        $duplicateTxnQ = 'SELECT * FROM `fin_transactions` WHERE `reference` = \'' . $ref_id . '\'';
        $duplicateTxnRes = $dbHandle->query($duplicateTxnQ) or die('Query: ' . $duplicateTxnQ . ' failed!!');
        return $duplicateTxnRes->num_rows > 0;
    }
    return false;
}
/**
 *
 * @param type $table
 * @param type $data
 * @return type
 */

function makeAnInsertQ($table, $data) {
    $colQ = "INSERT INTO `$table` (";
    $valQ = ") VALUES (";
    $endQ = ");";
    $colPlaceholder = "`%s`";
    $valPlaceholder = "'%s'";
    end($data);
    $end = key($data);
    reset($data);
    
    foreach ($data as $key => $datum) {
        $thisColPlaceholder = $colPlaceholder;
        $thisValPlaceholder = $valPlaceholder;
        
        if ($key < $end) {
            $thisColPlaceholder.= ",";
            $thisValPlaceholder.= ",";
        }
        $colQ = sprintf($colQ . $thisColPlaceholder, $datum['col']);
        $valQ = sprintf($valQ . $thisValPlaceholder, $datum['val']);
    }
    return $colQ . $valQ . $endQ;
}

function persistTxn($txnData) {
    initGlobalTimeZone();
    $created_at = date("Y-m-d H:i:s");
    $updated_at = $created_at;
    $dbHandle = initDB();
    $txnData2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'immutable', 'val' => 1]];
    $mergedTxnData = array_merge($txnData2, $txnData);
    $txnQ = makeAnInsertQ('fin_transactions', $mergedTxnData);
    echo "\n$txnQ";
    $r = $dbHandle->query($txnQ);
    
    if ($r) {
        return mysqli_insert_id($dbHandle);
    }
}

function persistTxnMap($txn_id, $asset_id) {
    $dbHandle = initDB();
    $txnMapData = [['col' => 'transaction_id', 'val' => $txn_id], ['col' => 'asset_id', 'val' => $asset_id]];
    $txnMapQ = makeAnInsertQ('fin_asset_transaction_map', $txnMapData);
    echo "\n$txnMapQ";
    $r = $dbHandle->query($txnMapQ);
    
    if ($r) {
        return mysqli_insert_id($dbHandle);
    }
}

function addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $description) {
    $delimiter = '$$';
    $suspense = false;
    $suspenseReason = '';
    $aTxn = [];
    $acc_ysg_id = '';
    
    if (property_exists($workload, 'suspense_reasons')) {
        $suspense = $suspense || (boolean)count($injectedReasons = $workload->suspense_reasons);
        
        foreach ($injectedReasons as $injected_reason) {
            $suspenseReason.= $injected_reason . "$$";
        }
    }
    //...
    array_push($aTxn, ['col' => 'pl_asset_id', 'val' => $workload->car_number]);
    array_push($aTxn, ['col' => 'pl_account_type', 'val' => $accountType]);
    array_push($aTxn, ['col' => 'trans_date', 'val' => $workload->for_transaction_day]);
    array_push($aTxn, ['col' => 'post_date', 'val' => $workload->payment_post_day]);
    //Check if post date is within acct. stmt. period...
    $txnDateIssue = false;
    
    if ($workload->payment_post_day >= $workload->period_start && $workload->payment_post_day <= $workload->period_end) {
        // Now check transaction date...w.r.t. post  date..diff of 27 days and not after..
        
        if ($workload->for_transaction_day > $workload->payment_post_day) {
            $txnDateIssue = true;
            $suspense = $suspense || true;
            $suspenseReason.= 'Transaction Date lies beyond Post Date$$';
        }
        elseif (($workload->payment_post_day - $workload->for_transaction_day - 27 * 24 * 60 * 60) > 0) {
            $suspense = $suspense || true;
            $suspenseReason.= 'Transaction Date is older than Post Date by more than 27 days$$';
        }
    }
    else {
        $suspense = $suspense || true;
        $suspenseReason.= 'Payment Post Date lies beyond Acct. Stmt. period$$';
    }
    /**
     * DCO-Id / YSG account Id / Transaction type, class and Id...[START]
     *
     */
    
    if ($accountType == 'DCO') {
        
        if (!$txnDateIssue) {
            
            if (!empty($workload->car_number)) {
                
                if (carInSystem($workload->car_number)) {
                    $acc_ysg_id = getDCOYSGId($workload->car_number, $workload->for_transaction_day);
                    
                    if ($acc_ysg_id) {
                        $dco_acc = fetchAccNumberBy($acc_ysg_id);
                        
                        if (count($dco_acc) > 0) {
                            array_push($aTxn, ['col' => 'accnt_number', 'val' => $dco_acc[key($dco_acc) ]['id']]);
                        }
                        else {
                            $suspense = $suspense || true;
                            $suspenseReason.= 'DCO account number not found$$';
                        }
                    }
                    else {
                        $suspense = $suspense || true;
                        $suspenseReason.= 'Car DCO not found$$';
                    }
                }
                else {
                    $suspense = $suspense || true;
                    $suspenseReason.= 'Car not found in system$$';
                }
            }
        }
        else {
            $suspense = $suspense || true;
            $suspenseReason.= 'Car DCO not found due to Transaction date issue$$';
        }
    }
    elseif ($accountType == 'YSG') {
        $ysg_acc = fetchAccNumberBy($accountType, 'account_type');
        
        if (count($ysg_acc) > 0) {
            array_push($aTxn, ['col' => 'accnt_number', 'val' => $ysg_acc[key($ysg_acc) ]['id']]);
            $acc_ysg_id = $ysg_acc[key($ysg_acc) ]['account_id'];
        }
        else {
            $suspense = $suspense || true;
            $suspenseReason.= 'YSG account number not found$$';
        }
    }
    array_push($aTxn, ['col' => 'pl_account_id', 'val' => $acc_ysg_id]);
    array_push($aTxn, ['col' => 'pl_trans_type_code', 'val' => $txnType]);
    $trans_type = fetchTransTypeId($txnType);
    
    if (count($trans_type) > 0) {
        array_push($aTxn, ['col' => 'trans_type_id', 'val' => $trans_type[key($trans_type) ]['id']]);
        array_push($aTxn, ['col' => 'pl_class', 'val' => $trans_type[key($trans_type) ]['class']]);
    }
    else {
        $suspense = $suspense || true;
        $suspenseReason.= 'Transaction type Id not found$$';
    }
    /**
     * DCO-Id / YSG account Id / Transaction type, class and Id...[END]
     *
     */
    array_push($aTxn, ['col' => 'source', 'val' => $source]);
    array_push($aTxn, ['col' => 'trans_amount', 'val' => $amount]);
    array_push($aTxn, ['col' => 'cr_db', 'val' => $cr_db]);
    array_push($aTxn, ['col' => 'description', 'val' => $description]);
    array_push($aTxn, ['col' => 'secondary_reference', 'val' => $workload->reference]);
    $reference = $source . $delimiter . $description . $delimiter . $workload->reference;
    array_push($aTxn, ['col' => 'reference', 'val' => $reference]);
    
    if (!issetAndIsNotEmpty($workload->car_number)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Car number$$';
    }
    else {
    }
    
    if (!issetAndIsNotEmpty($acc_ysg_id)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Account Id$$';
    }
    
    if (!issetAndIsNotEmpty($workload->for_transaction_day)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Transaction date$$';
    }
    
    if (!issetAndIsNotEmpty($workload->payment_post_day)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Post date$$';
    }
    
    if (!issetAndIsNotEmpty($amount)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Amount$$';
    }
    
    if (!issetAndIsNotEmpty($cr_db)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Credit or Debit specified$$';
    }
    
    if (!issetAndIsNotEmpty($workload->reference)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'No Reference-Id$$';
    }
    
    if (isDuplicateTxn($reference)) {
        $suspense = $suspense || true;
        $suspenseReason.= 'Duplicate Txn$$';
    }
    array_push($aTxn, ['col' => 'suspense', 'val' => (int)$suspense]);
    array_push($aTxn, ['col' => 'suspense_reason', 'val' => $suspenseReason]);
    $txn = persistTxn($aTxn);
    // if() {
    echo "\nAdded a TXN: " . $txn . " w/ Ref-Id:[$reference] \n";
    //  }else {
    
    // echo "\nFound a TXN conflict w/ Ref-Id:[$reference] \n";
    
    // }
    
    if ($txn > 0 && issetAndIsNotEmpty($workload->car_number)) {
        persistTxnMap($txn, $workload->car_number);
    }
}

function addAnOlaTripTxn($job) {
    echo "Received an Ola Trip Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $tripWorkload = new stdClass();
    $tripWorkload->period_start = $workload->period_start;
    $tripWorkload->period_end = $workload->period_end;
    $tripWorkload->for_transaction_day = $workload->date;
    $tripWorkload->payment_post_day = $workload->date;
    $tripWorkload->car_number = $workload->car_number;
    $tripWorkload->reference = $workload->txn_crn_osn . '$$' . $workload->txn_crn_osn_type;
    $tripWorkload->trip_type = $workload->txn_crn_osn_trip_type == 'OSN' ? '_SHARE_' : '_';
    $tripSource = 'OLA';
    $accountType = 'DCO';
    $tdsAccountType = 'YSG';
    /** Trip Fare [START]**/
    $fareSourceType = 'TRIP' . $tripWorkload->trip_type . 'FARE_EXCL_TAX';
    $fareTxnType = 'FARE';
    $fareCrDb = 'credit';
    $fareAmount = $workload->trip_operator_bill;
    
    if ($workload->trip_operator_bill < 0) {
        $fareCrDb = 'debit';
        $fareAmount = - 1 * $workload->trip_operator_bill;
    }
    addAnOlaTxn($tripWorkload, $fareAmount, $fareCrDb, $accountType, $fareTxnType, $tripSource, $fareSourceType);
    /** Trip Fare [END]**/
    /** Trip Commission/FEE [START]**/
    $feesSourceType = 'TRIP' . $tripWorkload->trip_type . 'COMMISSION_FEES';
    $feesTxnType = 'FEES';
    $feesCrDb = 'debit';
    $feesAmount = $workload->trip_ola_commission;
    
    if ($workload->trip_ola_commission < 0) {
        $feesCrDb = 'credit';
        $feesAmount = - 1 * $workload->trip_ola_commission;
    }
    addAnOlaTxn($tripWorkload, $feesAmount, $feesCrDb, $accountType, $feesTxnType, $tripSource, $feesSourceType);
    /** Trip Commission/FEE [END]**/
    /** Trip Service Tax [START]**/
    /*
    $serviceTaxSourceType = 'TRIP' . $tripWorkload->trip_type . 'SER_TAX';
    $serviceTaxTxnType = 'SER_TAX';
    $serviceTaxCrDb = 'debit';
    $serviceTaxAmount = 0;
    addAnOlaTxn($tripWorkload, $serviceTaxAmount, $serviceTaxCrDb, $accountType, $serviceTaxTxnType, $tripSource, $serviceTaxSourceType);
    */
    /** Trip Service Tax [END]**/
    /** Trip TDS [START]**/
    $tdsSourceType = 'TRIP' . $tripWorkload->trip_type . 'TDS';
    $tdsTxnType = 'TRIP_TDS';
    $tdsCrDb = 'credit';
    $tdsAmount = $workload->trip_tds;
    
    if ($workload->trip_tds < 0) {
        $tdsCrDb = 'debit';
        $tdsAmount = - 1 * $workload->trip_tds;
    }
    addAnOlaTxn($tripWorkload, $tdsAmount, $tdsCrDb, $tdsAccountType, $tdsTxnType, $tripSource, $tdsSourceType);
    /** Trip TDS [END]**/
    /** Trip Tolls [START]**/
    $tollsSourceType = 'TRIP' . $tripWorkload->trip_type . 'TOLLS';
    $tollsTxnType = 'TOLLS';
    $tollsCrDb = 'credit';
    $tollsAmount = $workload->trip_tolls;
    
    if ($workload->trip_tolls < 0) {
        $tollsCrDb = 'debit';
        $tollsAmount = - 1 * $workload->trip_tolls;
    }
    addAnOlaTxn($tripWorkload, $tollsAmount, $tollsCrDb, $accountType, $tollsTxnType, $tripSource, $tollsSourceType);
    /** Trip Tolls [END]**/
    /** Trip Cash Collected [START]**/
    $ccSourceType = 'TRIP' . $tripWorkload->trip_type . 'CC';
    $ccTxnType = 'CC';
    $ccCrDb = 'debit';
    $ccAmount = $workload->trip_cash_collected;
    
    if ($workload->trip_cash_collected < 0) {
        $ccCrDb = 'credit';
        $ccAmount = - 1 * $workload->trip_cash_collected;
    }
    addAnOlaTxn($tripWorkload, $ccAmount, $ccCrDb, $accountType, $ccTxnType, $tripSource, $ccSourceType);
    /** Trip Cash collected [END]**/
}

function addAnOlaTripFareTxn($job) {
    echo "Received an Ola Trip Fare Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaTripTollsTxn($job) {
    echo "Received an Ola Trip Tolls Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaTripTDSTxn($job) {
    echo "Received an Ola Trip TDS Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaTripServiceTaxTxn($job) {
    echo "Received an Ola Trip Service Tax Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaTripCommissionTxn($job) {
    echo "Received an Ola Trip Commission Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaTripCashCollectedTxn($job) {
    echo "Received an Ola Trip Cash Collected Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
}

function addAnOlaAdjustmentTxn($job) {
    global $fin_transactions_type;    
    $workload = json_decode($job->workload());
    echo "Received an Ola $workload->xtype Txn job: " . $job->handle() . "\n";
    print_r($workload);
    $source = 'OLA';
    $source_type = strtoupper($workload->xtype); //'ADJUSTMENT';
    $accountType = 'DCO';
    $txnType = 'GEN_CR';
    $cr_db = 'credit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $txnType = 'GEN_DR';
        $cr_db = 'debit';
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAnOlaIncentiveTxn($job) {
    echo "Received an Ola Incentive Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $source = 'OLA';
    $source_type = 'INCENTIVE';
    
    if (property_exists($workload, "description")) {
        $source_type.= "//" . $workload->description;
    }
    $accountType = 'DCO';
    $txnType = 'INCENTIVE';
    $cr_db = 'credit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $cr_db = 'debit';
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAnOlaMbgCalcTxn($job) {
    echo "Received an Ola Mbg Calc Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    echo "Processing workload....\n";
    print_r($workload);
    $source = 'OLA';
    $source_type = 'MBG_CALC';
    $accountType = 'DCO';
    $txnType = 'MBG';
    $cr_db = 'credit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $cr_db = 'debit';
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAnOlaBonusTxn($job) {
    echo "Received an Ola Bonus Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    echo "Processing workload....\n";
    print_r($workload);
    $source = 'OLA';
    $source_type = 'BONUS';
    
    if (property_exists($workload, "description")) {
        
        if (issetAndIsNotEmpty($workload->description)) $source_type.= "//" . $workload->description;
    }
    $accountType = 'DCO';
    $txnType = 'BONUS';
    $cr_db = 'credit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $cr_db = 'debit';
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addADDChargesTxn($job) {
    echo "Received an Ola Data Device Charges Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $source = 'OLA';
    $source_type = 'DATA_DEVICE_CHARGES';
    $accountType = 'DCO';
    $txnType = 'CHARGES';
    $cr_db = 'debit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAPenaltyTxn($job) {
    echo "Received an Ola Penalty Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $source = 'OLA';
    $source_type = 'PENALTY';
    $accountType = 'DCO';
    $txnType = 'PENALTY';
    $cr_db = 'debit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAShareCCTxn($job) {
    echo "Received an Ola Share Cash collected Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $source = 'OLA';
    $source_type = 'SHARE_CASH_COLLECTED';
    $accountType = 'DCO';
    $txnType = 'CC';
    $cr_db = 'debit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}

function addAShareEarningTxn($job) {
    echo "Received an Ola Share Earning Txn job: " . $job->handle() . "\n";
    $workload = json_decode($job->workload());
    print_r($workload);
    $source = 'OLA';
    $source_type = 'SHARE_EARNINGS';
    $accountType = 'DCO';
    $txnType = 'FARE';
    $cr_db = 'credit';
    $amount = $workload->amount;
    
    if ($workload->amount < 0) {
        $cr_db = 'debit';
        $amount = - 1 * $workload->amount;
    }
    addAnOlaTxn($workload, $amount, $cr_db, $accountType, $txnType, $source, $source_type);
}
?>
