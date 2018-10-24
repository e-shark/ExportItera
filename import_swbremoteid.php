<?php
const SCRIPTVERSION = "v.0.1";
const SCRIPTNAME="import_swbremoteid";
require_once 'ExportIteraLib.php';

$IteraVDESList = [];
$IteraSWBList = [];

//--------------------------------------------------------------------------------------
//	Получаем таблицу счетчиков на сайте Itera
//--------------------------------------------------------------------------------------
function GetIteraVDES()
{
	global $config;
	global $curloptions;
	global $ch;
	$res = NULL;

	if ($config['options']['debug']) logger("get Itera VDES list");

	$request = $config['bsmartapi']['url_getdevice']."?filter(hardware_id)=in(10)";

	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$result = curl_exec($ch);
	if(FALSE!==$result)
	{
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result, true);
		if(!empty($jsonAnswer)) {
			$res = $jsonAnswer;
			//logger(serialize($res));
			$cnt = count($res['Records']);
			if (!empty($cnt)){
				$res = $jsonAnswer;
				logger($cnt." records received from Itera");	
			}
		}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Получаем таблицу счетчиков на сайте Itera
//--------------------------------------------------------------------------------------
function GetIteraSWB()
{
	global $config;
	global $curloptions;
	global $ch;
	$res = NULL;

	if ($config['options']['debug']) logger("get Itera SwitchBoards list");

	$request = $config['bsmartapi']['url_getdevice']."?filter(hardware_id)=in(11)";

	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$result = curl_exec($ch);
	if(FALSE!==$result)
	{
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result, true);
		if(!empty($jsonAnswer)) {
			$res = $jsonAnswer;
			//logger(serialize($res));
			$cnt = count($res['Records']);
			if (!empty($cnt)){
				$res = $jsonAnswer;
				logger($cnt." records received from Itera");	
			}
		}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Ищет в id свитчбоарда по id ВДЭС этого дома
//--------------------------------------------------------------------------------------
function findcross($rid)
{
	$res = null;
	global $IteraVDESList;
	global $IteraSWBList;

	$building_id = null;
	foreach($IteraVDESList["Records"] as $VDES){
		if (!empty($VDES['id']) && ($rid == $VDES['id'])) {
			$building_id = $VDES['building_id'];
			//logger("rec[$rid]: ".json_encode($VDES));
			//logger("building_id: ".$building_id);
			break;
		}
	}
	if (!empty($building_id)) {
		foreach($IteraSWBList["Records"] as $SWB){
			if ($building_id == $SWB['building_id']){
				$res = $SWB['id'];
				break;
			}
		}
		if (is_null($res)) 
			logger("Switchboard for VDES ".$rid." (building id ".$building_id.") not found!");
	}else{
		logger("VDES with ID ".$rid." not found in Itera!");
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Прописа RemoteID для щита в базе 
//--------------------------------------------------------------------------------------
function DBSaveCross($elID, $swbRID)
{
	return true;	//	! ! ! 	Д Л  Я    О Т Л А Д К И
	$res = false;
	if (empty($elID)) return $res;
	if (empty($swbRID)) return $res;
	$sql = "INSERT INTO cross_swb (devID, IteraSwbID) VALUES ($elID, $swbRID);";
	if( FALSE != mysql_query($sql) ) $res = true;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Основной цыкла службы
//--------------------------------------------------------------------------------------
function MAIN_LOOP()
{
	global $IteraVDESList;
	global $IteraSWBList;

	$IteraVDESList = GetIteraVDES();
	$IteraSWBList = GetIteraSWB();

	$COUNT = 0;
	$cntVDES = 0;
	$cntADD = 0;

	if (empty($IteraVDESList) || empty($IteraSWBList)) {
		logger("ERROR: Can't read devices table from ITERA !");
		return;
	}

	$sql = "SELECT * FROM elevator WHERE eldevicetype = '10' AND elremoteid IS NOT NULL ORDER BY ID;";

	logger("SQL: ",$sql);

	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$num_rows = mysql_num_rows($cursor);
		logger(mysql_num_rows($cursor).' rows have fetched from DB');
		if ($num_rows > 0) {

			$COUNT = 0;
			while( $row = mysql_fetch_assoc( $cursor ) ) {
				//logger("--------------------------------------------------------");
				$COUNT += 1;

				$crossid = findcross($row['elremoteid']);
				if ( !empty($crossid) ) {
					$cntVDES++;
					if (DBSaveCross($row['id'], $crossid))
						$cntADD++;
				}

				//usleep(100e3);	// задержка на 100 миллисекунд
				//if ($COUNT>5) break;	// !!! ДЛЯ ОТЛАДКИ
				if ($COUNT % 50 == 0) logger("next 50 (".$COUNT.")");

				// Проверка на время выполнения скрипта
				if ( IsExecTimeExceeded() ) break;

			} //while
		}
		mysql_free_result($cursor);	
		//logger("Exported  successfully ".$counerDone." of ".$counerAll." records");
		logger("Was found VDES: ".$cntVDES);
		logger("Added switchbiards ID: ".$cntADD);
	}else{
		logger("ERROR: Can't read table from DB !");
	}

}


//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();

?>
