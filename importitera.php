<?php
const SCRIPTVERSION = "v.2.4";
const SCRIPTNAME="importitera";
require_once 'ExportIteraLib.php';

define('RECSPERPAGE',   20); 

$GlobalRecCount = 0;				// Всего записей от Итеры отработано
$InsertRecCount = 0;				// ЗЗаявок добавлено в нашу базу
$UpdateRecCoun = 0;					// Обновлено статусов заявок
$RejectedRecCount = 0;				// Возвращенных заявок

//--------------------------------------------------------------------------------------
//	Добавить кросс таблицу причин заявки
//--------------------------------------------------------------------------------------
function LoadMalfunctionCrossTable()
{
	global $config;

	$config['cross']['malfunction'] = [];
	$sql = "SELECT oostypecode as remotecod, id FROM oostype ; ";

	if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
		while ($row = mysql_fetch_assoc($rescursor)) 
			$config['cross']['malfunction'][$row['remotecod']] = $row['id'];
		mysql_free_result($rescursor);
	}else{
		if ($config['options']['debuglog'])  logger("Can't read oostype cross table. ");
	}

}


//--------------------------------------------------------------------------------------
//	Прочитать страницу с заявками из итеры
//	Page - какую страницу загружать
//	onlyRejected - если true, вытягивает возвращенные заявки
//--------------------------------------------------------------------------------------
function GetIteraTicketsPage($Page, $onlyRejected = false)
{
	$res = [];
	global $config;
	global $curloptions;
	global $ch;

	if (is_null($Page)) $Page = 0;
	//date("d M Y", strtotime("yesterday"))
	$DayDepth = 0 + $config['options']['daydepth'];
	if (empty($DayDepth)) $DayDepth = 1;
	$DateFrom = date('Y-m-d', strtotime("now -".$DayDepth." day"));
	if ($onlyRejected)	
//bgt 05.04.2019 window depth for rejected tickets must be determined on status_changed Itera date (changed created - status_changed)
		$request = $config['bsmartapi']['url_getticket']."?sort(id)=desc&pageSize=".RECSPERPAGE."&page=".$Page."&filter(status_changed)=After(".$DateFrom.")&filter(return_count)=greaterthan(0)&filter(status_id)=equals(1)";	
	else
		$request = $config['bsmartapi']['url_getticket']."?sort(id)=desc&pageSize=".RECSPERPAGE."&page=".$Page."&filter(created)=After(".$DateFrom.")&filter(source_id)=in(2,3,20,21,22,24,25,26,27,28,29,30,31,32)"; 	
	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$result = curl_exec($ch);
	if(FALSE===$result)
		logger('Error:failed request page'.$Page);
	else {
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result,true);
		if(is_array($jsonAnswer)) {
			// У нас есть массив в тикетами от Итеры
			$cnt = $jsonAnswer['TotalRecordsCount'];
			if ( is_array($jsonAnswer['Records']) ) {
				$res = $jsonAnswer['Records'];
				$cnt = count($res);
				logger("Recive ".$cnt." records on page ".$Page." from Itera");
			}
		}
	}

	if ($config['options']['debuglog']) logger("Itera GET page ".$Page. "\n  Request: ".$request."\n  Response: ".$result."");

	return $res;
}

//--------------------------------------------------------------------------------------
//--------------------------------------------------------------------------------------
function GetIteraTicketCommentLast($ITID)
{
	$res = [];
	global $config;
	global $curloptions;
	global $ch;

	$request = $config['bsmartapi']['url_getticketcomment']."?sort(created)=desc&filter(ticket_id)=equals({$ITID})"; 	
	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	if ($config['options']['debuglog']) logger("try read comment: ".$request)	;
	$result = curl_exec($ch);
	if(FALSE===$result)
		logger('Error:failed request page'.$Page);
	else {
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result,true);
		if(is_array($jsonAnswer)) {
			// У нас есть массив в тикетами от Итеры
			$cnt = $jsonAnswer['TotalRecordsCount'];
			if ( is_array($jsonAnswer['Records']) ) {
				$res = $jsonAnswer['Records'][0]['description'];
//logger("****************************************************[\n".json_encode($res)."\n]");
				$cnt = count($res);
				logger("Recive ".$cnt." comments for ITID".$ITID." ");
			}
		}
	}

	return $res;

}

//--------------------------------------------------------------------------------------
//	Ищет в нашей базе уже имеющиеся заявки из списка заявок Itera
//--------------------------------------------------------------------------------------
function FindExisting($IteraRecs)
{
	$res = [];
	// создаем строки с перечьнем номеров заявок, которые получены от Итеры
	$idlist = "";
	$nolist = "";
	$first = true;
	foreach($IteraRecs as $Rec){
		if ($first) 
			$first = false;
		else {
			$idlist .= ",";
			$nolist .= ",";
		}
		$idlist .= "'".$Rec['id']."'";
		$nolist .= "'".$Rec['no']."'";
	}
	// получаем из нашей базы тикеты, которые есть в списке тикетов, полученных от Итеры
	$sql = "SELECT id, ticode, ticoderemote, tistatus, ticalltype, tireturncount FROM ticket WHERE ticoderemote in (".$idlist.") OR ticode in (".$nolist.")  OR ticoderemote in (".$nolist.") ;";
	if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
		while ($row = mysql_fetch_assoc($rescursor)) 
			$res[] = $row;
		mysql_free_result($rescursor);
	}else{
		logger("Can't read existing ticets. ");
	}

	return $res;
}

//--------------------------------------------------------------------------------------
// Проверяет, есть ли у нас в базе уже такая заявка
//		$tlist - список тикетов из нашей базы, в которых есть номера из списка тиккетов Итеры
//		$rID   - ID тикета Итеры
//		$rNo   - No тикета Итеры
//	Возвращает ticket, если ID или No найден в нашей базе
//--------------------------------------------------------------------------------------
function CheckExists($tlist, $rID, $rNo)
{
	global $config;
	$result = NULL;

	if (empty($rID)) return false;
	foreach($tlist as $rec){
		if ((!empty($rID)) && ($rID == $rec['ticoderemote'])) 	{$result = $rec; break;}
		if ((!empty($rNo)) && ($rNo == $rec['ticode'])) 		{$result = $rec; break;}
		if ((!empty($rNo)) && ($rNo == $rec['ticoderemote'])) 	{$result = $rec; break;}
	}
	if (!empty($result))
		if ($config['options']['debuglog']) 
			logger( "record alredy exists (id='".$rID."', no='".$rNo."'  ticode='{$rec['ticode']}')" );

	return $result;
}

//--------------------------------------------------------------------------------------
//	Конвертировать значение поля используя кросс таблицу
//--------------------------------------------------------------------------------------
function GetCrossVal($Table, $Val)
{
	global $config;
	$result = NULL;
	if (empty($Table) || empty($Val)) return $result;
	foreach($Table as $key => $rec)
		if ($key == $Val) { $result = $rec; break; }
	return $result;
}

//--------------------------------------------------------------------------------------
//	Получить данные лифта из нашей базы по id лифта базы Itera
//--------------------------------------------------------------------------------------
function GetElevator($i_devid)
{
	$result = [];
	global $config;

	$sql = "SELECT l.id as elid, l.elinventoryno, f.id as faid, f.facode, l.eldivision_id FROM elevator l 
			left join
			(SELECT id, facode FROM facility )f
			ON l.elfacility_id = f.id
			WHERE l.elremoteid = {$i_devid} LIMIT 1 ; ";

	if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
		$result = mysql_fetch_assoc($rescursor); 
		mysql_free_result($rescursor);
	}else{
		if ($config['options']['debuglog'])  logger("Can't read Elevator for elremoteid={$i_devid}. ");
	}

	return $result;
}

//--------------------------------------------------------------------------------------
//	Получить данные юзера из нашей базы по id юзера базы Itera	
//--------------------------------------------------------------------------------------
function GetOriginator($i_userid)
{
	$result = [];
	global $config;

	$sql = "SELECT e.id, e.division_id, CONCAT(e.lastname,' ',e.firstname,' ',e.patronymic) as fio FROM employee e WHERE e.remoteid = {$i_userid} LIMIT 1;";		

	if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
		$result = mysql_fetch_assoc($rescursor); 
		mysql_free_result($rescursor);
	}else{
		if ($config['options']['debuglog'])  logger("Can't read Originator for remoteid={$i_userid}. ");
	}

	return $result;
}


//--------------------------------------------------------------------------------------
//	Записать в лог запись ticket, полученную от Itera и запись, которую я сформирую на основе этих данных
//--------------------------------------------------------------------------------------
function LogRecord($Rec,$tirec)
{
	$rn = "\n";
	$str = "";
	$str .= "'ticoderemote':".$tirec['ticoderemote']."	=	'id':".$Rec['id'].$rn;
	$str .= "'tiregion':".$tirec['tiregion']."	<=	(district), 'district_id':".$Rec['district_id'].$rn;
	$str .= "'tiobjectcode':".$tirec['tiobjectcode']."	<=	(DB elevator, elinventoryno), 'device_id':".$Rec['device_id'].$rn;
	$str .= "'tiequipment_id':".$tirec['tiequipment_id']."	<=	(DB elevator, id), 'device_id':".$Rec['device_id'].$rn;
	$str .= "'tifacilitycode':".$tirec['tifacilitycode']."	<=	(DB facility, facode), 'device_id':".$Rec['device_id'].$rn;
	$str .= "'tifacility_id':".$tirec['tifacility_id']."	<=	(DB facility, id), 'device_id':".$Rec['device_id'].$rn;
	$str .= "'tiaddress':".$tirec['tiaddress']."	=	'address':".$Rec['address'].$rn;
	$str .= "'tioostype_id':".$tirec['tioostype_id']."	<=	(malfunction), 'malfunction_id':".$Rec['malfunction_id'].$rn;
	$str .= "'tiobject_id':".$tirec['tiobject_id']."	<=	(malfunction_type), 'malfunction_type_id':".$Rec['malfunction_type_id'].$rn;
	$str .= "'tidescription':".$tirec['tidescription']."	=	'description':".$Rec['description'].$rn;
	$str .= "'tipriority':".$tirec['tipriority']."	<=	(tipriority), 'priority_id':".$Rec['priority_id'].$rn;
	$str .= "'tistatus':".$tirec['tistatus']."	<=	(tistatus), 'status_id':".$Rec['status_id'].$rn;
	$str .= "'tioriginator':".$tirec['tioriginator']."	<=	DB(employee,id) remoteid= 'created_by_user_id':".$Rec['created_by_user_id'].$rn;
	$str .= "'tioriginatordesk_id':".$tirec['tioriginatordesk_id']."	<=	DB(employee,division_id) remoteid= 'created_by_user_id':".$Rec['created_by_user_id'].$rn;
	$str .= "'tiplannedtime':".$tirec['tiplannedtime']."	=	'turnon_plan_time':".$Rec['turnon_plan_time'].$rn;
	$str .= "'tiplannedtimenew':".$tirec['tiplannedtimenew']."	=	'turnon_plan_time':".$Rec['turnon_plan_time'].$rn;
	$str .= "'ticalltype':".$tirec['ticalltype']."	<=	(source), 'source_id':".$Rec['source_id'] .$rn;
	$str .= "'tiopenedtime':".$tirec['tiopenedtime']."	=	'created':".$Rec['created'].$rn;
	$str .= "'tioosbegin':".$tirec['tioosbegin']."	=	'turnon_time':".$Rec['turnon_time'].$rn;
	$str .= "'tioosend':".$tirec['tioosend']."	=	'turnoff_time':".$Rec['turnoff_time'].$rn;
	$str .= "'tiopstatus':".$tirec['tiopstatus']."	=	'tiopstatus':".$Rec['tiopstatus'].$rn;

	logger($str);
}

//--------------------------------------------------------------------------------------
//	Определяет (по SourceId), что заявка из 9 программ
//--------------------------------------------------------------------------------------
function SourceIs9Prg($SourceId)
{
	$res = false;
	if ( (($SourceId >=20) && ($SourceId <=22)) ||
		 (($SourceId >=24) && ($SourceId <=32))) $res = true;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Получить из базы Division_Id по tiRigion 
// Region - название района (например 'КИЇВСЬКИЙ') берется по кросстаблице из кода района itera GetCrossVal( $config['cross']['district'], $Rec['district_id'] )
// ObjectId - тип оборудования
//--------------------------------------------------------------------------------------
function FindDivisionID($Region,$ObjectId)
{
	global $config;
	$res = NULL;
	$sql = "select division_id from district_division_devices, district where district.id=district_id  and districtlocality_id=159 and districtname='".$Region."' and device_type=".$ObjectId.";";

	if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
		$res = mysql_fetch_assoc($rescursor); 
		mysql_free_result($rescursor);
	}else{
		if ($config['options']['debuglog'])  logger("Can't read division_id for Region='{$Region}'. ");
	}

	if ($config['options']['debugmode']) { 			// ! ! !   ДЛЯ ОТЛАДКИ
		logger('get DI for region = '.$Region.' ObjID='.$ObjectId);
		logger('sql='.$sql);
		logger('res= '.serialize($res));
	}

	return $res['division_id'];
}
//--------------------------------------------------------------------------------------
// Обрабатывает массив заявок, полученных на странице Итеры
//--------------------------------------------------------------------------------------
function ProcessIteraTickets($IteraRecs)
{
	$Res = 0;
	global $config;
	global $GlobalRecCount;

	if (!is_array($IteraRecs)) return $Res;
	if (empty($IteraRecs)) return $Res;

	$RecExists = FindExisting($IteraRecs);

	$cnt = count($IteraRecs);
	foreach($IteraRecs as $Rec){
		$GlobalRecCount++;
		logger("--- ".$Rec['id']." -------------------------------------------------------------------");
		$Exists = CheckExists($RecExists, $Rec['id'], $Rec['no']);
		if (!empty($Exists)){
			// Такая заявка у нас уже есть
			if  (( 'Itera2' == $Exists['ticalltype']) && 							// рассматриваем только заявки, которые мы ранее импортировали из Итеры
				 ( in_array($Rec['status_id'], [2,3,11,12]))){
				// Меняем статус заяки
				if (SourceIs9Prg($Rec['source_id']))
					$FromIteraStatus = GetCrossVal( $config['cross']['tistatus9PRG'],$Rec['status_id'] );
				else
					$FromIteraStatus = GetCrossVal( $config['cross']['tistatus'],$Rec['status_id'] );

				if ($FromIteraStatus != $Exists['tistatus']){
					UpdateTicket( $Exists, $FromIteraStatus);
					$Res = $Exists['id'];
					// Читаем эту заявку, чтобы сформировать TicletLog
					$sql = "SELECT * FROM ticket WHERE id={$Exists['id']};";		
					if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
						$tirec = mysql_fetch_assoc($rescursor); 
						mysql_free_result($rescursor);
						InsertTicketLog($tirec);
					}else{
						if ($config['options']['debuglog'])  logger("Can't read exists ticket id={$Exists['id']}. ");
					}
				}else{
					if ($config['options']['debuglog'])  logger("Status not changed. ");
				}

			}
			// другие статусы нас вааще не интересуют
		}else{
			// У нас еще нет такой заявки
			if  ( in_array($Rec['status_id'], [11,12,13])) continue;		// Такие заявки не импортируем
			if (SourceIs9Prg($Rec['source_id'])) continue;					// Заявки из 9 программ не импортируем

			// Далее шаманство по трансформации данных из Itera в нашу заявку
			$tirec = [];

			$tirec['tiregion'] = GetCrossVal( $config['cross']['district'], $Rec['district_id'] );

			$getres = GetElevator($Rec['device_id']);
			if (!empty($getres)) {
				$tirec['tiobjectcode'] = $getres['elinventoryno']; 				// $tirec['tiobjectcode']  = select e.elinventoryno from elevator e where e.elremoteid = {$tirec['i_device_id']} limit 1;
				$tirec['tiequipment_id'] = $getres['elid']; 
				$tirec['tifacilitycode'] = $getres['facode'];
				$tirec['tifacility_id']	= $getres['faid'];
				$tirec['tidivision_id']	= $getres['eldivision_id'];
			}

			$tirec['tiaddress'] = $Rec['address'];

			$tirec['tioostype_id'] = GetCrossVal( $config['cross']['malfunction'], $Rec['malfunction_id'] );
			$tirec['tiobject_id']  = GetCrossVal( $config['cross']['malfunction_type'], $Rec['malfunction_type_id'] );

			$tirec['tidescription']  = mb_substr( trim($Rec['description']), 0, 999);

			$tirec['tipriority'] = GetCrossVal( $config['cross']['tipriority'],$Rec['priority_id'] );

			$tirec['ticoderemote'] = $Rec['id'];

			if ( 32 == $Rec['source_id'] ) {										// елс источник itera - техническое обслуживание
				$tirec['tistatus'] = 'TO_ASSIGN'; 
				if (empty($tirec['tidivision_id']))									// если не удалось привязать id подразделения, берем его по району
					$tirec['tidivision_id'] = FindDivisionID($tirec['tiregion']);
			}else{
				if (SourceIs9Prg($Rec['source_id'])) {								// елс источник itera - 9 программ
					$tirec['tistatus'] = GetCrossVal( $config['cross']['tistatus9PRG'],$Rec['status_id'] );
					$tirec['ticoderemote'] = $Rec['no'];							// Добавлено по просьбе Савченко Дмитрия 22.02.2019
					$tirec['ticaller'] = $Rec['ticket_source_name'];
				}else
					$tirec['tistatus'] = GetCrossVal( $config['cross']['tistatus'],$Rec['status_id'] );
			}

			$tirec['tistatustime'] = date("Y-m-d H:i:s");

			$getres = GetOriginator($Rec['created_by_user_id']);
			if (!empty($getres)) {
				$tirec['tioriginator_id'] = $getres['id']; 							// это для ticketlog
				$tirec['tioriginator'] = $getres['fio']; 
				$tirec['tioriginatordesk_id'] = $getres['division_id'];
			}

			$tirec['tiplannedtime'] = $Rec['turnon_plan_time'];
			$tirec['tiplannedtimenew'] = $Rec['turnon_plan_time'];

			$tirec['ticalltype'] = GetCrossVal( $config['cross']['source'],$Rec['source_id'] );

			$tirec['tiopenedtime'] = $Rec['created'];

			if (!empty($Rec['tioosbegin'])) $tirec['rturnon_time'] = $Rec['turnon_time'];
			if (!empty($Rec['tioosend'])) $tirec['rturnoff_time'] = $Rec['turnoff_time'];
			if (empty($Rec['is_turnoff_confirmed'])){
				if (empty($Rec['turnon_time']))
					$tirec['tiopstatus'] = 1;
			}else
				$tirec['tiopstatus'] = 0;			

			LogRecord($Rec,$tirec);
			$tirec['id'] = InsertTicket($tirec);
			if (!empty($tirec['id'])) {
				InsertTicketLog($tirec);
				$Res = $tirec['id'];
			}
			// ЦОЙ ЖИВ !!!
		}

	}
	return $Res;
}

//--------------------------------------------------------------------------------------
//	Добавить Ticket в нашу базу
//--------------------------------------------------------------------------------------
function InsertTicket($rec)
{
	global $InsertRecCount;
	global $config;
	$res = false;
	if (empty($rec)) goto instickexit;

	if( FALSE === mysql_query("call getNewTicketRegNumber(@tino,@tinostr);") ) goto instickdberr;
	if( FALSE === ( $dbresult = mysql_query("select @tinostr;" ) ) )goto instickdberr; 
	if( FALSE === ( $row = mysql_fetch_assoc( $dbresult ) ) ) goto instickdberr; 
	$rec['ticode'] = $row['@tinostr']; 
	mysql_free_result($dbresult);

	$into = "";
	$values = "";
	$first = true;
	foreach($rec as $key=>$val){
		if ($key=='tioriginator_id') continue;								// в этом инсерте это поле не используется
		if ($first) $first=false; else {$into.=','; $values.=",";};
		$into .= $key;
		$values .= ( empty($val) ? 'NULL' : (is_string($val)?"'".$val."'":$val) );
	}
	$sql = "INSERT INTO ticket ({$into}) VALUES ({$values});";

	if ($config['options']['debuglog']) logger( "_ TICKET: ".$sql);		
	else logger("Insert '{$rec['ticode']}'");

	if( FALSE === mysql_query($sql) ) goto instickdberr; 
	$res = mysql_insert_id();
	$InsertRecCount++;

instickexit:
	return $res;

instickdberr:
	logger('InsertTicket db error : '.mysql_error());
	return false;
}

//--------------------------------------------------------------------------------------
//	Обновляет статус Ticket-а в нашей базе
//--------------------------------------------------------------------------------------
function UpdateTicket($ticket, $status)
{
	global $config;
	global $UpdateRecCount;

	$res = false;
	$sql = "UPDATE ticket SET tistatus='{$status}' WHERE id={$ticket['id']};";

	if ($config['options']['debuglog']) logger( "_ TICKET: ".$sql);	
	else logger("update '{$ticket['ticode']}' status '{$status}'");

	if( FALSE === mysql_query($sql) ) goto updtickdberr; 
	$UpdateRecCoun++;
	$res = true;
updtickexit:
	return $res;

updtickdberr:
	logger('Update Ticket db error : '.mysql_error());
	return false;
}

//--------------------------------------------------------------------------------------
//	Вставляет сообщение об изменении статуса заявки в нашу базу (в ticketlog)
//--------------------------------------------------------------------------------------
function InsertTicketLog($rec)
{
	global $config;

	$sql="INSERT INTO ticketlog (tilplannedtime,tiltype,tilstatus,tilticket_id,tilsender_id,tilsenderdesk_id,tilreceiver_id,tilreceiverdesk_id) 
		VALUES (".(empty($rec['tiplannedtime'])?'NULL':"'".$rec['tiplannedtime']."'").",'WORKORDER','".$rec['tistatus']."',".$rec['id'].",".(empty($rec['tioriginator_id'])?'NULL':$rec['tioriginator_id']).",".(empty($rec['tioriginatordesk_id'])?'NULL':$rec['tioriginatordesk_id']).",NULL,NULL);";

	if ($config['options']['debuglog']) logger( "_ LOG: ".$sql);			

	if( FALSE === mysql_query($sql) ) 
		logger('InsertLog db error : '.mysql_error());
}


//--------------------------------------------------------------------------------------
// Обрабатывает массив возвращенных заявок, полученных на странице Итеры
//--------------------------------------------------------------------------------------
function ProcessIteraRejectedTickets($IteraRecs)
{
	$Res = 0;
	global $config;
	global $GlobalRecCount;

	if (!is_array($IteraRecs)) return $Res;
	if (empty($IteraRecs)) return $Res;

	$RecExists = FindExisting($IteraRecs);

	$cnt = count($IteraRecs);
	foreach($IteraRecs as $Rec){
		$GlobalRecCount++;
		logger("--- ".$Rec['id']." -------------------------------------------------------------------");
		$Exists = CheckExists($RecExists, $Rec['id'], $Rec['no']);
		if (!empty($Exists)){
			// Такая заявка у нас есть
			// Меняем статус заяки
			if ($Exists['tireturncount'] < $Rec['return_count']) {
				UpdateRejectedTicket( $Exists, $Rec['status_changed'], $Rec['return_count']);
				$Res = $Exists['id'];
				// Читаем эту заявку, чтобы сформировать TicletLog
				$sql = "SELECT * FROM ticket WHERE id={$Exists['id']};";		
				if( FALSE !== ( $rescursor = mysql_query($sql) ) ) {
					$tirec = mysql_fetch_assoc($rescursor); 
					mysql_free_result($rescursor);
					$description = GetIteraTicketCommentLast($Rec['id']);
					InsertRejectedTicketLog($tirec, $description);
				}else{
					if ($config['options']['debuglog'])  logger("Can't read exists ticket id={$Exists['id']}. ");
				}
			}else{
				if ($config['options']['debuglog'])  logger("Status not changed. ");
			}
		}
	}
	return $Res;

}


//--------------------------------------------------------------------------------------
//	Обновляет статус Ticket-а в нашей базе
//--------------------------------------------------------------------------------------
function UpdateRejectedTicket($ticket, $statustime, $rcount)
{
	global $config;
	global $RejectedRecCount;

	$res = false;
	$sql = "UPDATE ticket SET tistatus='ITERA_REASSIGN', tistatustime='{$statustime}', tireturncount={$rcount} WHERE id={$ticket['id']};";

	if ($config['options']['debuglog']) logger( "_ TICKET: ".$sql);	
	else logger("update ticket '{$ticket['ticode']}' rcount={$rcount} statustime='{$statustime}' ");

	if( FALSE === mysql_query($sql) ) goto updrjtickdberr; 
	$res = true;
	$RejectedRecCount++;
updrjtickexit:
	return $res;

updrjtickdberr:
	logger('Update Ticket db error : '.mysql_error());
	return false;
}

//--------------------------------------------------------------------------------------
//	Вставляет сообщение об изменении статуса заявки в нашу базу (в ticketlog)
//--------------------------------------------------------------------------------------
function InsertRejectedTicketLog($rec, $description)
{
	global $config;

	$sql="INSERT INTO ticketlog (tilplannedtime,tiltype,tilstatus,tilticket_id,tilsender_id,tilsenderdesk_id,tilreceiver_id,tilreceiverdesk_id,tiltext) 
		VALUES (".(empty($rec['tiplannedtime'])?'NULL':"'".$rec['tiplannedtime']."'").",'WORKORDER','ITERA_REASSIGN',".$rec['id'].",".(empty($rec['tioriginator_id'])?'NULL':$rec['tioriginator_id']).",".(empty($rec['tioriginatordesk_id'])?'NULL':$rec['tioriginatordesk_id']).",NULL,NULL,'".$description."');";

	if ($config['options']['debuglog']) logger( "_ LOG: ".$sql);			

	if( FALSE === mysql_query($sql) ) 
		logger('InsertLog db error : '.mysql_error());
}

//--------------------------------------------------------------------------------------
//	Основной цыкла экспорта
//--------------------------------------------------------------------------------------
function MAIN_LOOP()
{
	global $config;
	global $InsertRecCount;
	global $UpdateRecCoun;
	global $RejectedRecCount;

	// Делаем "умолчания" настроек
	if  (empty($config['options']['daydepth'])) $config['options']['daydepth']=3;

	LoadMalfunctionCrossTable();																					// Подгружаем кросс таблицу причин подачи заявки

	/*
	if ($config['options']['debugmode']) { 			// ! ! !   ДЛЯ ОТЛАДКИ FindDivisionID
		logger('debug test'); 
		$di = FindDivisionID( GetCrossVal( $config['cross']['district'], 7) , 1);
		logger('di='.serialize($di) );
		return; 
	}
	*/

	// Импортируем новые заявки из Итеры
	logger('=== Load new tickets ============================================');
	$PageCount = 0;
	do{
//if ($config['options']['debugmode']) {logger('Exit by debug'); break;}  // ! ! !   ДЛЯ ОТЛАДКИ
		$IteraTickets = GetIteraTicketsPage($PageCount);
		$PageCount++;
		ProcessIteraTickets($IteraTickets);
	}while( count($IteraTickets)>0 );

	// Импортируем возвернутые заявки из Итеры
	logger('=== Load rejected tickets ============================================');
	$PageCount = 0;
	do{
//if ($config['options']['debugmode']) {logger('Exit by debug'); break;}  // ! ! !   ДЛЯ ОТЛАДКИ
		$IteraTickets = GetIteraTicketsPage($PageCount, true);
		$PageCount++;
		ProcessIteraRejectedTickets($IteraTickets);
	}while( count($IteraTickets)>0 );


	logger("".$InsertRecCount." entries inserted.");
	logger("".$UpdateRecCoun." entries updated.");
	logger("".$RejectedRecCount." entries rejected.");

}

//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();

