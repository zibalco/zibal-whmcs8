<?php
/*
 - Author : GoldenSource.iR
 - Module Designed For : zibal.ir
 - Mail : Mail@GoldenSource.ir
*/

use WHMCS\Database\Capsule;

if (isset($_REQUEST['invoiceId']) && is_numeric($_REQUEST['invoiceId'])) {
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../includes/invoicefunctions.php';
    $gatewayParams = getGatewayVariables('zibal');
    if (isset($_GET['status'])) {
        $invoice = Capsule::table('tblinvoices')->where('id', $_GET['invoiceId'])->where('status', 'Unpaid')->first();
        if (!$invoice) {
            die("Invoice not found");
        }
        if ($_GET['status'] == 2) {
            $amount = ceil($invoice->total * ($gatewayParams['currencyType'] == 'IRR' ? 1 : 10));
            if ($gatewayParams['feeFromClient'] == 'on') {
                $amount = ceil(1.01 * $amount);
            }
            $result = post_to_zibal('verify', [
                'merchant' => $gatewayParams['MerchantID'],
                'trackId' => $_GET['trackId'],
            ]);
            if ($result->result == 100 && $result->amount == $amount) {
                // checkCbTransID($result->refNumber);
                logTransaction($gatewayParams['name'], $_REQUEST, 'Success');
                addInvoicePayment(
                    $invoice->id,
                    $result->refNumber,
                    $invoice->total,
                    0,
                    'zibal'
                );
            } else {
                logTransaction($gatewayParams['name'], array(
                    'Code'        => 'Zibal Result Code',
                    'Message'     => $result->result,
                    'Transaction' => $_GET['order_id'],
                    'Invoice'     => $invoice->id,
                    'Amount'      => $invoice->total,
                ), 'Failure');
            }
        }
        header('Location: ' . $gatewayParams['systemurl'] . '/viewinvoice.php?id=' . $invoice->id);
    } else if (isset($_SESSION['uid'])) {
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->where('userid', $_SESSION['uid'])->first();
        if (!$invoice) {
            die("Invoice not found");
        }
        $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
        $amount = ceil($invoice->total * ($gatewayParams['currencyType'] == 'IRR' ? 1 : 10));
        if ($gatewayParams['feeFromClient'] == 'on') {
            $amount = ceil(1.01 * $amount);
        }

        $data = array(
            'merchant' => $gatewayParams['MerchantID'],
            'amount' => $amount,
            'description' => sprintf('پرداخت فاکتور #%s', $invoice->id),
            'mobile' => $client->phonenumber,
            'callbackUrl' => $gatewayParams['systemurl'] . '/modules/gateways/zibal.php?invoiceId=' . $invoice->id . '&callback=1',
        );

        if ($gatewayParams['checkMobileWithCard'] == 'on') {

            if ($client->phonenumber != '' || $client->phonenumber != null) {

                $phoneNumber = $client->phonenumber;
                if (str_contains($phoneNumber, '.')) {
                    $phoneNumber = explode('.', $phoneNumber);
                    $phoneNumber = '0' . $phoneNumber[1];
                } else if (str_contains($phoneNumber, '+98')) {
                    $phoneNumber = explode('+98', $phoneNumber);
                    $phoneNumber = '0' . $phoneNumber[1];
                } else if (!str_contains($phoneNumber, '09')) {
                    echo 'شماره موبایل وارد شده صحیح نیست. لطفا شماره صحیح وارد فرمایید.';
                    return;
                }

                if (strlen($phoneNumber) !== 11) {
                    echo 'شماره موبایل وارد شده صحیح نیست. لطفا شماره صحیح وارد فرمایید.';
                    return;
                }

                $data = array(
                    'merchant' => $gatewayParams['MerchantID'],
                    'amount' => $amount,
                    'description' => sprintf('پرداخت فاکتور #%s', $invoice->id),
                    'mobile' => $phoneNumber,
                    'callbackUrl' => $gatewayParams['systemurl'] . '/modules/gateways/zibal.php?invoiceId=' . $invoice->id . '&callback=1',
                    'checkMobileWithCard' => true,
                );
            } else {
                echo 'لطفا شماره موبایل خود را وارد فرمایید.';
                return;
            }

        }

        $result = post_to_zibal('request', $data);
        if ($result->result == 100) {
            header('Location: https://gateway.zibal.ir/start/' . $result->trackId);
        } else {
            echo 'اتصال به درگاه امکان پذیر نیست: ', $result->result;
        }
    }
    return;
}

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function post_to_zibal($url, $data = false)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://gateway.zibal.ir/v1/" . $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
    curl_setopt($ch, CURLOPT_POST, 1);
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $result = curl_exec($ch);
    curl_close($ch);
    return !empty($result) ? json_decode($result) : false;
}

function zibal_MetaData()
{
    return array(
        'DisplayName' => 'ماژول پرداخت زیبال',
        'APIVersion' => '2.0',
    );
}

function zibal_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'zibal.ir',
        ),
        'currencyType' => array(
            'FriendlyName' => 'نوع ارز',
            'Type' => 'dropdown',
            'Options' => array(
                'IRR' => 'ریال',
                'IRT' => 'تومان',
            ),
        ),
        'MerchantID' => array(
            'FriendlyName' => 'کد درگاه (مرچنت)',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'مرچنت کد دریافتی از سایت زیبال',
        ),
      
        'checkMobileWithCard' => array(
            'FriendlyName' => 'تطابق کد ملی صاحب کارت پرداخت کننده با کد ملی مالک شماره موبایل مشتری',
            'Type' => 'yesno',
            'Description' => 'جهت فعال سازی تطبیق شماره موبایل و کارت پرداخت کننده تیک بزنید. برای این امر حتما نیاز به ارسال شماره موبایل مشتری می‌باشد و این سرویس تنها در درگاه پرداخت سامان کیش قابل استفاده می‌باشد',
        ),
    );
}

function zibal_link($params)
{
    $htmlOutput = '<form method="GET" action="modules/gateways/zibal.php">';
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . $params['invoiceid'] . '">';
    $htmlOutput .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $htmlOutput .= '</form>';
    return $htmlOutput;
}
