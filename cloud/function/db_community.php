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
case 'create_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$name = $_POST['name'];
		$invite_only_admin = (int) $_POST['invite_only_admin'];
		$old_message = (int) $_POST['old_message'];
		$community_type = (int) $_POST['type'];
		$user_two_id = (int) $_POST['user_two_id'];
		$secure = (int) $_POST['secure'];
		$post_admin = (int) $_POST['post_admin'];
		$community_public = (int) $_POST['public'];
		$date = date("Y-m-d H:i:s");
		
		// Если сообщество не простой диалог - генерируем id
		if ($community_type != 2) {
			$avatar_id = gen_id();
		}
		
		// Устанавливаем сообщение в зависимости от типа диалога
		if ($community_type == 1) {
			$message = "Создал(а) новое сообщество";
		} else {
			$message = "Создал(а) новый диалог";
			
			// Если групповой диалог имеет флаг секретности
			if ($secure == 1) {
				$message = "Cоздал(а) новый секретный диалог";
				$message = base64_encode($cypher->encrypt($message));
			}
			
		}
		
		// Проверка на имя диалога
		if (empty($name) and $community_type != 2) {
			throw new Exception('Ошибка передачи данных');
		} elseif (mb_strlen($name, 'utf-8') < 3 and $community_type != 2) {
			throw new Exception('Название не может быть менее 3 символов');
		}
		
		// Проверка на id пользователя
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на простой диалог и выбранного пользователя
		if ($community_type == 2 and empty($user_two_id)) {
			throw new Exception('Ошибка передачи данных');
		} elseif ($community_type == 2 and !empty($user_two_id)) {
			
			// Если id совпадает с текущим пользователем
			if ($user_id == $user_two_id) {
				throw new Exception('Ошибка передачи данных');
			}
			
			// Проверяем на ограничение создания диалога собеседнику
			// Подготавливаем запрос в базу данных
			$sth = $dbh->prepare("SELECT write_to_me,
											   (CASE WHEN write_to_me == 1 THEN (
													   SELECT id
														 FROM bookmarks_users AS BU
														WHERE BU.user_one_id = u.id AND 
															  BU.user_two_id = ?
															  )
											   ELSE 1 END) AS bookmarks
										  FROM users as u
										 WHERE u.id = ?;");
			$sth->execute(array($user_id, $user_two_id));
			
			// Проверяем ограничение
			$result = $sth->fetchAll(PDO::FETCH_ASSOC);
			if (empty($result[0]['bookmarks'])) {
				throw new Exception('Пользователь ограничил отправку сообщений');
			}	
			
			// Подготавливаем запрос в базу данных
			$sth = $dbh->prepare("SELECT *
										  FROM (
												   SELECT count(cu.id) AS _cnt,
														  community_id
													 FROM community_users AS cu
														  JOIN
														  community AS c ON c.id = cu.community_id
													WHERE cu.user_id IN (?, ?) AND 
														  c.community_type = 2
													GROUP BY cu.community_id
											   )
											   AS dialog_user
										 WHERE dialog_user._cnt = 2;");
			$sth->execute(array($user_id, $user_two_id));

			// Проверяем запись в БД
			$сid = $sth->fetchAll(PDO::FETCH_ASSOC);
			if (count($сid) == 1) {
				
				// Проверяем диалог на удаление
				// Подготавливаем запрос в базу данных
				$sth = $dbh->prepare("SELECT hide_message,
											   (
												   SELECT MAX(id) 
													 FROM messages AS M
													WHERE community_id = CU.community_id
											   ) AS max_id
										  FROM community_users AS CU
										 WHERE community_id = ? AND 
											   user_id = ?;");
				$sth->execute(array($сid[0]['community_id'], $user_id));

				// Проверяем запись в БД
				$result = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				// Сравниваем id скрытого сообщения с максимальным в диалоге
				if ($result[0]['hide_message'] == $result[0]['max_id']) {
					
					// Отправляем системное сообщение в диалог
					// Если диалог имеет флаг секретности
					$message = "Продолжает диалог";
					if ($secure == 1) {
						$message = base64_encode($cypher->encrypt($message));
					}
					$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
					$stmt->execute(array($сid[0]['community_id'], $user_id, $message, 1, $date));
					
					// Выводим сообщение
					$message = ['status' => 'success'];
					echo json_encode($message);
					break;
					
				} else {
					throw new Exception('Диалог между Вами существует');
				}
				
			}
			
		}
		
		// Создаем новый групповой диалог
		$stmt = $dbh->prepare('INSERT INTO community (user_id, name, invite_only_admin, old_message, community_type, secure, post_admin, public, avatar, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$stmt->execute(array($user_id, $name, $invite_only_admin, $old_message, $community_type, $secure, $post_admin, $community_public, $avatar_id, $date));		
		$community_id = $dbh->lastInsertId();
		
		// Вносим создателя в список пользователей этого диалога
		$stmt = $dbh->prepare('INSERT INTO community_users (community_id, privilege, user_id, accept, date) VALUES (?, ?, ?, ?, ?)');
		$stmt->execute(array($community_id, 1, $user_id, 1, $date));
		
		// Если тип - простой диалог, добавляем второго пользователя в беседу
		if (!empty($user_two_id) and $community_type == 2) {
			// Вносим собеседника в список пользователей этого диалога
			$stmt = $dbh->prepare('INSERT INTO community_users (community_id, privilege, user_id, accept, date) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute(array($community_id, 0, $user_two_id, 0, $date));
		}
		
		// Отправляем системное сообщение в диалог
		$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
		$stmt->execute(array($community_id, $user_id, $message, 1, $date));		
        $message_last_id = $dbh->lastInsertId();
        
        // Вставляем запись в таблицу последних сообщений
        $stmt = $dbh->prepare('INSERT INTO last_view_posts (user_id, community_id, last_id) VALUES (?, ?, ?)');
        $stmt->execute(array($user_one, $community_id, $message_last_id));
		
		// Если тип сообщества - простой диалог, то не создаем ему аватарку
		if ($community_type != 2) {
			
			// Создаем картинку отсутствия аватара
			$no_avatar = "../avatars/no_avatar.jpg";
			$avatar = "../avatars/channels/$avatar_id.jpg";
			$avatar_thumb = "../avatars/channels/$avatar_id.thumb.jpg";

			if (!copy($no_avatar, $avatar)) {
				throw new Exception('Возможно не выполнились некоторые действия');
			}
			
			// Создаем thumbnail фотографии для вывода в клиенте
			ImageThumbnail($avatar, $avatar_thumb, 50, "image/jpeg");
			
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
// Функция подтверждения либо отказа начала диалога
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'accept_dialog_user':


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
        $sth = $dbh->prepare("SELECT CU.user_id, C.community_type, C.secure FROM community_users AS CU JOIN community AS C ON C.id = CU.community_id WHERE C.id = ? AND CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] != 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Обновляем id сообщения в БД
        $stmt = $dbh->prepare('UPDATE community_users SET accept = ? WHERE user_id = ? AND community_id = ?;');
		$stmt->execute(array(1, $user_id, $community_id));
		
		// Сообщение в диалог
		$message = "подтвердил(а) диалог";
		$date = date("Y-m-d H:i:s");
		
		// Если диалог имеет флаг секретности - шифруем сообщение
		if ($safety[0]['secure'] == 1) {
			$message = base64_encode($cypher->encrypt($message));
		}
		
		// Вставляем запись в таблицу сообщений
        $stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(array($community_id, $user_id, $message, 1, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция подтверждения либо отказа начала диалога
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'decline_dialog_user':


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
        $sth = $dbh->prepare("SELECT user_id FROM community_users WHERE community_id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Удаляем созданный групповой диалог
		$stmt = $dbh->prepare('DELETE FROM community WHERE id=?;');
		$stmt->execute(array($community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загрузка аватара канала
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'upload_community_avatar':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$avatar = $_FILES;
		$community_id = (int) $_POST['community_id'];
		$avatar_id = gen_id();
		$src = "../avatars/channels/$avatar_id.tmp.jpg";
		$src_new = "../avatars/channels/$avatar_id.jpg";
		$src_thumb = "../avatars/channels/$avatar_id.thumb.jpg";
		$mime_type = $avatar['avatar']['type'];
		$name = urldecode($avatar['avatar']['name']);
		$file_ext = pathinfo($name, PATHINFO_EXTENSION);

		// Проверка на id
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на файлы
		if (empty($avatar)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		if ($safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************

		// Копируем изображение
		if($avatar['avatar']['error'] == 0) { 
		
			// Получаем MIME TYPE файла
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mtype = finfo_file($finfo, $avatar["avatar"]["tmp_name"]);
			finfo_close($finfo);
			
			// Перемещяем файл в дирректорию
			if (!rename($avatar["avatar"]["tmp_name"], $src)) {
				throw new Exception('Ошибка обработки данных');
			} else {
				
				// Создаем thumbnail фотографии для вывода в клиенте
				// А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
				$type_img = array('image/jpeg','image/png');
				if (in_array($mtype, $type_img)) {
					
					// Перезаписываем оригинал аватарки
					ImageThumbnail($src, $src_new, 200, $mtype);
					// Создаем миниатюру
					ImageThumbnail($src_new, $src_thumb, 50, 'image/jpeg');
					
					// Удаляем старый файл аватара
					$sth = $dbh->prepare('SELECT avatar FROM community WHERE id = ?');
					$sth->execute(array($community_id));
					
					// Удаляем старые файлы
					$result = $sth->fetchAll(PDO::FETCH_ASSOC);
					$old_avatar = '../avatars/channels/'.$result[0]['avatar'].'.jpg';
					$old_avatar_thumb = '../avatars/channels/'.$result[0]['avatar'].'.thumb.jpg';
					unlink($src);
					unlink($old_avatar);
					unlink($old_avatar_thumb);
					
					// Обновляем данные в БД
					$stmt = $dbh->prepare('UPDATE community SET avatar=:avatar_id WHERE id=:id;');
					$stmt->execute(array(':avatar_id'=>$avatar_id, ':id'=>$community_id));
					
				}
				
			}
		} else { 
			throw new Exception('Ошибка передачи данных');
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success',
					'avatar' => $avatar_id
				   ];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
   
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Записываем id последнего сообщения диалога
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'last_view_message':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
        $message_last_id = (int) $_POST['message_last_id'];
		
		// Проверка на id сообщения
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на текст сообщения
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id сообщения
		if (empty($message_last_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community_users WHERE community_id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
        
        // Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT id FROM last_view_posts WHERE user_id = ? AND community_id = ?");
		$sth->execute(array($user_id, $community_id));

		// Проверяем запись в БД
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 0) {
            
            // Добавляем id сообщения в БД
            $stmt = $dbh->prepare('INSERT INTO last_view_posts (user_id, community_id, last_id) VALUES (?, ?, ?)');
            $stmt->execute(array($user_id, $community_id, $message_last_id));
            
        } else {
            
            // Обновляем id сообщения в БД
            $stmt = $dbh->prepare('UPDATE last_view_posts SET last_id=:message_last_id WHERE user_id=:user_id AND community_id=:community_id;');
			$stmt->execute(array(':message_last_id'=>$message_last_id, ':user_id'=>$user_id, ':community_id'=>$community_id));
            
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
// Приглашаем пользователя в групповой диалог
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'invite_user':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$select_user_id = (int) $_POST['select_user_id'];
		$community_id = (int) $_POST['community_id'];
		$date = date("Y-m-d H:i:s");

		// Проверка на id
		if (empty($select_user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
		$sth = $dbh->prepare("SELECT CU.user_id, C.community_type, C.user_id as admin, C.secure FROM community_users AS CU JOIN community AS C ON C.id = CU.community_id WHERE C.id = ? AND CU.user_id = ?;");
		$sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		} elseif ($safety[0]['invite_only_admin'] == 1 AND $safety[0]['admin'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на возможность существования пользователя в этом диалоге
		$sth = $dbh->prepare('SELECT user_id FROM community_users AS CU WHERE CU.community_id = ? AND CU.user_id = ?;');
		$sth->execute(array($community_id, $select_user_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) > 0) {
			throw new Exception('Пользователь уже приглашён');
		}
		
		// Проверяем тип диалога
		if ($safety[0]['community_type'] == 1) {
			$message = "приглашен(а) в сообщество";
		} else {
			
			// Проверяем на администратора сообщества
			if ($safety[0]['admin'] == $select_user_id) {
				$message = "[администратор] приглашен(а) в диалог";
			} else {
				$message = "приглашен(а) в диалог";
			}
			
		}
		
		// Если диалог имеет флаг секретности - шифруем сообщение
		if ($safety[0]['secure'] == 1) {
			$message = base64_encode($cypher->encrypt($message));
		}
		
		// Подготавливаем запрос в базу данных
		$stmt = $dbh->prepare('INSERT INTO community_users (user_id, community_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($select_user_id, $community_id, $date));
		
		// Подготавливаем запрос в базу данных
		$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
		$stmt->execute(array($community_id, $select_user_id, $message, 1, $date));
        $message_last_id = $dbh->lastInsertId();
        
        // Вставляем запись в таблицу последних сообщений
        $stmt = $dbh->prepare('INSERT INTO last_view_posts (user_id, community_id, last_id) VALUES (?, ?, ?)');
        $stmt->execute(array($select_user_id, $community_id, $message_last_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
		
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция удаления создателем выбранного диалога
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$password = $_POST['password'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на пароль
		if (empty($password)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Запрашиваем данные о пользователе
		$sth = $dbh->prepare("SELECT * FROM users WHERE id = ?;");
		$sth->execute(array($user_id));
		
		// Сверяем пароль пользователя
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				// Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				// Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}
				
			}
		}
		
		// Удаляем групповой диалог
		$stmt = $dbh->prepare('DELETE FROM community WHERE id = ? and community_type == 1;');
		$stmt->execute(array($community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
			
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция отписки от выбранного диалога
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'unfollow_community':


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
        $sth = $dbh->prepare("SELECT CU.user_id, C.community_type, C.user_id as admin, C.secure FROM community_users AS CU JOIN community AS C ON C.id = CU.community_id WHERE C.id = ? AND CU.user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Удаляем пользователя из диалогв
		$stmt = $dbh->prepare('DELETE FROM community_users WHERE community_id = ? AND user_id = ?;');
		$stmt->execute(array($community_id, $user_id));
		
		// Удаляем запись просмотров из таблицы
		$stmt = $dbh->prepare('DELETE FROM last_view_posts WHERE community_id = ? AND user_id = ?;');
		$stmt->execute(array($community_id, $user_id));
			
		// Проверка на тип диалога
		if ($safety[0]['community_type'] == 0) {

			// Сообщение
			if ($safety[0]['admin'] == $user_id) {
				$message = "[администратор] покинул групповой чат";
			} else {
				$message = "Покинул(а) диалог";
			}
			
			// Если диалог имеет флаг секретности - шифруем сообщение
			if ($safety[0]['secure'] == 1) {
				$message = base64_encode($cypher->encrypt($message));
			}
			
			// Подготавливаем запрос в базу данных
			$date = date("Y-m-d H:i:s");
			$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute(array($community_id, $user_id, $message, 1, $date));
		
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
// Функция переименования группового чата или сообщества
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'rename_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$name = $_POST['name'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на имя
		if (empty($name)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET name=:name WHERE id=:id;');
		$stmt->execute(array(':name'=>$name, ':id'=>$community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}				
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция параметра Приглашает только создатель
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'param_invite_community':


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
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET invite_only_admin = (CASE WHEN invite_only_admin == 0 THEN 1 ELSE 0 END), public = (CASE WHEN invite_only_admin == 0 THEN 0 ELSE public END) WHERE id = ?;');
		$stmt->execute(array($community_id));

		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция параметра отображение старых сообщений
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'param_old_message_community':


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
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET old_message = (CASE WHEN old_message == 1 THEN 0 ELSE 1 END) WHERE id=?;');
		$stmt->execute(array($community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
		
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция параметра Постит только создатель
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'param_post_admin_community':


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
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET post_admin = (CASE WHEN post_admin == 1 THEN 0 ELSE 1 END) WHERE id=:id;');
		$stmt->execute(array(':id'=>$community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
			
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция параметра Все могут подписываться и читать
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'param_public_community':


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
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET invite_only_admin = (CASE WHEN public == 0 THEN 0 ELSE 1 END), public = (CASE WHEN public == 0 THEN 1 ELSE 0 END) WHERE id = :id;');
		$stmt->execute(array(':id'=>$community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}

	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция загрузки списка публичных каналов
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$search = $_POST['search'];
		
		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на поиск канала
		if (!empty($search)) {
			$search = 'AND (C.name LIKE "%'.$search.'%")';
		} else {
			$search = "";
		}

		// Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT C.name,
									   C.id AS community_id,
									   C.avatar,
									   CU.date,
									   COUNT(M.id) AS message_count,
									   (
										   SELECT COUNT(user_id) 
											 FROM community_users
											WHERE community_id = C.id
									   )
									   AS user_count,
									   MAX(M.id) AS message_last_id
								  FROM community AS C
									   JOIN
									   community_users AS CU ON CU.community_id = C.id
									   JOIN
									   messages AS M ON M.community_id = C.id
								 WHERE C.community_type = 1 AND 
									   C.invite_only_admin = 0 AND 
									   C.public = 1
								 GROUP BY CU.community_id
								 ORDER BY message_last_id DESC;");
		$sth->execute();

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
// Приглашаем пользователя в групповой диалог
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'follow_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$date = date("Y-m-d H:i:s");

		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// ---------------------------------------------------------------------------------
		
		// Проверяем на возможность существования пользователя в этом диалоге
		$sth = $dbh->prepare('SELECT user_id FROM community_users AS CU WHERE CU.community_id = ? AND CU.user_id = ?;');
		$sth->execute(array($community_id, $user_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) > 0) {
			throw new Exception('Вы уже подписаны на сообщество');
		}
		
		// ---------------------------------------------------------------------------------
		
		// Подготавливаем запрос в базу данных
		$stmt = $dbh->prepare('INSERT INTO community_users (user_id, community_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($user_id, $community_id, $date));
		
		// Получаем id последнего сообщения
		$sth = $dbh->prepare('SELECT MAX(id) AS last_id FROM messages WHERE community_id = ?;');
		$sth->execute(array($community_id));

		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		$message_last_id = $result[0]['last_id'];
		
		// ---------------------------------------------------------------------------------
		
		// Получаем параметры диалога
		$sth = $dbh->prepare('SELECT community_type, secure FROM community WHERE id = ?;');
		$sth->execute(array($community_id));

		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// ---------------------------------------------------------------------------------
		
		// Проверка на тип диалога
		if ($result[0]['community_type'] == 0) {
			
			// Сообщение
			$message = "Присоединился(ась) к диалогу";
			
			// Если диалог имеет флаг секретности - шифруем сообщение
			if ($result[0]['secure'] == 1) {
				$message = base64_encode($cypher->encrypt($message));
			}
			
			// Подготавливаем запрос в базу данных
			$stmt = $dbh->prepare('INSERT INTO messages (community_id, user_id, message, system, date) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute(array($community_id, $user_id, $message, 1, $community_join));
			$message_last_id = $dbh->lastInsertId();
		
		}
        
        // Вставляем запись в таблицу последних сообщений
        $stmt = $dbh->prepare('INSERT INTO last_view_posts (user_id, community_id, last_id) VALUES (?, ?, ?)');
        $stmt->execute(array($user_id, $community_id, $message_last_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
				
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция жалобы на групповой диалог или канал
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'complaint_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$date = date("Y-m-d H:i:s");
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community_users WHERE community_id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверяем на возможность существования такой записи в БД
		$sth = $dbh->prepare('SELECT id FROM сomplaints WHERE user_id = ? AND community_id = ?;');
		$sth->execute(array($user_id, $community_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) >= 1) {
			throw new Exception('Ваша жалоба уже была отправлена');
		}
			
		// Вставляем запись в таблицу жалоб
        $stmt = $dbh->prepare('INSERT INTO сomplaints (user_id, community_id, date) VALUES (?, ?, ?)');
        $stmt->execute(array($user_id, $community_id, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция передачи прав на сообщество
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'param_move_community':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$new_user_id = (int) $_POST['new_user_id'];
		$password = $_POST['password'];
		
		// Проверка на пароль
		if (empty($password)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id сообщества
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id пользователя
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id нового пользователя
		if (empty($new_user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] != 1) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
	
		// Запрашиваем данные о пользователе
		$sth = $dbh->prepare("SELECT * FROM users WHERE id = ?;");
		$sth->execute(array($user_id));
		
		// Сверяем пароль пользователя
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				// Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				// Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}
				
			}
		}
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community SET user_id=:user_id WHERE id=:id;');
		$stmt->execute(array(':user_id'=>$new_user_id, ':id'=>$community_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загрузка подписчиков сообщества
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_follower':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$search = $_POST['search'];
		$privilege = (int) $_POST['privilege'];
		
		// Проверка на id
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на привилегию
		if ($privilege) {
			$privilege = " AND CU.privilege = $privilege";
		} else {
			$privilege = "";
		}
		
		// Проверка на поиск пользователей
		if (!empty($search)) {
			$search = ' AND (first_name LIKE "%'.$search.'%" OR last_name LIKE "%'.$search.'%")';
		} else {
			$search = "";
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community_users WHERE community_id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
			
        // Подготавливаем запрос в базу данных
        $sth = $dbh->prepare("SELECT U.id,
									   U.avatar,
									   (
										   SELECT MAX(last_action) AS last_action
											 FROM (
													  SELECT last_login AS last_action
														FROM users WHERE id = U.id
													  UNION
													  SELECT MAX(date) AS last_action
														FROM messages WHERE user_id = U.id
													  UNION
													  SELECT MAX(date) AS last_action
														FROM messages_likes WHERE user_id = U.id
													  UNION
													  SELECT MAX(date) AS last_action
														FROM comments WHERE user_id = U.id
												  )
									   )
									   AS last_action,
									   CU.privilege,
									   (
										   SELECT id
											 FROM bookmarks_users AS BU
											WHERE BU.user_one_id = ? AND 
												  BU.user_two_id = U.id
									   )
									   AS bookmark_id,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
											 FROM users
											WHERE id = CU.user_id
									   )
									   AS display_name
								  FROM community_users AS CU
									   JOIN
									   users AS U ON CU.user_id = U.id
								 WHERE CU.community_id = ? $privilege $search
								 ORDER BY U.last_login DESC;");
        $sth->execute(array($user_id, $community_id));

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
// Функция установки привилегий подписчику
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'apply_follower_privilege':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
        $follower = (int) $_POST['follower'];
		$community_id = (int) $_POST['community_id'];
		
		// Проверка на id диалога
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на id подписчика
		if (empty($follower)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id, community_type FROM community WHERE id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id or $safety[0]['community_type'] == 2) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Устанавливаем параметр
		$stmt = $dbh->prepare('UPDATE community_users SET privilege = (CASE WHEN privilege == 0 THEN 1 ELSE 0 END) WHERE community_id = ? AND user_id = ?;');
		$stmt->execute(array($community_id, $follower));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}	
	
    
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция загрузки файлов и мультимедиа
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_multimedia':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$community_id = (int) $_POST['community_id'];
		$attach_type = (int) $_POST['attach_type'];
		$old_message = (int) $_POST['old_message'];
		$dialog_join = $_POST['dialog_join'];
		
		// Проверка на id
		if (empty($community_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		//********************************************************************************************
		// Код проверки безопасности
        $sth = $dbh->prepare("SELECT user_id FROM community_users WHERE community_id = ? AND user_id = ?;");
        $sth->execute(array($community_id, $user_id));
		$safety = $sth->fetchAll(PDO::FETCH_ASSOC);
		if ($safety[0]['user_id'] != $user_id) {
			throw new Exception('Отказано в доступе');
		}
		// Конец кода проверки безопасности
		//********************************************************************************************
		
		// Проверка на просмотр старых сообщений
		if ($old_message == 0) {
			$sql_dialog_join = '';
		} else {
			$this_date = date("Y-m-d H:i:s");
			$sql_dialog_join = " AND M.date BETWEEN '$dialog_join' AND '$this_date'";
		}
		
		// Проверка на attach_type
		if ($attach_type == 0) {
			$sql_attach_type = ' and MA.mime = "image/jpeg"';
		} else {
			$sql_attach_type = ' and MA.mime IS NULL';
		}

		// Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT MA.id,
									   MA.message_id,
									   MA.name,
									   MA.mime,
									   MA.thumb_w,
									   MA.thumb_h,
									   MA.size,
									   MA.salt,
									   M.date
								  FROM message_attach AS MA
									   JOIN
									   messages AS M ON MA.message_id = M.id
								 WHERE M.community_id = ? ".$sql_attach_type.$sql_dialog_join."
								 ORDER BY M.date DESC;");
		$sth->execute(array($community_id));

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
	

default:
break;


}


	// Закрываем соединение с базой
	$dbh = null;
	die();
	

?>