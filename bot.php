<?php
require_once 'config.php';

class TelegramEventBot {
    private $botToken;
    private $groupId;
    private $processedEventsFile;
    private $logFile;
    private $incomingLogFile;
    private $errorLogFile;
    public string $uploadsDir;
    
    public function __construct() {
        $this->botToken = BOT_TOKEN;
        $this->groupId = GROUP_ID;
        $this->processedEventsFile = PROCESSED_EVENTS_FILE;
        $this->logFile = LOG_FILE;
        $this->incomingLogFile = INCOMING_LOG_FILE;
        $this->errorLogFile = ERROR_LOG_FILE;
        $this->uploadsDir = __DIR__ . '/uploads';
        
        // Инициализируем файл обработанных событий если его нет
        if (!file_exists($this->processedEventsFile)) {
            file_put_contents($this->processedEventsFile, '');
        }
        
        // Создаем директорию для загрузок если не существует
        if (!file_exists($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }
    
    /**
     * Отправка запроса к Telegram API с поддержкой multipart/form-data
     */
    private function sendTelegramRequest($method, $params = [], $isMultipart = false) {
    $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Для отладки SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Устанавливаем разный таймаут в зависимости от типа запроса
    if ($isMultipart || in_array($method, ['sendVideo', 'sendPhoto', 'sendDocument', 'sendAudio', 'sendVoice'])) {
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 120 секунд для отправки файлов
    } else {
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 секунд для обычных запросов
    }
    
    // Дополнительные опции для больших файлов
    if ($isMultipart) {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }
    
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        $this->writeLog("CURL Error in $method: $error", 'ERROR');
        return false;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (DEBUG_MODE && $method !== 'getUpdates') {
        $this->writeLog("Telegram API Response for $method: " . json_encode($result, JSON_UNESCAPED_UNICODE), 'DEBUG');
    }
    
    return $result;
}
    
    /**
     * Обработка загруженного файла от пользователя (без скачивания на сервер)
     */
    public function handleUploadedFile($update) {
        if (!isset($update['message'])) {
            return false;
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $userName = $message['from']['first_name'] ?? 'Unknown';

        $fileId = null;
        $fileType = null;
        $fileName = '';
        $caption = $message['caption'] ?? '';

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $fileId = $photo['file_id'] ?? null;
            $fileType = 'photo';
            $fileName = 'photo';
        } elseif (isset($message['document'])) {
            $document = $message['document'];
            $fileId = $document['file_id'] ?? null;
            $fileType = 'document';
            $fileName = $document['file_name'] ?? 'document';
        } elseif (isset($message['video'])) {
            $video = $message['video'];
            $fileId = $video['file_id'] ?? null;
            $fileType = 'video';
            $fileName = $video['file_name'] ?? 'video';
        } elseif (isset($message['audio'])) {
            $audio = $message['audio'];
            $fileId = $audio['file_id'] ?? null;
            $fileType = 'audio';
            $fileName = $audio['file_name'] ?? 'audio';
        } elseif (isset($message['voice'])) {
            $voice = $message['voice'];
            $fileId = $voice['file_id'] ?? null;
            $fileType = 'voice';
            $fileName = 'voice';
            $caption = '';
        } elseif (isset($message['sticker'])) {
            $sticker = $message['sticker'];
            $fileId = $sticker['file_id'] ?? null;
            $fileType = 'sticker';
            $fileName = 'sticker';
            $caption = '';
        } else {
            return false;
        }

        if (empty($fileId)) {
            $this->writeLog("Uploaded file without file_id from user $userName (ID: $userId)", 'ERROR');
            return false;
        }

        $this->writeLog("File reference received from $userName (ID: $userId) in chat $chatId: $fileType/$fileId", 'INFO');

        $response = "✅ Файл добавлен в черновик!\n";
        $response .= "📁 Тип: $fileType\n";
        $response .= "🆔 file_id: `$fileId`\n";
        if (!empty($caption)) {
            $response .= "📋 Подпись: $caption";
        }

        $this->sendMessage($chatId, $response, 'Markdown');

        return [
            'file_id' => $fileId,
            'file_name' => $fileName,
            'type' => $fileType,
            'caption' => $caption,
            'chat_id' => $chatId,
            'user_id' => $userId
        ];
    }

    /**
     * Отправка фото из локального файла (с поддержкой topic_id)
     */
    public function sendPhotoFromFile($chatId, $filePath, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'photo' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendPhoto', $params, true);
    }
    
    /**
     * Отправка видео из локального файла (с поддержкой topic_id)
     */
    public function sendVideoFromFile($chatId, $filePath, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'video' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendVideo', $params, true);
    }
    
    /**
     * Отправка документа из локального файла (с поддержкой topic_id)
     */
    public function sendDocumentFromFile($chatId, $filePath, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'document' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendDocument', $params, true);
    }
    
    /**
     * Отправка аудио из локального файла (с поддержкой topic_id)
     */
    public function sendAudioFromFile($chatId, $filePath, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'audio' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendAudio', $params, true);
    }
    
    /**
     * Отправка голосового сообщения из локального файла (с поддержкой topic_id)
     */
    public function sendVoiceFromFile($chatId, $filePath, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'voice' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendVoice', $params, true);
    }
    
    /**
     * Отправка стикера из локального файла (с поддержкой topic_id)
     */
    public function sendStickerFromFile($chatId, $filePath, $replyToMessageId = null, $topicId = null) {
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $params = [
            'chat_id' => $chatId,
            'sticker' => new CURLFile($filePath)
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendSticker', $params, true);
    }
    
    /**
     * Универсальный метод отправки локального файла
     */
    public function sendLocalFile($chatId, $filePath, $type, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $validTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];
        
        if (!in_array($type, $validTypes)) {
            $this->writeLog("Invalid file type: $type", 'ERROR');
            return false;
        }
        
        if (!file_exists($filePath)) {
            $this->writeLog("File not found: $filePath", 'ERROR');
            return false;
        }
        
        $method = 'send' . ucfirst($type) . 'FromFile';
        
        if ($type === 'sticker') {
            return $this->$method($chatId, $filePath, $replyToMessageId, $topicId);
        } else {
            return $this->$method($chatId, $filePath, $caption, $parseMode, $replyToMessageId, $topicId);
        }
    }
    
    /**
     * Определение типа файла по расширению
     */
    private function detectFileType($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $photoExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv'];
        $audioExtensions = ['mp3', 'm4a', 'ogg', 'wav', 'flac'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $voiceExtensions = ['ogg', 'mp3'];
        $stickerExtensions = ['webp'];
        
        if (in_array($extension, $stickerExtensions)) {
            return 'sticker';
        } elseif (in_array($extension, $voiceExtensions)) {
            return 'voice';
        } elseif (in_array($extension, $photoExtensions)) {
            return 'photo';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        } elseif (in_array($extension, $audioExtensions)) {
            return 'audio';
        } elseif (in_array($extension, $documentExtensions)) {
            return 'document';
        }
        
        return 'document';
    }
     
     
    /**
     * Форматирование размера файла
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Создание темы в группе
     */
    public function createForumTopic($topicName, $iconColor = 7322096) {
        // Ограничиваем длину названия темы (макс 128 символов)
        $topicName = mb_substr(trim($topicName), 0, 128);
        
        $params = [
            'chat_id' => $this->groupId,
            'name' => $topicName,
            'icon_color' => $iconColor
        ];
        
        $this->writeLog("Creating forum topic: '$topicName'", 'INFO');
        $result = $this->sendTelegramRequest('createForumTopic', $params);
        
        if (!$result || !isset($result['ok']) || !$result['ok']) {
            $error = isset($result['description']) ? $result['description'] : 'Unknown error';
            $this->writeLog("Failed to create forum topic: $error", 'ERROR');
            return false;
        }
        
        $this->writeLog("Forum topic created successfully, ID: " . $result['result']['message_thread_id'], 'INFO');
        return $result['result'];
    }
    
    /**
     * Отправка текстового сообщения в тему
     */
    public function sendMessageToTopic($message, $topicId, $parseMode = 'Markdown') {
        $params = [
            'chat_id' => $this->groupId,
            'message_thread_id' => $topicId,
            'text' => $message,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => false
        ];
        
        $result = $this->sendTelegramRequest('sendMessage', $params);
        
        if (!$result || !isset($result['ok']) || !$result['ok']) {
            $this->writeLog("Failed to send message to topic $topicId", 'WARNING');
            return false;
        }
        
        return true;
    }
    
    /**
     * Отправка текстового сообщения в чат (с поддержкой topic_id)
     */
    public function sendMessage($chatId, $message, $parseMode = 'HTML', $replyToMessageId = null, $topicId = null, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => false
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        
        return $this->sendTelegramRequest('sendMessage', $params);
    }
        
    /**
     * Отправка фото в чат (с поддержкой topic_id)
     */
    public function sendPhoto($chatId, $photo, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли фото URL или локальным файлом
        $isMultipart = false;
        if (filter_var($photo, FILTER_VALIDATE_URL) === false && file_exists($photo)) {
            // Локальный файл - используем multipart/form-data
            $params['photo'] = new CURLFile($photo);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendPhoto', $params, $isMultipart);
    }
    
    /**
     * Отправка видео в чат (с поддержкой topic_id)
     */
    public function sendVideo($chatId, $video, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'video' => $video,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли видео URL или локальным файлом
        $isMultipart = false;
        if (filter_var($video, FILTER_VALIDATE_URL) === false && file_exists($video)) {
            // Локальный файл - используем multipart/form-data
            $params['video'] = new CURLFile($video);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendVideo', $params, $isMultipart);
    }
    
    /**
     * Отправка документа в чат (с поддержкой topic_id)
     */
    public function sendDocument($chatId, $document, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли документ URL или локальным файлом
        $isMultipart = false;
        if (filter_var($document, FILTER_VALIDATE_URL) === false && file_exists($document)) {
            // Локальный файл - используем multipart/form-data
            $params['document'] = new CURLFile($document);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendDocument', $params, $isMultipart);
    }
    
    /**
     * Отправка аудио в чат (с поддержкой topic_id)
     */
    public function sendAudio($chatId, $audio, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'audio' => $audio,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли аудио URL или локальным файлом
        $isMultipart = false;
        if (filter_var($audio, FILTER_VALIDATE_URL) === false && file_exists($audio)) {
            // Локальный файл - используем multipart/form-data
            $params['audio'] = new CURLFile($audio);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendAudio', $params, $isMultipart);
    }
    
    /**
     * Отправка голосового сообщения в чат (с поддержкой topic_id)
     */
    public function sendVoice($chatId, $voice, $caption = '', $parseMode = 'Markdown', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'voice' => $voice,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли голосовое сообщение URL или локальным файлом
        $isMultipart = false;
        if (filter_var($voice, FILTER_VALIDATE_URL) === false && file_exists($voice)) {
            // Локальный файл - используем multipart/form-data
            $params['voice'] = new CURLFile($voice);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendVoice', $params, $isMultipart);
    }
    
    /**
     * Отправка медиа-группы (несколько фото/видео) (с поддержкой topic_id)
     */
    public function sendMediaGroup($chatId, $media, $replyToMessageId = null, $topicId = null) {
        foreach ($media as $item) {
            if (isset($item['media']) && file_exists($item['media'])) {
                $this->writeLog("sendMediaGroup: local files not supported yet", 'ERROR');
                return false;
            }
        }
    
        $params = [
            'chat_id' => $chatId,
            'media' => json_encode($media)
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendMediaGroup', $params);
    }
    
    /**
     * Отправка стикера в чат (с поддержкой topic_id)
     */
    public function sendSticker($chatId, $sticker, $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'sticker' => $sticker
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли стикер URL или локальным файлом
        $isMultipart = false;
        if (filter_var($sticker, FILTER_VALIDATE_URL) === false && file_exists($sticker)) {
            // Локальный файл - используем multipart/form-data
            $params['sticker'] = new CURLFile($sticker);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest('sendSticker', $params, $isMultipart);
    }
    
    /**
     * Отправка контакта в чат (с поддержкой topic_id)
     */
    public function sendContact($chatId, $phoneNumber, $firstName, $lastName = '', $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'phone_number' => $phoneNumber,
            'first_name' => $firstName
        ];
        
        if (!empty($lastName)) {
            $params['last_name'] = $lastName;
        }
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendContact', $params);
    }
    
    /**
     * Отправка локации в чат (с поддержкой topic_id)
     */
    public function sendLocation($chatId, $latitude, $longitude, $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendLocation', $params);
    }
    
    /**
     * Отправка опроса в чат (с поддержкой topic_id)
     */
    public function sendPoll($chatId, $question, $options, $isAnonymous = true, $type = 'regular', 
                           $allowsMultipleAnswers = false, $correctOptionId = null, 
                           $explanation = '', $explanationParseMode = 'Markdown', 
                           $openPeriod = null, $closeDate = null, $isClosed = false,
                           $replyToMessageId = null, $topicId = null) {
        $params = [
            'chat_id' => $chatId,
            'question' => $question,
            'options' => json_encode($options),
            'is_anonymous' => $isAnonymous,
            'type' => $type,
            'allows_multiple_answers' => $allowsMultipleAnswers
        ];
        
        if ($correctOptionId !== null) {
            $params['correct_option_id'] = $correctOptionId;
        }
        
        if (!empty($explanation)) {
            $params['explanation'] = $explanation;
            $params['explanation_parse_mode'] = $explanationParseMode;
        }
        
        if ($openPeriod !== null) {
            $params['open_period'] = $openPeriod;
        }
        
        if ($closeDate !== null) {
            $params['close_date'] = $closeDate;
        }
        
        if ($isClosed) {
            $params['is_closed'] = $isClosed;
        }
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        return $this->sendTelegramRequest('sendPoll', $params);
    }
    
    /**
     * Универсальный метод для отправки любого типа медиа (с поддержкой topic_id)
     */
    public function sendMedia($chatId, $type, $media, $options = [], $replyToMessageId = null, $topicId = null) {
        $validTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];
        
        if (!in_array($type, $validTypes)) {
            $this->writeLog("Invalid media type: $type", 'ERROR');
            return false;
        }
        
        $method = 'send' . ucfirst($type);
        
        $params = [
            'chat_id' => $chatId,
            $type => $media
        ];
        
        // Добавляем дополнительные параметры
        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($topicId) {
            $params['message_thread_id'] = $topicId;
        }
        
        // Проверяем, является ли медиа URL или локальным файлом
        $isMultipart = false;
        if (filter_var($media, FILTER_VALIDATE_URL) === false && file_exists($media)) {
            // Локальный файл - используем multipart/form-data
            $params[$type] = new CURLFile($media);
            $isMultipart = true;
        }
        
        return $this->sendTelegramRequest($method, $params, $isMultipart);
    }
    
    /**
     * Получение событий из WordPress
     */
    public function getWordPressEvents($lastCheckTime = null) {
        $url = WORDPRESS_URL . '/wp-json/wp/v2/' . EVENTS_POST_TYPE;
        
        // Добавляем параметры
        $params = [
            'per_page' => 20,
            'orderby' => 'date',
            'order' => 'desc',
            'status' => 'publish'
        ];
        
        if (EVENTS_CATEGORY_ID > 0) {
            $params['categories'] = EVENTS_CATEGORY_ID;
        }
        
        if ($lastCheckTime) {
            $params['after'] = date('c', $lastCheckTime);
        }
        
        $url .= '?' . http_build_query($params);
        
        $this->writeLog("Fetching events from: " . $url, 'DEBUG');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Если нужна авторизация
        if (defined('WORDPRESS_USERNAME') && defined('WORDPRESS_PASSWORD') && 
            WORDPRESS_USERNAME && WORDPRESS_PASSWORD) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, WORDPRESS_USERNAME . ':' . WORDPRESS_PASSWORD);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->writeLog("CURL Error fetching WordPress events: $error", 'ERROR');
            return [];
        }
        
        curl_close($ch);
        
        if ($httpCode == 200) {
            $events = json_decode($response, true);
            $this->writeLog("Successfully fetched " . count($events) . " events from WordPress", 'INFO');
            
            if (DEBUG_MODE && !empty($events)) {
                $this->writeLog("First event sample: " . json_encode($events[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'DEBUG');
            }
            
            return $events;
        }
        
        $this->writeLog("WordPress API Error: HTTP $httpCode, Response: " . substr($response, 0, 500), 'ERROR');
        return [];
    }
    
    /**
     * Загрузка обработанных событий
     */
    public function loadProcessedEvents() {
        if (!file_exists($this->processedEventsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->processedEventsFile);
        if (empty($content)) {
            return [];
        }
        
        $events = explode("\n", trim($content));
        
        return array_filter($events, function($eventId) {
            return !empty($eventId) && is_numeric($eventId);
        });
    }
    
    /**
     * Сохранение обработанного события
     */
    public function saveProcessedEvent($eventId) {
        $events = $this->loadProcessedEvents();
        $events[] = $eventId;
        
        // Ограничиваем количество хранимых событий (последние 1000)
        if (count($events) > 1000) {
            $events = array_slice($events, -1000);
        }
        
        // Убираем дубликаты
        $events = array_unique($events);
        
        file_put_contents($this->processedEventsFile, implode("\n", $events));
        $this->writeLog("Saved processed event ID: $eventId", 'DEBUG');
    }
    
    /**
     * Форматирование сообщения о событии
     */
    public function formatEventMessage($event) {
        // Извлекаем данные из события
        $title = html_entity_decode(strip_tags($event['title']['rendered'] ?? 'Без названия'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $excerpt = html_entity_decode(strip_tags($event['excerpt']['rendered'] ?? 'Без описания'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $date = isset($event['event_details']['start_date']) ? $this->formatEventDate($event['event_details']['start_date']) : 'Дата не указана';
        $link = $event['link'] ?? WORDPRESS_URL;
        $location = isset($event['event_details']['location']) ? $event['event_details']['location'] : 'Локация не указана';
        $category = $this->getEventCategory($event);
        
        // Если excerpt пустой, берем начало content
        if (empty($excerpt) || $excerpt === 'Без описания') {
            $content = html_entity_decode(strip_tags($event['content']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!empty($content)) {
                $excerpt = mb_substr($content, 0, 300) . (mb_strlen($content) > 300 ? '...' : '');
            }
        }
        
        // Обрезаем слишком длинные тексты для Telegram
        if (mb_strlen($excerpt) > 1000) {
            $excerpt = mb_substr($excerpt, 0, 1000) . '...';
        }
        
        // Форматируем сообщение
        $message = str_replace(
            [
                '{title}',
                '{excerpt}',
                '{date}',
                '{location}',
                '{category}',
                '{link}'
            ],
            [
                $this->escapeMarkdown($title),
                $this->escapeMarkdown($excerpt),
                $date,
                $this->escapeMarkdown($location),
                $this->escapeMarkdown($category),
                $link
            ],
            EVENT_MESSAGE_TEMPLATE
        );
        
        return $message;
    }
    
    /**
     * Форматирование даты события
     */
    private function formatEventDate($dateString) {
        try {
            $date = new DateTime($dateString);
            return $date->format('d.m.Y');
        } catch (Exception $e) {
            return 'Дата не указана';
        }
    }
    
    /**
     * Получение места проведения события
     */
    private function getEventLocation($event) {
        // Ищем в контенте ключевые слова
        $content = '';
        if (isset($event['content']['rendered'])) {
            $content = html_entity_decode(strip_tags($event['content']['rendered']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Поиск местоположения в контенте
        $locationPatterns = [
            '/место[:\s]+([^\n\.]+)/ui',
            '/адрес[:\s]+([^\n\.]+)/ui',
            '/локация[:\s]+([^\n\.]+)/ui',
            '/где[:\s]+([^\n\.]+)[\?\.]/ui',
            '/(Пермский район)/ui',
            '/(Пермский край)/ui'
        ];
        
        foreach ($locationPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $location = trim($matches[1]);
                if (!empty($location)) {
                    return $location;
                }
            }
        }
        
        // Проверяем ACF поля
        if (isset($event['acf']) && is_array($event['acf'])) {
            foreach ($event['acf'] as $key => $value) {
                if (stripos($key, 'location') !== false || 
                    stripos($key, 'address') !== false ||
                    stripos($key, 'место') !== false ||
                    stripos($key, 'адрес') !== false) {
                    if (!empty($value)) {
                        return $value;
                    }
                }
            }
        }
        
        return 'Уточняется';
    }
    
    /**
     * Получение категории события
     */
    private function getEventCategory($event) {
        if (isset($event['event_category']) && is_array($event['event_category']) && !empty($event['event_category'])) {
            // Получаем названия категорий из API
            $categories = [];
            foreach ($event['event_category'] as $catId) {
                $catName = $this->getCategoryName($catId);
                if ($catName) {
                    $categories[] = $catName;
                }
            }
            if (!empty($categories)) {
                return implode(', ', $categories);
            }
        }
        
        return 'Общие';
    }
    
    /**
     * Получение названия категории по ID
     */
    private function getCategoryName($categoryId) {
        static $categoriesCache = [];
        
        if (isset($categoriesCache[$categoryId])) {
            return $categoriesCache[$categoryId];
        }
        
        $url = WORDPRESS_URL . "/wp-json/wp/v2/event_category/{$categoryId}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (isset($data['name'])) {
            $categoriesCache[$categoryId] = $data['name'];
            return $data['name'];
        }
        
        return null;
    }
    
    /**
     * Экранирование символов Markdown
     */
    private function escapeMarkdownV2($text) {
        $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($chars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
     
    /**
     * Экранирование для обычного Markdown (обратная совместимость)
     */
    private function escapeMarkdown($text) {
        // Для обычного Markdown экранируем меньше символов
        $chars = ['_', '*', '`', '['];
        foreach ($chars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
    
    /**
     * Запись в лог
     */
    public function writeLog($message, $type = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$type] $message\n";
        
        // Записываем в общий лог
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Если это ошибка, дублируем в error.log
        if ($type === 'ERROR' || $type === 'WARNING') {
            file_put_contents($this->errorLogFile, $logMessage, FILE_APPEND);
        }
        
        // Также выводим в консоль если в режиме CLI
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
        
        return true;
    }
    
    /**
     * Логирование ошибок
     */
    public function logError($message) {
        return $this->writeLog($message, 'ERROR');
    }
    
    /**
     * Проверка бота (тестовая функция)
     */
    public function testBot() {
        $this->writeLog("Testing bot connectivity...", 'INFO');
        
        // Тест Telegram API
        $result = $this->sendTelegramRequest('getMe');
        if ($result && isset($result['ok']) && $result['ok']) {
            $this->writeLog("✅ Telegram API: OK (Bot: @" . ($result['result']['username'] ?? 'Unknown') . ")", 'INFO');
        } else {
            $this->writeLog("❌ Telegram API: Failed", 'ERROR');
            return false;
        }
        
        // Тест WordPress API
        $events = $this->getWordPressEvents(time() - 86400); // За последние 24 часа
        if (is_array($events)) {
            $this->writeLog("✅ WordPress API: OK (" . count($events) . " events found)", 'INFO');
            if (!empty($events)) {
                $this->writeLog("Sample event: " . strip_tags($events[0]['title']['rendered'] ?? 'No title'), 'INFO');
            }
        } else {
            $this->writeLog("❌ WordPress API: Failed", 'ERROR');
            return false;
        }
        
        // Проверка файлов
        $files = [$this->logFile, $this->errorLogFile, $this->incomingLogFile, $this->processedEventsFile];
        foreach ($files as $file) {
            if (file_exists($file)) {
                $this->writeLog("✅ File exists: " . basename($file), 'INFO');
            } else {
                $this->writeLog("⚠️ File missing: " . basename($file), 'WARNING');
            }
        }
        
        return true;
    }
    
    /**
     * Основной метод проверки новых событий
     */
    public function checkForNewEvents($forceCheckAll = false) {
        $this->writeLog("=== Начало проверки новых событий ===", 'INFO');
        
        // Получаем уже обработанные события
        $processedEvents = $this->loadProcessedEvents();
        $this->writeLog("Уже обработано событий: " . count($processedEvents), 'INFO');
        
        // Если нужно проверить все события (принудительно)
        if ($forceCheckAll) {
            $lastCheckTime = null;
            $this->writeLog("Принудительная проверка ВСЕХ событий", 'INFO');
        } else {
            // Проверяем события за последние 24 часа
            $lastCheckTime = time() - (24 * 3600);
        }
        
        // Получаем события из WordPress
        $events = $this->getWordPressEvents($lastCheckTime);
        
        if (empty($events)) {
            $this->writeLog("Новых событий не найдено", 'INFO');
            return ['total' => 0, 'processed' => 0];
        }
        
        $this->writeLog("Найдено потенциально новых событий: " . count($events), 'INFO');
        $newEventsCount = 0;
        $totalEvents = count($events);
        
        // Проходим по событиям в обратном порядке (от старых к новым, если не принудительная проверка)
        if (!$forceCheckAll) {
            $events = array_reverse($events);
        }
        
        foreach ($events as $index => $event) {
            $eventId = $event['id'];
            $eventTitle = html_entity_decode(strip_tags($event['title']['rendered'] ?? 'Без названия'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $eventStatus = $event['status'] ?? 'unknown';
            
            $this->writeLog("Обработка события [$index/$totalEvents]: ID $eventId - '$eventTitle' (статус: $eventStatus)", 'DEBUG');
            
            // Пропускаем черновики и события в корзине
            if ($eventStatus !== 'publish') {
                $this->writeLog("Событие ID $eventId не опубликовано (статус: $eventStatus), пропускаем", 'DEBUG');
                continue;
            }
            
            // Пропускаем уже обработанные события (только если не принудительная проверка)
            if (!$forceCheckAll && in_array($eventId, $processedEvents)) {
                $this->writeLog("Событие ID $eventId уже обработано, пропускаем", 'DEBUG');
                continue;
            }
            
            $this->writeLog("Новое событие обнаружено: ID $eventId - '$eventTitle'", 'INFO');
            
            try {
                // Создаем тему в группе
                $topicName = $this->sanitizeTopicName($eventTitle);
                $topicResult = $this->createForumTopic($topicName);
                
                if (!$topicResult) {
                    $this->logError("Не удалось создать тему для события ID: $eventId");
                    continue;
                }
                
                $topicId = $topicResult['message_thread_id'];
                $this->writeLog("Создана тема ID: $topicId для события ID: $eventId", 'INFO');
                
                // Отправляем сообщение с информацией о событии
                $eventMessage = $this->formatEventMessage($event);
                $this->sendMessageToTopic($eventMessage, $topicId, 'Markdown');
                
                // Отправляем приветственное сообщение
                $this->sendMessageToTopic(WELCOME_MESSAGE, $topicId);
                
                // Сохраняем ID обработанного события
                $this->saveProcessedEvent($eventId);
                
                $newEventsCount++;
                $this->writeLog("✅ Успешно обработано событие ID: $eventId", 'INFO');
                
                // Пауза между созданиями тем (чтобы не превысить лимиты API)
                if ($newEventsCount < count($events)) {
                    sleep(2);
                }
                
            } catch (Exception $e) {
                $this->logError("Ошибка обработки события $eventId: " . $e->getMessage());
                $this->logError("Trace: " . $e->getTraceAsString());
            }
        }
        
        $this->writeLog("=== Проверка завершена. Обработано новых событий: $newEventsCount ===", 'INFO');
        
        return [
            'total' => $totalEvents,
            'processed' => $newEventsCount,
            'already_processed' => count($processedEvents)
        ];
    }
    
    /**
     * Логирование входящих сообщений
     */
    public function logIncomingMessage($update) {
        if (empty($update) || !isset($update['message'])) {
            return;
        }
        
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? 'N/A';
        $chatTitle = $message['chat']['title'] ?? $message['chat']['username'] ?? 'N/A';
        $userId = $message['from']['id'] ?? 'N/A';
        $userName = $message['from']['first_name'] ?? $message['from']['username'] ?? 'N/A';
        $text = $message['text'] ?? $message['caption'] ?? '[Нет текста]';
        $messageType = $this->detectMessageType($update);
        
        // Формируем строку лога
        $logLine = sprintf(
            "[%s] [INCOMING] Chat: %s (ID: %s) | User: %s (ID: %s) | Type: %s | Text: %s\n",
            date('Y-m-d H:i:s'),
            $chatTitle,
            $chatId,
            $userName,
            $userId,
            strtoupper($messageType),
            substr($text, 0, 200)
        );
        
        // Записываем в файл входящих логов
        file_put_contents($this->incomingLogFile, $logLine, FILE_APPEND);
        
        // Также записываем в общий лог
        $this->writeLog("Incoming message from $userName in $chatTitle", 'INFO');
    }
    
    /**
     * Определение типа сообщения
     */
    private function detectMessageType($update) {
        if (!isset($update['message'])) {
            return 'unknown';
        }
        
        $message = $update['message'];
        
        if (isset($message['text'])) {
            return 'text';
        } elseif (isset($message['photo'])) {
            return 'photo';
        } elseif (isset($message['document'])) {
            return 'document';
        } elseif (isset($message['sticker'])) {
            return 'sticker';
        } elseif (isset($message['voice'])) {
            return 'voice';
        } elseif (isset($message['video'])) {
            return 'video';
        } elseif (isset($message['audio'])) {
            return 'audio';
        } elseif (isset($message['contact'])) {
            return 'contact';
        } elseif (isset($message['location'])) {
            return 'location';
        } elseif (isset($message['new_chat_members'])) {
            return 'new_chat_members';
        } elseif (isset($message['left_chat_member'])) {
            return 'left_chat_member';
        }
        
        return 'other';
    }
    
    /**
     * Получение логов с пагинацией
     */
    public function getLogs($type = 'all', $limit = 100) {
        $file = $this->logFile;
        
        if ($type === 'incoming') {
            $file = $this->incomingLogFile;
        } elseif ($type === 'error') {
            $file = $this->errorLogFile;
        }
        
        if (!file_exists($file)) {
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (empty($lines)) {
            return [];
        }
        
        // Реверсируем, чтобы последние записи были первыми
        $lines = array_reverse($lines);
        
        // Берем только нужное количество
        return array_slice($lines, 0, $limit);
    }
    
    /**
     * Очистка названия темы от лишних символов
     */
    private function sanitizeTopicName($title) {
        // Убираем HTML теги
        $title = strip_tags($title);
        
        // Убираем лишние пробелы
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);
        
        // Ограничиваем длину (Telegram ограничение: 128 символов)
        if (mb_strlen($title) > 128) {
            $title = mb_substr($title, 0, 125) . '...';
        }
        
        return $title;
    }
    
    /**
     * Очистка старых логов
     */
    public function cleanupOldLogs($daysToKeep = 7) {
        $this->writeLog("Очистка логов старше $daysToKeep дней", 'INFO');
        
        $cutoffTime = time() - ($daysToKeep * 24 * 3600);
        $files = [$this->logFile, $this->incomingLogFile, $this->errorLogFile];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                $keptLines = [];
                
                foreach ($lines as $line) {
                    // Пытаемся извлечь дату из строки лога
                    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                        $logTime = strtotime($matches[1]);
                        if ($logTime > $cutoffTime) {
                            $keptLines[] = $line;
                        }
                    } else {
                        // Если не можем определить дату, оставляем строку
                        $keptLines[] = $line;
                    }
                }
                
                file_put_contents($file, implode("\n", $keptLines));
                $this->writeLog("Очищен лог: " . basename($file) . " (сохранено строк: " . count($keptLines) . ")", 'INFO');
            }
        }
        
        return true;
    }
    
    /**
     * Получение статистики
     */
    public function getStats() {
        $processedEvents = $this->loadProcessedEvents();
        
        // Проверяем размеры лог-файлов
        $logSizes = [];
        $files = [$this->logFile, $this->incomingLogFile, $this->errorLogFile];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $size = filesize($file);
                $logSizes[basename($file)] = $this->formatBytes($size);
            }
        }
        
        return [
            'processed_events' => count($processedEvents),
            'log_sizes' => $logSizes,
            'last_check' => date('Y-m-d H:i:s'),
            'bot_status' => 'active'
        ];
    }
    
    private function initUploadsDir() {
    if (!isset($this->uploadsDir)) {
        $this->uploadsDir = __DIR__ . '/uploads';
    }

    if (!is_dir($this->uploadsDir)) {
        mkdir($this->uploadsDir, 0755, true);
    }
}

/**
 * Получение списка локальных файлов
 */
public function getLocalFiles() {
    
    if (!file_exists($this->uploadsDir)) {
        mkdir($this->uploadsDir, 0755, true);
    }

    $result = [];

    $files = scandir($this->uploadsDir);
    if ($files === false) {
        return [];
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $this->uploadsDir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $type = 'photo';
        } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv'])) {
            $type = 'video';
        } elseif (in_array($ext, ['mp3', 'wav', 'ogg'])) {
            $type = 'audio';
        } else {
            $type = 'document';
        }

        $result[] = [
            'name' => $file,
            'path' => $path,
            'type' => $type,
            'size' => filesize($path),
            'size_formatted' => $this->formatBytes(filesize($path)),
            'mtime' => filemtime($path),
        ];
    }

    // сортировка: новые сверху
    usort($result, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

    return $result;
}
    
    
    /**
     * Получение списка чатов, в которых состоит бот
     */
    public function getChats() {
        $result = $this->sendTelegramRequest('getUpdates', ['offset' => -1, 'limit' => 1]);
        
        if (!$result || !isset($result['ok']) || !$result['ok']) {
            return [];
        }
        
        // Получаем информацию о чатах из обновлений
        $chats = [];
        if (isset($result['result']) && is_array($result['result'])) {
            foreach ($result['result'] as $update) {
                if (isset($update['message']['chat'])) {
                    $chat = $update['message']['chat'];
                    $chatId = $chat['id'];
                    
                    // Добавляем чат в список, если его еще нет
                    if (!isset($chats[$chatId])) {
                        $chats[$chatId] = [
                            'id' => $chatId,
                            'title' => $chat['title'] ?? $chat['first_name'] ?? 'Unknown',
                            'type' => $chat['type'] ?? 'unknown',
                            'username' => $chat['username'] ?? null
                        ];
                    }
                }
            }
        }
        
        return array_values($chats);
    }
    
    /**
     * Отправка сообщения в любой чат, где состоит бот
     */
    public function sendToAnyChat($chatId, $message, $type = 'text', $media = null, $options = []) {
        $validTypes = ['text', 'photo', 'video', 'document', 'audio', 'voice', 'sticker'];
        
        if (!in_array($type, $validTypes)) {
            $this->writeLog("Invalid message type: $type", 'ERROR');
            return false;
        }
        
        if ($type === 'text') {
            $parseMode = $options['parse_mode'] ?? 'Markdown';
            $topicId = $options['topic_id'] ?? null;
            return $this->sendMessage($chatId, $message, $parseMode, null, $topicId);
        } else {
            $caption = $options['caption'] ?? '';
            $parseMode = $options['parse_mode'] ?? 'Markdown';
            $topicId = $options['topic_id'] ?? null;
            
            switch ($type) {
                case 'photo':
                    return $this->sendPhoto($chatId, $media, $caption, $parseMode, null, $topicId);
                case 'video':
                    return $this->sendVideo($chatId, $media, $caption, $parseMode, null, $topicId);
                case 'document':
                    return $this->sendDocument($chatId, $media, $caption, $parseMode, null, $topicId);
                case 'audio':
                    return $this->sendAudio($chatId, $media, $caption, $parseMode, null, $topicId);
                case 'voice':
                    return $this->sendVoice($chatId, $media, $caption, $parseMode, null, $topicId);
                case 'sticker':
                    return $this->sendSticker($chatId, $media, null, $topicId);
            }
        }
        
        return false;
    }
}

// Запуск из командной строки
if (php_sapi_name() === 'cli') {
    $scriptName = basename($argv[0]);
    
    if (isset($argv[1])) {
        $command = $argv[1];
        $bot = new TelegramEventBot();
        
        switch ($command) {
            case 'check':
                echo "Запуск проверки новых событий...\n";
                $result = $bot->checkForNewEvents();
                echo "Результат: обработано {$result['processed']} из {$result['total']} событий\n";
                exit(0);
                
            case 'check-all':
                echo "Принудительная проверка ВСЕХ событий...\n";
                $result = $bot->checkForNewEvents(true);
                echo "Результат: обработано {$result['processed']} из {$result['total']} событий\n";
                exit(0);
                
            case 'test':
                echo "Тестирование бота...\n";
                $success = $bot->testBot();
                echo $success ? "✅ Все тесты пройдены успешно\n" : "❌ Тесты не пройдены\n";
                exit($success ? 0 : 1);
                
            case 'stats':
                echo "Статистика бота:\n";
                $stats = $bot->getStats();
                echo "Обработано событий: {$stats['processed_events']}\n";
                echo "Размеры логов:\n";
                foreach ($stats['log_sizes'] as $file => $size) {
                    echo "  $file: $size\n";
                }
                echo "Последняя проверка: {$stats['last_check']}\n";
                exit(0);
                
            case 'cleanup':
                echo "Очистка старых логов...\n";
                $days = isset($argv[2]) ? intval($argv[2]) : 7;
                $bot->cleanupOldLogs($days);
                echo "Очистка завершена\n";
                exit(0);
                
            case 'chats':
                echo "Получение списка чатов...\n";
                $chats = $bot->getChats();
                if (empty($chats)) {
                    echo "Чаты не найдены\n";
                } else {
                    echo "Найдено чатов: " . count($chats) . "\n";
                    foreach ($chats as $chat) {
                        echo "  - {$chat['title']} (ID: {$chat['id']}, тип: {$chat['type']})\n";
                    }
                }
                exit(0);
                
            case 'send':
                if (!isset($argv[2]) || !isset($argv[3])) {
                    echo "Использование: php $scriptName send <chat_id> <message> [type] [media]\n";
                    echo "Примеры:\n";
                    echo "  php $scriptName send -100123456789 \"Привет!\"\n";
                    echo "  php $scriptName send -100123456789 \"Фото\" photo https://example.com/photo.jpg\n";
                    exit(1);
                }
                
                $chatId = $argv[2];
                $message = $argv[3];
                $type = $argv[4] ?? 'text';
                $media = $argv[5] ?? null;
                $topicId = $argv[6] ?? null;
                
                $options = [];
                if ($topicId) {
                    $options['topic_id'] = $topicId;
                }
                
                echo "Отправка сообщения в чат $chatId...\n";
                $result = $bot->sendToAnyChat($chatId, $message, $type, $media, $options);
                if ($result && isset($result['ok']) && $result['ok']) {
                    echo "✅ Сообщение отправлено успешно\n";
                } else {
                    echo "❌ Ошибка отправки сообщения\n";
                    if (isset($result['description'])) {
                        echo "   Ошибка: {$result['description']}\n";
                    }
                }
                exit(0);
                
            default:
                echo "Неизвестная команда: $command\n";
        }
    }
    
    echo "Использование:\n";
    echo "  php $scriptName check        - проверить новые события\n";
    echo "  php $scriptName check-all    - принудительно проверить все события\n";
    echo "  php $scriptName test         - тест подключений\n";
    echo "  php $scriptName stats        - статистика бота\n";
    echo "  php $scriptName cleanup [дни] - очистка старых логов\n";
    echo "  php $scriptName chats        - список чатов, где состоит бот\n";
    echo "  php $scriptName send <chat_id> <message> [type] [media] [topic_id] - отправить сообщение\n";
    echo "\nПример для cron (проверка каждые 5 минут):\n";
    echo "  */5 * * * * php " . __DIR__ . "/bot.php check > /dev/null 2>&1\n";
    echo "\nПримеры отправки сообщений:\n";
    echo "  php $scriptName send -100123456789 \"Привет!\"\n";
    echo "  php $scriptName send -100123456789 \"Фото\" photo https://example.com/photo.jpg\n";
    echo "  php $scriptName send -100123456789 \"В топик\" text \"Сообщение в топик\" 123\n";
}
?>
