<?php
const SCRIPTVERSION = "v.0.1";
const SCRIPTNAME="importitera_meterremoteid";
require_once 'ExportIteraLib.php';

//--------------------------------------------------------------------------------------
//	Получаем таблицу счетчиков на сайте Itera
//--------------------------------------------------------------------------------------
function GetIteraMeters()
{
	global $config;
	global $curloptions;
	global $ch;
	$res = NULL;

	if ($config['options']['debug']) logger("get Itera meters list");

	$request = $config['bsmartapi']['url_getmeters'];

	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$result = curl_exec($ch);
	if(FALSE!==$result)
	{
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result,true);
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
//	Найти в нашей базе счетчик с указанныс серийным номером
//--------------------------------------------------------------------------------------
function FindDBMeterBySN($Model, $SN)
{
	$res = false;
	$sql = "SELECT * FROM powermeter WHERE meterserialno = $SN AND metermodel = $Model ;";
	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$row = mysql_fetch_assoc( $cursor ); 
		if (!empty($row)){
			$res = $row;
		}
		mysql_free_result($cursor);	
	}

	return $res;
}

//--------------------------------------------------------------------------------------
//--------------------------------------------------------------------------------------
function DBMeterUpdateRID($MID,$MRID)
{
	$res = false;
	if (empty($MID)) return $res;
	if (empty($MRID)) return $res;
	$sql = "UPDATE powermeter SET meterremoteid = $MRID WHERE id = $MID ;";
	if( FALSE != mysql_query($sql) ) $res = true;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Основной цыкла службы
//--------------------------------------------------------------------------------------
function MAIN_LOOP()
{
	$CountAll = 0;
	$CountDuplicatRid = 0;
	$CountNotFound = 0;
	$CountAdded = 0;
	$CountAddFailed = 0;

	$IteraMeterList = GetIteraMeters();

	if (!empty($IteraMeterList)){
		foreach($IteraMeterList["Records"] as $rec){
			$CountAll++;
			$lsh = "IteraID: ".$rec["id"]." serial:".$rec["device_no"]." => ";
			$dbmeter = FindDBMeterBySN( $rec["device_no"], $rec["device_no"]  );
			if (!empty($dbmeter)){
				if (empty($dbmeter["meterremoteid"])){
					if ( DBMeterUpdateRID($dbmeter["counter_type"], $rec["id"]) ){
						$CountAdded++;
						logger($lsh." successful update rid for meter dbID:".$dbmeter["id"]);
					}else{
						$CountAddFailed++;
						logger($lsh." failed update rid for meter dbID:".$dbmeter["id"]);
					}
				}else{
					$CountDuplicatRid++;
					logger($lsh." meter dbID:".$dbmeter["id"]." already have rid:".$dbmeter["meterremoteid"]);
				}
			}else{
				$CountNotFound++;
				logger($lsh." meter with this serial not fount in DB");
			}

			//if ($CountAll % 10 == 0)  logger("next 10 (".$CountAll.")");
		}

		logger("Processed ".$CountAll." records, of them:");
		logger("Meters not found - ".$CountNotFound);
		logger("Failed to update mrid - ".$CountAddFailed);
		logger("Update mrid successful - ".$CountAdded);
		logger("Meters with duplication mrid - ".$CountDuplicatRid);
	}else{
		logger("Filed to load Itera meter list!");
	}
}

//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();

?>
