<?php
const SCRIPTVERSION = "v.0.3";
const SCRIPTNAME="exportitera_swboard";
require_once 'ExportIteraLib.php';


//--------------------------------------------------------------------------------------
//	Поставить отметку о передачи файла
//--------------------------------------------------------------------------------------
function UpdateDBRec($id)
{
	$sql = "UPDATE elevator_gallery SET iteraexporttime = now() WHERE id=".$id.";";
	logger("UPDATE elevator_gallery for ".$id);
	if( FALSE == mysql_query($sql) ) 
		logger("Can't UPDATE elevator_gallery for id=".$id."!");
}

//--------------------------------------------------------------------------------------
//	Отправить файл фотографии в Итеру
//--------------------------------------------------------------------------------------
function SendFileToItera($picrec)
{
	$res = false;
	global $config;
	global $curloptions;
	global $ch;

	// проверяем
	if (empty($picrec)) return $res;

	if ($config['options']['debug']) {
		logger( "Processing pic: ".$picrec['id']." ".json_encode((array)$picrec,JSON_UNESCAPED_UNICODE) );
	}

	if (empty($picrec['elremoteid'])) return $res;
	if (empty($picrec['elevator_id'])) return $res;

	// готовим данные
	$filepath = trim( $config['SWPictures']['PicPath'] );
	if ($config['SWPictures']['PicPath']{strlen($config['SWPictures']['PicPath'])-1} == DIRECTORY_SEPARATOR) 		// убираем лишнюю черту, если она есть
		$filepath = substr($config['SWPictures']['PicPath'] ,0,-1);
	$filepath =  $filepath.DIRECTORY_SEPARATOR."E_".$picrec['elevator_id'];

	$filename = $filepath.DIRECTORY_SEPARATOR.$picrec['fname'];
	$maxlen = $config['SWPictures']['maxfilelen'];
	$data = file_get_contents( $filename , FALSE, NULL, 0 ,$maxlen );
	if (!$data) {
		logger("Error: can't load picture id:".$picrec['id'].' from ['.$filename.'] for sw_id:'.$picrec['elevator_id']);
		return $res;
	}
	$data64 = base64_encode( $data );
	$time = filemtime($filename);
	if (false == $time) $time = date("Y-m-d H:i:s");		// если не удалось получить время создания файла, берем текущее время
	else $time = date("Y-m-d H:i:s", $time);

	// отправляем данные
	$iteraAPIurl = $config['bsmartapi']['url_addphoto'];
	$postdata=[			
		'@device_id' => $picrec['elremoteid'],
		'@created' => $time,
		'@description' => $picrec['fclientname'],
		'@image' => $data64,
	];
	curl_setopt_array($ch, $curloptions+[
		CURLOPT_URL=>$iteraAPIurl,
		CURLOPT_POST=>1,
		CURLOPT_POSTFIELDS=>$postdata,
	]);

	if ($config['options']['debug']) {
		logger( "try send pic:  {@device_id=".$postdata['@device_id'].", @created=".$postdata['@created'].", @description=".$postdata['@description']."}" );
	}


	if ($config['options']['skiptransfer']) {
		if ($config['options']['debug']) logger(" ... skiptransfer by config.");
		return $res;		// Если запрет передачи данных
	}

	$txresult = FALSE;
	$txresult = curl_exec($ch);
	if( FALSE === $txresult )
		logger('Error: failed picture id:'.$picrec['id'].' transfer for sw_id:'.$picrec['elevator_id']);
	else {
		$txresult = mb_convert_encoding($txresult,"UTF-8");
		if ($config['options']['debug']) {
			logger("Itera ".$opname." result: ".$txresult);
		}
		$jsonAnswer = json_decode($txresult,true);
		if ( is_array($jsonAnswer) ) 
			if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
				$res = true;
				//$responce =  $jsonAnswer['Result'];
			}
	}	
	return $res;
}

//--------------------------------------------------------------------------------------
//	Основной цыкла экспорта
//--------------------------------------------------------------------------------------
function MAIN_LOOP(){

	$sql = "SELECT eg.*, el.elremoteid
			FROM elevator_gallery eg 
			JOIN elevator el ON el.id = eg.elevator_id 
			WHERE eg.iteraexporttime IS NULL ;";
	logger("SQL: ",$sql);

	$cntSent = 0;

	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$num_rows = mysql_num_rows($cursor);
		logger(mysql_num_rows($cursor).' rows have fetched from tale');
		if ($num_rows > 0) {

			$COUNT = 0;
			while( $row = mysql_fetch_assoc( $cursor ) ) {
				logger("--------------------------------------------------------");
				$COUNT += 1;

				if ( SendFileToItera($row) ) {
					UpdateDBRec($row['id']);
					$cntSent++;
				}

				//usleep(100e3);	// задержка на 100 миллисекунд
				//if ($COUNT>2) break;	// !!! ДЛЯ ОТЛАДКИ
				if ($COUNT % 10 == 0) logger("next 10 (".$COUNT.")");

				// Проверка на время выполнения скрипта
				if ( IsExecTimeExceeded() ) break;

			} //while
		}
		mysql_free_result($cursor);	
		//logger("Exported  successfully ".$counerDone." of ".$counerAll." records");
		logger("Images have been sent: ".$cntSent);
	}else{
		logger("Can't read table from DB");
	}

}

//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();

?>
