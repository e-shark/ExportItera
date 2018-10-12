<?php
const SCRIPTVERSION = "v.0.1";
const SCRIPTNAME="exportitera_meterdata";
require_once 'ExportIteraLib.php';


//--------------------------------------------------------------------------------------
//	Поставить отметку о передачи файла
//--------------------------------------------------------------------------------------
function UpdateDBRec($id)
{
	$sql = "UPDATE powermeterdata SET mdataexportiteratime = now() WHERE id=".$id.";";
	logger("UPDATE powermeterdata for ".$id);
	if( FALSE == mysql_query($sql) ) 
		logger("Can't UPDATE powermeterdata for id=".$id."!");
}

//--------------------------------------------------------------------------------------
function ProcessMeterData($rec)
{
	$res = false;
	if (empty($rec)) return $res;

	// Тут надо попытаться запихать данные из записи в Итеру

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

	$sql = "SELECT pmd.id, pmd.mdatatime, pmd.mdata, pm.meterremoteid FROM powermeterdata pmd 
			JOIN powermeter pm ON pm.id = pmd.mdatameter_id
			WHERE pm.meterremoteid IS NOT NULL
			AND TIMESTAMPDIFF(DAY, pmd.mdatatime, now()) <= ".$daydepth."
			AND pmd.mdatacode = '1.8.0'
			ORDER BY pmd.mdatatime;";
	if ($config['options']['debug']) 
		logger("SQL: "$sql);

	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$num_rows = mysql_num_rows($cursor);
		logger(mysql_num_rows($cursor).' rows have fetched from tale');
		if ($num_rows > 0) {

			$COUNT = 0;
			while( $row = mysql_fetch_assoc( $cursor ) ) {
				logger("--------------------------------------------------------");
				$COUNT += 1;

				if ( ProcessMeterData($row) ) 
					UpdateDBRec($row['id']);

				//usleep(100e3);	// задержка на 100 миллисекунд
				//if ($COUNT>5) break;	// !!! ДЛЯ ОТЛАДКИ
				if ($COUNT % 10 == 0) logger("next 10 (".$COUNT.")");

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
