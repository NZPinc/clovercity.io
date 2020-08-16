<?php
setlocale(LC_ALL, "ru_RU.UTF-8");


	// Устанавливаем параметры
	$action = $_POST['action'];
	$user_id = (int) $_POST['user_id'];
	$session_id = $_POST['session_id'];
	
	// Если action пустой, то прерываем скрипт
	if (!empty($action) and !empty($user_id) and !empty($session_id)) {
		
		// Подключаем системные файл
		include_once 'db_func.php';
		
		// Получаем session_id пользователя
		$sth = $dbh->prepare('SELECT session_id, block FROM users WHERE id = ?');
		$sth->execute(array($user_id));

		// Получаем id сессии с базы данных
		$sessions = $sth->fetchAll(PDO::FETCH_ASSOC);
		$_session_id = $sessions[0]['session_id'];
		$_block = $sessions[0]['block'];
		
		// Если id сессий не совпадает, обрываем связь
		if ($_session_id !== $session_id or $_block == 1) {
			$message = ['status' => 'Отказано в доступе'];
			echo json_encode($message);
			
			// Закрываем соединение с базой
			$dbh = null;
			die();
		}
		
	} else {
		// Закрываем соединение с базой
		$dbh = null;
		die();
	}


//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция Long Poll, для циклического прохода по БД и получения обновленной информации и ответа в httpClient
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'global_update':


	// Устанавливаем параметры
	$programm_data = $_POST['programm_data'];
	$global_update = [];
	$live_time = time();
	
	// Проверяем данные на соответствие
	try {
		
		// Проверка на id пользователя
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Запускаем цикл проверки новой информации в диалогах и сообщениях
		while (true) {
			
			// проверяем время коннекта к БД, если оно 58 или более секунд, закрываем скрипт
			$this_time = time()-$live_time;
			$this_date = date("Y-m-d H:i:s");
			if ($this_time >= 58) {
				// Закрываем соединение с базой
				$dbh = null;
				die();
			}
			
			// Получаем список групповых диалогов пользователя
			$sth = $dbh->prepare("SELECT C.*,
										   MAX(M.id) AS message_last_id,
										   M.stick AS stick,
										   M.user_id AS user_message_last_id,
										   U.avatar AS user_avatar,
										   CU.privilege AS privilege_user,
										   CU.date AS community_join,
										   CU.hide_message,
										   COUNT(M.id) AS message_count,
										   (CASE WHEN C.community_type = 2 AND 
													  CU.user_id = CU.user_id THEN CU.accept END) AS user_accept_dialog,
										   (CASE WHEN C.community_type == 2 THEN (
												   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
													 FROM users
													WHERE id = (
																   SELECT CCUU.user_id AS id
																	 FROM community_users AS CCUU
																	WHERE CCUU.user_id <> CU.user_id AND 
																		  CCUU.community_id = C.id
															   )
											   )
										   ELSE C.name END) AS community_name,
										   (CASE WHEN C.community_type == 2 THEN (
												   SELECT avatar
													 FROM users
													WHERE id = (
																   SELECT CCUU.user_id AS id
																	 FROM community_users AS CCUU
																	WHERE CCUU.user_id <> CU.user_id AND 
																		  CCUU.community_id = C.id
															   )
											   )
										   ELSE C.avatar END) AS community_avatar,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
												 FROM users
												WHERE id = C.user_id
										   )
										   AS display_name,
										   (
											   SELECT COUNT(user_id) 
												 FROM community_users
												WHERE community_id = C.id
										   )
										   AS follow_user,
										   (
											   SELECT COUNT(ML.id) 
												 FROM messages_likes AS ML
													  JOIN
													  messages AS M ON M.id = ML.message_id
												WHERE M.community_id = C.id
										   )
										   AS count_likes,
										   (
											   SELECT COUNT(CM.id) 
												 FROM comments AS CM
													  JOIN
													  messages AS M ON M.id = CM.message_id
												WHERE M.community_id = C.id
										   )
										   AS count_comments,
										   (
											   SELECT COUNT(MA.id) 
												 FROM message_attach AS MA
													  JOIN
													  messages AS M ON M.id = MA.message_id
												WHERE M.community_id = C.id
										   )
										   AS count_files,
										   (
											   SELECT COUNT(id) 
												 FROM community_users
												WHERE community_id = C.id AND 
													  privilege = 1 AND 
													  user_id <> CU.user_id
										   )
										   AS count_privilege_users,
										   (
											   SELECT COUNT(id) 
												 FROM messages
												WHERE community_id = C.id AND 
													  privilege = 1
										   )
										   AS count_privilege_posts,
										   (
											   SELECT COUNT(id) 
												 FROM messages AS M
												WHERE community_id = 1 AND 
													  M.public_date > datetime('now') 
										   )
										   AS count_unpublished_posts,
										   (
											   SELECT COUNT(M.id) 
												 FROM messages AS M
													  JOIN
													  last_view_posts AS LVP ON M.community_id = LVP.community_id
												WHERE M.community_id = C.id AND 
													  M.id > LVP.last_id AND 
													  LVP.user_id = CU.user_id AND 
													  (CASE WHEN M.public_date IS NULL THEN M.date ELSE M.public_date END) <= datetime('now') 
										   )
										   AS unread_messages
									  FROM community AS C
										   JOIN
										   community_users AS CU ON CU.community_id = C.id
										   LEFT JOIN
										   users AS U ON U.id = C.user_id
										   JOIN
										   messages AS M ON M.community_id = C.id
									 WHERE CU.user_id = ? AND 
										   (CASE WHEN M.public_date IS NULL THEN M.date ELSE M.public_date END) <= datetime('now') 
									 GROUP BY CU.community_id
									 ORDER BY message_last_id DESC;");
			$sth->execute(array($user_id));

			// Записываем групповые диалоги в массив
			$community = $sth->fetchAll(PDO::FETCH_ASSOC);
			if (count($community) > 0) {
				foreach ($community as $c) {
					
					// Если сообщество заблокировано
					if ($c['block'] == 1) {
						$c['message_last'] = "Сообщество заблокировано";
					}
					
					// Если диалог имеет флаг секретности
					if ($c['secure'] == 1) {
						$c['message_last'] = "секретный диалог";
					}
					
					// Устанавливаем значения
					$global_update['community']['c_'.$c['id']] = $c;
					
				}
				
				// Получаем сообщения для кажого группового диалога
				foreach ($global_update['community'] as $c) {
					
					// Если диалог заблокирован
					if ($c['block'] == 0) {
                    
						// Проверяем на блокировку старых групповых сообщений
						if ($c['old_message'] == 1) {
							$this_date = date("Y-m-d H:i:s");
							$dialog_join = $c['community_join'];
							$old_message = " AND M.date BETWEEN '$dialog_join' AND '$this_date'";
						} else {
							$old_message = "";
						}
						
						// Проверяем на привилегии в группе
						if ($c['privilege_user'] == 1) {
							$privilege_message = "";
						} else {
							$privilege_message = " AND M.privilege = 0";
						}
						
						// Проверяем диалог на удаление
						if (!empty($c['hide_message'])) {
							$hide_message = " AND M.id > ".$c['hide_message'];
						} else {
							$hide_message = "";
						}
						
						// Получаем сообщения диалога
						$sth = $dbh->prepare("SELECT M.*,
													   U.avatar,
													   (
														   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
															 FROM users
															WHERE id = M.user_id
													   )
													   AS display_name,
													   (
														   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_to_name
															 FROM users
																  JOIN
																  messages ON messages.id = M.reply_to
															WHERE users.id = messages.user_id
													   )
													   AS reply_to_name,
													   (
														   SELECT (CASE WHEN messages.message IS NULL THEN NULL ELSE substr(messages.message, 1, 30) END) AS reply_to_text
															 FROM messages
															WHERE messages.id = M.reply_to
													   )
													   AS reply_to_text,
													   (
														   SELECT COUNT(id)
															 FROM messages_likes
															WHERE message_id = M.id
													   )
													   AS likes,
													   (
														   SELECT id
															 FROM messages_likes
															WHERE message_id = M.id AND 
																  user_id = ?
													   )
													   AS like_this,
													   (
														   SELECT COUNT(id)
															 FROM comments
															WHERE message_id = M.id
													   )
													   AS comments_count
												  FROM messages AS M
													   INNER JOIN
													   users AS U ON M.user_id = U.id
												 WHERE M.community_id = ? $old_message $privilege_message AND
													   M.id <= ? $hide_message AND (CASE WHEN M.public_date IS NULL THEN M.date ELSE M.public_date END) <= datetime('now')
												 ORDER BY M.pin DESC,
														  M.public_date DESC,
														  M.date DESC
												 LIMIT 20;");
						$sth->execute(array($user_id, $c['id'], $c['message_last_id']));
						
						// Записываем сообщения каждого группового диалога к нему в массив
						$messages = $sth->fetchAll(PDO::FETCH_ASSOC);
						if (count($messages) > 0) {
							foreach ($messages as $m) {
								
								// Если диалог имеет флаг секретности
								// Расшифровываем сообщение
								if ($global_update['community']['c_'.$c['id']]['secure'] == 1) {
									$m['message'] = $cypher->decrypt(base64_decode($m['message']));
								}
								
								// Устанавливаем значения
								if (!empty($messages[0]['public_date'])) {
									$m['operating_date'] = strtotime($m['public_date']);
								} else {
									$m['operating_date'] = strtotime($m['date']);
								}
								$global_update['community']['c_'.$c['id']]['messages']['m_'.$m['id']] = $m;
								
								// Проверяем каждое сообщение на прикрепленный файл
								$smh = $dbh->prepare('SELECT * FROM message_attach WHERE message_id = ?');
								$smh->execute(array($m['id']));
								
								$attaches = $smh->fetchAll(PDO::FETCH_ASSOC);
								if (count($attaches) > 0) {
									foreach ($attaches as $a) {
										$global_update['community']['c_'.$c['id']]['messages']['m_'.$m['id']]['attaches']['at_'.$a['id']] = $a;
									}
								}

							}
							
							// Устанавливаем текст последнего сообщения
							$global_update['community']['c_'.$c['id']]['message_last'] = mb_substr($messages[0]['message'], 0, 50, 'UTF-8');
							
							// Устанавливаем дату последнего сообщения
							if (!empty($messages[0]['public_date'])) { $view_date = $messages[0]['public_date']; } else { $view_date = $messages[0]['date']; }
							$global_update['community']['c_'.$c['id']]['message_last_date'] = $view_date;
							$global_update['community']['c_'.$c['id']]['message_last_operating_date'] = strtotime($view_date);
							
						} else {
							
							// Если сообщений в диалоге не обнаружено, удаляем диалог
							if ($c['community_type'] == 2 or $c['community_type'] == 0) {
								unset($global_update['community']['c_'.$c['id']]);
							}
							
						}
					
					}
					
				}
				
				// Если групповые диалоги не обнаружены - удаляем из массива
				if (count($global_update['community']) == 0) {
					unset($global_update['community']);
				}
				
			}
			
			// Сверяем данные полученные от клиента с текущими данными
			// Если они отличаются, отправляем данные клиенту
			if ($global_update != $programm_data) {
				break;
			}
			
			// Сбрасываем текущие данные и ждем 1 секунду
			$global_update = [];
			sleep(1);
		
		}
	
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'sql' => $global_update
				   ];
		echo json_encode($message);
		
	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

default:
break;


}


	// Закрываем соединение с базой
	$dbh = null;
	die();
	

?>