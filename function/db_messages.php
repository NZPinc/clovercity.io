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
// Функция загрузки старых групповых сообщений диалогов
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'loading_old_messages':	
	
	
	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$message_id = (int) $_POST['message_id'];
		$only_privilege = (int) $_POST['only_privilege'];
		$only_unpublished = (int) $_POST['only_unpublished'];
		$string = $_POST['string'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id пользователя
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на строку поиска сообщений
		if (!empty($string)) {
			$sql_search = " AND M.message LIKE '%$string%'";
		} else {
			$sql_search = "";
		}

		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.old_message,
									   CU.privilege,
									   CU.hide_message,
									   C.community_type,
									   CU.date AS community_join
								  FROM community_users AS CU
									   JOIN
									   community AS C ON C.id = CU.community_id
								 WHERE C.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
			
		// Проверяем на блокировку старых групповых сообщений
		if ($safety[0]['old_message'] == 1) {
			$community_join = $safety[0]['community_join'];
			$this_date = date("Y-m-d H:i:s");
			$old_message = " AND M.date BETWEEN '$community_join' AND '$this_date'";
		} else {
			$old_message = "";
		}
			
		// Главные проверки если это сообщество
		if ($safety[0]['community_type'] == 1) {
			
			// Проверяем на привилегию пользователя
			if ($safety[0]['privilege'] == 1) {
				
				// Проверяем только на привилегированные записи
				if ($only_privilege == 1) {
					$privilege_message = "AND M.privilege = 1";
				} else {
					$privilege_message = "";
				}
				
			} else {
				$privilege_message = "AND M.privilege = 0";
			}
			
			// Проверяем на неопубликованные записи
			if ($only_unpublished == 1) {
				$only_unpublished = "AND M.public_date > datetime('now')";
			} else {
				$only_unpublished = "";
			}
			
		} else {
			
			// Проверяем диалог или чат на удаление
			if (!empty($safety[0]['hide_message'])) {
				$hide_message = " AND M.id > ".$safety[0]['hide_message'];
			} else {
				$hide_message = "";
			}
			
			// Устанавливаем параметры
			$privilege_message = "AND M.privilege = 0";
			$only_unpublished = "";
		}
	
		// Получаем 20 старых сообщений
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
									   AS comments_count,
									   (
										   SELECT secure
											 FROM community
											WHERE id = M.community_id
									   )
									   AS secure
								  FROM messages AS M
									   INNER JOIN
									   users AS U ON M.user_id = U.id
								 WHERE M.community_id = ? $privilege_message $only_unpublished $old_message AND
									   M.id < ? $hide_message $sql_search
								 ORDER BY M.id DESC,
										  M.date DESC
								 LIMIT 20;");
		$sth->execute(array($user_id, $community_id, $message_id));
		$get_messages = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($get_messages) == 0) {
			$messages = false;
		} else {
			
			// Проходимся по всем найденным сообщениям
			$messages = [];
			foreach ($get_messages as $m) {
				
				// Если диалог имеет флаг секретности
				// Расшифровываем сообщение
				if ($m['secure'] == 1) {
					$m['message'] = $cypher->decrypt(base64_decode($m['message']));
				}
				
				// Устанавливаем значения
				$messages['m_'.$m['id']] = $m;
				
				// Проверяем каждое сообщение на прикрепленный файл
				$smh = $dbh->prepare('SELECT * FROM message_attach AS MA WHERE MA.message_id = ?');
				$smh->execute(array($m['id']));
				$attaches = $smh->fetchAll(PDO::FETCH_ASSOC);
				if (count($attaches) > 0) {
					foreach ($attaches as $a) {
						$messages['m_'.$m['id']]['attaches']['at_'.$a['id']] = $a;
					}
				}
				
			}
			
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'sql' => $messages
				   ];
		echo json_encode($message);
		
	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция загрузки только привилегированных записей
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_only_privilege':	
	
	
	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}

		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.old_message,
									   CU.privilege,
									   C.community_type,
									   CU.date AS community_join
								  FROM community_users AS CU
									   JOIN
									   community AS C ON C.id = CU.community_id
								 WHERE C.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Главные проверки если это сообщество
		if ($safety[0]['community_type'] == 1) {
			
			// Проверяем на блокировку старых групповых сообщений
			if ($safety[0]['old_message'] == 1) {
				$community_join = $safety[0]['community_join'];
				$this_date = date("Y-m-d H:i:s");
				$old_message = " AND M.date BETWEEN '$community_join' AND '$this_date'";
			} else {
				$old_message = "";
			}
			
			// Проверяем на привилегию пользователя
			if ($safety[0]['privilege'] != 1) {
				throw new Exception('Нет доступа к записям');
			}
			
		} else {
			throw new Exception('Отказано в доступе');
		}
	
		// Получаем 20 старых сообщений
		$sth = $dbh->prepare("SELECT M.*,
									   U.avatar,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
											 FROM users
											WHERE id = M.user_id
									   )
									   AS display_name,
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
									   AS comments_count,
									   (
										   SELECT secure
											 FROM community
											WHERE id = M.community_id
									   )
									   AS secure
								  FROM messages AS M
									   INNER JOIN
									   users AS U ON M.user_id = U.id
								 WHERE M.community_id = ? AND M.privilege = 1 $old_message
								 ORDER BY M.id DESC,
										  M.date DESC
								 LIMIT 20;");
		$sth->execute(array($user_id, $community_id));
		$get_messages = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($get_messages) == 0) {
			$messages = false;
		} else {
			
			// Проходимся по всем найденным сообщениям
			$messages = [];
			foreach ($get_messages as $m) {
				
				// Если диалог имеет флаг секретности
				// Расшифровываем сообщение
				if ($m['secure'] == 1) {
					$m['message'] = $cypher->decrypt(base64_decode($m['message']));
				}
				
				// Устанавливаем значения
				$messages['m_'.$m['id']] = $m;
				
				// Проверяем каждое сообщение на прикрепленный файл
				$smh = $dbh->prepare('SELECT * FROM message_attach AS MA WHERE MA.message_id = ?');
				$smh->execute(array($m['id']));
				$attaches = $smh->fetchAll(PDO::FETCH_ASSOC);
				if (count($attaches) > 0) {
					foreach ($attaches as $a) {
						$messages['m_'.$m['id']]['attaches']['at_'.$a['id']] = $a;
					}
				}
				
			}
			
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'sql' => $messages
				   ];
		echo json_encode($message);
		
	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция загрузки только неопубликованных записей
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_unpublished_messages':
	
	
	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}

		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.old_message,
									   C.community_type,
									   CU.date AS community_join
								  FROM community_users AS CU
									   JOIN
									   community AS C ON C.id = CU.community_id
								 WHERE C.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Главные проверки если это сообщество
		if ($safety[0]['community_type'] == 1) {
			
			// Проверяем на блокировку старых групповых сообщений
			if ($safety[0]['old_message'] == 1) {
				$community_join = $safety[0]['community_join'];
				$this_date = date("Y-m-d H:i:s");
				$old_message = " AND M.date BETWEEN '$community_join' AND '$this_date'";
			} else {
				$old_message = "";
			}
			
		} else {
			throw new Exception('Отказано в доступе');
		}
	
		// Получаем 20 старых сообщений
		$sth = $dbh->prepare("SELECT M.*,
									   U.avatar,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
											 FROM users
											WHERE id = M.user_id
									   )
									   AS display_name,
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
									   AS comments_count,
									   (
										   SELECT secure
											 FROM community
											WHERE id = M.community_id
									   )
									   AS secure
								  FROM messages AS M
									   INNER JOIN
									   users AS U ON M.user_id = U.id
								 WHERE M.community_id = ? AND M.public_date > datetime('now') $old_message
								 ORDER BY M.id DESC,
										  M.date DESC
								 LIMIT 20;");
		$sth->execute(array($user_id, $community_id));
		$get_messages = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($get_messages) == 0) {
			$messages = false;
		} else {
			
			// Проходимся по всем найденным сообщениям
			$messages = [];
			foreach ($get_messages as $m) {
				
				// Если диалог имеет флаг секретности
				// Расшифровываем сообщение
				if ($m['secure'] == 1) {
					$m['message'] = $cypher->decrypt(base64_decode($m['message']));
				}
				
				// Устанавливаем значения
				$messages['m_'.$m['id']] = $m;
				
				// Проверяем каждое сообщение на прикрепленный файл
				$smh = $dbh->prepare('SELECT * FROM message_attach AS MA WHERE MA.message_id = ?');
				$smh->execute(array($m['id']));
				$attaches = $smh->fetchAll(PDO::FETCH_ASSOC);
				if (count($attaches) > 0) {
					foreach ($attaches as $a) {
						$messages['m_'.$m['id']]['attaches']['at_'.$a['id']] = $a;
					}
				}
				
			}
			
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'sql' => $messages
				   ];
		echo json_encode($message);
		
	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}	
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Отправляем сообщение
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'send_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$date = date("Y-m-d H:i:s");
		$message = trim($_POST['message']);
		$stick = $_POST['stick'];
		$public_date = $_POST['public_date'];
		$community_id = (int) $_POST['community_id'];
		$reply_to = (int) $_POST['reply_to'];
		$user_id = (int) $_POST['user_id'];
		$attach = $_FILES;
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id пользователя
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на сообщение
		if ($message == "" and empty($attach) and empty($stick)) {
			throw new Exception('Введите сообщение');
		}
		
		// Проверка на количество символов в тексте
		if (mb_strlen($message, 'utf-8') > 16834) {
			throw new Exception('Слишком длинный текст');
		}
		
		// Проверяем на ответ пользователю
		if (empty($reply_to)) {
			$reply_to = NULL;
		}
		
		// Проверка на stick
		if (empty($stick)) {
			$stick = NULL;
		}
		
		// Проверка на дату публикации
		if (empty($public_date)) {
			$public_date = NULL;
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.community_type,
									   C.post_admin,
									   C.user_id AS admin,
									   C.secure
								  FROM community_users AS CU
									   JOIN
									   community AS C ON C.id = CU.community_id
								 WHERE C.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['admin'] != $user_id and $safety[0]['post_admin'] == 1) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Если тип диалога - простой диалог 2
		if ($safety[0]['community_type'] == 2) {
		
			// Проверка на подтверждение диалога собеседником
			$sth = $dbh->prepare('SELECT accept,
										   (CASE WHEN (
														  SELECT write_to_me
															FROM users
														   WHERE id = CU.user_id
													  )
									==         1 THEN (
												   SELECT id
													 FROM bookmarks_users AS BU
													WHERE BU.user_one_id = CU.user_id AND 
														  BU.user_two_id = ?
											   )
										  ELSE 1 END) AS bookmarks
									  FROM community_users AS CU
									 WHERE CU.community_id = ? AND 
										   CU.user_id <> ?;');
			$sth->execute(array($user_id, $community_id, $user_id));
			$accept_user = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			// Если диалог не подтвержден - отправляем сообщение
			if ($accept_user[0]['accept'] == 0) {
				throw new Exception('Ваш собеседник не подтвердил диалог');
			}
			
			// Если диалог не подтвержден - отправляем сообщение
			if (empty($accept_user[0]['bookmarks'])) {
				throw new Exception('Пользователь ограничил отправку сообщений');
			}
		
		}
		
		// Если диалог имеет флаг секретности - шифруем сообщение и файлы пользователя
		if ($safety[0]['secure'] == 1) {
			$message = base64_encode($cypher->encrypt($message));
		}

		// Подготавливаем запрос в базу данных
		$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, stick, reply_to, public_date, date) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$stmt->execute(array($community_id, $user_id, $message, $stick, $reply_to, $public_date, $date));
		$message_id = $dbh->lastInsertId();
		
		// Проверяем сообщение на прикрепленный файл
		if (!empty($attach)) {
			if($attach['attach']['error'] == 0) {
				
				// Получаем MIME TYPE файла
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mtype = finfo_file($finfo, $attach["attach"]["tmp_name"]);
				finfo_close($finfo);
				
				// Проверяем на архив
				if ($mtype !== "application/zip") {
					throw new Exception('При обработке данных произошла ошибка');
				}
				
				// Создаём объект для работы с ZIP-архивами
				$zip = new ZipArchive;
				$tmp_zip_path = '../attach/'.gen_id();
				if ($zip->open($attach["attach"]["tmp_name"]) === TRUE) {
					$zip->extractTo($tmp_zip_path);
					$zip->close();
				} else {
					throw new Exception('При обработке данных произошла ошибка');
				}
				
				// Проходим по распакованным файлам
				$skip = array('.', '..');
				$tmp_files = scandir($tmp_zip_path);
				foreach($tmp_files as $tmp_file) {
					
					if(!in_array($tmp_file, $skip)) { 

						$salt = gen_id();
						$path = '../attach/at_'.$message_id.'_'.$salt.'.smh';
						$name = mb_strtolower(urldecode(basename($tmp_zip_path.'/'.$tmp_file)));
						$size = filesize($tmp_zip_path.'/'.$tmp_file);
						
						// Получаем MIME TYPE файла
						$finfo = finfo_open(FILEINFO_MIME_TYPE);
						$mtype = finfo_file($finfo, $tmp_zip_path.'/'.$tmp_file);
						finfo_close($finfo);
				
						// Перемещяем файл в дирректорию
						if (!rename($tmp_zip_path.'/'.$tmp_file, $path)) {
							throw new Exception('Возможно не выполнились некоторые действия');
						} else {
							
							// Создаем thumbnail фотографии для вывода в клиенте
							// А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
							$type_img = array('image/jpeg','image/png');
							if (in_array($mtype, $type_img)) {
							
								// Создаем thumb файл
								$mime = "image/jpeg";
								$path_thumb = '../attach/at_'.$message_id.'_'.$salt.'.thumb.jpg';
								$image_size = getimagesize($path);
								
								// Пересоздаем оригинал изображения
								$th_w = $image_size[0];
								ImageThumbnail($path, $path, $th_w, $mtype);
								
								// Создаем миниатюру фотографии
								if ($image_size[0] < 180) {
									$th_w = $image_size[0];
								} else {
									$th_w = 180;
								}
								ImageThumbnail($path, $path_thumb, $th_w, $mime);
								
								// Вычисляем размеры thumbnail
								$thumb_size = getimagesize($path_thumb);
								
							} else {
								
								// Сбрасываем переменные
								$mime = NULL;
								$thumb_size = NULL;
								
							}
					
							// Подготавливаем запрос в базу данных
							$stmt = $dbh->prepare('INSERT INTO message_attach (message_id, name, mime, thumb_w, thumb_h, size, salt) VALUES (?, ?, ?, ?, ?, ?, ?)');
							$stmt->execute(array($message_id, $name, $mime, $thumb_size[0], $thumb_size[1], $size, $salt));
						
						}
						
					}
					
				}
				
				// Удаляем временную директорию zip архива
				rmdir($tmp_zip_path);
				
			}
		}
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Редактируем выбранное сообщение сообщества
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'get_edit_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		$author_id = (int) $_POST['author_id'];
		
		// Проверка на id сообщения
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id автора
		if (empty($author_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type,
									   M.user_id AS author
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['community_type'] == 1) {
			if ($safety[0]['author'] != $user_id and $safety[0]['admin_id'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['community_type'] != 1) {
			if ($safety[0]['author'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['author'] != $author_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Получаем сообщение с сервера
		$sth = $dbh->prepare("SELECT M.message,
									   M.privilege,
									   M.public_date,
									   C.secure
								  FROM messages AS M
								  JOIN community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   M.user_id = ?;");
		$sth->execute(array($id, $author_id));
					
		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Получаем файлы сообщения в БД
		$sth = $dbh->prepare("SELECT * FROM message_attach WHERE message_id = ?;");
		$sth->execute(array($id));
		
		// Обработка полученных данных
		$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Если сообщение зашифровано - расшифровываем
		if ($result[0]['secure'] == 1) {
			$result[0]['message'] = $cypher->decrypt(base64_decode($result[0]['message']));
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success', 
					'message' => $result[0]['message'], 
                    'privilege' => $result[0]['privilege'], 
					'public_date' => $result[0]['public_date'],
					'attach' => $result_attach
				   ];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загружаем файлы для сообщения в режиме редактирования
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'upload_attach_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		$attach = $_FILES;
		
		// Проверка на сообщение
		if (empty($attach)) {
			throw new Exception('Файлы не загружены');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type,
									   M.user_id AS author
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['community_type'] == 1) {
			if ($safety[0]['author'] != $user_id and $safety[0]['admin_id'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['community_type'] != 1) {
			if ($safety[0]['author'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['author'] != $author_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем сообщение на прикрепленные файлы
		if (!empty($attach)) {
			if($attach['attach']['error'] == 0) {
				
				// Создаём объект для работы с ZIP-архивами
				$zip = new ZipArchive;
				$tmp_zip_path = '../attach/'.gen_id();
				if ($zip->open($attach["attach"]["tmp_name"]) === TRUE) {
					$zip->extractTo($tmp_zip_path);
					$zip->close();
				} else {
					throw new Exception('При обработке данных произошла ошибка');
				}
				
				// Проходим по распакованным файлам
				$skip = array('.', '..');
				$tmp_files = scandir($tmp_zip_path);
				foreach($tmp_files as $tmp_file) {
					
					if(!in_array($tmp_file, $skip)) { 

						$salt = gen_id();
						$path = '../attach/at_'.$message_id.'_'.$salt.'.smh';
						$name = mb_strtolower(urldecode(basename($tmp_zip_path.'/'.$tmp_file)));
						$size = filesize($tmp_zip_path.'/'.$tmp_file);
						
						// Получаем MIME TYPE файла
						$finfo = finfo_open(FILEINFO_MIME_TYPE);
						$mtype = finfo_file($finfo, $tmp_zip_path.'/'.$tmp_file);
						finfo_close($finfo);
				
						// Перемещяем файл в дирректорию
						if (!rename($tmp_zip_path.'/'.$tmp_file, $path)) {
							throw new Exception('Возможно не выполнились некоторые действия');
						} else {
							
							// Создаем thumbnail фотографии для вывода в клиенте
							// А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
							$type_img = array('image/jpeg','image/png');
							if (in_array($mtype, $type_img)) {
							
								// Создаем thumb файл
								$mime = "image/jpeg";
								$path_thumb = '../attach/at_'.$message_id.'_'.$salt.'.thumb.jpg';
								$image_size = getimagesize($path);
								
								// Пересоздаем оригинал изображения
								$th_w = $image_size[0];
								ImageThumbnail($path, $path, $th_w, $mtype);
								
								// Создаем миниатюру фотографии
								if ($image_size[0] < 180) {
									$th_w = $image_size[0];
								} else {
									$th_w = 180;
								}
								ImageThumbnail($path, $path_thumb, $th_w, $mime);
								
								// Вычисляем размеры thumbnail
								$thumb_size = getimagesize($path_thumb);
								
							} else {
								
								// Сбрасываем переменные
								$mime = NULL;
								$thumb_size = NULL;
								
							}
							
							// Подготавливаем запрос в базу данных
							$stmt = $dbh->prepare('INSERT INTO message_attach (message_id, name, mime, thumb_w, thumb_h, size, salt) VALUES (?, ?, ?, ?, ?, ?, ?)');
							$stmt->execute(array($message_id, $name, $mime, $thumb_size[0], $thumb_size[1], $size, $salt));
							
							// Получаем файлы сообщения в БД
							$sth = $dbh->prepare("SELECT * FROM message_attach WHERE message_id = ?;");
							$sth->execute(array($message_id));
										
							// Обработка полученных данных
							$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
							
						}
						
					}
					
				}
				
				// Удаляем временную директорию zip архива
				rmdir($tmp_zip_path);
				
			}
		}
		
		// Выводим сообщение
		$message = ['status' => 'success', 'result_attach' => $result_attach];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Редактируем выбранное сообщение сообщества
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'edit_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		$message = trim($_POST['message']);
		$privilege = (int) $_POST['privilege'];
		$public_date = $_POST['public_date'];
        $author_id = (int) $_POST['author_id'];
		
		// Проверка на id сообщения
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на текст сообщения
		if (empty($message)) {
			$message = NULL;
		}
		
		// Проверка на количество символов в тексте
		if (mb_strlen($message, 'utf-8') > 16834) {
			throw new Exception('Слишком длинный текст');
		}
		
		// Проверка на id сообщения
		if (empty($author_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на дату публикации
		if (empty($public_date)) {
			$public_date = NULL;
		} else {
			
			// Проверяем дату публикации полученную от клиента
			$date = date("Y-m-d H:i:s");
			if (strtotime($date) > strtotime($public_date)) {
				throw new Exception("Выбрана неверная дата публикации");
			}
			
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type,
									   M.user_id AS author
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['community_type'] == 1) {
			if ($safety[0]['author'] != $user_id and $safety[0]['admin_id'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['community_type'] != 1) {
			if ($safety[0]['author'] != $user_id) {
				throw new Exception('Отказано в доступе');
			}			
		} elseif ($safety[0]['author'] != $author_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Получаем флаг секретности диалога
		$sth = $dbh->prepare("SELECT C.secure
								  FROM community AS C
									   JOIN
									   messages AS M ON M.community_id = C.id
								 WHERE M.id = ?;");
		$sth->execute(array($id));
		
		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Если диалог имеет флаг секретности - шифруем сообщение
		if ($result[0]['secure'] == 1) {
			$message = base64_encode($cypher->encrypt($message));
		}
		
		// Проверяем на тип диалога для установки привилегии
		if ($safety[0]['community_type'] != 1) { 
			$privilege = 0;
		}
		
		// Обновляем текст сообщения в БД
		$stmt = $dbh->prepare('UPDATE messages SET message=:message, privilege=:privilege, public_date=:public_date WHERE id=:id AND user_id=:user_id');
		$stmt->execute(array(':message'=>$message, ':privilege'=>$privilege, ':public_date'=>$public_date, ':id'=>$id, ':user_id'=>$author_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Скачиваем прикрепленный файл к сообщению
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'download_attaches':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		$message_id = (int) $_POST['message_id'];
		$salt = $_POST['salt'];
		
		// Проверка на id аттача
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id сообщения
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на соль
		if (empty($salt)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id FROM community_users AS CU JOIN messages AS M ON M.community_id = CU.community_id WHERE M.id = ? AND CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Получаем текст сообщения в БД
		$sth = $dbh->prepare("SELECT * FROM message_attach WHERE id = ? AND message_id = ? AND salt = ?;");
		$sth->execute(array($id, $message_id, $salt));
					
		// Обработка полученных данных
		$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Проверяем ответ от сервера
		if (count($result_attach) == 1) {
			
			// Выводим сообщение
			$message = ['status' => 'success', 
						'name' => $result_attach[0]['name']
						];
			echo json_encode($message);
			
		} else {
			throw new Exception('Файл не найден');
		}

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}

	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Удаляем выбранное сообщения диалога или группового чата
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		
		// Проверка на id сообщения
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   M.user_id AS author
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?");
        $sth->execute(array($id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['author'] != $user_id and $safety[0]['admin_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Получаем файлы сообщения в БД
		$sth = $dbh->prepare("SELECT * FROM message_attach WHERE message_id = ?;");
		$sth->execute(array($id));
					
		// Обработка полученных данных
		$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Удаляем файлы с сервера
		foreach ($result_attach as $at) {
			unlink('../attach/at_'.$at['message_id'].'_'.$at['salt'].'.smh');
			if (!empty($at['mime'])) {
				unlink('../attach/at_'.$at['message_id'].'_'.$at['salt'].'.thumb.jpg');
			}
		}
		
		// Удаляем сообщение из группового чата
		$stmt = $dbh->prepare('DELETE FROM messages WHERE id=?;');
		$stmt->execute(array($id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Скрываем диалог от пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'hide_dialog':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id, C.community_type FROM community_users AS CU JOIN community AS C ON C.id = CU.community_id WHERE C.id = ? AND CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['community_type'] != 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Обновляем данные на сервере
		$stmt = $dbh->prepare('UPDATE community_users SET hide_message = (SELECT MAX(id) FROM messages AS M WHERE M.community_id = community_id) WHERE community_id = ? AND user_id = ?;');
		$stmt->execute(array($community_id, $user_id));

		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}


//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Удаляем выбранные прикрепленные файлы к сообщению
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_attach':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		$message_id = (int) $_POST['message_id'];
		$salt = $_POST['salt'];
		$mime = $_POST['mime'];
		
		// Проверка на id прикрепления
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id сообщения
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на salt прикрепления
		if (empty($salt)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id, M.user_id AS author FROM community_users AS CU JOIN messages AS M ON M.community_id = CU.community_id WHERE M.id = ? AND CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['author'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Удаляем файлы с сервера
		unlink('../attach/at_'.$message_id.'_'.$salt.'.smh');
		if (!empty($mime)) {
			unlink('../attach/at_'.$message_id.'_'.$salt.'.thumb.jpg');
		}
		
		// Удаляем прикрепление из сообщения
		$stmt = $dbh->prepare('DELETE FROM message_attach WHERE id=? AND message_id = ? AND salt = ?;');
		$stmt->execute(array($id, $message_id, $salt));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция жалобы на групповое сообщение
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'complaint_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		$date = date("Y-m-d H:i:s");
		
		// Проверка на id диалога
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id FROM community_users AS CU JOIN messages AS M ON M.community_id = CU.community_id WHERE M.id = ? AND CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на возможность существования такой записи в БД
		$sth = $dbh->prepare('SELECT id FROM сomplaints AS C WHERE C.user_id = ? AND C.message_id = ?;');
		$sth->execute(array($user_id, $message_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) >= 1) {
			throw new Exception('Ваша жалоба уже была отправлена');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('INSERT INTO сomplaints (user_id, message_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($user_id, $message_id, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
					
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция выделения сообщения
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'accent_post':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		
		// Проверка на id диалога
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['admin_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на тип диалога для установки выделения
		if ($safety[0]['community_type'] != 1) { 
			throw new Exception('Отказано в доступе');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE messages SET accent = (CASE WHEN accent == 0 THEN 1 ELSE 0 END) WHERE id=?;');
		$stmt->execute(array($message_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
					
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция выделения сообщения
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'privilege_post':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		
		// Проверка на id диалога
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['admin_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на тип диалога для установки выделения
		if ($safety[0]['community_type'] != 1) { 
			throw new Exception('Отказано в доступе');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE messages SET privilege = (CASE WHEN privilege == 0 THEN 1 ELSE 0 END) WHERE id=?;');
		$stmt->execute(array($message_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
    
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция выделения сообщения
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'pin_post':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		
		// Проверка на id диалога
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
			
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.user_id AS admin_id,
									   C.community_type
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['admin_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на тип диалога для установки выделения
		if ($safety[0]['community_type'] != 1) { 
			throw new Exception('Отказано в доступе');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE messages SET pin = (CASE WHEN pin == 0 THEN 1 ELSE 0 END) WHERE id=?;');
		$stmt->execute(array($message_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция снятия или установки лайка
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'likes_procces':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		$date = date("Y-m-d H:i:s");
		
		// Проверка на id сообщения
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id,
									   C.community_type
								  FROM community_users AS CU
									   JOIN
									   messages AS M ON M.community_id = CU.community_id
									   JOIN
									   community AS C ON C.id = M.community_id
								 WHERE M.id = ? AND 
									   CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на тип диалога для установки выделения
		if ($safety[0]['community_type'] != 1) { 
			throw new Exception('Отказано в доступе');
		}
		
		// Проверка на существование лайка к этой записи
		$sth = $dbh->prepare("SELECT id FROM messages_likes AS ML WHERE ML.message_id = ? AND user_id = ?;");
		$sth->execute(array($message_id, $user_id));		
		$likes = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Если лайк не установлен то вписываем его
		if (count($likes) == 0) {
			$stmt = $dbh->prepare('INSERT INTO messages_likes (message_id, user_id, date) VALUES (?, ?, ?)');
			$stmt->execute(array($message_id, $user_id, $date));			
		} else {
			$stmt = $dbh->prepare('DELETE FROM messages_likes WHERE message_id=? AND user_id = ?;');
			$stmt->execute(array($message_id, $user_id));
		}
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загрузка комментариев
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_comments':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		
		// Проверка на id
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id FROM community_users AS CU JOIN messages AS M ON M.community_id = CU.community_id WHERE M.id = ? AND CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT C.*,
									   U.id AS user_id,
									   U.avatar,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
											 FROM users
											WHERE id = C.user_id
									   )
									   AS display_name,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_to_name
											 FROM users
												  JOIN
												  comments ON comments.id = C.reply_to
											WHERE users.id = comments.user_id
									   )
									   AS reply_to_name,
									   (
										   SELECT (CASE WHEN comments.comment IS NULL THEN NULL ELSE substr(comments.comment, 1, 30) END) AS reply_to_text
											 FROM comments
											WHERE comments.id = C.reply_to
									   )
									   AS reply_to_text
								  FROM comments AS C
									   JOIN
									   users AS U ON U.id = C.user_id
								 WHERE C.message_id = ?;");
		$sth->execute(array($message_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		$message = [
			'status' => 'success',
			'sql' => $result
		];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция отправки комментария к постам сообществ
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'add_comment':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$message_id = (int) $_POST['message_id'];
		$comment = $_POST['comment'];
		$reply_to = (int) $_POST['reply_to'];
		$date = date("Y-m-d H:i:s");
		
		// Проверка на id сообщения
		if (empty($message_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id диалога
		if (empty($comment)) {
			throw new Exception('Комментарий не может быть пустым');
		}
		
		// Проверка на ответ 
		if (empty($reply_to)) {
			$reply_to = NULL;
		}
		
		// Проверка на количество символов в тексте
		if (mb_strlen($comment, 'utf-8') > 1000) {
			throw new Exception('Слишком длинный текст');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT CU.user_id FROM community_users AS CU JOIN messages AS M ON M.community_id = CU.community_id WHERE M.id = ? AND CU.user_id = ?;");
        $sth->execute(array($message_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Заносим комментарий в БД
		$stmt = $dbh->prepare('INSERT INTO comments (message_id, user_id, comment, reply_to, date) VALUES (?, ?, ?, ?, ?)');
		$stmt->execute(array($message_id, $user_id, $comment, $reply_to, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Удаляем выбранный комментарий из сообщества
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_comment':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$id = (int) $_POST['id'];
		
		// Проверка на id сообщения
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM comments WHERE id = ? AND user_id = ?;");
        $sth->execute(array($id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Удаляем сообщение из группового чата
		$stmt = $dbh->prepare('DELETE FROM comments WHERE id=?;');
		$stmt->execute(array($id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция жалобы на комментарий группы
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'complaint_comment':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$comment_id = (int) $_POST['comment_id'];
		$date = date("Y-m-d H:i:s");
		
		// Проверка на id комментария
		if (empty($comment_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверяем на возможность существования такой записи в БД
		$sth = $dbh->prepare('SELECT id FROM сomplaints WHERE user_id = ? AND comment_id = ?;');
		$sth->execute(array($user_id, $comment_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) >= 1) {
			throw new Exception('Ваша жалоба уже была отправлена');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('INSERT INTO сomplaints (user_id, comment_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($user_id, $comment_id, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Получаем список паков стикеров
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_stickers_pack':


	// Подготавливаем запрос
	if ($handle = opendir('../stickers')) {
		$pack_list = [];
		while (false !== ($entry = readdir($handle))) {
			if (is_dir("../stickers/$entry") && $entry != "." && $entry != "..") {
				$pack_list[] = $entry;
			}
		}
		closedir($handle);
	}
	
	// Выводим сообщение
	$message = [
				'status' => 'success',
				'pack_list' => $pack_list
			   ];
	echo json_encode($message);
		
		
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Получаем список стикеров
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_stickers_image':


	// Проверяем данные на соответствие
	try {
	
		// Устанавливаем значения
		$pack_path = $_POST['pack_path'];

		// Проверка на сообщение
		if (empty($pack_path)) {
			throw new Exception('Ошибка передачи данных.');
		}

		// Подготавливаем запрос
		if ($handle = opendir("../stickers/$pack_path/64")) {
			$stick_list = [];
			while (false !== ($entry = readdir($handle))) {
				if (is_file("../stickers/$pack_path/64/$entry") && $entry != "." && $entry != "..") {
					$stick_list[] = $entry;
				}
			}
			closedir($handle);
		}
	
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'stick_list' => $stick_list
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