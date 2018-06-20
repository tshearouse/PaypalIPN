<?php

//Uncomment these to enable error output to the response.
// error_reporting(E_ALL);
// ini_set('display_errors', 'On');
//Uncomment this line (and comment the two above) to disable error output.
error_reporting(0);

require ('PaypalIPN.php');
require ('GoogleDriveSpreadsheets.php');
include ('log4php/Logger.php');

Logger::configure('config.xml');
$log = Logger::getLogger('ipnLog');

$ipn = new PaypalIPN();
$googleDrive = new GoogleSpreadsheet();

// Uncomment to test in the Paypal sandbox.
//$ipn->useSandbox();
//$verified = $ipn -> verifyIPN();
if (!$verified) {
    $requestBody = @file_get_contents('php://input');
    $log->warn("Unverified call, with body: \n$requestBody");
}
if ($verified) {
    /*
     * Process IPN
     * A list of variables is available here:
     * https://developer.paypal.com/webapps/developer/docs/classic/ipn/integration-guide/IPNandPDTVariables/
     */
    if ($_POST["payer_email"])
        $senderEmail = $_POST["payer_email"];
    else
        $senderEmail = $_POST["sender_email"];
    $paymentAmount = floatval($_POST["mc_gross"]);
    $transactionType = $_POST["txn_type"];
    $transactionId = $_POST["txn_id"];

    if (isSuccessfulPayment($transactionType)) {
		$googleDrive -> updateRow($senderEmail, $paymentAmount, $transactionId);
    } else if (isPaymentReversal($transactionType)) {
        $log->warn("Payment reversal. \tEmail: $senderEmail\tAmount: $paymentAmount\tTransaction type: $transactionType");
        //If someone filed a chargeback or adjustment,
        //we should un-apply their membership extension.
		$googleDrive -> updateRow($senderEmail, $paymentAmount, $transactionId);
    } else if (isPaymentFailure($transactionType)) {
        $log->warn("Payment failure. \tEmail: $senderEmail\tAmount: $paymentAmount\tTransaction type: $transactionType");
        //idk, leave a note somewhere?
    }
}

// Reply with an empty 200 response to indicate to paypal the IPN was received correctly.
header("HTTP/1.1 200 OK");

function isSuccessfulPayment($transactionType) {
    if ($transactionType === "web_accept" || $transactionType === "express_checkout" || $transactionType === "masspay" || $transactionType === "merch_pmt" || $transactionType === "pro_hosted" || $transactionType === "recurring_payment" || $transactionType === "subscr_payment" || $transactionType === "send_money" || $transactionType === "virtual_terminal" || $transactionType === "cart") {
        if ($_POST["payment_status"] && strtolower($_POST["payment_status"]) === "completed") {
            return true;
        }
    }
    return false;
}

function isPaymentReversal($transactionType) {
    if ($_POST["case_type"] && $_POST["case_type"] === "chargeback") {
        return true;
    }
    if ($transactionType === "adjustment") {
        return true;
    }
    return false;
}

function isPaymentFailure($transactionType) {
    if ($transactionType === "recurring_payment_expired" || $transactionType === "recurring_payment_failed" || $transactionType === "recurring_payment_suspended_due_to_max_failed_payment" || $transactionType === "recurring_payment_suspended" || $transactionType === "subscr_eot" || $transactionType === "subscr_failed") {
        return true;
    }
    return false;
}
