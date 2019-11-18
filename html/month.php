<?php
require 'vendor/autoload.php';
use MongoDB\Client;

$Year=2019;
if (isset($_GET["y"])) $Year=$_GET["y"];

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

$collection = $client->TestSales->SalesCollection;
$options = [
    'allowDiskUse' => TRUE
];

// запрос в мангу
$pipeline = [
    [
        '$group' => [
            '_id' => [
                'ShopNumber' => '$ShopNumber',
                'CashNumber' => '$CashNumber',
//				'Year'  => ['$year' => '$CreateDT'],
//              'Month' => ['$month' => '$CreateDT'],
				'Year'  => ['$year' => ['date' => '$CreateDT', 'timezone' => 'Europe/Moscow']],
                'Month' => ['$month' => ['date' => '$CreateDT', 'timezone' => 'Europe/Moscow']]
            ],
			'CheckCount' => ['$sum' => 1],
            'SUM(CheckBuySum)' => ['$sum' => '$CheckBuySum'],
            'SUM(CheckSum)' => ['$sum' => '$CheckSum']
        ]
    ],
    [
        '$project' => [
            'ShopNumber' => '$_id.ShopNumber',
            'CashNumber' => '$_id.CashNumber',
            'Year' => '$_id.Year',
            'Month' => '$_id.Month',
			'CheckCount' => '$CheckCount',
            'SUM(CheckBuySum)' => '$SUM(CheckBuySum)',
            'SUM(CheckSum)' => '$SUM(CheckSum)',
            '_id' => 0
        ]
    ],
    [
        '$match' => ['Year' => (int)$Year]
    ],
    [
        '$sort' => ['ShopNumber' => 1, 'CashNumber' => 1, 'Month' => 1]
    ]
];

$cursor = $collection->aggregate($pipeline, $options);

setlocale(LC_ALL, 'ru_RU');

$TableArray = Array();

// перекладываем результат запроса в массив, иерархия "Маг", "Касса", "Месяц", "Суммы"
foreach ($cursor as $document) {
//	print_r($document);

	$SN = $document["ShopNumber"];
	$CN = $document["CashNumber"];
	$MN = $document["Month"];
	
	if (! isset($TableArray[$SN])) 		 		$TableArray[$SN]=array();
	if (! isset($TableArray[$SN][$CN]))  		{$TableArray[$SN][$CN]=array(); $TableArray[$SN][$CN]["Total"]=array("CheckCount"=>0,"BuySum"=>0,"Sum"=>0);};
    if (! isset($TableArray[$SN][$CN][$MN]))  	$TableArray[$SN][$CN][$MN]=array();

	$TableArray[$SN][$CN][$MN]=array("CheckCount"=>$document["CheckCount"], 
									 "BuySum"=>$document["SUM(CheckBuySum)"], 
									 "Sum"=>$document["SUM(CheckSum)"]);
									 
	$TableArray[$SN][$CN]["Total"]["CheckCount"]	+= $document["CheckCount"];
	$TableArray[$SN][$CN]["Total"]["BuySum"]		+= $document["SUM(CheckBuySum)"];
	$TableArray[$SN][$CN]["Total"]["Sum"]		    += $document["SUM(CheckSum)"];
}

if (!count($TableArray)) 
{
	echo "Ошибка, Нет данных!<BR>запрос в MongoDB не чего не вернул."; exit;
};

print	
"<style>
	body {font-family:Arial; background-color:white;}
	table {width:100%; border:1; border-collapse:collapse;}
	th {border: 1px solid black; text-align:center;}
	td {border: 1px solid lightgrey; text-align:center; padding-top:7px; padding-bottom:7px;}
    tbody .Cell:hover {
		cursor:pointer;
		background: #ccffff; /* Цвет фона при наведении */
		text-decoration:underline;
		font-weight: bold;
		/*color: #fff; /* Цвет текста при наведении */
   }	
</style>\n\n";

function GetCellText($X)
{
	if (isset($X))
	{
		$Result=number_format($X["CheckCount"],0,',',' ').
			    "<BR><FONT Color=Green>".number_format(($X["Sum"] - $X["BuySum"])/100,2,',',' ').
			    "<BR><FONT Color=Blue>".number_format($X["Sum"]/100,2,',',' ');
		return($Result);
	};
};

echo "<table>\n";
echo "<TR><TH><BIG>$Year<BR><Small><Small><Small>Europe/Moscow</TH>";
$TR_Shop="<TR><TH><B>Касса<BR>Месяц</TH>";
// мастерим заголовок таблицы
foreach($TableArray as $ShopNumber => $CashNumberArray)
{
	$ShopCount=count($CashNumberArray);
	echo "<TH colspan=$ShopCount><B>Магазин № $ShopNumber</B><Small><BR>".
	                                $Shops[$ShopNumber]["ShopAddress"]."<BR><Small>".
									$Shops[$ShopNumber]["TimeZoneName"]."</TH>";
	foreach($CashNumberArray as $CashNumber => $CashNumberMonth)
		$TR_Shop.="<TH>касса<BR><B>$CashNumber</TH>";
};
$TR_Shop.="<TH>ИТОГО:</TH></TR>\n";
echo "</TR>\n";
echo $TR_Shop;

$MonthRus=array("1"=>"Январь", "2"=>"Февраль", "3"=>"Март", "4"=>"Апрель",  "5"=>"Май",  "6"=>"Июнь",  "7"=>"Июль",  "8"=>"Август",  "9"=>"Сентябрь",  "10"=>"Октябрь",  "11"=>"Ноябрь", "12"=>"Декабрь");
error_reporting(E_ALL & ~E_NOTICE);
// формирование таблицы шахматки
for ($Month=1; $Month<13; $Month++)
{
	echo "<TR>";
	$tag="y=$Year&m=$Month&s=-1&c=-1";
	echo "<TH Class=\"Cell\" tag=\"$tag\">$Month<BR>".$MonthRus[$Month]."</TH>";
	$RowTotal = array("CheckCount"=>0, "BuySum"=>0, "Sum"=>0);
	foreach($TableArray as $ShopNumber => $CashNumberArray)
	{
		foreach($CashNumberArray as $CashNumber => $CashNumberMonth)
		{
			$Cell = $TableArray[$ShopNumber][$CashNumber][$Month];
			$RowTotal["CheckCount"]	+= $Cell["CheckCount"];
			$RowTotal["BuySum"]		+= $Cell["BuySum"];
			$RowTotal["Sum"]		+= $Cell["Sum"];
			
			$CellText=GetCellText($Cell);
			if (isset($CellText))
			{
				$tag="y=$Year&m=$Month&s=$ShopNumber&c=$CashNumber";
				echo "<TD Class=\"Cell\" tag=\"$tag\">$CellText</TD>";
			}
			else
				echo "<TD></TD>";
		}
	};
	$CellText=GetCellText($RowTotal);
	if (isset($CellText)) echo "<TH>$CellText</TH>"; else echo "<TH></TH>";
	echo "</TR>\n";
};

// формирование строки ИТОГО в конце таблицы
echo "<TR>\n";
//		echo "<TH $Color>$CellDate<BR>$DescDayOfWeek</TH>";
echo "<TH $Color><BIG><BIG><BIG>итого:</TH>";
$RowTotal = array("CheckCount"=>0, "BuySum"=>0, "Sum"=>0);
foreach($TableArray as $ShopNumber => $CashNumberArray)
{
	foreach($CashNumberArray as $CashNumber => $CashNumberMonth)
	{			
		$Cell = $TableArray[$ShopNumber][$CashNumber]["Total"];
		$RowTotal["CheckCount"]	+= $Cell["CheckCount"];
		$RowTotal["BuySum"]		+= $Cell["BuySum"];
		$RowTotal["Sum"]		+= $Cell["Sum"];

		$CellText=GetCellText($Cell);
		if (isset($CellText)) echo "<TH>$CellText</TH>"; else echo "<TH></TH>";
	}
}
$CellText=GetCellText($RowTotal);
if (isset($CellText)) echo "<TH>$CellText</TH>"; else echo "<TH></TH>";
echo "</TR>\n";
echo "</table>";

echo "<p align=Left><FONT Color=Blue><Small>Ячейка, сверху вниз: 1. количество чеков; 2. доход; 3. выручка;</p>";

?>