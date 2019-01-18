<?php
const VERSION_EXPORTITERA="v.1.25";
require_once __DIR__.'\\exportitera_config.php';

$ridtbl = [];
$ch;
$curloptions = [
	CURLOPT_HTTPGET=>TRUE,
	CURLOPT_HEADER=>0,
	CURLOPT_RETURNTRANSFER=>TRUE,
	CURLOPT_TIMEOUT=>4,
	CURLOPT_COOKIEFILE=>"",	// Set an empty string to enable Cookie engine!!!
];

//--------------------------------------------------------------------------------------
//	Очистить текст от непечатаемых символов
function stripWhitespaces($string) {
  $old_string = $string;
  $string = strip_tags($string);
  $string = preg_replace('/([^\pL\pN\pP\pS\pZ])|([\xC2\xA0])/u', ' ', $string);
  $string = str_replace('  ',' ', $string);
  $string = trim($string);
  
  if ($string === $old_string) {
    return $string;
  } else {
    return stripWhitespaces($string); 
  }  
}

//--------------------------------------------------------------------------------------
/**
 *	Logger to file
 * @param string message
 * @return boolean result
 */
function logger($message)
{
	global $config;

	$dbgfn = __DIR__."\\".date('ymd_').basename(__FILE__,".php")."dbg.txt";
	
	if ($config['options']['conlog']) echo stripWhitespaces($message)."\n";
	
	if( FALSE === ( $dbgfp = fopen( $dbgfn, "a" ) ) ) return FALSE;
	fputs($dbgfp, date('H:i:s').' '.$message."\n");
	fclose($dbgfp);
	return TRUE;
}

//--------------------------------------------------------------------------------------
//	Процедура логина на сервере Итера
//--------------------------------------------------------------------------------------
function LoginForItera()
{
	$res = false;
	global $config;
	global $curloptions;
	global $ch;

	$request = $config['bsmartapi']['url_login'];
	$ch=curl_init($request);
	curl_setopt_array($ch,$curloptions);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HTTPHEADER, ["Referer: ".$request]);  
	curl_setopt($ch, CURLOPT_POSTFIELDS, ['Login' => $config['bsmartapi']['username'], 'Password'=>$config['bsmartapi']['password']]);
	if ( FALSE === ( $result = curl_exec($ch) ) ) {
			$txresult = 'Error:network failure when auth';
			$res = false;
	}else{
		$curlinfo=curl_getinfo($ch,CURLINFO_COOKIELIST);
		$curlinfostr = (is_array($curlinfo)) ? implode(' ',$curlinfo) : $curlinfo;
		if (FALSE !== strpos($curlinfostr,"ASPXAUTH")) {
			$txresult = 'Auth Ok!';
			$res = true;
		}else{
			$txresult = 'Error:failed to Auth';
			$res = false;
		}	
	}

	return $res;
}

//--------------------------------------------------------------------------------------
//	Получить в Итере remote_id и девайс (rdevice_id) 
//  по номеру зафвки Itera, или по номеру заявки 1562
//	(бывшая функция GetTi1562Id)
//--------------------------------------------------------------------------------------
function GetTicketItera( &$row )
{	
	$res = -1;
	global $config;
	global $curloptions;
	global $ch;

	if ( empty($row['rticket_id']) && empty($row['ticode1562']) && empty($row['ticodelogged']) ){
		if ($config['options']['debug']) logger("Itera GET id Error! Empty rticket_id and ticode1562 and ticodelogged for ticket_id=".$row['ticket_id']);
		return $res;
	}

	if ( !empty($row['rticket_id']) )
		$request = $config['bsmartapi']['url_getticket']."?filter(id)=equals(".$row['rticket_id'].")";
	else
		if ( !empty($row['ticode1562']) )
			$request = $config['bsmartapi']['url_getticket']."?filter(no)=equals(".$row['ticode1562'].")";
		else
			$request = $config['bsmartapi']['url_getticket']."?filter(no)=equals(".$row['ticodelogged'].")";

	$row['txrequest'] .= $request."\n";
	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$row['txcount']++;
	$result = curl_exec($ch);
	if(FALSE===$result)
		$row['txresult'] .= 'Error: failed to transmit 1562 request'."\n";
	else {
		$res = 0;
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result,true);
		if(is_array($jsonAnswer)) {
			if (!empty($jsonAnswer['Records'][0]['id'])){
				$row['rticket_id'] = $jsonAnswer['Records'][0]['id'];
				$res = 1;
				// Заодно, если у нас нет id девайса, возьмем его у Итеры			
				if (empty($row['rdevice_id']) && !empty($jsonAnswer['Records'][0]['device_id'])){
					$row['rdevice_id'] = $jsonAnswer['Records'][0]['device_id'];
				}
			}
		}
		$row['txresult'] .= str_replace("\n", "", mb_substr($result,0,50) )."\n";
	}

	if ($config['options']['debug']) logger("Itera GET id for ticode1562=".$row['ticode1562']."\n  Request:".$request." Response:".$result."");

	return $res;
}		

//--------------------------------------------------------------------------------------
//	Апдейтим remote_id для всех записей с указанным номером заявки
//	в базе и в локальной таблице
//--------------------------------------------------------------------------------------
function UpdateRId($t_id, $r_id, $r_dev_id)
{
	global $ridtbl;
	global $config;

	if (is_null($t_id)) return;

	// Запись для локальной таблицы
	$rec=["ticket_id"=>$t_id, "rticket_id"=>$r_id];

	// Апдейтим таблицу БД
	$tsql = "UPDATE exportiteralog SET rticket_id=".$r_id;
	if (!empty($r_dev_id)) {
		$tsql .= ", rdevice_id=".$r_dev_id;
		$rec["rdevice_id"] = $r_dev_id;
	}	
	$tsql .= " WHERE ticket_id=".$t_id." ;";
	if ($config['options']['debug']) logger( $tsql );
	if( FALSE == ( $curupd = mysql_query($tsql) ) ) {
		logger( "Error: failed UPDATE exportiteralog  (ticket_id:".$t_id.",rticket_id:".$r_id.") , error: ".mysql_error() );
	}
	//mysql_free_result($curupd); не выполняем, потомучто тут $curupd для update - это boolean а не курсор

	// Апдейтим локальную таблицу
	$ridtbl[] = $rec;
}

//--------------------------------------------------------------------------------------
//	Найти RemotID (если есть) для заявки в локальной таблице
//	Возвращает rticket_id, или NULL если указанного номера заявки нет в таблице
//--------------------------------------------------------------------------------------
function FindLocalRID($t_id)
{
	global $ridtbl;
	global $config;
	$res = NULL;

	foreach ($ridtbl as $value) {
		if ($t_id == $value['ticket_id']) {
			$res = $value['rticket_id'];
			if ($config['options']['debug']) logger("find rticket_id=".$res." for ticket_id=".$t_id." in local table");
			break;
		}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Найти RemotDeviceID (если есть) для заявки в локальной таблице
//	Возвращает rdevice_id, или NULL если указанного номера заявки нет в таблице
//--------------------------------------------------------------------------------------
function FindLocalRDevID($t_id)
{
	global $ridtbl;
	global $config;
	$res = NULL;

	foreach ($ridtbl as $value) {
		if ($t_id == $value['ticket_id']) {
			$res = $value['rdevice_id'];
			if ($config['options']['debug']) logger("find rdevice_id=".$res." for ticket_id=".$t_id." in local table");
			break;
		}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Получить предидущий статус для заявки
//--------------------------------------------------------------------------------------
function FindPredStatus($recid, $ticketid, &$rperformerID)
{
	global $config;
	$res = NULL;
	$sql = "SELECT id, rstatus_id from exportiteralog where id < $recid and ticket_id = $ticketid order by id desc limit 1; ";
    if( FALSE !== ( $dscursor = mysql_query($sql) ) ) { 
    		$row = mysql_fetch_assoc( $dscursor );
			if ( !empty($row) ) {
				if ( !empty($row['rstatus_id']) ) {$res = $row['rstatus_id'];}
				if ( !empty($row['rperformer_id']) ) $rperformerID = $row['rperformer_id'];
				else  $rperformerID = NULL;
			}
		mysql_free_result($dscursor);
	}else{
		logger("Can't make SELECT from table exportiteralog");
	}
	return $res;

}

//--------------------------------------------------------------------------------------
//	Инсерт (или апдейт) записи в Иетру
//	Возвращает rticket_id
//--------------------------------------------------------------------------------------
function InsUpdIteraRec(&$row, $fInsert = false)
{
	global $config;
	global $curloptions;
	global $ch;

	$iteraAPIurl = $config['bsmartapi']['url_insupd'];
	$postdata=[			
		'@device_id'=> $row[ 'rdevice_id' ],
		'@priority_id'=> $row[ 'rpriority_id' ],
		'@performer_id'=> $row[ 'rperformer_id' ],
		'@turnon_plan_time'=> $row[ 'rturnon_plan_time' ],
		'@description'=> $row[ 'rdescription' ],
		'@created'=> $row[ 'rcreated' ],
	];

	if (!empty($row[ 'rstatus_id' ]))
		$postdata['@status_id'] = $row[ 'rstatus_id' ];

	if (!empty($row['rmalfunction_id']))
		$postdata['@malfunction_id'] = $row[ 'rmalfunction_id' ];

	if (is_null($row['ticode1562']))
		$postdata['@no'] = $row[ 'ticodelogged' ];

	if (is_null($row[ 'rturnoff_confirmed' ])){
		if (!empty($row[ 'rturnoff_time' ]))
			$postdata['@turnoff_time']= $row[ 'rturnoff_time' ];
		else
			$postdata['@turnoff_time']= $row[ 'rcreated' ];
	}else{
		if (1 == $row[ 'rturnoff_confirmed' ]){
			$postdata['@turnoff_time'] = $row[ 'rturnoff_time' ];
			$postdata['@turnon_time'] = $row[ 'rturnon_time' ];
			$postdata['@is_turnoff_confirmed'] = '1';
		}
	}
	
	if($fInsert){	
	}else{
		// Edit existing
		$iteraAPIurl .= "?id=".$row[ 'rticket_id' ];	
		$postdata['@time'] = $row[ 'recordtime' ];
		$postdata['@user_id'] = $row[ 'ruser_id' ];
		//unset($postdata['@device_id']);
		//unset($postdata['@created']);
	}
	curl_setopt_array($ch,$curloptions+[
		CURLOPT_URL=>$iteraAPIurl,
		CURLOPT_POST=>1,
		CURLOPT_POSTFIELDS=>$postdata,
	]);
	$row['txcount']++;
	$row['txrequest'] .= $iteraAPIurl."\n";
	$insupdOk = false;
	$txresult = curl_exec($ch); 
	if( FALSE === $txresult )
		$txresult='Error:failed to transmit ticket';
	else {
		$txresult = mb_convert_encoding($txresult,"UTF-8");
		$jsonAnswer = json_decode($txresult,true);
		if ( is_array($jsonAnswer) ) 
			if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
				$insupdOk = true;
				$row['isexportdone'] = 1;
				if( empty($row[ 'rticket_id' ]) ) {
					$row[ 'rticket_id' ] = $jsonAnswer['Result'];
				}
			}
	}
	$row['txresult'] .= str_replace("\n","",$txresult)."\n";

	logger("Itera POST ".($fInsert?"INSERT":"UPDATE")." for id=".$row[ 'rticket_id' ]."\n  Request:".$iteraAPIurl." postdata:".var_export($postdata,true)."\n Response:".$txresult);

	//--- Set new status for Itera ticket
	/*
	if( (!empty($row[ 'rticket_id' ])) && $insupdOk ){
		if (!empty($row[ 'rstatus_id' ])) {
			$pid = NULL;
			$pstatus = FindPredStatus($row['id'], $row['ticket_id'], $pid);		// смотрим какой был предидущий статус
			if (($pstatus != $row[ 'rstatus_id' ]) ||											// отправляем в Итеру только смену статуса
			    (($pstatus == $row[ 'rstatus_id' ]) && ($pid != $row[ 'rperformer_id' ])))	{	// или смену исполнителя						
				$iteraAPIurl = $config['bsmartapi']['url_setstatus'];	
				$postdata=[			
					'ticket_id'=> $row[ 'rticket_id' ],
					'status_id'=> $row[ 'rstatus_id' ],
					'time'=> $row[ 'recordtime' ],
					'user_id' => $row[ 'ruser_id' ],
				];

				curl_setopt_array($ch, $curloptions+[ 
					CURLOPT_URL=>$iteraAPIurl,
					CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postdata
				]);
				$row['txcount']++;
				$row['txrequest'] .= $iteraAPIurl."\n";
				$txresult=curl_exec($ch);
				if( FALSE === $txresult ) 
					$txresult = 'Error:failed to transmit status';
				else {
					$txresult = mb_convert_encoding($txresult,"UTF-8");
					$jsonAnswer = json_decode($txresult, true);
					if ( is_array($jsonAnswer) ) 
						if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
							$row['isexportdone'] = 1;
						}
				}
				$row['txresult'] .= str_replace("\n","",$txresult)."\n";

				logger("Itera POST STATE for id=".$row[ 'rticket_id' ]."\n Request:".$iteraAPIurl." postdata:".var_export($postdata,true)."\n Response:".$txresult);
			}else{
				$row['isexportdone'] = 1;
			}
		}else{
			$row['isexportdone'] = 1;
		}
	}
	*/

	//-- Send Comment
	if (!empty($row[ 'rcomment' ]) && !empty($row[ 'rticket_id' ])){
			$iteraAPIurl = $config['bsmartapi']['url_setcomment'];	
			$postdata=[			
				'@ticket_id'=> $row[ 'rticket_id' ],
				'@description' => $row[ 'rcomment' ],
				'@user_id' => $row[ 'ruser_id' ],
				'@time'=> date("Y-m-d H:i:s", strtotime($row[ 'recordtime' ]." +1 seconds")),
			];
			curl_setopt_array($ch, $curloptions+[ 
				CURLOPT_URL=>$iteraAPIurl,
				CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postdata
			]);
			$row['txcount']++;
			$row['txrequest'] .= $iteraAPIurl."\n";
			$txresult=curl_exec($ch);
			if( FALSE === $txresult ) 
				$txresult = 'Error:failed to transmit comment';
			else {
				$txresult = mb_convert_encoding($txresult,"UTF-8");
				$jsonAnswer = json_decode($txresult, true);
				if ( is_array($jsonAnswer) ) 
					if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
						//пока непонятно как праздновать успешную передачу коммента
					}
			}
			$row['txresult'] .= str_replace("\n","",$txresult)."\n";

			logger("Itera POST comment for id=".$row[ 'rticket_id' ]."\n Request:".$iteraAPIurl." postdata:".var_export($postdata,true)."\n Response:".$txresult);

	}

	return $row[ 'rticket_id' ];
}

//--------------------------------------------------------------------------------------
//	Апдейт состояния тикета в нашей базе
//--------------------------------------------------------------------------------------
function UpdateRecState($row)
{
	global $config;

	$sql = "UPDATE exportiteralog SET".
	       " txtime='".date("Y-m-d H:i:s")."'".
	       " ,txattempts=".$row['txattempts'].
	       " ,txcount=".$row['txcount'].
	       " ,txrequest='".mb_substr( mb_convert_encoding(addslashes($row['txrequest']),'UTF-8') ,0,255)." '".	//vpr,181027, add space to the last substring ("'"->" '")
	       " ,txresult='".mb_substr( mb_convert_encoding(addslashes($row['txresult']),'UTF-8') ,0,255)." '".	//vpr,181027, add space to the last substring ("'"->" '")
      	   (!is_null($row['isexportdone']) ? " ,isexportdone=".$row['isexportdone'] : "").
      	   " WHERE id=".$row['id']." ;";

	if ($config['options']['debug']) logger( "UPDATE exportiteralog sql: ".stripWhitespaces($sql) );

	if( FALSE == ( $cursorupd = mysql_query($sql) ) ) 
	{
		logger( "Error: failed update exportiteralog id:".$row['id']." , error: ".mysql_error() );
	}
	//mysql_free_result($cursorupd); не выполняем, потомучто тут $cursorupd для update - это boolean а не курсор
}

//--------------------------------------------------------------------------------------
//	Обработка очередной записи
//--------------------------------------------------------------------------------------
function ProcessRecord(&$row)
{
	global $config;
	$gtires = 0;

	if (empty($row['rticket_id'] )) 
		$row['rticket_id'] = FindLocalRID($row['ticket_id']);						// Пытаемся получить remote_id в локальной таблице

	if (empty($row['rdevice_id'] )) 
		$row['rdevice_id'] = FindLocalRDevID($row['ticket_id']);					// Пытаемся получить rdevice_id в локальной таблице

	if ( empty($row['rdevice_id']) || empty($row['rticket_id']) ){					// если нет девайса, или нет номера заявки Itera
		$gtires = GetTicketItera( $row );											// Пытаемся получить у Itera remote_id для заявки (а так же номер девайса)
		if ( 1 == $gtires ) 
			UpdateRId($row['ticket_id'], $row['rticket_id'], $row['rdevice_id']);	// Обновляем remote_id и rdevice_id в нашей базе для всех заявок с данным номером
	}

	if ($gtires >= 0) {
		if ( empty( $row['ticode1562'] ) OR  !empty( $row['rticket_id'] ) ) {		// Выполняется для всех кроме 1562 без rticket_id
			if (!$config['options']['skiptransfer']) {								// Во время отладки пропускаем передачу данных в Итеру
				if(empty( $row['rticket_id']))  {					
					InsUpdIteraRec($row, true);										// insert
					UpdateRId($row['ticket_id'], $row['rticket_id'], NULL);			// Обновляем remote_id в нашей базе для всех заявок с данным номером
				}else
					InsUpdIteraRec($row, false);									// update
			}else{
				logger("SKIPPED transmission to Itera (by config).");
			}	
		}
	} else
		logger("Process SKIPPED from the fail GetTicketItera.");

}


//--------------------------------------------------------------------------------------
//	Проверяет, не запущена ли уже копия задачи,
//  если копий нет, ставит в базе отметку о запуске
//--------------------------------------------------------------------------------------
function ChekFoStartPermission()
{
	global $config;
	$res = false;
	$sql = "SELECT starttime, TIMESTAMPDIFF(MINUTE, starttime, now()) as extime from batchscriptlog where scriptname='exportitera';";
    if( FALSE !== ( $dscursor = mysql_query($sql) ) ) {
    	$dsrows = mysql_num_rows($dscursor);
    	if ($dsrows>0){
    		// Есть запись. Значит задача уже запускалась
    		$dsrow = mysql_fetch_assoc( $dscursor );
    		// Проверяем, как давно был запуск задачи
    		if ( is_null($dsrow['extime']) || ($dsrow['extime'] > $config['options']['exectout']) ) {
    			if (($dsrow['extime'] > $config['options']['exectout']))
    				logger("StartTime label (".$dsrow['extime']." min ago) out of timeout (".($config['options']['exectout'])." min) !");
    			// Превыщен лимит. Подразумеваем, что процесс, оставивший пометку в базе, просто звершился крахом, не успев снять пометку из базы
    			$sql = "UPDATE batchscriptlog SET starttime = now() WHERE scriptname='exportitera'; ";
    			if( mysql_query($sql) ) {
					logger("Update table batchscriptlog ");
	    			$res = true;
    			}else
					logger("Can't update table batchscriptlog");
    		}else{
				logger("Task already started!");
    		}
        }else{ 
        	// Записей нет, значит уже запущенных задач нет
  			$sql = "INSERT INTO batchscriptlog (scriptname, starttime) VALUES ('exportitera', now()); ";
			if( mysql_query($sql) ) {
    			$res = true;
				logger("Insert in table batchscriptlog");
			}
			else
				logger("Can't insert in table batchscriptlog");
		}
		mysql_free_result($dscursor);
	}else{
		logger("Can't read table batchscriptlog");
	}
	return $res;
}

//--------------------------------------------------------------------------------------
// Удаляет из базы отметку о запуске задачи
//--------------------------------------------------------------------------------------
function DeleteStartMarker()
{
	$sql = "DELETE FROM batchscriptlog WHERE scriptname='exportitera'";
	logger("Delete StartMarker from table batchscriptlog");
	if( FALSE == mysql_query($sql) ) 
		logger("Can't delete marker from table batchscriptlog");
}


//--------------------------------------------------------------------------------------
//	MAIN LOOP
//--------------------------------------------------------------------------------------
$scriptstat = stat(__FILE__);
$TaskStartTime = time();
logger( "*****************************************************************************************************************");
logger( "Start ".__FILE__.' '.VERSION_EXPORTITERA." Build ".date('Y-m-d H:i:s',$scriptstat['mtime']).' Size: '.$scriptstat['size'] );
//---Open DB connection
if( ! ( $db = @mysql_connect ( $config['db']['dbserver'], $config['db']['username'], $config['db']['password'] ) ) ) {
	logger( "Error: failed connected to DB, error: ".mysql_error() );
	exit(-1);
}
mysql_select_db($config['db']['dbname']);
	$counerAll = 0;
	$counerDone = 0;

	if (empty($config['options']['periodattempt'])) 
		$attemptsinterval = 10;
	else
		$attemptsinterval = $config['options']['periodattempt'] + 0;

	if (empty($config['options']['daydepth'])) 
		$daydepth = 3;
	else
		$daydepth = $config['options']['daydepth'] + 0;

	if (empty($config['options']['exectout'])) 
		$config['options']['exectout'] = 30;

    if (ChekFoStartPermission()) {
		//$sql = "SELECT * FROM exportiteralog WHERE IFNULL(isexportdone,0) = 0 AND TIMESTAMPDIFF(DAY, recordtime, now())<=".$daydepth." ORDER BY id ;";
		$sql = "SELECT exportiteralog.*, ticket.ticode 
				FROM exportiteralog join ticket on ticket.id = exportiteralog.ticket_id
				WHERE IFNULL(isexportdone,0) = 0 AND recordtime >= DATE_SUB(NOW(), INTERVAL ".$daydepth." DAY)
				ORDER BY exportiteralog.id ;";
		if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
			$num_rows = mysql_num_rows($cursor);
// $num_rows = 0;	// ДЛЯ ОТЛАДКИ !!!!!!
			logger(mysql_num_rows($cursor).' rows have fetched from exportiteralog for export to Itera');
			if ($num_rows > 0) {
				if (LoginForItera()) {
					while( $row = mysql_fetch_assoc( $cursor ) ) {

						// если попытка отослать заявку уже была, то смотрим когда она была, 
						// и если не прошло еще достаточно времени, с последней попытки, пропускаем эту заявку
						if (!empty($row['txattempts'])) {
							if (!empty($row['txtime'])){
								$attempttime = strtotime($row['txtime']." +".$attemptsinterval." min");		// вычисляем, с какого времени можно предпринимать следующую попытку
								if ($attempttime > time()) {
									//logger("--- [".$row['id']."] skipped (last time ".$row['txtime'].")");
									continue;
								}
							}
						}

						$row['txrequest'] = "";
						$row['txresult'] = "";
						$row['txcount'] = 0;

						$row['txattempts']++;					// увеличиваем счетчик попыток

						logger("--------------------------------------------------------");
						logger("process exportiteralog record id=".$row['id']." ticode=".$row['ticode']." ticket_id=".$row['ticket_id']." recordtime:".$row['recordtime'].
							   " rticket_id='".$row['rticket_id']."' ticode1562='".$row['ticode1562']."'");

						ProcessRecord($row);  

						// проапдейтить запись $txresult , count и пр
						UpdateRecState($row);

						$counerAll++;
						if (!is_null($row['isexportdone'])) {
							logger("Export for id ".$row['id'].": Ok");
							$counerDone++;
						}else{
							logger("Export for id ".$row['id'].": Error");
						}

						$TaskExecTime = time() - $TaskStartTime;
						if ( $TaskExecTime/60 > $config['options']['exectout']) {
							logger("Execution time  exceeded (".$config['options']['exectout']." min max). Execution aborted.");
							break;							// Прерываем выполнение цикла
						}

					} //while
				}else{
					logger("Can't login to Itera server.");
				}
				curl_close( $ch );
			}
			mysql_free_result($cursor);	
			logger("Exported  successfully ".$counerDone." of ".$counerAll." records");
		}else{
			logger("Can't read table");
		}
		DeleteStartMarker();
	}else{
		logger("Haven't start permission. Export is not started.");
	}
mysql_close($db);
$TaskExecTime = time() - $TaskStartTime;
logger("Done. Execution time ".$TaskExecTime." sec (".round( (time() - $TaskStartTime)/60 , 2)." min).");

?>