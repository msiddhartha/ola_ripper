<?php
define('SITE_ROOT', realpath(dirname(__FILE__)));
// Include Composer autoloader if not already done.
include 'vendor/autoload.php';
/**
 * Tables:
 *
 *  ola_acc_stmt_adjustment
 ola_acc_stmt_booking_details
 ola_acc_stmt_booking_summary
 ola_acc_stmt_data_device_charges
 ola_acc_stmt_incentives
 ola_acc_stmt_mbg_calc
 ola_acc_stmt_penalty
 ola_acc_stmt_parse_log
 ola_acc_stmt_share_cash_coll
 ola_acc_stmt_share_earnings
 ola_exports
 ola_imports
 *
 * TRUNCATE `ola_acc_stmt_adjustment`;
 TRUNCATE `ola_acc_stmt_booking_details`;
 TRUNCATE `ola_acc_stmt_booking_summary`;
 TRUNCATE `ola_acc_stmt_data_device_charges`;
 TRUNCATE `ola_acc_stmt_incentives`;
 TRUNCATE `ola_acc_stmt_mbg_calc`;
 TRUNCATE `ola_acc_stmt_penalty`;
 TRUNCATE `ola_acc_stmt_parse_log`;
 TRUNCATE `ola_acc_stmt_share_cash_coll`;
 TRUNCATE `ola_acc_stmt_share_earnings`;
 TRUNCATE `ola_exports`;
 TRUNCATE `ola_imports`;
 */
use \ForceUTF8\Encoding;

class OlaAccountStatement {
    /**
     *
     * @var type
     */
    private $twigger;
    /**
     *
     * @var type
     */
    private $dbHandle;
    /**
     *
     * @var type
     */
    private $relativeTemplateDirPath = './templates';
    /**
     *
     * @var type
     */
    private $envMap = ['dev' => ['db_handle' => ['u' => 'root', 'p' => 'abcd!234', 'd' => 'ops_live_20160501', 'h' => '127.0.0.1']], 'prod' => ['db_handle' => ['u' => '****', 'p' => '*****', 'd' => '***', 'h' => '***']], ];
    /**
     *
     * @var type
     */
    private $env = 'dev';
    /**
     *
     * @var type
     */
    private static $logLevelMap = ['FATAL', 'ERROR', 'WARN', 'INFO', 'DEBUG', 'TRACE'];
    /**
     *
     * @var type
     */
    private static $parseStageMap = ['ARBITRARY', 'PDF2TEXT', 'SPLIT_BOOKINGS', 'EXTRACT_BOOKINGS', 'BOOKING_STRUCT_CHECK', 'SPLIT_BOOKING_DATES', 'EXTRACT_BOOKING_DATES', 'EXTRACT_BOOKING_SUMMARY', 'SPLIT_BOOKING_TRIPS', 'EXTRACT_BOOKING_TRIPS', 'EXTRACT_BOOKING_TRIPS_CRN_OSN', 'EXTRACT_BOOKING_TRIPS_DIST', 'EXTRACT_BOOKING_TRIPS_RIDE_TIME', 'EXTRACT_BOOKING_TRIPS_FINANCIALS'];
    /**
     *
     * @var type
     */
    private $currentImportRun;
    private $gclient;
    private $period_start_ts;
    private $period_end_ts;
    private $missingCars;
    private $trackedCars;
    /**
     *
     */
    public 
    function __construct($env = 'dev') {
        $this->env = $env;
        $this->missingCars = [];
        $this->trackedCars = [];
    }
    private 
    function initGlobalTimeZone() {
        date_default_timezone_set('UTC');
    }
    private 
    function initLocalTimeZone() {
        date_default_timezone_set('Asia/Kolkata');
    }
    private 
    function initDB() {
        
        if (!$this->dbHandle) {
            $h = $this->envMap[$this->env]['db_handle']['h'];
            $u = $this->envMap[$this->env]['db_handle']['u'];
            $p = $this->envMap[$this->env]['db_handle']['p'];
            $d = $this->envMap[$this->env]['db_handle']['d'];
            $this->dbHandle = new mysqli($h, $u, $p, $d);
            
            if (!$this->dbHandle) {
                throw new Exception("Couldn't get handler to the database!");
            }
        }
    }
    private 
    function initTwig() {
        $twigOptions = array(
            'cache' => './tmp/cache',
            'debug' => true,
        );
        $loader = new Twig_Loader_Filesystem($this->relativeTemplateDirPath);
        $this->twigger = new Twig_Environment($loader, $twigOptions);
        $this->twigger->addExtension(new Twig_Extension_Debug());
    }
    private 
    function initGearmanClient() {
        
        if ($this->gclient && $this->gclient instanceof \GearmanClient) {
            return;
        }
        $this->gclient = new GearmanClient();
        $this->gclient->addServer();
    }
    private 
    function stripWS($content, $replacement = "") {
        $spacerPattern = '/\s*/';
        return preg_replace($spacerPattern, $replacement, $content);
    }
    private 
    function stripComma($content, $replacement = "") {
        $commaPattern = '/,/';
        return preg_replace($commaPattern, $replacement, $content);
    }
    private 
    function stripRupee($content, $replacement = "") {
        $commaPattern = '/₹/';
        return preg_replace($commaPattern, $replacement, $content);
    }
    private 
    function stripPageFooter($content, $replacement = "\n###PAGER###\n") {
        $pageFooterPattern = '/\s*S\s*e\s*r\s*v\s*i\s*c\s*e\s*t\s*a\s*x\s*N\s*o[.-\sA-Z\da-z():=]*P\s*a\s*g\s*e\s*[\d]+\s*o\s*f\s*[\d]+/';
        /**
         * Serv ic e  t a x N o. -  A AJC A1389G SD 001
         AN I Te ch nolo gie s P VT L TD  ( B angalo re )
         2 015
         TA N  N o. -  M UM A41166G
         Page
         14
         o f
         109
         07:5 8 P M
         */
        return preg_replace($pageFooterPattern, $replacement, $content);
    }
    private 
    function detectMissingCar($carNum) {
                
        if (!empty($carNum) && !in_array($carNum, $this->missingCars) && !in_array($carNum, $this->trackedCars)) {
            $this->initDB();
            $carQ = 'SELECT * FROM `car_table` WHERE `carNumber` = \'' . $carNum . '\' LIMIT 0,1';
            $carRes = $this->dbHandle->query($carQ); // or die('Query ' . $carQ . ' failed! Error: \n' . mysqli_error($dbHandle) . "\n");
            
            if ($carRes->num_rows > 0) {
                array_push($this->trackedCars, $carNum);
            }
            else {
                array_push($this->missingCars, $carNum);
            }
        }
		
    }
    /**
     *
     */
    private 
    function detectDuplicateMalRowsPgBrk($content1, $content2) {
        $pagerPattern = '/###PAGER###/';
        $replacement = '';
        $content1Matcher = preg_match_all($pagerPattern, $content1, $content1Matches);
        $content2Matcher = preg_match_all($pagerPattern, $content2, $content2Matches);
        
        if ($content1Matcher > 0 || $content2Matcher > 0) {
            $strippedContent1 = $this->stripWS(preg_replace($pagerPattern, $replacement, $content1));
            $strippedContent2 = $this->stripWS(preg_replace($pagerPattern, $replacement, $content2));
            return $strippedContent1 == $strippedContent2;
        }
        return false;
    }
    private 
    function handleDocStream() {
        $fileName = str_replace('/', '_', date("Y_m_d_H_i_s_e")) . "_" . $_FILES["ola_doc"]["name"];
        $fileTmpLoc = $_FILES["ola_doc"]["tmp_name"];
        $inputFileName = SITE_ROOT . "/tmp/ola_docs/" . $fileName;
        
        if (move_uploaded_file($fileTmpLoc, $inputFileName)) {
            return $inputFileName;
        }
        return FALSE;
    }
    private 
    function addAnOlaAdjustmentTxn($anAdjustment) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaAdjustmentTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $anAdjustment)));
    }
    private 
    function addAnOlaTripFareTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripFareTxn", json_encode($trip));
    }
    private 
    function addAnOlaTripTollsTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripTollsTxn", json_encode($trip));
    }
    private 
    function addAnOlaTripTDSTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripTDSTxn", json_encode($trip));
    }
    private 
    function addAnOlaTripServiceTaxTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripServiceTaxTxn", json_encode($trip));
    }
    private 
    function addAnOlaTripCommissionTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripCommissionTxn", json_encode($trip));
    }
    private 
    function addAnOlaTripCashCollectedTxn($trip) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripCashCollectedTxn", json_encode($trip));
    }
    private 
    function addAnOlaIncentiveTxn($incentive) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaIncentiveTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $incentive)));
    }
    private 
    function addAnOlaBonusTxn($bonus) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaBonusTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $bonus)));
    }
    private 
    function addAnOlaMbgCalcTxn($anMbgCalc) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaMbgCalcTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $anMbgCalc)));
    }
    private 
    function addADDChargesTxn($aDDCharges) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addADDChargesTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $aDDCharges)));
    }
    private 
    function addAPenaltyTxn($aPenalty) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAPenaltyTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $aPenalty)));
    }
    private 
    function addAShareCCTxn($aShareCC) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAShareCCTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $aShareCC)));
    }
    private 
    function addAShareEarningTxn($aShareEarning) {
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAShareEarningTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $aShareEarning)));
    }
    public 
    function getAllContent() {
        $filePath = $this->handleDocStream();
        $return['filePath'] = $filePath;
        // Parse pdf file and build necessary objects...
        
        if ($filePath) {
            $proc_stage_pdf2text = "pdf2text";
            $siteRelPath = $this->getRelativeDocPath($filePath);
            $proc_stage_pdf2text_header = "Extracting PDF content for file: $siteRelPath";
            $this->renderProcStageProg($proc_stage_pdf2text, $proc_stage_pdf2text_header, true);
            
            if (array_key_exists('parseFile', $_GET) && $this->issetAndIsNotEmpty($_GET['parseFile'])) {
                $filen = $_GET['parseFile'];
                $debugContentX = $return['text_c'] = $this->useAlreadyParsedVal($filen);
            }
            else {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $debugContentX = $return['text_c'] = $pdf->getText();
            }
            
            if (array_key_exists('debugContent', $_GET) && $this->issetAndIsNotEmpty($_GET['debugContent']) && $this->issetAndIsNotEmpty($debugContentX)) {
                $this->debugContent($debugContentX);
            }
            $this->renderProcStageProgPct($proc_stage_pdf2text, 100);
            return $return;
        }
        else {
            throw new Exception('Upload failed!');
        }
    }
    private 
    function debugContent($txt) {
        $file_suffix = time();
        $file_prefix = 'parsed_txt';
        $filen = SITE_ROOT . "/tmp/ola_parsed/" . $file_prefix . '_' . $file_suffix;
        $fp = fopen($filen, 'w');
        $bytesW = fwrite($fp, $txt);
        fclose($fp);
        
        if (array_key_exists('debugContent', $_GET) && $this->issetAndIsNotEmpty($_GET['debugContent'])) {
            die($bytesW . " bytes written to $filen ..! Run again to debug content..");
        }
    }
    private 
    function useAlreadyParsedVal($filename) {
        $filename = SITE_ROOT . "/tmp/ola_parsed/" . $filename;
        $handle = fopen($filename, "r");
        $contents = fread($handle, filesize($filename));
        fclose($handle);
        return $contents;
    }
    public 
    function getBookingDetails($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        // fucking non-capturing group..
        $expressionBookingDetails = '/(?:\bB[\s]*o[\s]*o[\s]*k[\s]*i[\s]*n[\s]*g[\s]*D[\s]*e[\s]*t[\s]*a[\s]*i[\s]*l[\s]*s[\s]*o[\s]*f\b)(.*)/';
        $bookingDetailsMatcher = (int)preg_match_all($expressionBookingDetails, $allContent, $bookingDetailsMatches);
        $splitBookingsParseLog = ['stage' => 'SPLIT_BOOKINGS', 'content' => $allContent, 'pattern' => $expressionBookingDetails];
        $proc_stage_all_bookings = 'all_bookings';
        $proc_stage_all_bookings_header = "Processing $bookingDetailsMatcher car bookings!";
        $this->renderProcStageProg($proc_stage_all_bookings, $proc_stage_all_bookings_header, true);
        $bookingDetails = [];
        
        if ($bookingDetailsMatcher > 0) {
            $splitBookingsParseLog['level'] = 'INFO';
            $this->addAnOlaAccStmtParseLog($splitBookingsParseLog['stage'], $splitBookingsParseLog['content'], $splitBookingsParseLog['level'], $splitBookingsParseLog['pattern']);
            $bookingDetailsMatchedStrings = $bookingDetailsMatches[0];
            $bookingDetailsCars = $bookingDetailsMatches[1];
            $carBookingDetailsContent = [];
            
            foreach ($bookingDetailsCars as $key => $aCar) {
                $carNum = $this->stripWS($aCar); //strip all spaces..
                $this->detectMissingCar($carNum);
                $thisAnchorString = $bookingDetailsMatchedStrings[$key];
                $thisAnchorPos = strpos($allContent, $thisAnchorString);
                $thatAnchorString = '';
                $le = NULL;
                $carBookingDetailsTxt = NULL;
                
                if (array_key_exists($key + 1, $bookingDetailsMatchedStrings)) { //no more "Booking Details.." remain to anchor between..
                    $thatAnchorString = $bookingDetailsMatchedStrings[$key + 1];
                    $thatAnchorPos = strpos($allContent, $thatAnchorString); // should always be found since this is a matched string..
                    $le = $thatAnchorPos - $thisAnchorPos;
                    $carBookingDetailsTxt = substr($allContent, $thisAnchorPos, $le);
                }
                else {
                    $carBookingDetailsTxt = substr($allContent, $thisAnchorPos);
                }
                $extractBookingsParseLog = ['stage' => 'EXTRACT_BOOKINGS', 'content' => $carBookingDetailsTxt, 'pattern' => $thisAnchorString . "$$" . $thatAnchorString];
                
                if (empty($carBookingDetailsTxt) && ($thisAnchorString == $thatAnchorString)) { //duplicate booking details delimiters..
                    $extractBookingsParseLog['level'] = 'WARN';
                    $this->addAnOlaAccStmtParseLog($extractBookingsParseLog['stage'], $extractBookingsParseLog['content'], $extractBookingsParseLog['level'], $extractBookingsParseLog['pattern']);
                    continue;
                }
                $extractBookingsParseLog['level'] = 'INFO';
                $this->addAnOlaAccStmtParseLog($extractBookingsParseLog['stage'], $extractBookingsParseLog['content'], $extractBookingsParseLog['level'], $extractBookingsParseLog['pattern']);
                $carBookingDetailsContent[$carNum] = $carBookingDetailsTxt; // extract content for that car's booking details section..
                $bookingDetails[$carNum] = $this->getCarBookingDetails($thisAnchorString, $carNum, $carBookingDetailsTxt); //now parse..
                $proc_stage_all_bookings_pct = (($key + 1) / $bookingDetailsMatcher) * 100;
                $this->renderProcStageProgPct($proc_stage_all_bookings, $proc_stage_all_bookings_pct);
            }
        }
        else {
            $splitBookingsParseLog['level'] = 'FATAL';
            $this->addAnOlaAccStmtParseLog($splitBookingsParseLog['stage'], $splitBookingsParseLog['content'], $splitBookingsParseLog['level'], $splitBookingsParseLog['pattern']);
            $this->renderProcStageProgPct($proc_stage_all_bookings, 999);
        }
        return $bookingDetails;
    }
    /**
     * A car's booking details...
     *
     * @param type $matchedAnchorString
     * @param type $capturedCar
     * @param type $text
     * @throws Exception
     */
    public 
    function getCarBookingDetails($matchedAnchorString, $capturedCar, $text) {
        $proc_stage_carbooking = "$capturedCar" . "_booking";
        $proc_stage_carbooking_header = "Processing Booking for Car# $capturedCar";
        $this->renderProcStageProg($proc_stage_carbooking, $proc_stage_carbooking_header);
        $words = ['Bookings', 'OperatorBill', 'OlaCommission', 'RideEarnings', 'Tolls', 'TDS', 'NetEarnings', 'CashCollected'];
        $pregDelimitPattern = '/';
        $pregDelimitAltPattern = '~';
        $whiteSpaceNewLinePattern = '[\s]*';
        //$capturedCarPattern = '(' . $matchedAnchorString . ')' . $whiteSpaceNewLinePattern;
        $capturedCarPattern = '';
        
        foreach ($words as $word) {
            $splitWord = str_split($word);
            $splitWordByPattern = implode($whiteSpaceNewLinePattern, $splitWord);
            $captureWordPattern = '(' . $splitWordByPattern . ')';
            $capturedCarPattern.= $captureWordPattern . $whiteSpaceNewLinePattern;
        }
        $bookingStructChkPattern = $pregDelimitPattern . $capturedCarPattern . $pregDelimitPattern;
        $carBookingDetailsMatcher = (int)preg_match_all($bookingStructChkPattern, $text, $carBookingDetailsMatches);
        $bookingStructChkParseLog = ['stage' => 'BOOKING_STRUCT_CHECK', 'content' => $text, 'pattern' => $bookingStructChkPattern];
        $bookingStructChkParseLog['level'] = 'INFO';
        $proc_stage_car_booking_st1_pct = (1 / 6) * 100;
        
        if ($carBookingDetailsMatcher != 1) {
            //  Check Format/Structure for, Booking Details
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st1_pct);
            $bookingStructChkParseLog['level'] = 'WARN';
        }
        else {
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st1_pct); // add a warning etc.
            
        }
        $this->addAnOlaAccStmtParseLog($bookingStructChkParseLog['stage'], $bookingStructChkParseLog['content'], $bookingStructChkParseLog['level'], $bookingStructChkParseLog['pattern']);
        // Format check done...
        $allDateDetails = [];
        // Describe all patterns..
        $dateCapturePattern = '([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])'; // 19-01-20    16
        $splitDatesPattern = $pregDelimitPattern . $dateCapturePattern . $pregDelimitPattern;
        $carBookingDateMatcher = (int)preg_match_all($splitDatesPattern, $text, $carBookingDateMatches);
        $splitDatesParseLog = ['stage' => 'SPLIT_BOOKING_DATES', 'content' => $text, 'pattern' => $splitDatesPattern];
        $splitDatesParseLog['level'] = 'INFO';
        $proc_stage_car_booking_st2_pct = (2 / 6) * 100;
        
        if (!$carBookingDateMatcher) {
            // Check Format/Structure for Dates, Booking Details ...
            $splitDatesParseLog['level'] = 'ERROR';
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st2_pct); // add a warning etc.
            $this->addAnOlaAccStmtParseLog($splitDatesParseLog['stage'], $splitDatesParseLog['content'], $splitDatesParseLog['level'], $splitDatesParseLog['pattern']);
            return;
        }
        else {
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st2_pct); // add a warning etc.
            
        }
        $this->addAnOlaAccStmtParseLog($splitDatesParseLog['stage'], $splitDatesParseLog['content'], $splitDatesParseLog['level'], $splitDatesParseLog['pattern']);
        $dayCapturePattern = '(?:[(])([a-zA-Z\s]+)(?:[)])'; // (T u      e)
        
        /**
         * 722
         b ookin gs
         */
        $numberOfBookingsPattern = '([0-9]+)(?:[\s]*b[\s]*o[\s]*o[\s]*k[\s]*i[\s]*n[\s]*g[\s]*s[\s]*)';
        $operatorBillSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)'; //(?:[-][₹]|[₹])([ \t,.0-9]+)'; // -₹974.74
        $olaCommissionSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        $rideEarningsSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        $tollsSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        $tdsSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        $netEarningsSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        $cashCollectedSummaryPattern = '([-\s]*[₹][ \t,.0-9-₹]*)';
        /**
         * Summary pattern
         */
        $summaryPattern = $numberOfBookingsPattern . $whiteSpaceNewLinePattern . $operatorBillSummaryPattern . $whiteSpaceNewLinePattern;
        $summaryPattern.= $olaCommissionSummaryPattern . $whiteSpaceNewLinePattern . $rideEarningsSummaryPattern . $whiteSpaceNewLinePattern . $whiteSpaceNewLinePattern;
        $summaryPattern.= $tollsSummaryPattern . $whiteSpaceNewLinePattern . $tdsSummaryPattern . $whiteSpaceNewLinePattern . $netEarningsSummaryPattern . $whiteSpaceNewLinePattern . $cashCollectedSummaryPattern . $whiteSpaceNewLinePattern;
        $summaryWDateDayPattern = $dateCapturePattern . $whiteSpaceNewLinePattern . $dayCapturePattern . $whiteSpaceNewLinePattern . $summaryPattern;
        $carBookingDateAnchors = $carBookingDateMatches[0];
        $carBookingDates = $carBookingDateMatches[1];
        $perDateDetails = [];
        /**
         * initialize and populate the details array..
         */
        
        foreach ($carBookingDates as $key => $aDate) {
            $date = $this->stripWS($aDate); //strip all spaces..
            $details = ['Date' => $date];
            $thisAnchorString = $carBookingDateAnchors[$key];
            $thisAnchorPos = strpos($text, $thisAnchorString);
            $thatAnchorString = '';
            $le = NULL;
            $carBookingDateDetailsTxt = NULL;
            
            if (array_key_exists($key + 1, $carBookingDateAnchors)) { //no more "Booking Details.." remain to anchor between..
                $thatAnchorString = $carBookingDateAnchors[$key + 1];
                $thatAnchorPos = strpos($text, $thatAnchorString, $thisAnchorPos + 1); // should always be found since this is a matched string..
                $le = $thatAnchorPos - $thisAnchorPos;
                $carBookingDateDetailsTxt = substr($text, $thisAnchorPos, $le);
            }
            else {
                $carBookingDateDetailsTxt = substr($text, $thisAnchorPos);
            }
            $extractBookingDatesParseLog = ['stage' => 'EXTRACT_BOOKING_DATES', 'content' => $carBookingDateDetailsTxt, 'pattern' => $thisAnchorString . "$$" . $thatAnchorString];
            $proc_stage_car_booking_st3_pct = (3 / 6) * 100;
            
            if (empty($carBookingDateDetailsTxt) && ($thisAnchorString == $thatAnchorString)) { //duplicate date delimiters..
                $extractBookingDatesParseLog['level'] = 'WARN';
                $this->addAnOlaAccStmtParseLog($extractBookingDatesParseLog['stage'], $extractBookingDatesParseLog['content'], $extractBookingDatesParseLog['level'], $extractBookingDatesParseLog['pattern']);
                $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st3_pct); // add a warning etc.
                continue;
            }
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st3_pct); // add a warning etc.
            $extractBookingDatesParseLog['level'] = 'INFO';
            $this->addAnOlaAccStmtParseLog($extractBookingDatesParseLog['stage'], $extractBookingDatesParseLog['content'], $extractBookingDatesParseLog['level'], $extractBookingDatesParseLog['pattern']);
            $carBookingDateDetailsContent[$date] = $carBookingDateDetailsTxt; // extract content for that car's booking details section..
            
            //now parse..
            $extractBookingSummaryPattern = $pregDelimitPattern . $summaryPattern . $pregDelimitPattern . 'u';
            $summaryDataNum = (int)preg_match_all($extractBookingSummaryPattern, $carBookingDateDetailsTxt, $summaryData);
            $extractBookingSummaryParseLog = ['stage' => 'EXTRACT_BOOKING_SUMMARY', 'content' => $carBookingDateDetailsTxt, 'pattern' => $extractBookingSummaryPattern];
            $proc_stage_car_booking_st4_pct = (4 / 6) * 100;
            
            if ($summaryDataNum != 1) {
                //Check Format/Structure for Summary, Booking Details
                $extractBookingSummaryParseLog['level'] = 'ERROR';
                $this->addAnOlaAccStmtParseLog($extractBookingSummaryParseLog['stage'], $extractBookingSummaryParseLog['content'], $extractBookingSummaryParseLog['level'], $extractBookingSummaryParseLog['pattern']);
                $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st4_pct); // add a warning etc.
                continue;
            }
            $extractBookingSummaryParseLog['level'] = 'INFO';
            $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st4_pct); // add a warning etc.
            $this->addAnOlaAccStmtParseLog($extractBookingSummaryParseLog['stage'], $extractBookingSummaryParseLog['content'], $extractBookingSummaryParseLog['level'], $extractBookingSummaryParseLog['pattern']);
            //$details['DayOfWeek'] = $this->stripWS($summaryData[2][0]);
            $details['Summary'] = [];
            $details['Summary']['Bookings'] = $summaryBookingNum = $this->stripRupee($this->stripWS($summaryData[1][0]));
            $details['Summary']['OperatorBill'] = $summaryOpsBill = $this->stripRupee($this->stripComma($this->stripWS($summaryData[2][0])));
            $details['Summary']['OlaCommission'] = $summaryOlaComm = $this->stripRupee($this->stripComma($this->stripWS($summaryData[3][0])));
            $details['Summary']['RideEarnings'] = $summaryRideEarnings = $this->stripRupee($this->stripComma($this->stripWS($summaryData[4][0])));
            $details['Summary']['Tolls'] = $summaryTolls = $this->stripRupee($this->stripComma($this->stripWS($summaryData[5][0])));
            $details['Summary']['TDS'] = $summaryTds = $this->stripRupee($this->stripComma($this->stripWS($summaryData[6][0])));
            $details['Summary']['NetEarnings'] = $summaryNetEarnings = $this->stripRupee($this->stripComma($this->stripWS($summaryData[7][0])));
            $details['Summary']['CashCollected'] = $summaryCashCollected = $this->stripRupee($this->stripComma($this->stripWS($summaryData[8][0])));
            $booking_summary_id = $this->addAnOlaBookingSummary(strtotime($date) , $summaryBookingNum, $summaryOpsBill, $summaryOlaComm, $summaryRideEarnings, $summaryTolls, $summaryTds, $summaryNetEarnings, $summaryCashCollected, $capturedCar);
            $timeOfTheDayPattern = '([\d: ]+)\s*(A\s*M|P\s*M)';
            $crn_osnPattern = '(CRN|OSN)\s*(?:###PAGER###)*\s*([\d \t]*\s*)(?:[.]*)(?:\()*(\s*r\s*e\s*v|\s*r\s*e\s*c\s*|\s*s\s*s\s*c\s*)*(?:\))*'; //'(?:CRN)\s*(?:###PAGER###)*\s*([\d \t]*\s*)(?:\()*(\s*r\s*e\s*v|\s*r\s*e\s*c\s*|\s*s\s*s\s*c\s*)*(?:\))*';
            $distanceInKmsPattern = '([\d. ]*)(?:K\s*M)';
            $rideTimeStrictPattern = '(?:R\s*i\s*d\s*e\s*T\s*i\s*m\s*e\s*:\s*)\s*([\d.\s]*)\s*(?:m\s*i\s*n)';
            $rideTimeSharpPattern = '([\d.\s]*)\s*(?=m\s*i\s*n)';
            $tripAccountPattern = '\s*(?:/\s*-)*\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(?:(?:###PAGER###)*.*\s*.*\s*.*(?:###END###)*)'; //'\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(-*\s*₹[. \t\s\d,]*)\s*(?:(?:###PAGER###)*.*\s*.*\s*.*(?:###END###)*)'; // '\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*₹\s*([. \t\s\d,]*)\s*(?:(?:###PAGER###)*.*\s*.*\s*.*(?:###END###)*)';
            
            //split by time..
            $splitBookingTripsPattern = $pregDelimitPattern . $timeOfTheDayPattern . $pregDelimitPattern . 'u';
            $timeOfTheDayNum = (int)preg_match_all($splitBookingTripsPattern, $carBookingDateDetailsTxt, $timeOfTheDayTripSplit);
            $splitBookingTripsParseLog = ['stage' => 'SPLIT_BOOKING_TRIPS', 'content' => $carBookingDateDetailsTxt, 'pattern' => $splitBookingTripsPattern];
            $proc_stage_car_booking_st5_pct = (5 / 6) * 100;
            
            if ($timeOfTheDayNum > 0 /* && $timeOfTheDayNum == $summaryBookingNum */ /* as this might be a reversal and hence a booking count will still not include  a reversal */) {
                $splitBookingTripsParseLog['level'] = 'INFO';
                $this->addAnOlaAccStmtParseLog($splitBookingTripsParseLog['stage'], $splitBookingTripsParseLog['content'], $splitBookingTripsParseLog['level'], $splitBookingTripsParseLog['pattern']);
                $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st5_pct); // add a warning etc.
                $details['Details'] = [];
                $timeOfTheDayTripMatches = $timeOfTheDayTripSplit[0];
                $timeOfTheDayTripTimes = $timeOfTheDayTripSplit[1];
                $timeOfTheDayTripAMPM = $timeOfTheDayTripSplit[2];
                $timeOffsets = 0;
                
                foreach ($timeOfTheDayTripMatches as $ki => $daySplitMatch) {
                    $tripDetails = [];
                    $tripDetails['Bookings'] = [];
                    $timeOfDay = $this->stripWS($timeOfTheDayTripTimes[$ki]); //strip all spaces..
                    $ampm = $this->stripWS($timeOfTheDayTripAMPM[$ki]); //strip all spaces..
                    $tripDetails['Bookings']['Time'] = $trip_start_time = $timeOfDay . ' ' . $ampm;
                    $thizAnchorString = $daySplitMatch;
                    $thizAnchorPos = strpos($carBookingDateDetailsTxt, $thizAnchorString, $timeOffsets + 1);
                    $timeOffsets = 0; //reset once applied..
                    $thadAnchorString = '';
                    $le = NULL;
                    $carTripDetailsTxt = NULL;
                    
                    if (array_key_exists($ki + 1, $timeOfTheDayTripMatches)) { //no more "Booking Details.." remain to anchor between..
                        $thadAnchorString = $timeOfTheDayTripMatches[$ki + 1];
                        $thadAnchorPos = strpos($carBookingDateDetailsTxt, $thadAnchorString, $thizAnchorPos + 1); // should always be found since this is a matched string..//but offset in-case of repetitive susbsequent time pattern..
                        $le = $thadAnchorPos - $thizAnchorPos;
                        $carTripDetailsTxt = substr($carBookingDateDetailsTxt, $thizAnchorPos, $le);
                    }
                    else {
                        $carTripDetailsTxt = substr($carBookingDateDetailsTxt, $thizAnchorPos);
                    }
                    
                    if ($thizAnchorString == $thadAnchorString) { //duplicate time delimiters..
                        $timeOffsets = $thizAnchorPos;
                    }
                    $extractBookingTripsParseLog = ['stage' => 'EXTRACT_BOOKING_TRIPS', 'content' => $carBookingDateDetailsTxt, 'pattern' => $thizAnchorString . "$$" . $thadAnchorString, 'level' => 'INFO'];
                    $this->addAnOlaAccStmtParseLog($extractBookingTripsParseLog['stage'], $extractBookingTripsParseLog['content'], $extractBookingTripsParseLog['level'], $extractBookingTripsParseLog['pattern']);
                    /**
                     * CRN / OSN [START]
                     */
                    $extractCRNOSNPattern = $pregDelimitPattern . $crn_osnPattern . $pregDelimitPattern . 'u';
                    $crn_osnFound = (int)preg_match_all($extractCRNOSNPattern, $carTripDetailsTxt, $crn_osnData);
                    $extractBookingTripsCRNOSNParseLog = ['stage' => 'EXTRACT_BOOKING_TRIPS_CRN_OSN', 'content' => $carTripDetailsTxt, 'pattern' => $extractCRNOSNPattern];
                    
                    if ($crn_osnFound > 0) {
                        $extractBookingTripsCRNOSNParseLog['level'] = 'INFO';
                        $tripDetails['Bookings']['TXN_CRN_OSN_Trip_Type'] = $txn_crn_osn_trip_type = $this->stripWS($crn_osnData[1][0]);
                        $tripDetails['Bookings']['TXN_CRN_OSN_Num'] = $txn_crn_osn = $this->stripWS($crn_osnData[2][0]);
                        
                        if (isset($crn_osnData[3][0]) && !empty($crn_osnData[3][0])) {
                            $tripDetails['Bookings']['TXN_CRN_OSN_Type'] = $txn_crn_osn_type = $this->stripWS($crn_osnData[3][0]);
                        }
                        else {
                            $tripDetails['Bookings']['TXN_CRN_OSN_Type'] = $txn_crn_osn_type = 'default';
                        }
                    }
                    else {
                        $extractBookingTripsCRNOSNParseLog['level'] = 'ERROR';
                        $tripDetails['Bookings']['TXN_CRN_OSN_Trip_Type'] = $txn_crn_osn_trip_type = '';
                        $tripDetails['Bookings']['TXN_CRN_OSN_Num'] = $txn_crn_osn = 0;
                        $tripDetails['Bookings']['TXN_CRN_OSN_Type'] = $txn_crn_osn_type = 'default';
                    }
                    $this->addAnOlaAccStmtParseLog($extractBookingTripsCRNOSNParseLog['stage'], $extractBookingTripsCRNOSNParseLog['content'], $extractBookingTripsCRNOSNParseLog['level'], $extractBookingTripsCRNOSNParseLog['pattern']);
                    /**
                     * CRN / OSN [END]
                     */
                    /**
                     * Distance [START]
                     */
                    $extractBookingTripsDistPattern = $pregDelimitPattern . $distanceInKmsPattern . $pregDelimitPattern . 'u';
                    $distanceInKmsFound = (int)preg_match_all($extractBookingTripsDistPattern, $carTripDetailsTxt, $distanceInKmsData);
                    $extractBookingTripsDistParseLog = ['stage' => 'EXTRACT_BOOKING_TRIPS_DIST', 'content' => $carTripDetailsTxt, 'pattern' => $extractBookingTripsDistPattern];
                    
                    if ($distanceInKmsFound > 0) {
                        $extractBookingTripsDistParseLog['level'] = 'INFO';
                        $tripDetails['Bookings']['DistanceInKms'] = $trip_distance_kms = $this->stripWS($distanceInKmsData[1][0]);
                    }
                    else {
                        $extractBookingTripsDistParseLog['level'] = 'ERROR';
                        $tripDetails['Bookings']['DistanceInKms'] = $trip_distance_kms = 0.0;
                    }
                    $this->addAnOlaAccStmtParseLog($extractBookingTripsDistParseLog['stage'], $extractBookingTripsDistParseLog['content'], $extractBookingTripsDistParseLog['level'], $extractBookingTripsDistParseLog['pattern']);
                    /**
                     * Distance [END]
                     */
                    /**
                     * Ride time found: primary
                     */
                    $extractBookingTripsRideTimePattern = $pregDelimitPattern . $rideTimeStrictPattern . $pregDelimitPattern . 'u';
                    $rideTimeFound = (int)preg_match_all($extractBookingTripsRideTimePattern, $carTripDetailsTxt, $rideTimeData);
                    $extractBookingTripsRideTimeParseLog = ['stage' => 'EXTRACT_BOOKING_TRIPS_RIDE_TIME', 'content' => $carTripDetailsTxt, 'pattern' => $extractBookingTripsRideTimePattern];
                    
                    if ($rideTimeFound > 0) {
                        /**
                         * if Ride time  not found: try secondary pattern
                         */
                        $rideTimeData = [];
                        $extractBookingTripsRideTimeSharpPattern = $pregDelimitPattern . $rideTimeSharpPattern . $pregDelimitPattern . 'u';
                        $rideTimeFound = (int)preg_match_all($extractBookingTripsRideTimeSharpPattern, $carTripDetailsTxt, $rideTimeData);
                        $extractBookingTripsRideTimeParseLog['pattern'] = $extractBookingTripsRideTimeSharpPattern;
                    }
                    /**
                     * RideTimeInMins [START]
                     */
                    
                    if ($rideTimeFound > 0 /*                     * after primary  & secondary checks */) {
                        $extractBookingTripsRideTimeParseLog['level'] = 'INFO';
                        $tripDetails['Bookings']['RideTimeInMins'] = $trip_ride_time_mins = $this->stripWS($rideTimeData[1][0]);
                    }
                    else {
                        $extractBookingTripsRideTimeParseLog['level'] = 'ERROR';
                        $tripDetails['Bookings']['RideTimeInMins'] = $trip_ride_time_mins = 0.0;
                    }
                    $this->addAnOlaAccStmtParseLog($extractBookingTripsRideTimeParseLog['stage'], $extractBookingTripsRideTimeParseLog['content'], $extractBookingTripsRideTimeParseLog['level'], $extractBookingTripsRideTimeParseLog['pattern']);
                    /**
                     * RideTimeInMins [END]
                     */
                    /**
                     * Trip Financials [START]
                     */
                    //Add an end-delimiter..
                    $carTripDetailsTxt.= '###END###';
                    $extractBookingTripsFinancialsPattern = $pregDelimitAltPattern . $tripAccountPattern . $pregDelimitAltPattern . 'u';
                    $tripAccountsFound = (int)preg_match_all($extractBookingTripsFinancialsPattern, $carTripDetailsTxt, $tripAccountsData);
                    $extractBookingTripsFinancialsParseLog = ['stage' => 'EXTRACT_BOOKING_TRIPS_FINANCIALS', 'content' => $carTripDetailsTxt, 'pattern' => $extractBookingTripsFinancialsPattern, 'level' => 'INFO'];
                    
                    if (!$tripAccountsFound && count($tripAccountsData) != 7) {
                        // Check Format/Structure for Trip  Financial Details
                        $extractBookingTripsFinancialsParseLog['level'] = 'FATAL';
                        continue;
                    }
                    $this->addAnOlaAccStmtParseLog($extractBookingTripsFinancialsParseLog['stage'], $extractBookingTripsFinancialsParseLog['content'], $extractBookingTripsFinancialsParseLog['level'], $extractBookingTripsFinancialsParseLog['pattern']);
                    $tripDetails['OperatorBill'] = $trip_operator_bill = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[1][0])));
                    $tripDetails['OlaCommission'] = $trip_ola_commission = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[2][0])));
                    $tripDetails['RideEarnings'] = $trip_ride_earnings = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[3][0])));
                    $tripDetails['Tolls'] = $trip_tolls = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[4][0])));
                    $tripDetails['TDS'] = $trip_tds = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[5][0])));
                    $tripDetails['NetEarnings'] = $trip_net_earnings = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[6][0])));
                    $tripDetails['CashCollected'] = $trip_cash_collected = $this->stripRupee($this->stripComma($this->stripWS($tripAccountsData[7][0])));
                    /**
                     * Trip Financials [END]
                     */
                    $trip_detail_id = $this->addAnOlaBookingTripDetail($date, $trip_start_time, $txn_crn_osn_trip_type, $txn_crn_osn, $txn_crn_osn_type, $trip_distance_kms, $trip_ride_time_mins, $trip_operator_bill, $trip_ola_commission, $trip_ride_earnings, $trip_tolls, $trip_tds, $trip_net_earnings, $trip_cash_collected, $capturedCar, $booking_summary_id);
                    
                    if ($trip_detail_id > 0) {
                        $this->addAnOlaTripTxn($date, $txn_crn_osn_trip_type, $txn_crn_osn, $txn_crn_osn_type, $trip_operator_bill, $trip_ola_commission, $trip_ride_earnings, $trip_tolls, $trip_tds, $trip_net_earnings, $trip_cash_collected, $capturedCar);
                    }
                    $proc_stage_car_booking_st6_pct = (6 / 6) * 100;
                    $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st6_pct); // add a warning etc.
                    array_push($details['Details'], $tripDetails);
                }
            }
            else {
                $splitBookingTripsParseLog['level'] = 'ERROR';
                $this->addAnOlaAccStmtParseLog($splitBookingTripsParseLog['stage'], $splitBookingTripsParseLog['content'], $splitBookingTripsParseLog['level'], $splitBookingTripsParseLog['pattern']);
                //Check Format/Structure for Rides Data, Booking Details
                $this->renderProcStageProgPct($proc_stage_carbooking, $proc_stage_car_booking_st5_pct); // add a warning etc.
                continue;
            }
            array_push($perDateDetails, $details);
        }
        /**
         * Now extract content in between the Dates
         */
        return $perDateDetails;
    }
    private 
    function addAnOlaTripTxn($date, $txn_crn_osn_trip_type, $txn_crn_osn, $txn_crn_osn_type, $trip_operator_bill, $trip_ola_commission, $trip_ride_earnings, $trip_tolls, $trip_tds, $trip_net_earnings, $trip_cash_collected, $capturedCar) {
        $OlaTripData = [];
        $OlaTripData['date'] = strtotime($date);
        $OlaTripData['txn_crn_osn_trip_type'] = $txn_crn_osn_trip_type;
        $OlaTripData['txn_crn_osn'] = $txn_crn_osn;
        $OlaTripData['txn_crn_osn_type'] = $txn_crn_osn_type;
        $OlaTripData['trip_operator_bill'] = $trip_operator_bill;
        $OlaTripData['trip_ola_commission'] = $trip_ola_commission;
        $OlaTripData['trip_ride_earnings'] = $trip_ride_earnings;
        $OlaTripData['trip_tolls'] = $trip_tolls;
        $OlaTripData['trip_tds'] = $trip_tds;
        $OlaTripData['trip_net_earnings'] = $trip_net_earnings;
        $OlaTripData['trip_cash_collected'] = $trip_cash_collected;
        $OlaTripData['car_number'] = $capturedCar;
        $this->initGearmanClient();
        $job_handle = $this->gclient->doBackground("addAnOlaTripTxn", json_encode(array_merge(array(
            'period_start' => $this->period_start_ts,
            'period_end' => $this->period_end_ts
        ) , $OlaTripData)));
    }
    /**
     *
     * @param type $olaIncentives
     * @return type
     */
    private 
    function addAnOlaIncentive($olaIncentives) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaIncentives2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaIncentivesData = array_merge($olaIncentives2, $olaIncentives);
        $olaIncentivesQ = $this->makeAnInsertQ('ola_acc_stmt_incentives', $olaIncentivesData);
        $r = $this->dbHandle->query($olaIncentivesQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     *
     *
     */
    private 
    function addAnOlaBonus($olaBonus) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaBonus2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaBonusData = array_merge($olaBonus2, $olaBonus);
        $olaBonusQ = $this->makeAnInsertQ('ola_acc_stmt_bonus', $olaBonusData);
        $r = $this->dbHandle->query($olaBonusQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     * @param type $olaAdjustments
     * @return type
     */
    private 
    function addAnOlaAdjustment($olaAdjustments) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaAdjustments2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaAdjustmentsData = array_merge($olaAdjustments2, $olaAdjustments);
        $olaAdjustmentsQ = $this->makeAnInsertQ('ola_acc_stmt_adjustment', $olaAdjustmentsData);
        $r = $this->dbHandle->query($olaAdjustmentsQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     */
    private 
    function addAnOlaMbgCalc($olaMbgCalc) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaMbgCalc2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaMbgCalcData = array_merge($olaMbgCalc2, $olaMbgCalc);
        $olaMbgCalcQ = $this->makeAnInsertQ('ola_acc_stmt_mbg_calc', $olaMbgCalcData);
        $r = $this->dbHandle->query($olaMbgCalcQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     *
     */
    private 
    function issetAndIsNotEmpty($VV) {
        
        if (isset($VV) && !empty($VV)) {
            return true;
        }
        return false;
    }
    /**
     *
     */
    public 
    function getIncentives($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        // fucking non-capturing group..
        $expressionIncentives = '/\s*(I\s*n\s*c\s*e\s*n\s*t\s*i\s*v\s*e\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/'; //'/\s*(?:T\s*r\s*a\s*n\s*s\s*a\s*c\s*t\s*i\s*o\s*n\s*D\s*e\s*t\s*a\s*i\s*l\s*s)\s*(I\s*n\s*c\s*e\s*n\s*t\s*i\s*v\s*e[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $incentivesMatcher = (int)preg_match_all($expressionIncentives, $allContent, $incentivesMatches);
        $found_incentives = 'found_incentives';
        
        if ($incentivesMatcher > 0) {
            $found_incentives_header = "Found incentives!";
            $this->renderProcStageProg($found_incentives, $found_incentives_header, true);
            $this->renderProcStageProgPct($found_incentives, 100);
            $incentiveBlock = $incentivesMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $incentiveRowMatcher = (int)preg_match_all($splitPattern, $incentiveBlock, $incentiveRowMatches);
            $proc_stage_all_incentives = 'all_incentives';
            $proc_stage_all_incentives_header = "Processing $incentiveRowMatcher incentives!";
            $this->renderProcStageProg($proc_stage_all_incentives, $proc_stage_all_incentives_header, true);
            
            if ($incentiveRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractBookingsPattern = '/([\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)/'; //'/([\d\s]*)(?=\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?:(?=[A-Z][A-Z])|(?=\s*,\s*F\s*o\s*r\s*))/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)(?:\s*,\s*F\s*o\s*r\s*)/'; // (?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)(?=[A-Z][A-Z])
                $extractDescriptionPatternPlaceholder = '/(?:b\s*o\s*o\s*k\s*i\s*n\s*g\s*s)\s*(?:###PAGER###){0,1}\s*(\s*.*\s*.*)\s*(?:###PAGER###){0,1}\s*(?=%s)/'; //"/(?:b\s*o\s*o\s*k\s*i\s*n\s*g\s*s)(\s*.*\s*.*)(?=%s)/";
                $incentiveRowBlocks = $incentiveRowMatches[0];
                end($incentiveRowBlocks);
                $incentiveRowsEnd = key($incentiveRowBlocks);
                reset($incentiveRowBlocks);
                $structuredIncentives = [];
                //print_r($incentiveRowBlocks);//die;
                
                foreach ($incentiveRowBlocks as $key => $aRowBlock) {
                    $aIncentive = [];
                    $aIncentiveData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $incentiveRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $incentiveRowBlocks[$key + 1])) {
                        // continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aIncentive, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $aIncentiveData['for_transaction_day'] = $txnTs;
                        array_push($aIncentive, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aIncentive, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $aIncentiveData['payment_post_day'] = $ppTs;
                        array_push($aIncentive, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aIncentive, ['col' => 'car_number', 'val' => $carNum]);
                        $aIncentiveData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $bookingsMatcher = (int)preg_match_all($extractBookingsPattern, $aRowBlock, $bookingsMatches);
                    
                    if ($bookingsMatcher > 0) {
                        $bookings = $this->stripWS($bookingsMatches[1][0]);
                        array_push($hashCollector, $bookings);
                        array_push($aIncentive, ['col' => 'bookings', 'val' => $bookings]);
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[1][0])) {
                            //$malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0]));
                        array_push($hashCollector, $amount);
                        array_push($aIncentive, ['col' => 'amount', 'val' => $amount]);
                        $aIncentiveData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aIncentive, ['col' => 'type', 'val' => $type]);
                        $aIncentiveData['type'] = $type;
                    }
                    else {
                        //$malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    $extractDescriptionPattern = sprintf($extractDescriptionPatternPlaceholder, $carNumMatches[1][0]);
                    $descriptionMatcher = (int)preg_match_all($extractDescriptionPattern, $aRowBlock, $descriptionMatches);
                    
                    if ($descriptionMatcher > 0) {
                        $description = $this->stripWS($descriptionMatches[1][0]);
                        array_push($hashCollector, $description);
                        array_push($aIncentive, ['col' => 'description', 'val' => $description]);
                        $aIncentiveData['description'] = $description;
                    }
                    array_push($aIncentive, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aIncentive, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aIncentiveData['reference'] = $ref_id;
                    $aIncentiveData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaIncentive = (int)$this->addAnOlaIncentive($aIncentive);
                    
                    if ($resAnOlaIncentive > 0 && !$malformed) {
                        $this->addAnOlaIncentiveTxn($aIncentiveData);
                    }
                    $aKeyIncentive = "$key" . "_incentive";
                    $aKeyIncentive_header = "Processed an incentive, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyIncentive, $aKeyIncentive_header, false, $aIncentive);
                    $this->renderProcStageProgPct($aKeyIncentive, 100);
                    array_push($structuredIncentives, $aIncentive);
                    $proc_stage_all_incentives_pct = (($key + 1) / $incentiveRowMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_incentives, $proc_stage_all_incentives_pct);
                }
                //print_r($structuredIncentives);die;
                
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_incentives, 999);
            }
        }
        else {
            $found_incentives_header = "Not found incentives!";
            $this->renderProcStageProg($found_incentives, $found_incentives_header, true);
            $this->renderProcStageProgPct($found_incentives, 999);
        }
    }
    /**
     *
     */
    public 
    function getBonus($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        // fucking non-capturing group..
        $expressionBonus = '/\s*(B\s*o\s*n\s*u\s*s\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $bonusMatcher = (int)preg_match_all($expressionBonus, $allContent, $bonusMatches);
        $found_bonus = 'found_bonus';
        
        if ($bonusMatcher > 0) {
            $found_bonus_header = "Found bonus!";
            $this->renderProcStageProg($found_bonus, $found_bonus_header, true);
            $this->renderProcStageProgPct($found_bonus, 100);
            $bonusBlock = $bonusMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $bonusRowMatcher = (int)preg_match_all($splitPattern, $bonusBlock, $bonusRowMatches);
            $proc_stage_all_bonus = 'all_bonus';
            $proc_stage_all_bonus_header = "Processing $bonusRowMatcher bonuses!";
            $this->renderProcStageProg($proc_stage_all_bonus, $proc_stage_all_bonus_header, true);
            
            if ($bonusRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                
                //$extractBookingsPattern = '/([\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)/'; //'/([\d\s]*)(?=\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?:(?=[A-Z][A-Z])|(?=\s*,\s*F\s*o\s*r\s*))/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)(?:\s*,\s*F\s*o\s*r\s*)/'; // (?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)(?=[A-Z][A-Z])
                
                //$extractDescriptionPatternPlaceholder = '/(?:b\s*o\s*o\s*k\s*i\s*n\s*g\s*s)\s*(?:###PAGER###){0,1}\s*(\s*.*\s*.*)\s*(?:###PAGER###){0,1}\s*(?=%s)/'; //"/(?:b\s*o\s*o\s*k\s*i\s*n\s*g\s*s)(\s*.*\s*.*)(?=%s)/";
                $bonusRowBlocks = $bonusRowMatches[0];
                end($bonusRowBlocks);
                $bonusRowsEnd = key($bonusRowBlocks);
                reset($bonusRowBlocks);
                $structuredBonus = [];
                //print_r($bonusRowBlocks);//die;
                
                foreach ($bonusRowBlocks as $key => $aRowBlock) {
                    $aBonus = [];
                    $aBonusData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $bonusRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $bonusRowBlocks[$key + 1])) {
                        //continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aBonus, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $aBonusData['for_transaction_day'] = $txnTs;
                        array_push($aBonus, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aBonus, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $aBonusData['payment_post_day'] = $ppTs;
                        array_push($aBonus, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aBonus, ['col' => 'car_number', 'val' => $carNum]);
                        $aBonusData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    /*$bookingsMatcher = (int)preg_match_all($extractBookingsPattern, $aRowBlock, $bookingsMatches);
                    
                    if ($bookingsMatcher > 0) {
                        $bookings = $this->stripWS($bookingsMatches[1][0]);
                        array_push($hashCollector, $bookings);
                        array_push($aBonus, ['col' => 'bookings', 'val' => $bookings]);
                    }*/
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[1][0])) {
                            //$malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0]));
                        array_push($hashCollector, $amount);
                        array_push($aBonus, ['col' => 'amount', 'val' => $amount]);
                        $aBonusData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aBonus, ['col' => 'type', 'val' => $type]);
                        $aBonusData['type'] = $type;
                    }
                    else {
                        // $malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    /*$extractDescriptionPattern = sprintf($extractDescriptionPatternPlaceholder, $carNumMatches[1][0]);
                    $descriptionMatcher = (int)preg_match_all($extractDescriptionPattern, $aRowBlock, $descriptionMatches);
                    
                    if ($descriptionMatcher > 0) {
                        $description = $this->stripWS($descriptionMatches[1][0]);
                        array_push($hashCollector, $description);
                        array_push($aBonus, ['col' => 'description', 'val' => $description]);
                        $aBonusData['description'] = $description;
                    }*/
                    array_push($aBonus, ['col' => 'description', 'val' => '']);
                    $aBonusData['description'] = '';
                    array_push($aBonus, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aBonus, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aBonusData['reference'] = $ref_id;
                    $aBonusData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaBonus = (int)$this->addAnOlaBonus($aBonus);
                    
                    if ($resAnOlaBonus > 0 && !$malformed) {
                        $this->addAnOlaBonusTxn($aBonusData);
                    }
                    $aKeyBonus = "$key" . "_bonus";
                    $aKeyBonus_header = "Processed a bonus, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyBonus, $aKeyBonus_header, false, $aBonus);
                    $this->renderProcStageProgPct($aKeyBonus, 100);
                    array_push($structuredBonus, $aBonus);
                    $proc_stage_all_bonus_pct = (($key + 1) / $bonusRowMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_bonus, $proc_stage_all_bonus_pct);
                }
                //print_r($structuredBonus);die;
                
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_bonus, 999);
            }
        }
        else {
            $found_bonus_header = "Not found bonus!";
            $this->renderProcStageProg($found_bonus, $found_bonus_header, true);
            $this->renderProcStageProgPct($found_bonus, 999);
        }
    }
    /**
     *
     */
    public 
    function getAdjustmentX($xContent, $xtype = 'adjustment') {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        
        switch ($xtype) {
            case 'adjustment':
                $adjustmentTypePattern = 'A\s*d\s*j\s*u\s*s\s*t\s*m\s*e\s*n\s*t';
            break;
            case 'recomplete':
                $adjustmentTypePattern = 'R\s*e\s*c\s*o\s*m\s*p\s*l\s*e\s*t\s*e';
            break;
            case 'platform_fee':
                $adjustmentTypePattern = 'P\s*l\s*a\s*t\s*f\s*o\s*r\s*m\s*f\s*e\s*e';
            break;
            case 'settlement':
                $adjustmentTypePattern = 'S\s*e\s*t\s*t\s*l\s*e\s*m\s*e\s*n\s*t';
            break;
            case 'collection':
                $adjustmentTypePattern = 'C\s*o\s*l\s*l\s*e\s*c\s*t\s*i\s*o\s*n';
            break;
            case 'deposit':
                $adjustmentTypePattern = 'D\s*e\s*p\s*o\s*s\s*i\s*t';
            break;
        }
        $extractAdjustmentPattern = '/\s*(' . $adjustmentTypePattern . '\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $adjustmentMatcher = (int)preg_match_all($extractAdjustmentPattern, $allContent, $adjustmentMatches);
        $found_adjustments = 'found_' . $xtype;
        
        if ($adjustmentMatcher > 0) {
            $found_adjustment_header = "Found $xtype!";
            $this->renderProcStageProg($found_adjustments, $found_adjustment_header, true);
            $this->renderProcStageProgPct($found_adjustments, 100);
            $adjustmentBlock = $adjustmentMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $adjustmentRowMatcher = (int)preg_match_all($splitPattern, $adjustmentBlock, $adjustmentRowMatches);
            $proc_stage_all_adjustments = 'all_' . $xtype . 's';
            $proc_stage_all_adjustments_header = "Processing $adjustmentRowMatcher $xtype!";
            $this->renderProcStageProg($proc_stage_all_adjustments, $proc_stage_all_adjustments_header, true);
            
            if ($adjustmentRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                /**
                 * "Adjustments" for an anomaly..notice the double negative...
                 * F o r
                 17-0 5-2 016
                 S ettle d
                 , P la tfo rm  fe es
                 WB23D 4232
                 18-0 5-2 016
                 - -₹ 25.0 0
                 ###PAGER###
                 *
                 *
                 *
                 *
                 */
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-*\s*-*\s*(?:###PAGER###){0,1}\s*₹)/'; //Anomalous adjustment due to double negative... //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*-*\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //Anomalous adjustment due to double negative... //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $adjustmentRowBlocks = $adjustmentRowMatches[0];
                end($adjustmentRowBlocks);
                $adjustmentRowsEnd = key($adjustmentRowBlocks);
                reset($adjustmentRowBlocks);
                $structuredAdjustments = [];
                
                foreach ($adjustmentRowBlocks as $key => $aRowBlock) {
                    $anOlaAdjustmentData = [];
                    $anAdjustment = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $adjustmentRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $adjustmentRowBlocks[$key + 1])) {
                        // continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($anAdjustment, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        array_push($anAdjustment, ['col' => 'txn_day', 'val' => $txn_day]);
                        $anOlaAdjustmentData['for_transaction_day'] = $txnTs;
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($anAdjustment, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        array_push($anAdjustment, ['col' => 'post_day', 'val' => $post_day]);
                        $anOlaAdjustmentData['payment_post_day'] = $ppTs;
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($anAdjustment, ['col' => 'car_number', 'val' => $carNum]);
                        $anOlaAdjustmentData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            // $malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($anAdjustment, ['col' => 'amount', 'val' => $amount]);
                        $anOlaAdjustmentData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($anAdjustment, ['col' => 'type', 'val' => $type]);
                        $anOlaAdjustmentData['type'] = $type;
                    }
                    else {
                        //$malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    array_push($anAdjustment, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($anAdjustment, ['col' => 'ref_id', 'val' => $ref_id]);
                    $anOlaAdjustmentData['xtype'] = $xtype;
                    $anOlaAdjustmentData['reference'] = $ref_id;
                    $anOlaAdjustmentData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaAdjustment = (int)$this->addAnOlaAdjustment($anAdjustment);
                    
                    if ($resAnOlaAdjustment > 0 && !$malformed) {
                        $this->addAnOlaAdjustmentTxn($anOlaAdjustmentData);
                    }
                    $aKeyAdjustment = "$key" . "_" . $xtype;
                    $aKeyAdjustment_header = "Processed an $xtype, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyAdjustment, $aKeyAdjustment_header, false, $anAdjustment);
                    $this->renderProcStageProgPct($aKeyAdjustment, 100);
                    array_push($structuredAdjustments, $anAdjustment);
                    $proc_stage_all_adjustment_pct = (($key + 1) / $adjustmentRowMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_adjustments, $proc_stage_all_adjustment_pct);
                }
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_adjustments, 999);
            }
        }
        else {
            $found_adjustment_header = "Not found " . $xtype . "s!";
            $this->renderProcStageProg($found_adjustments, $found_adjustment_header, true);
            $this->renderProcStageProgPct($found_adjustments, 999);
        }
    }
    /**
     *
     */
    public 
    function getMbgCalc($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractMbgCalcPattern = '/\s*(M\s*b\s*g\s*c\s*a\s*l\s*c\s*u\s*l\s*a\s*t\s*i\s*o\s*n\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $mbgCalcMatcher = (int)preg_match_all($extractMbgCalcPattern, $allContent, $mbgCalcMatches);
        $found_mbg_calc = 'found_mbg_calc';
        
        if ($mbgCalcMatcher > 0) {
            $found_mbg_calc_header = "Found Mbg Calculations!";
            $this->renderProcStageProg($found_mbg_calc, $found_mbg_calc_header, true);
            $this->renderProcStageProgPct($found_mbg_calc, 100);
            $mbgCalcBlock = $mbgCalcMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $mbgCalcRowMatcher = (int)preg_match_all($splitPattern, $mbgCalcBlock, $mbgCalcRowMatches);
            $proc_stage_all_mbg_calc = 'all_mbg_calc';
            $proc_stage_all_mbg_calc_header = "Processing $mbgCalcRowMatcher Mbg Calculations!";
            $this->renderProcStageProg($proc_stage_all_mbg_calc, $proc_stage_all_mbg_calc_header, true);
            
            if ($mbgCalcRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $mbgCalcRowBlocks = $mbgCalcRowMatches[0];
                end($mbgCalcRowBlocks);
                $mbgCalcRowsEnd = key($mbgCalcRowBlocks);
                reset($mbgCalcRowBlocks);
                $structuredMbgCalcs = [];
                
                foreach ($mbgCalcRowBlocks as $key => $aRowBlock) {
                    $anMbgCalc = [];
                    $anOlaMbgCalcData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $mbgCalcRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $mbgCalcRowBlocks[$key + 1])) {
                        //continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($anMbgCalc, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $anOlaMbgCalcData['for_transaction_day'] = $txnTs;
                        array_push($anMbgCalc, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($anMbgCalc, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $anOlaMbgCalcData['payment_post_day'] = $ppTs;
                        array_push($anMbgCalc, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($anMbgCalc, ['col' => 'car_number', 'val' => $carNum]);
                        $anOlaMbgCalcData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            //$malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($anMbgCalc, ['col' => 'amount', 'val' => $amount]);
                        $anOlaMbgCalcData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($anMbgCalc, ['col' => 'type', 'val' => $type]);
                        $anOlaMbgCalcData['type'] = $type;
                    }
                    else {
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                        //  $malformed = true;
                        
                    }
                    array_push($anMbgCalc, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($anMbgCalc, ['col' => 'ref_id', 'val' => $ref_id]);
                    $anOlaMbgCalcData['reference'] = $ref_id;
                    $anOlaMbgCalcData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaMbgCalc = (int)$this->addAnOlaMbgCalc($anMbgCalc);
                    
                    if ($resAnOlaMbgCalc > 0 && !$malformed) {
                        $this->addAnOlaMbgCalcTxn($anOlaMbgCalcData);
                    }
                    $aKeyMbgCalc = "$key" . "_mbg_calc";
                    $aKeyMbgCalc_header = "Processed an Mbg Calc, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyMbgCalc, $aKeyMbgCalc_header, false, $anMbgCalc);
                    $this->renderProcStageProgPct($aKeyMbgCalc, 100);
                    array_push($structuredMbgCalcs, $anMbgCalc);
                    $proc_stage_all_mbg_calc_pct = (($key + 1) / $mbgCalcRowMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_mbg_calc, $proc_stage_all_mbg_calc_pct);
                }
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_mbg_calc, 999);
            }
        }
        else {
            $found_mbg_calc_header = "Not found Mbg Calc!";
            $this->renderProcStageProg($found_mbg_calc, $found_mbg_calc_header, true);
            $this->renderProcStageProgPct($found_mbg_calc, 999);
        }
    }
    private 
    function addAShareEarning($olaShareEarning) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaShareEarning2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaShareEarningData = array_merge($olaShareEarning2, $olaShareEarning);
        $olaShareEarningQ = $this->makeAnInsertQ('ola_acc_stmt_share_earnings', $olaShareEarningData);
        $r = $this->dbHandle->query($olaShareEarningQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     */
    public 
    function getShareEarnings($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractShareEarningsPattern = '/\s*(O\s*l\s*a\s*s\s*h\s*a\s*r\s*e\s*e\s*a\s*r\s*n\s*i\s*n\s*g\s*s\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $shareEarningsMatcher = (int)preg_match_all($extractShareEarningsPattern, $allContent, $shareEarningsMatches);
        $found_share_earnings = 'found_share_earnings';
        
        if ($shareEarningsMatcher > 0) {
            $found_share_earnings_header = "Found Share Earnings!";
            $this->renderProcStageProg($found_share_earnings, $found_share_earnings_header, true);
            $this->renderProcStageProgPct($found_share_earnings, 100);
            $shareEarningsBlock = $shareEarningsMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $shareEarningsRowMatcher = (int)preg_match_all($splitPattern, $shareEarningsBlock, $shareEarningsRowMatches);
            $proc_stage_all_share_earnings = 'all_share_earnings';
            $proc_stage_all_share_earnings_header = "Processing $shareEarningsRowMatcher Share Earnigns!";
            $this->renderProcStageProg($proc_stage_all_share_earnings, $proc_stage_all_share_earnings_header, true);
            
            if ($shareEarningsRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $shareEarningsRowBlocks = $shareEarningsRowMatches[0];
                end($shareEarningsRowBlocks);
                $shareEarningsRowsEnd = key($shareEarningsRowBlocks);
                reset($shareEarningsRowBlocks);
                $structuredShareEarnings = [];
                // print_r($shareEarningsRowBlocks);
                
                foreach ($shareEarningsRowBlocks as $key => $aRowBlock) {
                    $aShareEarning = [];
                    $aShareEarningData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $shareEarningsRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $shareEarningsRowBlocks[$key + 1])) {
                        // continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aShareEarning, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $aShareEarningData['for_transaction_day'] = $txnTs;
                        array_push($aShareEarning, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aShareEarning, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $aShareEarningData['payment_post_day'] = $txnTs;
                        array_push($aShareEarning, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aShareEarning, ['col' => 'car_number', 'val' => $carNum]);
                        $aShareEarningData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            //$malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($aShareEarning, ['col' => 'amount', 'val' => $amount]);
                        $aShareEarningData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aShareEarning, ['col' => 'type', 'val' => $type]);
                        $aShareEarningData['type'] = $type;
                    }
                    else {
                        //$malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    array_push($aShareEarning, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aShareEarning, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aShareEarningData['reference'] = $ref_id;
                    $aShareEarningData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaShareEarning = (int)$this->addAShareEarning($aShareEarning);
                    
                    if ($resAnOlaShareEarning > 0 && !$malformed) {
                        $this->addAShareEarningTxn($aShareEarningData);
                    }
                    $aKeyShareEarning = "$key" . "share_earning";
                    $aKeyShareEarning_header = "Processed a Share Earning, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyShareEarning, $aKeyShareEarning_header, false, $aShareEarning);
                    $this->renderProcStageProgPct($aKeyShareEarning, 100);
                    array_push($structuredShareEarnings, $aShareEarning);
                    $proc_stage_all_share_earnings_pct = (($key + 1) / $shareEarningsMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_share_earnings, $proc_stage_all_share_earnings_pct);
                }
                // print_r($structuredShareEarnings); die;
                
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_share_earnings, 999);
            }
        }
        else {
            $found_share_earnings_header = "Not found Share Earnings!";
            $this->renderProcStageProg($found_share_earnings, $found_share_earnings_header, true);
            $this->renderProcStageProgPct($found_share_earnings, 999);
        }
    }
    private 
    function formatExcelDate($ts) {
        // DD/MM/YYYY
        $this->initLocalTimeZone();
        return date('d/m/Y', $ts);
    }
    private 
    function converToExcelDate($dateString) {
        $myDateTime = DateTime::createFromFormat('j-M-y', $dateString);
        return $myDateTime->format('d/m/Y');
    }
    private 
    function converToTS($dateString) {
        $this->initLocalTimeZone();
        $myDateTime = DateTime::createFromFormat('j-M-y', $dateString);
        return strtotime($myDateTime->format('d-m-Y'));
    }
    private 
    function formatExcelTime($ts) {
        // HH:MM AM/PM... 24hours format
        $this->initLocalTimeZone();
        return date('h:m A', $ts);
    }
    private 
    function addAShareCC($olaShareCC) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaShareCC2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaShareCCData = array_merge($olaShareCC2, $olaShareCC);
        $olaShareCCQ = $this->makeAnInsertQ('ola_acc_stmt_share_cash_coll', $olaShareCCData);
        $r = $this->dbHandle->query($olaShareCCQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     */
    public 
    function getShareCashCollected($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractShareCCPattern = '/\s*(O\s*l\s*a\s*s\s*h\s*a\s*r\s*e\s*c\s*a\s*s\s*h\s*c\s*o\s*l\s*l\s*e\s* c\s*t\s*e\s*d\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $shareCCMatcher = (int)preg_match_all($extractShareCCPattern, $allContent, $shareCCMatches);
        $found_share_CC = 'found_share_cc';
        
        if ($shareCCMatcher > 0) {
            $found_share_cc_header = "Found Share Cash Collected!";
            $this->renderProcStageProg($found_share_CC, $found_share_cc_header, true);
            $this->renderProcStageProgPct($found_share_CC, 100);
            $shareCCBlock = $shareCCMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $shareCCRowMatcher = (int)preg_match_all($splitPattern, $shareCCBlock, $shareCCRowMatches);
            $proc_stage_all_share_cc = 'all_share_cc';
            $proc_stage_all_share_cc_header = "Processing $shareCCRowMatcher Share Cash Collected!";
            $this->renderProcStageProg($proc_stage_all_share_cc, $proc_stage_all_share_cc_header, true);
            
            if ($shareCCRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $shareCCRowBlocks = $shareCCRowMatches[0];
                end($shareCCRowBlocks);
                $shareCCRowsEnd = key($shareCCRowBlocks);
                reset($shareCCRowBlocks);
                $structuredShareCC = [];
                
                foreach ($shareCCRowBlocks as $key => $aRowBlock) {
                    $aShareCC = [];
                    $aShareCCData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $shareCCRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $shareCCRowBlocks[$key + 1])) {
                        //continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aShareCC, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $aShareCCData['for_transaction_day'] = $txnTs;
                        array_push($aShareCC, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aShareCC, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $aShareCCData['payment_post_day'] = $ppTs;
                        array_push($aShareCC, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aShareCC, ['col' => 'car_number', 'val' => $carNum]);
                        $aShareCCData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            // $malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($aShareCC, ['col' => 'amount', 'val' => $amount]);
                        $aShareCCData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aShareCC, ['col' => 'type', 'val' => $type]);
                        $aShareCCData['type'] = $type;
                    }
                    else {
                        // $malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    array_push($aShareCC, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aShareCC, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aShareCCData['reference'] = $ref_id;
                    $aShareCCData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaShareCC = (int)$this->addAShareCC($aShareCC);
                    
                    if ($resAnOlaShareCC > 0 && !$malformed) {
                        $this->addAShareCCTxn($aShareCCData);
                    }
                    $aKeyShareCC = "$key" . "share_cc";
                    $aKeyShareCC_header = "Processed a Share Cash Collected, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyShareCC, $aKeyShareCC_header, false, $aShareCC);
                    $this->renderProcStageProgPct($aKeyShareCC, 100);
                    array_push($structuredShareCC, $aShareCC);
                    $proc_stage_all_share_cc_pct = (($key + 1) / $shareCCMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_share_cc, $proc_stage_all_share_cc_pct);
                }
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_share_cc, 999);
            }
        }
        else {
            $found_share_cc_header = "Not found Share Cash Collected!";
            $this->renderProcStageProg($found_share_CC, $found_share_cc_header, true);
            $this->renderProcStageProgPct($found_share_CC, 999);
        }
    }
    private 
    function addADDCharges($olaDDCharges) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaDDCharges2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaDDChargesData = array_merge($olaDDCharges2, $olaDDCharges);
        $olaDDChargesQ = $this->makeAnInsertQ('ola_acc_stmt_data_device_charges', $olaDDChargesData);
        $r = $this->dbHandle->query($olaDDChargesQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     */
    public 
    function getDeviceDataCharges($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractDDChargesPattern = '/\s*(D\s*a\s*t\s*a\s*a\s*n\s*d\s*d\s*e\s*v\s*i\s*c\s*e\s*c\s*h\s*a\s*r\s*g\s*e\s*s\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $DDChargesMatcher = (int)preg_match_all($extractDDChargesPattern, $allContent, $DDChargesMatches);
        $found_dd_charges = 'found_dd_charges';
        
        if ($DDChargesMatcher > 0) {
            $found_dd_charges_header = "Found Data & Device Charges!";
            $this->renderProcStageProg($found_dd_charges, $found_dd_charges_header, true);
            $this->renderProcStageProgPct($found_dd_charges, 100);
            $DDChargesBlock = $DDChargesMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $DDChargesRowMatcher = (int)preg_match_all($splitPattern, $DDChargesBlock, $DDChargesRowMatches);
            $proc_stage_all_dd_charges = 'all_dd_charges';
            $proc_stage_all_dd_charges_header = "Processing $DDChargesRowMatcher Data & Device Charges!";
            $this->renderProcStageProg($proc_stage_all_dd_charges, $proc_stage_all_dd_charges_header, true);
            
            if ($DDChargesRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $DDChargesRowBlocks = $DDChargesRowMatches[0];
                end($DDChargesRowBlocks);
                $DDChargesRowsEnd = key($DDChargesRowBlocks);
                reset($DDChargesRowBlocks);
                $structuredDDCharges = [];
                
                foreach ($DDChargesRowBlocks as $key => $aRowBlock) {
                    $aDDChargesData = [];
                    $aDDCharges = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $DDChargesRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $DDChargesRowBlocks[$key + 1])) {
                        //continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aDDCharges, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        array_push($aDDCharges, ['col' => 'txn_day', 'val' => $txn_day]);
                        $aDDChargesData['for_transaction_day'] = $txnTs;
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aDDCharges, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        array_push($aDDCharges, ['col' => 'post_day', 'val' => $post_day]);
                        $aDDChargesData['payment_post_day'] = $ppTs;
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aDDCharges, ['col' => 'car_number', 'val' => $carNum]);
                        $aDDChargesData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            // $malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($aDDCharges, ['col' => 'amount', 'val' => $amount]);
                        $aDDChargesData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aDDCharges, ['col' => 'type', 'val' => $type]);
                        $aDDChargesData['type'] = $type;
                    }
                    else {
                        // $malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    array_push($aDDCharges, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aDDCharges, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aDDChargesData['reference'] = $ref_id;
                    $aDDChargesData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaDDCharges = (int)$this->addADDCharges($aDDCharges);
                    
                    if ($resAnOlaDDCharges > 0 && !$malformed) {
                        $this->addADDChargesTxn($aDDChargesData);
                    }
                    $aKeyDDCharges = "$key" . "dd_charges";
                    $aKeyDDCharges_header = "Processed a Data & Device Charge, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyDDCharges, $aKeyDDCharges_header, false, $aDDCharges);
                    $this->renderProcStageProgPct($aKeyDDCharges, 100);
                    array_push($structuredDDCharges, $aDDCharges);
                    $proc_stage_all_dd_charges_pct = (($key + 1) / $DDChargesMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_dd_charges, $proc_stage_all_dd_charges_pct);
                }
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_dd_charges, 999);
            }
        }
        else {
            $found_dd_charges_header = "Not found Data & Device Charge!";
            $this->renderProcStageProg($found_dd_charges, $found_dd_charges_header, true);
            $this->renderProcStageProgPct($found_dd_charges, 999);
        }
    }
    private 
    function addAPenalty($olaPenalty) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaPenalty2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $olaPenaltyData = array_merge($olaPenalty2, $olaPenalty);
        $olaPenaltyQ = $this->makeAnInsertQ('ola_acc_stmt_penalty', $olaPenaltyData);
        $r = $this->dbHandle->query($olaPenaltyQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     */
    public 
    function getPenalty($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractPenaltyPattern = '/\s*(P\s*e\s*n\s*a\s*l\s*t\s*y\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[\d*,.₹\s*]+)/';
        $PenaltyMatcher = (int)preg_match_all($extractPenaltyPattern, $allContent, $PenaltyMatches);
        $found_penalty = 'found_penalty';
        
        if ($PenaltyMatcher > 0) {
            $found_penalty_header = "Found Penalty!";
            $this->renderProcStageProg($found_penalty, $found_penalty_header, true);
            $this->renderProcStageProgPct($found_penalty, 100);
            $PenaltyBlock = $PenaltyMatches[1][0];
            $splitPattern = '/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?((?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])|(?=\s*T\s*o\s*t\s*a\s*l)))/'; //'/F\s*o\s*r\s*([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([a-zA-Z0-9,₹.\s*-…#]*?(?=F\s*o\s*r\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]))/';
            $PenaltyRowMatcher = (int)preg_match_all($splitPattern, $PenaltyBlock, $PenaltyRowMatches);
            $proc_stage_all_penalty = 'all_penalty';
            $proc_stage_all_penalty_header = "Processing $PenaltyRowMatcher Penalty!";
            $this->renderProcStageProg($proc_stage_all_penalty, $proc_stage_all_penalty_header, true);
            
            if ($PenaltyRowMatcher > 0) {
                $extractDatesPattern = '/([0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractCarNumPattern = '/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)\s*(?:###PAGER###){0,1}\s*(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*(?:###PAGER###){0,1}\s*-{0,1}\s*(?:###PAGER###){0,1}\s*₹)/'; //'/(\s*[A-Z]\s*[A-Z]\s*[A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*-{0,1}\s*₹)/'; // /([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*₹)/'; //'/(?:\s*b\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*)([A-Z\d\s]*)(?=[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])/';
                $extractAmountPattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)\s*(?:###PAGER###){0,1}\s*(-{0,1})\s*(?:###PAGER###){0,1}\s*₹\s*(?:###PAGER###){0,1}\s*([.,\s\d]*)/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9]\s*)(-{0,1})\s*₹([.,\s\d]*)/';
                $extractTypePattern = '/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])\s*(?:###PAGER###){0,1}\s*([\sa-zA-Z,]*)\s*(?:###PAGER###){0,1}\s*(?=[A-Z][A-Z])/'; //'/(?:[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*-{1}\s*[0-9]\s*[0-9]\s*[0-9]\s*[0-9])([\sa-zA-Z,]*)\s*(?=[A-Z][A-Z])/';
                $PenaltyRowBlocks = $PenaltyRowMatches[0];
                end($PenaltyRowBlocks);
                $PenaltyRowsEnd = key($PenaltyRowBlocks);
                reset($PenaltyRowBlocks);
                $structuredPenalty = [];
                
                foreach ($PenaltyRowBlocks as $key => $aRowBlock) {
                    $aPenalty = [];
                    $aPenaltyData = [];
                    $hashCollector = [];
                    $malformed = false;
                    $suspense_reasons = [];
                    
                    if ($key < $PenaltyRowsEnd && $this->detectDuplicateMalRowsPgBrk($aRowBlock, $PenaltyRowBlocks[$key + 1])) {
                        // continue; // will get back  to you...again ;-)
                        $this->enumerateSuspense(1, $suspense_reasons);
                    }
                    $dateMatcher = (int)preg_match_all($extractDatesPattern, $aRowBlock, $dateMatches);
                    
                    if ($dateMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($dateMatches[1][0]) || !$this->issetAndIsNotEmpty($dateMatches[1][1])) {
                            $malformed = true;
                        }
                        $txnTs = strtotime($this->stripWS($dateMatches[1][0]));
                        $txn_day = $this->formatExcelDate($txnTs);
                        array_push($hashCollector, $txnTs);
                        array_push($aPenalty, ['col' => 'for_transaction_day', 'val' => $txnTs]);
                        $aPenaltyData['for_transaction_day'] = $txnTs;
                        array_push($aPenalty, ['col' => 'txn_day', 'val' => $txn_day]);
                        $ppTs = strtotime($this->stripWS($dateMatches[1][1]));
                        $post_day = $this->formatExcelDate($ppTs);
                        array_push($hashCollector, $ppTs);
                        array_push($aPenalty, ['col' => 'payment_post_day', 'val' => $ppTs]);
                        $aPenaltyData['payment_post_day'] = $ppTs;
                        array_push($aPenalty, ['col' => 'post_day', 'val' => $post_day]);
                    }
                    else {
                        $malformed = true;
                    }
                    $carNumMatcher = (int)preg_match_all($extractCarNumPattern, $aRowBlock, $carNumMatches);
                    
                    if ($carNumMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                            $malformed = true;
                        }
                        $carNum = $this->stripWS($carNumMatches[1][0]);
                        $this->detectMissingCar($carNum);
                        array_push($hashCollector, $carNum);
                        array_push($aPenalty, ['col' => 'car_number', 'val' => $carNum]);
                        $aPenaltyData['car_number'] = $carNum;
                    }
                    else {
                        $malformed = true;
                    }
                    $amountMatcher = (int)preg_match_all($extractAmountPattern, $aRowBlock, $amountMatches);
                    
                    if ($amountMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($amountMatches[2][0])) {
                            //$malformed = true;
                            $this->enumerateSuspense(2, $suspense_reasons);
                        }
                        $amount = $this->stripComma($this->stripWS($amountMatches[1][0] . $amountMatches[2][0]));
                        array_push($hashCollector, $amount);
                        array_push($aPenalty, ['col' => 'amount', 'val' => $amount]);
                        $aPenaltyData['amount'] = $amount;
                    }
                    else {
                        $malformed = true;
                    }
                    $typeMatcher = (int)preg_match_all($extractTypePattern, $aRowBlock, $typeMatches);
                    
                    if ($typeMatcher > 0) {
                        
                        if (!$this->issetAndIsNotEmpty($typeMatches[1][0])) {
                            $malformed = true;
                            //$this->enumerateSuspense(3, $suspense_reasons);
                            
                        }
                        $type = $this->stripWS($typeMatches[1][0]);
                        array_push($hashCollector, $type);
                        array_push($aPenalty, ['col' => 'type', 'val' => $type]);
                        $aPenaltyData['type'] = $type;
                    }
                    else {
                        //$malformed = true;
                        $this->enumerateSuspense(3.5, $suspense_reasons);
                    }
                    array_push($aPenalty, ['col' => 'malformed', 'val' => (int)$malformed]);
                    $ref_id = md5(json_encode($hashCollector));
                    array_push($aPenalty, ['col' => 'ref_id', 'val' => $ref_id]);
                    $aPenaltyData['reference'] = $ref_id;
                    $aPenaltyData['suspense_reasons'] = $suspense_reasons;
                    $resAnOlaPenalty = (int)$this->addAPenalty($aPenalty);
                    
                    if ($resAnOlaPenalty > 0 && !$malformed) {
                        $this->addAPenaltyTxn($aPenaltyData);
                    }
                    $aKeyPenalty = "$key" . "penalty";
                    $aKeyPenalty_header = "Processed a Penalty, Ref-Id: " . $ref_id;
                    $this->renderProcStageProg($aKeyPenalty, $aKeyPenalty_header, false, $aPenalty);
                    $this->renderProcStageProgPct($aKeyPenalty, 100);
                    array_push($structuredPenalty, $aPenalty);
                    $proc_stage_all_penalty_pct = (($key + 1) / $PenaltyMatcher) * 100;
                    $this->renderProcStageProgPct($proc_stage_all_penalty, $proc_stage_all_penalty_pct);
                }
            }
            else {
                $this->renderProcStageProgPct($proc_stage_all_penalty, 999);
            }
        }
        else {
            $found_penalty_header = "Not found Penalty!";
            $this->renderProcStageProg($found_penalty, $found_penalty_header, true);
            $this->renderProcStageProgPct($found_penalty, 999);
        }
    }
    private 
    function enumerateSuspense($suspense_type, &$suspense_reasons) {
        
        switch ($suspense_type) {
            case 1:
                array_push($suspense_reasons, 'Duplicate Txn by Page break');
            break;
            case 2:
                array_push($suspense_reasons, 'No Amount');
            break;
            case 3:
                array_push($suspense_reasons, 'No Type specifier');
            break;
            case 3.5:
                array_push($suspense_reasons, 'No Type found');
            break;
        }
    }
    public 
    function getAll($xContent) {
        $this->getBookingDetails($xContent);
        $this->getIncentives($xContent);
        $this->getBonus($xContent);
        $this->getMbgCalc($xContent);
        $this->getAdjustments($xContent);
        $this->getShareEarnings($xContent);
        $this->getShareCashCollected($xContent);
        $this->getDeviceDataCharges($xContent);
        $this->getPenalty($xContent);
    }
    /**
     *
     */
    public 
    function getAdjustments($xContent) {
        $this->getAdjustmentX($xContent, 'platform_fee'); // Platform fee...
        $this->getAdjustmentX($xContent); // Adjustment..
        $this->getAdjustmentX($xContent, 'recomplete'); // Recomplete...
        $this->getAdjustmentX($xContent, 'settlement'); // Settlement...
        $this->getAdjustmentX($xContent, 'collection'); // Collection...
        $this->getAdjustmentX($xContent, 'deposit'); // Deposit...
        
    }
    private 
    function addACarLevelEarning($aCarLevelEarning) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $aCarLevelEarning2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'import_id', 'val' => $this->currentImportRun]];
        $aCarLevelEarningData = array_merge($aCarLevelEarning2, $aCarLevelEarning);
        $aCarLevelEarningQ = $this->makeAnInsertQ('ola_acc_stmt_carlevel_earnings', $aCarLevelEarningData);
        $r = $this->dbHandle->query($aCarLevelEarningQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    public 
    function getCarLevelEarnings($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        $extractAcctStmtPeriod = '/\s*A\s*c\s*c\s*o\s*u\s*n\s*t\s*S\s*t\s*a\s*t\s*e\s*m\s*e\s*n\s*t\s*f\s*o\s*r\s*([\d]\s*[\d]\s*-[a-zA-Z\s]+-\s*[\d]\s*[\d]\s*)[to ]+\s*([\d]\s*[\d]\s*-[a-zA-Z\s]+-\s*[\d]\s*[\d]\s*)/';
        $extractAcctStmtPeriodMatcher = (int)preg_match_all($extractAcctStmtPeriod, $allContent, $extractAcctStmtPeriodMatches);
        //print_r();
        
        if ($extractAcctStmtPeriodMatcher > 0) {
            $from_date_extract = $this->stripWS($extractAcctStmtPeriodMatches[1][0]);
            $to_date_extract = $this->stripWS($extractAcctStmtPeriodMatches[2][0]);
            $period_start = $this->converToExcelDate($from_date_extract);
            $period_start_arr = ['col' => 'period_start', 'val' => $period_start];
            $period_start_ts = strtotime($period_start);
            $period_start_ts_arr = ['col' => 'period_start_ts', 'val' => $period_start_ts];
            $period_end = $this->converToExcelDate($to_date_extract);
            $period_end_arr = ['col' => 'period_end', 'val' => $period_end];
            $period_end_ts = strtotime($period_end);
            $period_end_ts_arr = ['col' => 'period_end_ts', 'val' => $period_end_ts];
        }
        // fucking non-capturing group..
        $expressionCarLevelEarnings = '/\s*(C\s*a\s*r\s*L\s*e\s*v\s*e\s*l\s*E\s*a\s*r\s*n\s*i\s*n\s*g\s*s\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*[a-zA-Z0-9,₹.\s*-…#]*?T\s*o\s*t\s*a\s*l\s*[₹\d]+)/';
        $carLevelEarningsMatcher = (int)preg_match_all($expressionCarLevelEarnings, $allContent, $carLevelEarningsMatches);
        $found_earnings = 'found_carlevel_earnings';
        
        if ($carLevelEarningsMatcher > 0) {
            $found_earnings_header = "Found Car Level Earnings!";
            $this->renderProcStageProg($found_earnings, $found_earnings_header, true);
            $this->renderProcStageProgPct($found_earnings, 100);
            $carLevelEarningsContent = $carLevelEarningsMatches[1][0];
            // Format check..
            $formatCheck = '/\s*C\s*a\s*r\s*L\s*e\s*v\s*e\s*l\s*E\s*a\s*r\s*n\s*i\s*n\s*g\s*s\s*C\s*a\s*r\s*N\s*u\s*m\s*b\s*e\s*r\s*B\s*o\s*o\s*k\s*i\s*n\s*g\s*s\s*R\s*i\s*d\s*e\s*E\s*a\s*r\s*n\s*i\s*n\s*g\s*s\s*I\s*n\s*c\s*e\s*n\s*t\s*i\s*v\s*e\s*s\s*T\s*o\s*l\s*l\s*O\s*t\s*h\s*e\s*r\s*E\s*a\s*r\s*n\s*i\s*n\s*g\s*s\s*T\s*o\s*t\s*a\s*l\s*/';
            $formatCheckMatcher = (int)preg_match_all($formatCheck, $carLevelEarningsContent, $formatCheckMatches);
            
            if ($formatCheckMatcher > 0) {
                $splitPattern = '/([A-Z][A-Z\d \t]+)\s*([\d]+)\s*(-*\s*₹[\d]+[,.\s\d]+)\s*(-*\s*₹[\d]+[,.\s\d]+)\s*(-*\s*₹[\d]+[,.\s\d]+)\s*(-*\s*₹[\d]+[,.\s\d]+)\s*(-*\s*₹[\d]+[,.\s\d]+)\s*/';
                $carEarningSplitter = (int)preg_match_all($splitPattern, $carLevelEarningsContent, $carEarningSplits);
                $carEarningSplitMatches = $carEarningSplits[0];
                $proc_stage_all_earnings = 'all_earnings';
                $proc_stage_all_earnings_header = "Processing $carEarningSplitter car level earnings!";
                $this->renderProcStageProg($proc_stage_all_earnings, $proc_stage_all_earnings_header, true);
                
                if ($carEarningSplitter > 0) {
                    $carNumberExtractionPattern = '/(^[A-Z][A-Z\d \t]+)/';
                    $bookingsPattern = '/^[A-Z][A-Z\d \t]+\s*([\d]+)\s*(?=-*₹[\d,. \t]+)/';
                    $extractFinancialsPattern = '/(-*₹[\d,. \t]+)/';
                    end($carEarningSplitMatches);
                    $carEarningSplitEnd = key($carEarningSplitMatches);
                    reset($carEarningSplitMatches);
                    $structuredEarnings = [];
                    
                    foreach ($carEarningSplitMatches as $key => $aSplit) {
                        $aSplitCols = [];
                        $hashCollector = [];
                        $malformed = false;
                        
                        if ($key < $carEarningSplitEnd && $this->detectDuplicateMalRowsPgBrk($aSplit, $carEarningSplitMatches[$key + 1])) {
                            continue; // will get back  to you...again ;-)
                            
                        }
                        $carNumMatcher = (int)preg_match_all($carNumberExtractionPattern, $aSplit, $carNumMatches);
                        
                        if ($carNumMatcher > 0) {
                            
                            if (!$this->issetAndIsNotEmpty($carNumMatches[1][0])) {
                                $malformed = true;
                            }
                            $carNum = strtotime($this->stripWS($carNumMatches[1][0]));
                            array_push($hashCollector, $carNum);
                            array_push($aSplitCols, ['col' => 'car_number', 'val' => $carNum]);
                        }
                        else {
                            $malformed = true;
                        }
                        $bookingsMatcher = (int)preg_match_all($bookingsPattern, $aSplit, $bookingsMatches);
                        
                        if ($bookingsMatcher > 0) {
                            
                            if (!$this->issetAndIsNotEmpty($bookingsMatches[1][0])) {
                                $malformed = true;
                            }
                            $bookings = $this->stripWS($bookingsMatches[1][0]);
                            array_push($hashCollector, $bookings);
                            array_push($aSplitCols, ['col' => 'bookings', 'val' => $bookings]);
                        }
                        else {
                            $malformed = true;
                        }
                        $amountsMatcher = (int)preg_match_all($extractFinancialsPattern, $aSplit, $amountsMatches);
                        
                        if ($amountsMatcher < 5) {
                            $ride_earnings = $this->stripRupee($this->stripComma($this->stripWS($amountsMatches[1][0])));
                            $incentives = $this->stripRupee($this->stripComma($this->stripWS($amountsMatches[1][1])));
                            $toll = $this->stripRupee($this->stripComma($this->stripWS($amountsMatches[1][2])));
                            $other_earnings = $this->stripRupee($this->stripComma($this->stripWS($amountsMatches[1][3])));
                            $total = $this->stripRupee($this->stripComma($this->stripWS($amountsMatches[1][4])));
                            array_push($hashCollector, $ride_earnings);
                            array_push($hashCollector, $incentives);
                            array_push($hashCollector, $toll);
                            array_push($hashCollector, $other_earnings);
                            array_push($hashCollector, $total);
                            array_push($aSplitCols, ['col' => 'ride_earnings', 'val' => $ride_earnings]);
                            array_push($aSplitCols, ['col' => 'incentives', 'val' => $incentives]);
                            array_push($aSplitCols, ['col' => 'toll', 'val' => $toll]);
                            array_push($aSplitCols, ['col' => 'other_earnings', 'val' => $other_earnings]);
                            array_push($aSplitCols, ['col' => 'total', 'val' => $total]);
                        }
                        else {
                            $malformed = true;
                        }
                        array_push($aSplitCols, ['col' => 'period_start_arr', 'val' => $period_start_arr]);
                        array_push($aSplitCols, ['col' => 'period_start_ts_arr', 'val' => $period_start_ts_arr]);
                        array_push($aSplitCols, ['col' => 'period_end_arr', 'val' => $period_end_arr]);
                        array_push($aSplitCols, ['col' => 'period_end_ts_arr', 'val' => $period_end_ts_arr]);
                        array_push($aSplitCols, ['col' => 'malformed', 'val' => (int)$malformed]);
                        $ref_id = md5(json_encode($hashCollector)); //not used..
                        
                        //$this->addACarLevelEarning($aSplitCols);
                        $aKeyEarning = "$key" . "_carlevel_earning";
                        $aKeyEarning_header = "Processed a car level earning!";
                        $this->renderProcStageProg($aKeyEarning, $aKeyEarning_header, false, $aSplitCols);
                        $this->renderProcStageProgPct($aKeyEarning, 100);
                        array_push($structuredEarnings, $aSplitCols);
                        $proc_stage_all_earnings_pct = (($key + 1) / $carEarningSplitter) * 100;
                        $this->renderProcStageProgPct($proc_stage_all_earnings, $proc_stage_all_earnings_pct);
                    }
                }
                else {
                    $this->renderProcStageProgPct($proc_stage_all_earnings, 999);
                }
                print_r($structuredEarnings);
                die;
            }
            else {
                // Format check failed...no point parsing...
                $found_earnings_header = "Bad format for Car level earnings!";
                $this->renderProcStageProg($found_earnings, $found_earnings_header, true);
                $this->renderProcStageProgPct($found_earnings, 999);
            }
        }
        else {
            $found_earnings_header = "Not found Car level earnings!";
            $this->renderProcStageProg($found_earnings, $found_earnings_header, true);
            $this->renderProcStageProgPct($found_earnings, 999);
        }
    }
    public 
    function getCarLevelDeductions($xContent) {
        // strip all bullshit..
        $allContent = $this->stripPageFooter($xContent);
        print_r($allContent);
        die;
        // fucking non-capturing group..
        $expressionCarLevelDeductions = '//';
        // $bookingDetailsMatcher = (int) preg_match_all($expressionBookingDetails, $allContent, $bookingDetailsMatches);
        
    }
    public 
    function runStep1() {
        $this->initTwig();
        $renderVars = ['title' => 'Import Ola Account Statement', 'olaImportLegend' => 'Import Ola Account Statement', ];
        $this->render('olaWeeklyImport.html.twig', $renderVars);
    }
    /**
     *
     * @param type $table
     * @param type $data
     * @return type
     */
    private 
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
    /**
     *
     * @param type $table
     * @param type $data
     * @param type $condition
     * @return type
     */
    private 
    function makeAnUpdateQ($table, $data, $condition = null) {
        $prefixTableQ = "UPDATE `$table` SET ";
        $suffixWhere = " WHERE ";
        $setColValPlaceholder = "`%s` = '%s'";
        end($data);
        $end = key($data);
        reset($data);
        
        foreach ($data as $key => $datum) {
            $thisColValPlaceholder = $setColValPlaceholder;
            
            if ($key < $end) {
                $thisColValPlaceholder.= ",";
            }
            $prefixTableQ = sprintf($prefixTableQ . $thisColValPlaceholder, $datum['col'], $datum['val']);
        }
        
        if (is_array($condition)) {
            $suffixWhere = sprintf($suffixWhere . $setColValPlaceholder, $condition['col'], $condition['val']);
            $prefixTableQ.= $suffixWhere;
        }
        return $prefixTableQ . ';';
    }
    /**
     *
     * @param type $olaImportThings
     * @return type
     */
    private 
    function addAnOlaImport($olaImportThings) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaImportThings2 = [['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at]];
        $olaImportData = array_merge($olaImportThings2, $olaImportThings);
        $olaImportQ = $this->makeAnInsertQ('ola_imports', $olaImportData);
        $r = $this->dbHandle->query($olaImportQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     * @param type $olaImportThings
     * @return type
     */
    private 
    function updateAnOlaImport($olaImportThings) {
        $this->initLocalTimeZone();
        $this->initDB();
        $updated_at = time();
        $olaImportThings2 = [['col' => 'updated_at', 'val' => $updated_at]];
        $olaImportUData = array_merge($olaImportThings2, $olaImportThings);
        $olaImportUClause = ['col' => 'id', 'val' => $this->currentImportRun];
        $olaImportUQ = $this->makeAnUpdateQ('ola_imports', $olaImportUData, $olaImportUClause);
        return $this->dbHandle->query($olaImportUQ);
    }
    /**
     *
     * @param type $level
     * @return type
     */
    private static 
    function getLogLevel($level = 'INFO') {
        
        if (in_array($level, self::$logLevelMap)) {
            return array_search($level, self::$logLevelMap);
        }
        return array_search('INFO', self::$logLevelMap);
    }
    /**
     *
     * @param type $stage
     * @return type
     */
    private static 
    function getParseStage($stage = 'ARBITRARY') {
        
        if (in_array($stage, self::$parseStageMap)) {
            return array_search($stage, self::$parseStageMap);
        }
        return array_search('ARBITRARY', self::$parseStageMap);
    }
    /**
     *
     * @param type $stage
     * @param type $content
     * @param type $level
     * @param type $pattern
     * @return type
     */
    private 
    function addAnOlaAccStmtParseLog($stage, $content, $level = 'INFO', $pattern = '') {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaAccStmtParseLogEntry = [['col' => 'import_id', 'val' => $this->currentImportRun], ['col' => 'stage', 'val' => self::getParseStage($stage) ], ['col' => 'level', 'val' => self::getLogLevel($level) ], ['col' => 'content', 'val' => $content], ['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at], ['col' => 'pattern', 'val' => $pattern]];
        $olaAccStmtParseLogQ = $this->makeAnInsertQ('ola_acc_stmt_parse_log', $olaAccStmtParseLogEntry);
        $r = $this->dbHandle->query($olaAccStmtParseLogQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle);
        }
    }
    /**
     *
     * @param type $day
     * @param type $bookings
     * @param type $operator_bill
     * @param type $ola_commission
     * @param type $ride_earnings
     * @param type $tolls
     * @param type $tds
     * @param type $net_earnings
     * @param type $cash_collected
     * @param type $car_number
     * @return type
     */
    private 
    function addAnOlaBookingSummary($day, $bookings, $operator_bill, $ola_commission, $ride_earnings, $tolls, $tds, $net_earnings, $cash_collected, $car_number) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $olaBookingSummaryEntry = [['col' => 'day', 'val' => $day], ['col' => 'bookings', 'val' => $bookings], ['col' => 'operator_bill', 'val' => $operator_bill], ['col' => 'ola_commission', 'val' => $ola_commission], ['col' => 'ride_earnings', 'val' => $ride_earnings], ['col' => 'tolls', 'val' => $tolls], ['col' => 'tds', 'val' => $tds], ['col' => 'net_earnings', 'val' => $net_earnings], ['col' => 'cash_collected', 'val' => $cash_collected], ['col' => 'car_number', 'val' => $car_number], ['col' => 'import_id', 'val' => $this->currentImportRun], ['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at]];
        $olaBookingSummaryEntryQ = $this->makeAnInsertQ('ola_acc_stmt_booking_summary', $olaBookingSummaryEntry);
        $r = $this->dbHandle->query($olaBookingSummaryEntryQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle); //summary_id
            
        }
    }
    /**
     *
     * @param type $day
     * @param type $start_time
     * @param type $trip_type
     * @param type $crn_osn
     * @param type $crn_osn_type
     * @param type $distance_kms
     * @param type $ride_time_mins
     * @param type $operator_bill
     * @param type $ola_commission
     * @param type $ride_earnings
     * @param type $tolls
     * @param type $tds
     * @param type $net_earnings
     * @param type $cash_collected
     * @param type $car_number
     * @param type $summary_id
     * @return type
     */
    private 
    function addAnOlaBookingTripDetail($day, $start_time, $trip_type, $crn_osn, $crn_osn_type, $distance_kms, $ride_time_mins, $operator_bill, $ola_commission, $ride_earnings, $tolls, $tds, $net_earnings, $cash_collected, $car_number, $summary_id) {
        $this->initLocalTimeZone();
        $this->initDB();
        $created_at = time();
        $updated_at = $created_at;
        $trip_time = $start_time;
        $start_time = strtotime($day . " " . strtolower($start_time));
        $trip_day = $this->formatExcelDate($start_time);
        
        if ($trip_type == 'CRN') {
            $trip_type_normalized = 'trip';
        }
        else 
        if ($trip_type == 'OSN') {
            $trip_type_normalized = 'share';
        }
        $olaBookingTripDetail = [['col' => 'start_time', 'val' => $start_time], ['col' => 'trip_day', 'val' => $trip_day], ['col' => 'trip_time', 'val' => $trip_time], ['col' => 'trip_type', 'val' => $trip_type_normalized], ['col' => 'crn_osn', 'val' => $crn_osn], ['col' => 'crn_osn_type', 'val' => $crn_osn_type], ['col' => 'distance_kms', 'val' => $distance_kms], ['col' => 'ride_time_mins', 'val' => $ride_time_mins], ['col' => 'operator_bill', 'val' => $operator_bill], ['col' => 'ola_commission', 'val' => $ola_commission], ['col' => 'ride_earnings', 'val' => $ride_earnings], ['col' => 'tolls', 'val' => $tolls], ['col' => 'tds', 'val' => $tds], ['col' => 'net_earnings', 'val' => $net_earnings], ['col' => 'cash_collected', 'val' => $cash_collected], ['col' => 'car_number', 'val' => $car_number], ['col' => 'import_id', 'val' => $this->currentImportRun], ['col' => 'summary_id', 'val' => $summary_id], ['col' => 'created_at', 'val' => $created_at], ['col' => 'updated_at', 'val' => $updated_at]];
        $olaBookingTripDetailQ = $this->makeAnInsertQ('ola_acc_stmt_booking_details', $olaBookingTripDetail);
        $r = $this->dbHandle->query($olaBookingTripDetailQ);
        
        if ($r) {
            return mysqli_insert_id($this->dbHandle); //detail_id
            
        }
    }
    /**
     *
     * @param type $proc_stage
     * @param type $proc_stage_pct
     */
    private 
    function renderProcStageProgPct($proc_stage, $proc_stage_pct, $proc_log_level = 'success') {
        $this->render('olaWeeklyDataProcProgPct.html.twig', ['proc_stage' => $proc_stage, 'proc_stage_pct' => $proc_stage_pct, 'proc_log_level' => $proc_log_level])->immediateRender();
    }
    /**
     *
     * @param type $proc_stage
     */
    private 
    function renderProcStageProg($proc_stage, $proc_stage_header, $full = false, $details = false) {
        $this->render('olaWeeklyDataProcessProg.html.twig', ['proc_stage' => $proc_stage, 'proc_stage_header' => $proc_stage_header, 'full' => $full, 'details' => $details])->immediateRender();
    }
    private 
    function getRelativeDocPath($doc_path) {
        return preg_replace('/^' . preg_replace('/\//', '\/', SITE_ROOT) . '/', '.', $doc_path);
    }
    private 
    function getStatementPeriod($xContent) {
        $allContent = $this->stripPageFooter($xContent);
        $extractAcctStmtPeriod = '/\s*A\s*c\s*c\s*o\s*u\s*n\s*t\s*S\s*t\s*a\s*t\s*e\s*m\s*e\s*n\s*t\s*f\s*o\s*r\s*([\d]{0,1}\s*[\d]\s*-[a-zA-Z\s]+-\s*[\d]\s*[\d]\s*)[to ]+\s*([\d]{0,1}\s*[\d]\s*-[a-zA-Z\s]+-\s*[\d]\s*[\d]\s*)/';
        $extractAcctStmtPeriodMatcher = (int)preg_match_all($extractAcctStmtPeriod, $allContent, $extractAcctStmtPeriodMatches);
        $periodArray = [];
        
        if ($extractAcctStmtPeriodMatcher > 0) {
            $from_date_extract = $this->stripWS($extractAcctStmtPeriodMatches[1][0]);
            $to_date_extract = $this->stripWS($extractAcctStmtPeriodMatches[2][0]);
            $period_start = $this->converToExcelDate($from_date_extract);
            $periodArray['period_start'] = $period_start;
            $period_start_ts = $this->converToTS($from_date_extract);
            $periodArray['period_start_ts'] = $period_start_ts;
            $period_end = $this->converToExcelDate($to_date_extract);
            $periodArray['period_end'] = $period_end;
            $period_end_ts = $this->converToTS($to_date_extract);
            $periodArray['period_end_ts'] = $period_end_ts;
            return $periodArray;
        }
    }
    private 
    function sendMissingCarsNotification() {
        
        if ($this->missingCars && count($this->missingCars)) {
            echo "\n ...Missing Cars... \n";
            print_r($this->missingCars);
            $this->initGearmanClient();
            $job_handle = $this->gclient->doBackground("missingCarsAlert", json_encode($this->missingCars));
        }
    }
    /**
     *
     */
    public 
    function runStep2() {
        $this->initLocalTimeZone();
        $period_start = Util::requestVar('period_start');
        $period_end = Util::requestVar('period_end');
        $section_select = Util::requestVar('section_select');
        $doc_name = $_FILES['ola_doc']['name'];
        $olaImportThings = [['col' => 'period_start', 'val' => strtotime($period_start) ], ['col' => 'period_end', 'val' => strtotime($period_end) ], ['col' => 'document_name', 'val' => $doc_name], ['col' => 'document_section', 'val' => $section_select]];
        $this->currentImportRun = $this->addAnOlaImport($olaImportThings);
        $renderVars = ['periodStart' => $period_start, 'periodEnd' => $period_end, 'sectionSelect' => $section_select, 'file' => $doc_name];
        $this->render('olaWeeklyDataProcess.html.twig', $renderVars)->immediateRender();
        // Now go..
        $section_select_func_name = $section_select;
        $section_select_pattern = '/(?:-)(\w)/';
        $section_select_repl_pattern = '/(-\w)/';
        $matchFound = preg_match_all($section_select_pattern, $section_select, $matches);
        
        if ($matchFound) {
            $charUp = strtoupper($matches[1][0]); // no multiple matches as of now..
            $section_select_func_name = 'get' . ucfirst(preg_replace($section_select_repl_pattern, $charUp, $section_select));
        }
        else {
            $section_select_func_name = 'get' . ucfirst($section_select);
        }
        $fileProcRes = $this->getAllContent();
        $doc_path = $fileProcRes['filePath'];
        $xContent = $fileProcRes['text_c'];
        $olaImportUThings = [['col' => 'document_path', 'val' => $this->getRelativeDocPath($doc_path) ]];
        $this->updateAnOlaImport($olaImportUThings);
        $this->addAnOlaAccStmtParseLog('PDF2TEXT', $xContent);
        $period_match = 'period_match';
        $period_match_header = "Matching Account statement periods!";
        $this->renderProcStageProg($period_match, $period_match_header, true);
        $extractPeriodStartEnd = $this->getStatementPeriod($xContent);
        $this->renderProcStageProgPct($period_match, 100);
        
        if ($extractPeriodStartEnd) {
            $does_match = 'does_match';
            $user_period_start_ts = strtotime($period_start);
            $user_period_start_end = strtotime($period_end);
            $pdf_period_start_ts = $extractPeriodStartEnd['period_start_ts'];
            $pdf_period_end_ts = $extractPeriodStartEnd['period_end_ts'];
            
            if ($user_period_start_ts == $pdf_period_start_ts && $user_period_start_end == $pdf_period_end_ts) {
                $does_match_header = "Matched...!";
                $this->renderProcStageProg($does_match, $does_match_header, true);
                $this->renderProcStageProgPct($does_match, 100);
                $this->period_start_ts = $pdf_period_start_ts;
                $this->period_end_ts = $pdf_period_end_ts;
                $sectionProcRes = $this->{$section_select_func_name}($xContent);
                $this->sendMissingCarsNotification();
            }
            else {
                $this->renderProcStageProgPct($period_match, 100);
                $does_match_header = "Does not match..aborting!";
                $this->renderProcStageProg($does_match, $does_match_header, true);
                $this->renderProcStageProgPct($does_match, 100, 'danger');
            }
        }
        else {
            $period_not_found = 'period_not_found';
            $period_not_found_header = "Account statement period couldn't be extracted!";
            $this->renderProcStageProg($period_not_found, $period_not_found_header, true);
            $this->renderProcStageProgPct($period_not_found, 100, 'danger');
        }
        $renderVars2 = [];
        $this->render('olaWeeklyDataProcess2.html.twig', $renderVars2)->immediateRender();
    }
    /**
     *
     */
    public 
    function runStep3() {
        $renderVars = ['title' => 'Export Ola Data', 'olaExportLegend' => 'Export Ola Data'];
        $this->render('olaExport.html.twig', $renderVars)->immediateRender();
    }
    /**
     *
     * @throws Exception
     */
    public 
    function runStep4() {
        
        if (isset($_POST)) {
            $this->initLocalTimeZone();
            $period_start = Util::requestVar('period_start');
            $period_end = Util::requestVar('period_end');
            $section_select = Util::requestVar('section_select');
            $period_start_val = strtotime($period_start);
            $period_end_val = strtotime($period_end);
            
            if ($section_select == 'booking-details') {
                //http://stackoverflow.com/questions/125113/php-code-to-convert-a-mysql-query-to-csv
                
                //http://www.a2zwebhelp.com/export-data-to-csv
                $qSummary = 'SELECT * FROM `ola_acc_stmt_booking_summary` WHERE day BETWEEN ' . $period_start_val . ' AND ' . $period_end_val . ' ORDER BY car_number DESC, day ASC';
                $qDetails = 'SELECT * FROM `ola_acc_stmt_booking_details` ORDER BY car_number desc, start_time asc';
            }
        }
        else {
            throw new Exception('Go back to Step-3');
        }
    }
    /**
     *
     * @param type $templateName
     * @param type $renderVars
     * @return \OlaAccountStatement
     */
    private 
    function render($templateName, $renderVars) {
        $this->initTwig();
        $template = $this->twigger->loadTemplate($templateName);
        $template->display($renderVars);
        return $this;
    }
    /**
     *
     * @param type $data
     */
    private 
    function immediateRender($data = null) {
        
        if ($data != null) {
            
            if (is_string($data)) {
                echo $data;
            }
            else {
                print_r($data);
            }
        }
        ob_flush();
        flush();
    }
}

class Util {
    public static 
    function requestVar($sVarName) {
        
        if (array_key_exists($sVarName, $_GET) == TRUE) {
            $temp = $_GET[$sVarName];
        }
        else 
        if (array_key_exists($sVarName, $_POST) == TRUE) {
            $temp = $_POST[$sVarName];
        }
        else 
        if (array_key_exists($sVarName, $_SESSION) == TRUE) {
            $temp = $_SESSION[$sVarName];
        }
        else 
        if (array_key_exists($sVarName, $_COOKIE) == TRUE) {
            $temp = $_COOKIE[$sVarName];
        }
        else {
            $temp = "";
        }
        return $temp;
    }
}
session_start();
header('Content-Type:text/html; charset=UTF-8');
//header('Content-Type:text/plain; charset=UTF-8');
try {
    $statementObj = new OlaAccountStatement();
    // Step-1..present import the form...
    
    // Step-2 ..process/transform the PDF and store it in the DB..
    
    // Step-3 ..present export form to download CSV...
    $lastStep = 0;
    
    if (array_key_exists('step_num', $_SESSION)) {
        $lastStep = (int)$_SESSION['step_num'];
    }
    $stepVar = (int)Util::requestVar('step_num');
    /* if ($stepVar == 0) {
      header('Location: /ola-ripper.ysg.co.in/ola_ripper.php?step_num=1');
      }
    
      if ($stepVar <= $lastStep) {
      throw new Exception('Can\'t go back..clear session..currently executing:' . $lastStep . '!!');
      }
    
      if ($stepVar != $lastStep + 1) {
      throw new Exception('Can\'t jump steps..currently executing:' . $lastStep . '!!');
      } */
    $_SESSION['step_num'] = $stepVar;
    $stepFuncName = 'runStep' . $stepVar;
    
    if (method_exists($statementObj, $stepFuncName)) {
        $statementObj->{$stepFuncName}();
    }
    else {
        throw new Exception('An unknown step! Restart..');
    }
}
catch(Exception $ex) {
    echo "<br />************Exception [Start]**************<br />";
    print_r($ex->getMessage());
    echo "<br />*************Exception [End]*************<br />";
}
?>
