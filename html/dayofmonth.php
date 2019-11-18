<?php

require 'vendor/autoload.php';
use MongoDB\Client;

$Year=2019; if (isset($_GET["y"])) $Year=$_GET["y"];
$Month=1;   if (isset($_GET["m"])) $Month=$_GET["m"];
$SelShopNumber=1; 	if (isset($_GET["s"])) $SelShopNumber=$_GET["s"];
$SelCashNumber=1; 	if (isset($_GET["c"])) $SelCashNumber=$_GET["c"];

// запрашиваемые данные за месяц для "много касс" или "одна касса"
$ManyCash = (($SelShopNumber<0) and ($SelCashNumber<0));

if ($ManyCash)
	$MatchPL = array('Year' => (int)$Year, 'Month' => (int)$Month);
else
	$MatchPL = array('Year' => (int)$Year, 'Month' => (int)$Month, 'ShopNumber' => (int)$SelShopNumber, 'CashNumber' => (int)$SelCashNumber);

include "login_vars.php";
$client = new Client("mongodb://$MongoDB_Login/?authSource=TestSales&authMechanism=SCRAM-SHA-1");
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

// готовим запрос в мангу
$pipeline = [
    [
        '$group' => [
            '_id' => [
                'ShopNumber' => '$ShopNumber',
                'CashNumber' => '$CashNumber',
//				'Year'  => ['$year' => '$CreateDT'],
//              'Month' 	 => ['$month' => '$CreateDT'],
//				'DayOfMonth' => ['$dayOfMonth'=> '$CreateDT']
				'Year'  => ['$year' => ['date' => '$CreateDT', 'timezone' => 'Europe/Moscow']],
                'Month' => ['$month' => ['date' => '$CreateDT', 'timezone' => 'Europe/Moscow']],
                'DayOfMonth' => ['$dayOfMonth' => ['date' => '$CreateDT', 'timezone' => 'Europe/Moscow']]
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
			'DayOfMonth' => '$_id.DayOfMonth',
			'CheckCount' => '$CheckCount',
            'SUM(CheckBuySum)' => '$SUM(CheckBuySum)',
            'SUM(CheckSum)' => '$SUM(CheckSum)',
            '_id' => 0
        ]
    ],
    [
//        '$match' => ['Year' => (int)$Year, 'Month' => (int)$Month, 'ShopNumber' => (int)$SelShopNumber, 'CashNumber' => (int)$SelCashNumber ]
        '$match' => $MatchPL
    ],
    [
        '$sort' => ['ShopNumber' => 1, 'CashNumber' => 1, 'Month' => 1, 'DayOfMonth' =>1 ]
    ]
];

$cursor = $collection->aggregate($pipeline, $options);

setlocale(LC_MONETARY, 'ru_RU');

$TableArray = Array();

// перекладываем результат запроса в массив, иерархия "Маг", "Касса", "День месяца", "Суммы"
// в массиве "День месяца" есть один элемнт "Total", это итоги по дням (по столбцу)
foreach ($cursor as $document) {
//	print_r($document); exit;

	$SN = $document["ShopNumber"];
	$CN = $document["CashNumber"];
//	$MN = $document["Month"];
	$DM = $document["DayOfMonth"];
	
	if (! isset($TableArray[$SN])) 		 		$TableArray[$SN]=array();
	if (! isset($TableArray[$SN][$CN]))  		{$TableArray[$SN][$CN]=array(); $TableArray[$SN][$CN]["Total"]=array("CheckCount"=>0,"BuySum"=>0,"Sum"=>0);};
    if (! isset($TableArray[$SN][$CN][$DM]))  	$TableArray[$SN][$CN][$DM]=array(); 

	$TableArray[$SN][$CN][$DM]=array("CheckCount"=>$document["CheckCount"], 
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
	table {width:".(($SelShopNumber<0)?"80%":"30%")."; border:1; border-collapse:collapse;}
	th {border: 1px solid black; text-align:center;}
	td {border: 1px solid lightgrey; text-align:center; padding-top:7px; padding-bottom:7px;}
    tbody .Cell:hover {
		cursor:pointer;
		background: #ccffff; /* Цвет фона при наведении */
		text-decoration:underline;
		font-weight: bold;
		/*color: #fff; /* Цвет текста при наведении */
</style>\n\n";

function GetCellText($X)
{
	//print_r($X); exit;
	if (isset($X))
	{
		$Result=number_format($X["CheckCount"],0,',',' ').
			    "<BR><FONT Color=Green>".number_format(($X["Sum"] - $X["BuySum"])/100,2,',',' ').
			    "<BR><FONT Color=Blue>".number_format($X["Sum"]/100,2,',',' ');
		return($Result);
	};
};

echo "<table align=center>\n";
echo "<TR><TH><Big>$Month-$Year<BR><Small><Small><Small>Europe/Moscow</TH>";
$TR_Shop="<TR><TH><B>Касса<BR>День<BR>месяца</TH>";
// мастерим заголовок таблицы
foreach($TableArray as $ShopNumber => $CashNumberArray)
{
	$ShopCount=count($CashNumberArray);
//	echo "<TH colspan=$ShopCount><B>Магазин № $ShopNumber</TH>";
	echo "<TH colspan=$ShopCount><B>Магазин № $ShopNumber</B><Small><BR>".
	                                $Shops[$ShopNumber]["ShopAddress"]."<BR>".
									$Shops[$ShopNumber]["TimeZoneName"]."</TH>";
	foreach($CashNumberArray as $CashNumber => $CashNumberMonth)
		$TR_Shop.="<TH>касса<BR><B>$CashNumber</TH>";
};
if ($ManyCash) $TR_Shop.="<TH>ИТОГО:</TH>";
$TR_Shop.="</TR>\n";
echo "</TR>\n";
echo $TR_Shop;

$Week=array("понедельник", "вторник", "среда", "четверг", "пятница", "суббота", "воскресенье");

error_reporting(E_ALL & ~E_NOTICE);
for ($DayOfMonth=1; $DayOfMonth<33; $DayOfMonth++)
{
	if (checkdate($Month, $DayOfMonth, $Year))
	{
		// формирование таблицы шахматки
		echo "<TR>";	
		$CellDate=date('d-m-Y', mktime(0,0,0, $Month, $DayOfMonth, $Year));
		$NumberDayOfWeek=date('N', mktime(0,0,0, $Month, $DayOfMonth, $Year));
		$DescDayOfWeek=$Week[$NumberDayOfWeek-1];	
		$Color=""; if (($NumberDayOfWeek==6) or ($NumberDayOfWeek==7)) $Color="bgcolor=pink";
		echo "<TH $Color>$CellDate<BR>$DescDayOfWeek</TH>";
		$RowTotal = array("CheckCount"=>0, "BuySum"=>0, "Sum"=>0);
		foreach($TableArray as $ShopNumber => $CashNumberArray)
		{
			foreach($CashNumberArray as $CashNumber => $CashNumberMonth)
			{
				$Cell = $TableArray[$ShopNumber][$CashNumber][$DayOfMonth];
				$RowTotal["CheckCount"]	+= $Cell["CheckCount"];
				$RowTotal["BuySum"]		+= $Cell["BuySum"];
				$RowTotal["Sum"]		+= $Cell["Sum"];
				$CellText=GetCellText($Cell);
				$tag="y=$Year&m=$Month&d=$DayOfMonth&s=$ShopNumber&c=$CashNumber";
				if (isset($CellText)) echo "<TD Class=\"Cell\" tag=\"$tag\">$CellText</TD>"; else echo "<TD></TD>";
			}
		}
		if ($ManyCash)
		{
			$CellText=GetCellText($RowTotal);
			if (isset($CellText)) echo "<TH>$CellText</TH>"; else echo "<TH></TH>";
		};
		echo "</TR>\n";
	}
	else
	{
		// формирование строки ИТОГО в конце таблицы
		echo "<TR>\n";
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
		if ($ManyCash)
		{
			$CellText=GetCellText($RowTotal);
			if (isset($CellText)) echo "<TH>$CellText</TH>"; else echo "<TH></TH>";
		};
		echo "</TR>\n";
		break;
	};
	
};
echo "</table>";

