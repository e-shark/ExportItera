<?php
const SCRIPTVERSION = "v.0.1";
const SCRIPTNAME="exportitera_meterdata";
require_once 'ExportIteraLib.php';


//--------------------------------------------------------------------------------------
//	Поставить отметку о передачи показаний
//--------------------------------------------------------------------------------------
function UpdateDBRec($id)
{
	$sql = "UPDATE powermeterdata SET mdataexportiteratime = now() WHERE id=".$id.";";
	logger("UPDATE powermeterdata for ".$id);
	if( FALSE == mysql_query($sql) ) 
		logger("Can't UPDATE powermeterdata for id=".$id."!");
}

//--------------------------------------------------------------------------------------
//	Отправить показания счетчика в Итеру
//--------------------------------------------------------------------------------------
function SendMeterDataToItera($rec)
{
	global $config;
	global $curloptions;
	global $ch;
	$res = false;

	$postdata=[			
		'@device_id' => $rec['meterremoteid'],
		'@source_id' => 5,
		'@date' => $rec['mdatatime'],
		'@value' => $rec['mdata'],
		'@description' => $rec['mdatacomment'],
	];
	$iteraAPIurl = $config['bsmartapi']['url_addmeterdata'];
	curl_setopt_array($ch,$curloptions+[
		CURLOPT_URL=>$iteraAPIurl,
		CURLOPT_POST=>1,
		CURLOPT_POSTFIELDS=>$postdata,
	]);

	logger("Try send rec ".$rec['id'].": ".json_encode((array)$postdata,JSON_UNESCAPED_UNICODE));

	if ($config['options']['skiptransfer']) {
		if ($config['options']['debug']) logger(" ... skiptransfer by config.");
		return $res;									// Если запрет передачи данных
	}

	$txresult = curl_exec($ch); 
	if( FALSE === $txresult )
		logger('Error: failed insert for rec '.$rec['mdata']);
	else {
		$txresult = mb_convert_encoding($txresult,"UTF-8");
		if ($config['options']['debug']) {
			logger("Itera insert for rec ".$rec['mdata']." result: ".$txresult);
		}
		$jsonAnswer = json_decode($txresult,true);
		if ( is_array($jsonAnswer) ) 
			if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
				$res = true;
			}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Обработать запись с показаниями по счетчику
//--------------------------------------------------------------------------------------
function ProcessMeterData($rec)
{
	$res = false;
	if (empty($rec)) return $res;

	if ( SendMeterDataToItera($rec) ) {
		UpdateDBRec($rec['id']);
		$res = true;
		usleep(100e3);		// Небольшая задержка (100 миллисекунд), чтобы не очень насиловать сервера
	}

	return $res;
}

//--------------------------------------------------------------------------------------
//	Основной цыкла экспорта
//--------------------------------------------------------------------------------------
function MAIN_LOOP()
{
	global $config;

	if (empty($config['options']['daydepth'])) 
		$daydepth = 30;
	else
		$daydepth = $config['options']['daydepth'] + 0;

	$sql = "SELECT pmd.id, pmd.mdatatime, pmd.mdata, pmd.mdatacomment, pm.meterremoteid FROM powermeterdata pmd 
			JOIN powermeter pm ON pm.id = pmd.mdatameter_id
			WHERE pm.meterremoteid IS NOT NULL
			AND TIMESTAMPDIFF(DAY, pmd.mdatatime, now()) <= ".$daydepth."
			AND mdataexportiteratime IS NULL
			AND pmd.mdatacode = '1.8.0'
			ORDER BY pmd.mdatatime;";

	if ($config['options']['debug']) logger("SQL: ".$sql);

	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$num_rows = mysql_num_rows($cursor);
		logger(mysql_num_rows($cursor).' rows have fetched from table powermeterdata');
		if ($num_rows > 0) {

			$COUNT = 0;
			while( $row = mysql_fetch_assoc( $cursor ) ) {
				$COUNT += 1;
				if ($config['options']['debug']) logger("--------------------------------------------------------");

				ProcessMeterData($row); 								// Обрабатываем очередную запись

				//usleep(100e3);	// задержка на 100 миллисекунд
				//if ($COUNT>0) {logger("Stopped by debug mode!"); break;}	// !!! ДЛЯ ОТЛАДКИ
				//if ($COUNT % 10 == 0) logger("next 10 (".$COUNT.")");

				// Проверка на время выполнения скрипта
				if ( IsExecTimeExceeded() ) break;

			} //while
		}
		mysql_free_result($cursor);	
		//logger("Exported  successfully ".$counerDone." of ".$counerAll." records");
	}else{
		logger("Can't read table");
	}


}

//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();

?>
