<?php
require ("../vendor/autoload.php");
use Slince\SmartQQ\Client;

$smartQQ = new Client();
$loginResult = $smartQQ->login('E:/qq_test/qrcode.png'); //参数为保存二维码的位置
$friends = $smartQQ->getFriends();
var_dump($friends);