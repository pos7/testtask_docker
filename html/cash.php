<?PHP

if (PHP_SAPI <> 'cli')
{
	echo "\033[1;32m JSON cash check generator, 10.2019 (c) pos7@mail.ru\033[0m\n";
	echo "This php script must run only in CLI!<BR>\n";
	exit;
};

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function GetCurTZOffet($DT)
{
	return(" \"".$DT->getTimezone()->getName()."\" час: ".number_format((($DT->getOffset())/3600), 2, ':', ''));	
};
	
echo "**************************************************\n";
echo "\033[1;32m JSON cash check generator, 10.2019 (c) pos7@mail.ru\033[0m\n";
echo "CLI: php cash.php Year ShopNumber CashNumber TimeZone\n";
echo "Year:          2016 .. 2020, default 1\n";
echo "ShopNumber:    1 .. 99, default 1\n";
echo "CashNumber: 	 1 .. 99, default 1\n";
echo "TiemZone: TiemZoneName (Linux Timezone ID), default \"Europe/Samara\"\n";
echo "   https://en.wikipedia.org/wiki/List_of_tz_database_time_zones\n";
echo " \n";
echo "\033[0;31mFor break process press Ctrl + C\033[0m\n";
echo "**************************************************\n";
date_default_timezone_set('Europe/Samara'); // ставим временную зону системы (сервера)
echo "\n Наше время, время сервера: ".date("d.m.y H:i:s").", часовой пояс: \"Europe/Samara\"\n";  
$TimeZone = isset($argv[4])?$argv[4]:"Europe/Samara";

try
{
	$WorkTimeZone = new DateTimeZone($TimeZone);
	$WorkTime = new DateTime("now", $WorkTimeZone);
} catch (Exception $e) 
{
	echo "\n  Ошибка: Неверно задан TimeZone!\n\n";
	exit;
};

echo " Время, TiemZone для генерации чеков:  ".($WorkTime->format("d.m.y H:i:s"))." смещение,".GetCurTZOffet($WorkTime)."\n\n";

$Year = isset($argv[1])?$argv[1]:2019;
if (($Year<2016) or ($Year>2020)) $Year = 2019;
echo "Selected Year: $Year\n";

$ShopNumber = isset($argv[2])?$argv[2]:1;
if (($ShopNumber<1) or ($ShopNumber>99)) $ShopNumber = 1;
echo "Selected ShopNumber: $ShopNumber\n";

$CashNumber = isset($argv[3])?$argv[3]:1;
if (($CashNumber<1) or ($CashNumber>99)) $CashNumber = 1;
echo "Selected CashNumber: $CashNumber\n";


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


// порядок полей: код товар, название товара, цена закупа (себестоимость), цена продажи
$GoodsArray = array(
	array('0206808', 'Эчпочмак печеный 90г', 						1500, 2300),
	array('0501518', 'Чебурек жареный с мясом 130г',				2986, 4500),
	array('0260824', 'Сочни с творогом 75г',						1200, 1950),		
	array('0219364', 'Сосиска в тесте печеная 100г',				1100, 1990),
	array('4047184', 'Слойка дрожжевая с маком Фростмо 80г',		1431, 1990),
	array('4043593', 'Пирожок с мясом печеный 75г',					1255, 2690),
	array('4043592', 'Пирожок с ветчиной/сыром печеный 75г',		1328, 2500),
	array('0254985', 'Пирожок печеный с мясом/капустой 75г',	 	 995, 1390),
	array('4043589', 'Пирожок печеный с зеленым луком/яйцом 75г',    845, 1550),
	array('1000008', 'Оладьи с джемом 150/15',					     836, 2000)
	);
	
function GetGoodsListForCheck($CheckNumber, $DateTimeChek)
{
	global $GoodsArray;
	global $ShopNumber;
	global $CashNumber;
	global $TimeZone;
	$GoodsList=array();
	
	$CheckSum = 0;
	$CheckBuySum = 0;
	// задаем кол-во товаров в чеке по рандому 1..15
	$ItemsCount = rand(1, 15); 
	$GoodsArrayCount = count($GoodsArray)-1;
	for ($i=1; $i<=$ItemsCount; $i++)
	{
		$GoodsIndex = rand(0, $GoodsArrayCount);
		// количество товара в одной позиции чека
		$GoodsCountInItem = rand(1, 12); 
		$Goods = array(
					"GoodsCode"  	=> $GoodsArray[$GoodsIndex][0],
					"GoodsName"  	=> $GoodsArray[$GoodsIndex][1],
					"GoodsCount" 	=> $GoodsCountInItem,
					"GoodsPrice" 	=> $GoodsArray[$GoodsIndex][3],
					"GoodsBuySum" 	=> $GoodsCountInItem * $GoodsArray[$GoodsIndex][2],
					"GoodsSum" 		=> $GoodsCountInItem * $GoodsArray[$GoodsIndex][3],
				);
		$GoodsList[]  = $Goods;
		$CheckBuySum += $Goods["GoodsBuySum"];
		$CheckSum	 += $Goods["GoodsSum"];
	};	

	$HeadCheck = array(
		"CreateDT_Local"	=> $DateTimeChek->format("Y-m-d H:i:s"), 
		"CreateDT_Stamp"	=> $DateTimeChek->getTimestamp(), 
		"TimeZoneName"		=> $DateTimeChek->getTimezone()->getName(),  // для обкатки, для визуального контроля TZ, после можно убрать это поле из чека
		"TimeZoneOffSet"	=> ($DateTimeChek->getOffset() / 3600), // для обкатки, для визуального контроля TZ, после можно убрать это поле из чека
		
		"ShopNumber" 		=> (int)$ShopNumber,
		"CashNumber" 		=> (int)$CashNumber,
		"CheckNumber"		=> $CheckNumber,
		"ItemsCount"		=> count($GoodsList),
		"CheckBuySum"		=> $CheckBuySum,
		"CheckSum"			=> $CheckSum
	);

	$Check = $HeadCheck;
	$Check += $GoodsList;

	if (($CheckNumber % 100)==1)
		echo "   ".$HeadCheck["CreateDT_Local"]."  Создан чек: $CheckNumber   Shop:$ShopNumber;  Cash:$CashNumber;  GoodsCount:".$HeadCheck["ItemsCount"].";  BuySum:$CheckBuySum;  Sum:$CheckSum;\n";

	return($Check);
};

echo "\n";
for ($i=5; $i>0; $i--)
{	
	echo "   Countdown run $i... \r";
	sleep(1);
};

try 
{
//	$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');  // для VPS
//	$connection = new AMQPStreamConnection('RabbitMQ', 5672, 'root', 'secret');   // для Docker 
	try
	{
		$channel = $connection->channel();		
		try
		{	
			// толкаем в кролика данные по торгово точке
			$channel->queue_declare('TestSalesQueue', false, false, false, false);
			
			$RetailArray = array(		
					"ShopNumber"		=> (int)$ShopNumber,
					"ShopName" 			=> "Магазин №$ShopNumber",
					"ShopAddress"		=> "государство:YYY город:ХХХ улица:ZZZ дом:999",
					//"TimeZoneOffset"	=> $WorkTime->getTimezone()->getName(),
					"TimeZoneName"		=> GetCurTZOffet($WorkTime), 
					"CreateDT_Local"	=> date("Y-m-d H:i:s "), // для отладки, обкатки! после можно удалить
					"CreateDT_Stamp"	=> time(),
			);
			
			$str=json_encode($RetailArray, JSON_UNESCAPED_UNICODE);
			echo "Создана Торговая точка:\n   $str\n\n";
			$msg = new AMQPMessage($str);
			$channel->basic_publish($msg, '', 'TestSalesQueue');							
		} finally {
			$channel->close();
		};				
		
		
		// толкаем в кролика чеки
//		$StartCheckCountDay=rand(100,200);	// количество чеков в день "от"
		$EndCheckCountDay=rand(100,800);    // количество чеков в день "до"		
		$channel = $connection->channel();		
		$channel->queue_declare('TestSalesQueue', false, false, false, false);
		try
		{	
			$AllCheck=0;
			
			$StartDay = rand(1, 300);
			$EndtDay = rand($StartDay+1, 365);
//			for ($day=1; $day<365; $day++) // цикл на 365 дней
			for ($day=$StartDay; $day<$EndtDay; $day++) // цикл по рандому, так интереснее шахматка ложится
			{
				$CheckDT = (new DateTime("$Year-01-01 06:00:00", $WorkTimeZone))->modify("+$day day");;
				echo "\nDate: ".$CheckDT->format("Y-m-d H:i:s")."\n";
				
				$CheckNum=1;				
				$EndDateTime=(new DateTime("$Year-01-01 22:00:00", $WorkTimeZone))->modify("+$day day");;
//				$sec=rand(((22-6)*60*60)/$EndCheckCountDay, ((22-6)*60*60)/$StartCheckCountDay); // рандом от $StartCheckCountDay до $EndCheckCountDay чеков день, интервал меж чеками одинаковый
				$sec=rand(((22-6)*60*60)/$EndCheckCountDay, ((22-6)*60*60)/99); // рандом от 99 до $EndCheckCountDay чеков день, интервал меж чеками одинаковый
				while ($CheckDT < $EndDateTime) // цикл от 6:00 до 22:00 в секундах
				{
					//echo "     Чек: $CheckNum ".$CheckDT->format("Y-m-d H:i:s")."\n";
					$msg = new AMQPMessage(json_encode(GetGoodsListForCheck($CheckNum, $CheckDT), JSON_UNESCAPED_UNICODE));
					$channel->basic_publish($msg, '', 'TestSalesQueue');
					$CheckNum++;
					$AllCheck++;
					$CheckDT->modify("+$sec sec");
					usleep(500);
				}
			}
			echo "\n\nВсего чеков: $AllCheck\n\n";
		} finally {
			$channel->close();
		};				
	} finally {
		$connection->close();
	}

} catch (Exception $e) 
{
    echo 'Ошибка: ',  $e->getMessage(), "<BR>\n";
};

?>