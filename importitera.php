<?php
const SCRIPTVERSION = "v.2.4";
const SCRIPTNAME="importitera";
require_once 'ExportIteraLib.php';

define('RECSPERPAGE',   20); 

$GlobalRecCount = 0;				// Всего записей от Итеры отработано
$InsertRecCount = 0;				// Записей добавлено в нашу базу

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
//--------------------------------------------------------------------------------------
function GetIteraTicketsPage($Page)
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
	$request = $config['bsmartapi']['url_getticket']."?sort(id)=desc&pageSize=".RECSPERPAGE."&page=".$Page."&filter(created)=After(".$DateFrom.")&filter(source_id)=in(2,3)"; 	
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
	$sql = "SELECT id, ticode, ticoderemote, tistatus FROM ticket WHERE ticoderemote in (".$idlist.") OR ticode in (".$nolist.")  OR ticoderemote in (".$nolist.") ;";
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
//	Возвращает true, если ID или No найден в нашей базе
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

	$sql = "SELECT l.id as elid, l.elinventoryno, f.id as faid, f.facode FROM elevator l 
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
			if  ( in_array($Rec['status_id'], [2,3,11,12])) {
				// Меняем статус заяки
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

			// Далее шаманство по трансформации данных из Itera в нашу заявку
			$tirec = [];
			$tirec['ticoderemote'] = $Rec['id'];

			$tirec['tiregion'] = GetCrossVal( $config['cross']['district'], $Rec['district_id'] );

			$getres = GetElevator($Rec['device_id']);
			if (!empty($getres)) {
				$tirec['tiobjectcode'] = $getres['elinventoryno']; 				// $tirec['tiobjectcode']  = select e.elinventoryno from elevator e where e.elremoteid = {$tirec['i_device_id']} limit 1;
				$tirec['tiequipment_id'] = $getres['elid']; 
				$tirec['tifacilitycode'] = $getres['facode'];
				$tirec['tifacility_id']	= $getres['faid'];
			}

			$tirec['tiaddress'] = $Rec['address'];

			$tirec['tioostype_id'] = GetCrossVal( $config['cross']['malfunction'], $Rec['malfunction_id'] );
			$tirec['tiobject_id']  = GetCrossVal( $config['cross']['malfunction_type'], $Rec['malfunction_type_id'] );

			$tirec['tidescription']  = $Rec['description'];

			$tirec['tipriority'] = GetCrossVal( $config['cross']['tipriority'],$Rec['priority_id'] );
			$tirec['tistatus'] = GetCrossVal( $config['cross']['tistatus'],$Rec['status_id'] );
			$tirec['tistatustime'] = date("Y-m-d H:i:s");

			$getres = GetOriginator($Rec['created_by_user_id']);
			if (!empty($getres)) {
				$tirec['tioriginator_id'] = $getres['id']; 				// это для ticketlog
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
	$res = false;
	$sql = "UPDATE ticket SET tistatus='{$status}' WHERE id={$ticket['id']};";

	if ($config['options']['debuglog']) logger( "_ TICKET: ".$sql);	
	else logger("update '{$ticket['ticode']}' status '{$status}'");

	if( FALSE === mysql_query($sql) ) goto updtickdberr; 
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
		VALUES ('".$rec['tiplannedtime']."','WORKORDER','".$rec['tistatus']."',".$rec['id'].",".(empty($rec['tioriginator_id'])?'NULL':$rec['tioriginator_id']).",".(empty($rec['tioriginatordesk_id'])?'NULL':$rec['tioriginatordesk_id']).",NULL,NULL);";

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

	// Делаем "умолчания" настроек
	if  (empty($config['options']['daydepth'])) $config['options']['daydepth']=3;

	LoadMalfunctionCrossTable();																					// Подгружаем кросс таблицу причин подачи заявки

	$PageCount = 0;
	do{
		$IteraTickets = GetIteraTicketsPage($PageCount);
		$PageCount++;
		ProcessIteraTickets($IteraTickets);
//logger('Exit by debug'); return;  // ! ! !   ДЛЯ ОТЛАДКИ
	}while( count($IteraTickets)>0 );

	logger($InsertRecCount." entries inserted.");

}

//************************************************************************
//	Старт службы
//  Оттуда вызывается MAIN_LOOP()
//************************************************************************
MAIN_START();
