<?php
if (PHP_SAPI <> 'cli')
{
	echo "\033[1;32m JSON cash check CONSUMER, 10.2019 (c) pos7@mail.ru\033[0m\n";
	echo "This php script must run only in CLI!<BR>\n";
	exit;
};

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
	
echo "**************************************************\n";
echo "\033[1;32m JSON cash check CONSUMER, 11.2019 (c) pos7@mail.ru\033[0m\n";
echo "\n";
echo "     Wait massages from RabbitMQ...\n";
echo "\n";
echo "\033[0;31mFor break process press Ctrl + C\033[0m\n";
echo "**************************************************\n";

$RMQ_InTouch = 0;
do{
	try 
	{
     //$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');  // для VPS
	   $connection = new AMQPStreamConnection('RabbitMQ', 5672, 'root', 'secret');     // для Docker
	   $RMQ_InTouch = 999;
	   break;
	} catch (Exception $e) 
	{
		$RMQ_InTouch++;
		echo 'Ошибка: ',  $e->getMessage(), "<BR>\n";
	};
	sleep(3);
	echo "\033[0;31m Нет ответа от RabbitMQ, ждем...\033[0m\n";
} while($RMQ_InTouch<12);

echo "\033[1;32m Подключились к RabbitMQ, ждем чеки...\033[0m\n";

$channel = $connection->channel();
$channel->queue_declare('TestSalesQueue', false, false, false, false);

include "login_vars.php";
//$client = new Client("mongodb://$MongoDB_Login/?authSource=TestSales&authMechanism=SCRAM-SHA-1");
//$MDB_connect = new MongoDB\Client("mongodb://$MongoDB_Login/?authSource=TestSales&authMechanism=SCRAM-SHA-1");
$MDB_connect = new MongoDB\Client("mongodb://$MongoDB_Login/?authMechanism=SCRAM-SHA-1");


$CollectionChecks = $MDB_connect->TestSales->SalesCollection;
$CollectionRetail = $MDB_connect->TestSales->RetailCollection;

define('Ы', "\n");

$item=0;
// обработчик данных (торговая точка, чеки) от кролика
$callback_Check = function($msg) {
	global $CollectionChecks;
	global $CollectionRetail;
	global $item;
	
	$str = $msg->body;
	$Check = json_decode($str, true);
	
	// обработка данных торговой точки, если есть "ShopName", 
	// если нет, то значит это чек, обрабатываем JSON как чек
	if (isset($Check["ShopName"]))
	{
		$Cur=$CollectionRetail->find(array("ShopNumber"=>$Check["ShopNumber"]));
		if (count($Cur->toArray()) == 0)
		{	
			$Res=$CollectionRetail->insertOne($Check);
			echo Ы."Магазин обработан: ".substr($str, 1, 80)."...".Ы.Ы;
			return(0);
		}
		else
		{
			echo Ы."Такой магазин уже есть в базе! ".substr($str, 1, 80)."...".Ы.Ы;
			return(0);
		};
	};
	
	$ModoDT = new MongoDB\BSON\UTCDateTime($Check["CreateDT_Stamp"] * 1000);
	$Tmp=$Check;
	$Check=array("CreateDT" => $ModoDT);
	$Check+=$Tmp;

	if (($item++ % 100)==1)
		echo "Чек обработан: ".substr($str, 1, 80)."... \n";

	$Res=$CollectionChecks->insertOne($Check);
};


$channel->basic_consume('TestSalesQueue', '', false, true, false, false, $callback_Check);

while(count($channel->callbacks)) {$channel->wait();};

$channel->close();
$connection->close();

?>