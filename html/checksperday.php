<?php

require 'vendor/autoload.php';
use MongoDB\Client;

$Year=2019; if (isset($_GET["y"])) $Year=$_GET["y"];
$Month=1;   if (isset($_GET["m"])) $Month=$_GET["m"];
$Day=1;     if (isset($_GET["d"])) $Day=$_GET["d"];
$SelShopNumber=1; 	if (isset($_GET["s"])) $SelShopNumber=$_GET["s"];
$SelCashNumber=1; 	if (isset($_GET["c"])) $SelCashNumber=$_GET["c"];

include "login_vars.php";
//$client = new Client("mongodb://$MongoDB_Login/?authSource=TestSales&authMechanism=SCRAM-SHA-1");
$client = new Client("mongodb://$MongoDB_Login/?authMechanism=SCRAM-SHA-1");

// создаем массив торговых точек, заполняем данными с RetailCollection
$CollectionRetail = $client->TestSales->RetailCollection;
$Cur=$CollectionRetail->find();
$Shops=array();
foreach ($Cur as $document) {
	$Shops[$document["ShopNumber"]]["ShopName"] = $document["ShopName"];
	$Shops[$document["ShopNumber"]]["ShopAddress"] = $document["ShopAddress"];
	$Shops[$document["ShopNumber"]]["TimeZoneName"] = $document["TimeZoneName"];
};

// задаем выборку дат по TZ "Europe/Moscow"
$WorkTimeZone = new DateTimeZone("Europe/Moscow"); 
$StartDT = new DateTime("$Year-$Month-$Day 00:00:00", $WorkTimeZone);
$EndDT   = new DateTime("$Year-$Month-$Day 23:59:59", $WorkTimeZone);

// берем чеки с манги
$collection = $client->TestSales->SalesCollection;
//date_default_timezone_set('UTC'); 
$query = [
    'CreateDT' => [
        '$gte' => new MongoDB\BSON\UTCDateTime($StartDT->getTimestamp() * 1000),
        '$lte'  => new MongoDB\BSON\UTCDateTime($EndDT->getTimestamp() * 1000)
    ],
    'ShopNumber' => (int)$SelShopNumber,
    'CashNumber' => (int)$SelCashNumber
];

$options = [ 'sort' => ['CreateDT' => 1]];
$cursor = $collection->find($query, $options);

setlocale(LC_MONETARY, 'ru_RU');

print	
"<style>
	body {font-family:Arial; background-color:white;}
	table {width:80%; border:1; border-collapse:collapse;}
	th {border: 1px solid black; text-align:center;}
//	td {border: 1px solid lightgrey; text-align:center; padding-top:7px; padding-bottom:7px;}
	.TDR {border: 1px solid lightgrey; text-align:Right; padding-top:7px; padding-bottom:7px;}
    tbody .CheckGoods:hover {
		cursor:pointer;
		background: #ccffff; /* Цвет фона при наведении */
		text-decoration:underline;
		font-weight: bold;
		/*color: #fff; /* Цвет текста при наведении */
</style>\n\n";

echo "<table align=center>\n";
echo "<TR><TH rowspan=2><Big>$Day.$Month.$Year<BR><Small><Small><Small>Europe/Moscow</TH>";
echo "<TH colspan=7><B>Магазин № $SelShopNumber</B><Small><BR>".
	                                $Shops[$SelShopNumber]["ShopAddress"]."<BR>".
									$Shops[$SelShopNumber]["TimeZoneName"]."</TH>";
echo "</TR>";
echo "<TR><TH colspan=7>касса<BR><B>$SelCashNumber</TH></TR>\n";
echo "<TR><TH>Дата</TH><TH>Время UTC</TH><TH>Время<BR><Small><Small>Europe/Moscow</TH><TH>Дата Время кассы</TH><TH>Номер Чека</TH><TH>Позиций</TH><TH>Доход</TH><TH>Сумма</TH></TR>\n";

$Week=array("понедельник", "вторник", "среда", "четверг", "пятница", "суббота", "воскресенье");

// формирование таблицы чеков и их товаров
foreach ($cursor as $document) {
	$ChekNum=$document["CheckNumber"];
	echo "<TR Class=\"CheckGoods\" tag=\"CN_$ChekNum\">";
	$CheckDate = $document["CreateDT"]->toDateTime();
	$CheckDateInMoscowTZ = clone $CheckDate;
	$CheckDateInMoscowTZ->setTimezone($WorkTimeZone);  	
	$CheckIncomeSum = "<B><FONT Color=Green>".number_format(($document["CheckSum"]-$document["CheckBuySum"])/100,2,',',' ');
	$CheckSum = "<B><FONT Color=Blue>".number_format($document["CheckSum"]/100,2,',',' ');
	$CheckItemCount = $document["ItemsCount"];
	echo "<TH>".$CheckDateInMoscowTZ->format("d.m.y")."</TH>";
	echo "<TD Align=Center><small>".$CheckDate->format("d.m.y H:i:s")."</TD>";
	echo "<TD Align=Center><small>".$CheckDateInMoscowTZ->format("d.m.y H:i:s")."</TD>";
	echo "<TD Align=Center>".$document["CreateDT_Local"]."</TD>";
	echo "<TD Align=Center>$ChekNum</TD>";
	echo "<TD class=TDR>".$document["ItemsCount"]."</TD>";
	echo "<TD class=TDR>$CheckIncomeSum</TD>";
	echo "<TD class=TDR>$CheckSum</TD>";
	echo "</TR>\n";
	$CheckStr="<div align=Right><table><TR><TH>н/п</TH><TH>код</TH><TH>наименование</TH><TH>количество</TH><TH>цена</TH><TH>доход</TH><TH>сумма</TH></TR>";
	for ($i=0; $i<$CheckItemCount; $i++)
	{
		$CheckStr.="<TR>";
		$CheckStr.="<TD Align=Right><Small>".($i+1)."</TD>";
		$CheckStr.="<TD Align=Right><Small>".$document[$i]["GoodsCode"]."&nbsp&nbsp&nbsp</TD>";
		$CheckStr.="<TD Align=Left><Small>".$document[$i]["GoodsName"]."</TD>";
		$CheckStr.="<TD Align=Center><Small>".$document[$i]["GoodsCount"]."</TD>";
		$CheckStr.="<TD Align=Right><Small>".number_format($document[$i]["GoodsPrice"]/100,2,',',' ')."</TD>";
		$CheckStr.="<TD Align=Right><Small><FONT Color=Green>".number_format(($document[$i]["GoodsSum"]-$document[$i]["GoodsBuySum"])/100,2,',',' ')."</TD>";
		$CheckStr.="<TD Align=Right><Small><FONT Color=Blue>".number_format($document[$i]["GoodsSum"]/100,2,',',' ')."</TD>";
		$CheckStr.="</TR>\n";
	};
	$CheckStr.="</table></div>";
	echo "<TR id=\"CN_$ChekNum\" style=\"display:none;\"><TD colspan=8>$CheckStr</TD></TR>\n";
}

if (!isset($CheckDate)) 
{
	echo "Ошибка, Нет данных!<BR>запрос в MongoDB не чего не вернул."; exit;
};

echo "</table>";

