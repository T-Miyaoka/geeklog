<?php
/**
 * Happa Geeklog article inbox importer (CLI only).
 * Compatible with PHP 7.4 and Geeklog 2.2.2.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('HAPPA_IMPORT_ROOT', __DIR__);
// HAPPA_IMPORT_SITE_ROOT is only for the importer's own tests (a stand-in Geeklog); the server task does not set it.
define('HAPPA_SITE_ROOT', getenv('HAPPA_IMPORT_SITE_ROOT') ?: 'C:/inetpub/wwwroot/geeklog/2_2_2/public_html');
define('HAPPA_MAX_MANIFEST_BYTES', 5242880);
define('HAPPA_MAX_IMAGES', 10);
// gl_stories.title and page_title are varchar(128) and the server's database cuts them at 128 bytes,
// which can split a multibyte character (devblog-068: the Japanese title ended in a broken byte).
define('HAPPA_MAX_TITLE_BYTES', 128);

function importerLog($message)
{
    $directory = HAPPA_IMPORT_ROOT . '/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . preg_replace('/[\r\n]+/', ' ', $message) . PHP_EOL;
    @file_put_contents($directory . '/import-' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function importerFail($message, $exitCode = 1)
{
    importerLog('ERROR: ' . $message);
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function importerAbort($message)
{
    importerLog('ERROR: ' . $message);
    throw new RuntimeException($message);
}

function readCredentials()
{
    $path = HAPPA_IMPORT_ROOT . '/credentials/credentials.ini';
    if (!is_file($path) || is_link($path)) {
        importerAbort('CREDENTIALS_FILE_MISSING: credentials/credentials.ini was not found under the importer directory, or it is not a regular file.');
    }
    if (!is_readable($path)) {
        importerAbort('CREDENTIALS_FILE_UNREADABLE: credentials/credentials.ini exists but the task identity cannot read it. Check the file ACL.');
    }
    importerLog('CREDENTIALS_FILE_FOUND: credentials/credentials.ini exists under the importer directory and is readable; credential values are not logged.');
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        importerAbort('CREDENTIALS_FILE_READ_FAILED: credentials/credentials.ini exists but could not be read. Check the task identity and file ACL.');
    }
    $credentials = array();
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        $separator = strpos($line, '=');
        if ($separator === false) {
            importerAbort('CREDENTIALS_FORMAT_INVALID: line ' . ($index + 1) . ' has no equals sign. Expected username=... and password=...; line contents are not logged.');
        }
        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!in_array($key, array('username', 'password'), true)) {
            importerAbort('CREDENTIALS_FORMAT_INVALID: line ' . ($index + 1) . ' uses an unsupported key. Only username and password are accepted; line contents are not logged.');
        }
        if (isset($credentials[$key])) {
            importerAbort('CREDENTIALS_FORMAT_INVALID: key ' . $key . ' appears more than once.');
        }
        if ($value === '') {
            importerAbort('CREDENTIALS_VALUE_EMPTY: ' . $key . ' is present but empty.');
        }
        $credentials[$key] = $value;
    }
    if (!isset($credentials['username'])) {
        importerAbort('CREDENTIALS_USERNAME_MISSING: add a non-empty username=... line to credentials.ini.');
    }
    if (!isset($credentials['password'])) {
        importerAbort('CREDENTIALS_PASSWORD_MISSING: add a non-empty password=... line to credentials.ini.');
    }
    importerLog('CREDENTIALS_FORMAT_VALID: username and password fields are present and non-empty; values are not logged.');
    return $credentials;
}

function canonicalChildPath($parent, $relative)
{
    if (!is_string($relative) || $relative === '' || strpos($relative, "\0") !== false) {
        throw new RuntimeException('Invalid relative file path.');
    }
    $relative = str_replace('\\', '/', $relative);
    if ($relative[0] === '/' || preg_match('/^[A-Za-z]:/', $relative)) {
        throw new RuntimeException('Absolute paths are not allowed in a package.');
    }
    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Unsafe path component in package.');
        }
    }
    $base = realpath($parent);
    $candidate = realpath($parent . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts));
    if ($base === false || $candidate === false || !is_file($candidate)) {
        throw new RuntimeException('Package file does not exist.');
    }
    $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (stripos($candidate, $prefix) !== 0) {
        throw new RuntimeException('Package file resolves outside its package directory.');
    }
    return $candidate;
}

function isListArray($value)
{
    if (!is_array($value)) {
        return false;
    }
    if (count($value) === 0) {
        return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function validateManifest($packagePath)
{
    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'article.json';
    $manifestSize = is_file($manifestPath) ? filesize($manifestPath) : false;
    if (!is_file($manifestPath) || is_link($manifestPath) || $manifestSize === false || $manifestSize > HAPPA_MAX_MANIFEST_BYTES) {
        throw new RuntimeException('article.json is missing, linked, or exceeds the 5 MiB limit.');
    }
    $manifestText = file_get_contents($manifestPath);
    $manifest = json_decode($manifestText, true);
    if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('article.json is not valid UTF-8 JSON.');
    }
    if (!isset($manifest['schema_version']) || (int) $manifest['schema_version'] !== 1) {
        throw new RuntimeException('schema_version must be 1.');
    }
    if (empty($manifest['articles']) || !isListArray($manifest['articles']) || count($manifest['articles']) > 2) {
        throw new RuntimeException('articles must contain one article or one Japanese/English pair.');
    }
    if (array_key_exists('announce_on_x', $manifest) && !is_bool($manifest['announce_on_x'])) {
        throw new RuntimeException('announce_on_x must be true or false.');
    }
    if (array_key_exists('x_text', $manifest) && (!is_string($manifest['x_text']) || trim($manifest['x_text']) === '' || strlen($manifest['x_text']) > 12000)) {
        throw new RuntimeException('x_text must be a non-empty string of at most 12000 bytes.');
    }

    $seenSid = array();
    $seenLanguage = array();
        foreach ($manifest['articles'] as $index => $article) {
        if (!is_array($article)) {
            throw new RuntimeException('Each article entry must be an object.');
        }
        $preserveExistingContent = isset($article['preserve_existing_content']) && $article['preserve_existing_content'] === true;
        $requiredFields = $preserveExistingContent
            ? array('sid', 'title', 'topic', 'language', 'remove_bodytext_html')
            : array('sid', 'title', 'topic', 'language', 'introtext', 'bodytext');
        foreach ($requiredFields as $required) {
            if (!isset($article[$required]) || !is_string($article[$required]) || trim($article[$required]) === '') {
                if ($required === 'bodytext' && isset($article[$required]) && is_string($article[$required])) {
                    continue;
                }
                throw new RuntimeException('Article ' . ($index + 1) . ' is missing required field: ' . $required);
            }
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $article['sid'])) {
            throw new RuntimeException('Article sid must contain only ASCII letters, numbers, underscores, and hyphens.');
        }
        if (!in_array($article['topic'], array('Blog_ja', 'Blog_en'), true)) {
            throw new RuntimeException('topic must be Blog_ja or Blog_en.');
        }
        if (!in_array($article['language'], array('ja', 'en'), true)) {
            throw new RuntimeException('language must be ja or en.');
        }
        if (($article['language'] === 'ja' && $article['topic'] !== 'Blog_ja') ||
            ($article['language'] === 'en' && $article['topic'] !== 'Blog_en')) {
            throw new RuntimeException('language and topic must match (ja/Blog_ja or en/Blog_en).');
        }
        if (isset($seenSid[$article['sid']]) || isset($seenLanguage[$article['language']])) {
            throw new RuntimeException('Article SIDs and languages must be unique within a package.');
        }
        foreach (array('title', 'page_title') as $titleField) {
            if (array_key_exists($titleField, $article)) {
                validateStoredTitle($titleField, $article[$titleField], $index);
            }
        }
        $seenSid[$article['sid']] = true;
        $seenLanguage[$article['language']] = true;

        if (isset($article['publish']) && !is_bool($article['publish'])) {
            throw new RuntimeException('publish must be true or false.');
        }
        if (isset($article['frontpage']) && !is_bool($article['frontpage'])) {
            throw new RuntimeException('frontpage must be true or false.');
        }
        if (isset($article['update_existing']) && !is_bool($article['update_existing'])) {
            throw new RuntimeException('update_existing must be true or false.');
        }
        if (isset($article['preserve_existing_content']) && !is_bool($article['preserve_existing_content'])) {
            throw new RuntimeException('preserve_existing_content must be true or false.');
        }
        if ($preserveExistingContent && (empty($article['update_existing']) || !is_string($article['remove_bodytext_html']) || trim($article['remove_bodytext_html']) === '')) {
            throw new RuntimeException('preserve_existing_content requires update_existing=true and a non-empty remove_bodytext_html value.');
        }
        if (isset($article['publish_at']) && (!is_string($article['publish_at']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $article['publish_at']))) {
            throw new RuntimeException('publish_at must use YYYY-MM-DD HH:MM:SS local server time.');
        }
        $images = isset($article['images']) ? $article['images'] : array();
        if (!isListArray($images) || count($images) > HAPPA_MAX_IMAGES) {
            throw new RuntimeException('images must be an array of at most 10 image file paths.');
        }
        if ($preserveExistingContent && count($images) !== 0) {
            throw new RuntimeException('preserve_existing_content cannot be combined with package images.');
        }
        foreach ($images as $image) {
            if (!is_string($image)) {
                throw new RuntimeException('Each image path must be a string.');
            }
            canonicalChildPath($packagePath, $image);
        }
    }
    return $manifest;
}

/**
 * Geeklog stores a title after htmlspecialchars() (Article::_applyTitleFilter), and the database
 * truncates the stored value at HAPPA_MAX_TITLE_BYTES bytes without an error. Refuse a title that
 * would be cut, before any article in the package is saved, instead of publishing a broken title.
 */
function validateStoredTitle($field, $value, $index)
{
    $label = 'Article ' . ($index + 1) . ' ' . $field;
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new RuntimeException($label . ' must be a valid UTF-8 string.');
    }
    $stored = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $bytes = strlen($stored);
    if ($bytes > HAPPA_MAX_TITLE_BYTES) {
        throw new RuntimeException($label . ' is ' . $bytes . ' bytes after HTML escaping; Geeklog stores at most '
            . HAPPA_MAX_TITLE_BYTES . ' bytes and would cut it (a Japanese character is 3 bytes). Shorten it.');
    }
}

function bootstrapGeeklog()
{
    // lib-common.php normally runs in the global script scope. This CLI
    // importer bootstraps it from a function, so declare Geeklog's shared
    // state as global before including it (notably $_LOCALE, which COM_strftime
    // reads from global scope when lib-article.php is loaded below).
    global $_CONF, $_TABLES, $_USER, $_GROUPS, $_RIGHTS, $_LOCALE, $_SCRIPTS,
        $_DEVICE, $_URL,
        $_TOPICS, $_STRUCT_DATA, $_DB_dbms, $_COM_VERBOSE, $TEMPLATE_OPTIONS,
        $LANG24, $LANG32, $LANG_ISO639_1, $LANG_MONTH, $LANG_MONTH_SHORT, $LANG_WEEK,
        $LANG_WEEK_SHORT, $LANG_AMPM, $LANG_dateformats;

    if (!is_file(HAPPA_SITE_ROOT . '/lib-common.php')) {
        importerAbort('GEEKLOG_BOOTSTRAP_FAILED: lib-common.php was not found at the configured server path.');
    }
    $_SERVER['PHP_SELF'] = HAPPA_IMPORT_ROOT . '/run-import.php';
    $_SERVER['SCRIPT_NAME'] = '/happa-article-inbox/run-import.php';
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    // Geeklog's device detector reads this key even for the CLI importer.
    // On PHP 8 an absent key warns before its locale/error logger is initialized.
    $_SERVER['HTTP_USER_AGENT'] = 'Happa-Article-Inbox-CLI/1.0';
    $_SERVER['REQUEST_METHOD'] = 'CLI';
    require_once HAPPA_SITE_ROOT . '/lib-common.php';
    if (!empty($_CONF['timezone'])) {
        date_default_timezone_set($_CONF['timezone']);
    }
    require_once $_CONF['path_system'] . 'lib-article.php';
}

function bootstrapGeeklogSafely()
{
    importerLog('GEEKLOG_BOOTSTRAP_STARTED: loading the configured Geeklog installation and database connection.');
    try {
        bootstrapGeeklog();
    } catch (Throwable $error) {
        $errorType = get_class($error);
        $errorLocation = basename($error->getFile()) . ':' . (int) $error->getLine();
        importerAbort('GEEKLOG_BOOTSTRAP_FAILED: Geeklog initialization raised ' . $errorType . ' at ' . $errorLocation . '. The exception message is suppressed because it can contain sensitive configuration details; check the PHP/server log at that location.');
    }
    importerLog('GEEKLOG_BOOTSTRAP_SUCCEEDED: Geeklog initialized.');
}

function authenticateGeeklog($credentials)
{
    global $_TABLES, $_USER, $_GROUPS, $_RIGHTS;
    importerLog('GEEKLOG_LOGIN_ATTEMPT: checking the configured local Geeklog account; username and password are not logged.');
    $username = DB_escapeString($credentials['username']);
    try {
        $result = DB_query("SELECT uid, status FROM {$_TABLES['users']} WHERE username = '{$username}' AND ((remoteservice IS NULL) OR (remoteservice = ''))");
        $account = DB_fetchArray($result);
    } catch (Throwable $error) {
        importerAbort('GEEKLOG_USERNAME_LOOKUP_FAILED: Geeklog could not check the account against its database. Check server PHP/database logs; credentials and SQL details are suppressed.');
    }
    $uid = $account ? (int) $account['uid'] : 0;
    if ($uid < 2) {
        importerAbort('GEEKLOG_USERNAME_REJECTED: no matching local Geeklog account was found. Verify the username and that this is not an external-service account.');
    }
    if ((int) $account['status'] !== USER_ACCOUNT_ACTIVE) {
        importerAbort('GEEKLOG_ACCOUNT_INACTIVE: the Geeklog account exists but is not active. Activate it before importing.');
    }
    importerLog('GEEKLOG_USERNAME_ACCEPTED: a matching active local account was found; password verification is starting. Username is not logged.');
    try {
        $passwordValid = SEC_encryptUserPassword($credentials['password'], $uid) === 0;
    } catch (Throwable $error) {
        importerAbort('GEEKLOG_PASSWORD_CHECK_FAILED: Geeklog could not complete password verification. Check server PHP logs; credentials are suppressed.');
    }
    if (!$passwordValid) {
        importerAbort('GEEKLOG_PASSWORD_REJECTED: the username matched an active local account, but password verification failed. Verify the password in credentials.ini.');
    }
    try {
        $_USER = SESS_getUserDataFromId($uid);
    } catch (Throwable $error) {
        importerAbort('GEEKLOG_SESSION_LOAD_FAILED: password verification succeeded, but Geeklog could not load account session data.');
    }
    if (!is_array($_USER) || (int) $_USER['uid'] !== (int) $uid) {
        importerAbort('GEEKLOG_SESSION_LOAD_FAILED: password verification succeeded, but Geeklog could not load the account session data.');
    }
    importerLog('GEEKLOG_LOGIN_SUCCEEDED: account password verified and session data loaded; username is not logged.');
    $_USER['topic_id'] = '';
    $_GROUPS = SEC_getUserGroups($uid);
    $_RIGHTS = explode(',', SEC_getUserPermissions(0, $uid));
    if (!SEC_hasRights('story.edit')) {
        importerAbort('GEEKLOG_PERMISSION_MISSING: login succeeded, but the account lacks story.edit permission.');
    }
    importerLog('GEEKLOG_PERMISSION_CHECK_SUCCEEDED: story.edit is granted.');
}

function runCredentialCheck()
{
    importerLog('CREDENTIAL_CHECK_STARTED: credentials-only diagnostic; no article package will be processed.');
    $credentials = readCredentials();
    bootstrapGeeklogSafely();
    authenticateGeeklog($credentials);
    foreach (array('Blog_ja', 'Blog_en') as $topicId) {
        try {
            topicAccessForImport($topicId, $_USER['uid']);
        } catch (Throwable $error) {
            importerAbort('GEEKLOG_TOPIC_PERMISSION_MISSING: login succeeded, but the account cannot edit topic ' . $topicId . '.');
        }
        importerLog('GEEKLOG_TOPIC_PERMISSION_SUCCEEDED: edit access verified for ' . $topicId . '.');
    }
    importerLog('CREDENTIAL_CHECK_SUCCEEDED: Geeklog login, story.edit, and Blog_ja/Blog_en edit access verified; no article was created.');
    fwrite(STDOUT, 'Credential check passed: Geeklog login and article/topic permissions verified. No article was created.' . PHP_EOL);
}

function topicAccessForImport($tid, $uid)
{
    global $_TABLES;
    $safeTid = DB_escapeString($tid);
    $result = DB_query("SELECT tid, owner_id, group_id, perm_owner, perm_group, perm_members, perm_anon, archive_flag FROM {$_TABLES['topics']} WHERE tid = '{$safeTid}'");
    $topic = DB_fetchArray($result);
    if (!$topic || (int) $topic['archive_flag'] === 1) {
        throw new RuntimeException('The requested blog topic does not exist or is archived: ' . $tid);
    }
    if (SEC_hasAccess($topic['owner_id'], $topic['group_id'], $topic['perm_owner'], $topic['perm_group'], $topic['perm_members'], $topic['perm_anon'], $uid) < 3) {
        throw new RuntimeException('The configured Geeklog account cannot edit topic: ' . $tid);
    }
    return $topic;
}

function preflightArticle($article, $packagePath, $uid)
{
    global $_CONF, $_TABLES;
    topicAccessForImport($article['topic'], $uid);
    $safeSid = DB_escapeString($article['sid']);
    $existingResult = DB_query("SELECT sid FROM {$_TABLES['stories']} WHERE sid = '{$safeSid}'");
    $existing = DB_fetchArray($existingResult);
    $updateExisting = !empty($article['update_existing']);
    if ($existing && !$updateExisting) {
        throw new RuntimeException('Article sid already exists; duplicate import refused: ' . $article['sid']);
    }
    if (!$existing && $updateExisting) {
        throw new RuntimeException('update_existing was requested, but the article does not exist: ' . $article['sid']);
    }
    $topicResult = DB_query("SELECT tid FROM {$_TABLES['topic_assignments']} WHERE type = 'article' AND id = '{$safeSid}' AND tdefault = 1");
    $existingTopic = DB_fetchArray($topicResult);
    if ($existing && (!$existingTopic || $existingTopic['tid'] !== $article['topic'])) {
        throw new RuntimeException('update_existing refused because the existing article is not in the requested topic: ' . $article['sid']);
    }
    $preserveExistingContent = !empty($article['preserve_existing_content']);
    $existingImageCount = $existing ? DB_count($_TABLES['article_images'], 'ai_sid', $article['sid']) : 0;
    if ($existing && $existingImageCount > 0 && !$preserveExistingContent) {
        throw new RuntimeException('update_existing is limited to articles without registered images; refusing to replace or renumber existing attachments: ' . $article['sid']);
    }
    if ($preserveExistingContent) {
        $storyResult = DB_query("SELECT bodytext FROM {$_TABLES['stories']} WHERE sid = '{$safeSid}'");
        $story = DB_fetchArray($storyResult);
        $needleCount = substr_count($story['bodytext'], $article['remove_bodytext_html']);
        if ($needleCount !== 1) {
            throw new RuntimeException('Content-only update requires the exact target HTML to occur once in the existing article body; found ' . $needleCount . ' occurrences.');
        }
        $images = array();
    } else {
        list($intro, $body, $images) = prepareArticleContent($article, $packagePath, $article['sid']);
        unset($intro, $body);
    }
    if (count($images) > (int) $_CONF['maximagesperarticle']) {
        throw new RuntimeException('The Geeklog image limit is lower than the number of package images.');
    }
    foreach ($images as $image) {
        $finalImage = $_CONF['path_images'] . 'articles/' . $article['sid'] . '_' . $image['number'] . '.' . strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
        if (file_exists($finalImage)) {
            throw new RuntimeException('An article image with the generated filename already exists: ' . basename($finalImage));
        }
    }
    $dateText = isset($article['publish_at']) ? $article['publish_at'] : date('Y-m-d H:i:s');
    $date = DateTime::createFromFormat('!Y-m-d H:i:s', $dateText);
    $dateErrors = DateTime::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        throw new RuntimeException('publish_at is not a valid local date/time.');
    }
}

function prepareArticleContent($article, $packagePath, $sid)
{
    $articleImages = array();
    $images = isset($article['images']) ? $article['images'] : array();
    foreach ($images as $index => $relativePath) {
        $source = canonicalChildPath($packagePath, $relativePath);
        $imageInfo = @getimagesize($source);
        $imageTypes = array(IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif');
        if (!is_array($imageInfo) || !isset($imageTypes[$imageInfo[2]])) {
            throw new RuntimeException('Only valid JPEG, PNG, and GIF images are accepted.');
        }
        $mime = $imageTypes[$imageInfo[2]];
        $extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif');
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Only JPEG, PNG, and GIF images are accepted.');
        }
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (($mime === 'image/jpeg' && !in_array($extension, array('jpg', 'jpeg'), true)) ||
            ($mime !== 'image/jpeg' && $extension !== $extensions[$mime])) {
            throw new RuntimeException('Image extension does not match its actual content: ' . basename($relativePath));
        }
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > 10485760) {
            throw new RuntimeException('Image is empty or exceeds the 10 MiB per-image limit.');
        }
        $number = $index + 1;
        $articleImages[] = array('source' => $source, 'name' => basename($relativePath), 'mime' => $mime, 'size' => $size, 'number' => $number);
    }

    $intro = $article['introtext'];
    $body = isset($article['bodytext']) ? $article['bodytext'] : '';
    foreach ($articleImages as $image) {
        $number = $image['number'];
        $token = '[image' . $number . ']';
        if (strpos($intro . $body, $token) === false) {
            $body .= $token;
        }
    }
    return array($intro, $body, $articleImages);
}

function publishArticleThroughGeeklog($article, $packagePath, $uid)
{
    global $_CONF, $_TABLES, $_USER, $_POST, $_GET, $_REQUEST, $_FILES;
    $tid = $article['topic'];
    $topic = topicAccessForImport($tid, $uid);
    $sid = $article['sid'];
    $existing = DB_count($_TABLES['stories'], 'sid', $sid) > 0;
    $updateExisting = !empty($article['update_existing']);
    // Every new blog article is listed on the site front page, regardless of the
    // package's frontpage value. Updates keep the article's current front-page
    // setting so corrections made in Geeklog are not undone by a package.
    $frontpage = 1;
    if ($existing) {
        $frontpageResult = DB_query("SELECT frontpage FROM {$_TABLES['stories']} WHERE sid = '" . DB_escapeString($sid) . "'");
        $frontpageRow = DB_fetchArray($frontpageResult);
        $frontpage = $frontpageRow ? (int) $frontpageRow['frontpage'] : 1;
    } elseif (isset($article['frontpage']) && $article['frontpage'] === false) {
        importerLog('FRONTPAGE_FORCED: package requested frontpage=false for new article ' . $sid . '; new blog articles are always shown on the front page.');
    }
    if ($existing && !$updateExisting) {
        throw new RuntimeException('Article sid already exists; duplicate import refused: ' . $sid);
    }
    if (!$existing && $updateExisting) {
        throw new RuntimeException('update_existing was requested, but the article does not exist: ' . $sid);
    }

    $preserveExistingContent = !empty($article['preserve_existing_content']);
    $existingImageCount = $existing ? DB_count($_TABLES['article_images'], 'ai_sid', $sid) : 0;
    if ($preserveExistingContent) {
        $safeSid = DB_escapeString($sid);
        $storyResult = DB_query("SELECT introtext, bodytext FROM {$_TABLES['stories']} WHERE sid = '{$safeSid}'");
        $story = DB_fetchArray($storyResult);
        if (!$story) {
            throw new RuntimeException('The existing article disappeared before its content-only update: ' . $sid);
        }
        $needleCount = substr_count($story['bodytext'], $article['remove_bodytext_html']);
        if ($needleCount !== 1) {
            throw new RuntimeException('Content-only update requires the exact target HTML to occur once in the existing article body; found ' . $needleCount . ' occurrences.');
        }
        $intro = $story['introtext'];
        $body = str_replace($article['remove_bodytext_html'], '', $story['bodytext']);
        $images = array();
    } else {
        list($intro, $body, $images) = prepareArticleContent($article, $packagePath, $sid);
    }
    if (count($images) > 0 && (int) $_CONF['maximagesperarticle'] < count($images)) {
        throw new RuntimeException('The Geeklog image limit is lower than the number of package images.');
    }

    $_POST = array(
        'topic_options_hide' => 1,
        'topic_option' => TOPIC_SELECTED_OPTION,
        'tid' => array($tid),
        'topic_default_hide' => 0,
        'default_tid' => $tid,
    );
    $_GET = array();
    $_REQUEST = $_POST;
    $_FILES = array();

    foreach ($images as $image) {
        $number = $image['number'];
        $tmp = $_CONF['path_data'] . 'happa-import-work-' . bin2hex(random_bytes(12)) . '.' . strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
        if (!copy($image['source'], $tmp)) {
            throw new RuntimeException('Could not stage a package image in Geeklog private data directory.');
        }
        $_FILES['file' . $number] = array(
            'name' => $image['name'],
            'type' => $image['mime'],
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $image['size'],
            'non_upload' => true,
        );
    }

    $dateText = isset($article['publish_at']) ? $article['publish_at'] : date('Y-m-d H:i:s');
    $date = DateTime::createFromFormat('!Y-m-d H:i:s', $dateText);
    $dateErrors = DateTime::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        throw new RuntimeException('publish_at is not a valid local date/time.');
    }

    $args = array(
        // Run the normal article-save path so $_FILES attachments are handled
        // and registered with the story. The service-only mode skips images.
        'gl_svc' => false,
        'gl_edit' => $updateExisting,
        'sid' => $sid,
        'uid' => $uid,
        'owner_id' => $uid,
        'group_id' => $topic['group_id'],
        'perm_owner' => $topic['perm_owner'],
        'perm_group' => $topic['perm_group'],
        'perm_members' => $topic['perm_members'],
        'perm_anon' => $topic['perm_anon'],
        'title' => $article['title'],
        'page_title' => isset($article['page_title']) ? $article['page_title'] : $article['title'],
        'introtext' => $intro,
        'bodytext' => $body,
        'postmode' => 'html',
        'draft_flag' => !empty($article['publish']) ? 0 : 1,
        'frontpage' => $frontpage,
        'show_topic_icon' => 1,
        'category' => array($tid),
        'publish_year' => $date->format('Y'),
        'publish_month' => $date->format('n'),
        'publish_day' => $date->format('j'),
        'publish_hour' => $date->format('G'),
        'publish_minute' => $date->format('i'),
        'publish_second' => $date->format('s'),
        'expire_year' => '2099',
        'expire_month' => '12',
        'expire_day' => '31',
        'expire_hour' => '11',
        'expire_minute' => '59',
        'expire_second' => '59',
        'expire_ampm' => 'pm',
        'archiveflag' => 0,
    );
    if ($updateExisting) {
        $args['old_sid'] = $sid;
    }
    if (!empty($_CONF['meta_tags'])) {
        $args['meta_description'] = isset($article['meta_description']) ? $article['meta_description'] : '';
        $args['meta_keywords'] = isset($article['meta_keywords']) ? $article['meta_keywords'] : '';
    }

    // On success Geeklog's article-save path (gl_svc=false) redirects to the
    // article with COM_redirect(), which ends the PHP process with die(1).
    // This function therefore runs only in a per-article child process
    // (--publish-article); the parent verifies the saved article in the
    // database. The shutdown function removes the staged image copies even
    // when Geeklog ends the process.
    $stagedFiles = array();
    foreach ($_FILES as $file) {
        if (!empty($file['tmp_name'])) {
            $stagedFiles[] = $file['tmp_name'];
        }
    }
    register_shutdown_function('importerRemoveFiles', $stagedFiles);
    $output = '';
    $serviceMessage = array();
    $result = service_submit_story($args, $output, $serviceMessage);
    importerRemoveFiles($stagedFiles);
    if ($result !== PLG_RET_OK) {
        $detail = isset($serviceMessage['error_desc']) ? $serviceMessage['error_desc'] : 'Geeklog article save failed with status ' . $result;
        throw new RuntimeException($detail);
    }
    return array('sid' => $sid, 'publish' => !empty($article['publish']), 'language' => $article['language'], 'updated' => $updateExisting);
}

function importerRemoveFiles($paths)
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Runs a command without a shell and waits for it, reading its output.
 * Returns exit code (null on timeout), stdout, stderr and whether it timed out.
 */
function importerRunProcess(array $command, $cwd, $timeoutSeconds)
{
    // Output goes to temporary files, not pipes: Windows pipes cannot be read
    // without blocking, so a pipe would make the timeout unenforceable and a
    // full pipe buffer could stall the child.
    $stdoutPath = tempnam(sys_get_temp_dir(), 'happa-import-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'happa-import-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        throw new RuntimeException('Could not create temporary files for the article save process.');
    }
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('file', $stdoutPath, 'w'), 2 => array('file', $stderrPath, 'w'));
    $process = proc_open($command, $descriptors, $pipes, $cwd, null, array('bypass_shell' => true));
    if (!is_resource($process)) {
        importerRemoveFiles(array($stdoutPath, $stderrPath));
        throw new RuntimeException('Could not start the article save process.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    $exitCode = null;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100000);
    }
    $closeCode = proc_close($process);
    if (!$timedOut && ($exitCode === null || $exitCode === -1)) {
        $exitCode = $closeCode;
    }
    $stdout = (string) @file_get_contents($stdoutPath);
    $stderr = (string) @file_get_contents($stderrPath);
    importerRemoveFiles(array($stdoutPath, $stderrPath));
    return array('exit_code' => $timedOut ? null : $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut);
}

/** Command that saves article $index of the claimed package in a fresh PHP process. */
function importerArticleChildCommand($packageName, $index)
{
    $command = array(PHP_BINARY);
    $ini = php_ini_loaded_file();
    if (is_string($ini) && $ini !== '') {
        $command[] = '-c';
        $command[] = $ini;
    }
    return array_merge($command, array('-f', HAPPA_IMPORT_ROOT . DIRECTORY_SEPARATOR . 'run-import.php', '--', '--publish-article', $packageName, (string) $index));
}

/** Marker lines the child prints; the parent reads only these from its output. */
function importerChildMarker($status, $message)
{
    return 'HAPPA_ARTICLE_CHILD ' . json_encode(array('status' => $status, 'message' => $message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function importerParseChildMarker($stdout)
{
    $found = null;
    foreach (preg_split('/\r\n|\r|\n/', (string) $stdout) as $line) {
        if (strpos($line, 'HAPPA_ARTICLE_CHILD ') === 0) {
            $value = json_decode(substr($line, strlen('HAPPA_ARTICLE_CHILD ')), true);
            if (is_array($value) && isset($value['status'])) {
                $found = $value;
            }
        }
    }
    return $found;
}

/** State the parent reads before and after a child save, for verification. */
function importerReadArticleState($sid)
{
    global $_TABLES;
    $safeSid = DB_escapeString($sid);
    $story = DB_fetchArray(DB_query("SELECT draft_flag, bodytext FROM {$_TABLES['stories']} WHERE sid = '{$safeSid}'"));
    $topic = DB_fetchArray(DB_query("SELECT tid FROM {$_TABLES['topic_assignments']} WHERE type = 'article' AND id = '{$safeSid}' AND tdefault = 1"));
    return array(
        'exists' => (bool) $story,
        'draft_flag' => $story ? (int) $story['draft_flag'] : null,
        'bodytext' => $story ? (string) $story['bodytext'] : '',
        'tid' => $topic ? (string) $topic['tid'] : '',
        'image_count' => $story ? (int) DB_count($_TABLES['article_images'], 'ai_sid', $sid) : 0,
    );
}

/** Returns null when the saved article matches the package, otherwise the reason. */
function importerVerifySavedArticle($article, $before, $after)
{
    if (!$after['exists']) {
        return 'the article was not found in Geeklog after the save';
    }
    if ($after['tid'] !== $article['topic']) {
        return 'the saved article is not in topic ' . $article['topic'];
    }
    $expectedDraft = !empty($article['publish']) ? 0 : 1;
    if ($after['draft_flag'] !== $expectedDraft) {
        return 'the saved draft flag is ' . $after['draft_flag'] . ', expected ' . $expectedDraft;
    }
    if (!empty($article['preserve_existing_content'])) {
        if (strpos($after['bodytext'], $article['remove_bodytext_html']) !== false) {
            return 'the content-only update left the target text in the article body';
        }
        if ($after['image_count'] !== $before['image_count']) {
            return 'the content-only update changed the registered image count';
        }
        return null;
    }
    $expectedImages = isset($article['images']) ? count($article['images']) : 0;
    if ($after['image_count'] !== $expectedImages) {
        return 'the article has ' . $after['image_count'] . ' registered images, expected ' . $expectedImages;
    }
    return null;
}

function importerShortText($text)
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    return strlen($text) > 300 ? substr($text, 0, 300) . '...' : $text;
}

/** Parent side: saves one article in a child process and verifies it in the database. */
function importerPublishArticleInChild($article, $index, $packageName)
{
    $before = importerReadArticleState($article['sid']);
    importerLog('ARTICLE_SAVE_STARTED: saving ' . $article['sid'] . ' in a separate PHP process.');
    $run = importerRunProcess(importerArticleChildCommand($packageName, $index), HAPPA_IMPORT_ROOT, 600);
    $marker = importerParseChildMarker($run['stdout']);
    if ($run['timed_out']) {
        throw new RuntimeException('The save process for ' . $article['sid'] . ' did not finish within 600 seconds and was stopped.');
    }
    if ($marker !== null && $marker['status'] === 'failed') {
        throw new RuntimeException('Saving ' . $article['sid'] . ' failed: ' . $marker['message']);
    }
    $after = importerReadArticleState($article['sid']);
    $problem = importerVerifySavedArticle($article, $before, $after);
    if ($problem !== null) {
        throw new RuntimeException('Saving ' . $article['sid'] . ' could not be verified: ' . $problem . ' (save process exit code ' . var_export($run['exit_code'], true) . '; output: ' . importerShortText($run['stdout'] . ' ' . $run['stderr']) . ')');
    }
    importerLog('ARTICLE_SAVED: ' . $article['sid'] . ' verified in Geeklog (draft=' . $after['draft_flag'] . ', images=' . $after['image_count'] . ').');
    return array('sid' => $article['sid'], 'publish' => !empty($article['publish']), 'language' => $article['language'], 'updated' => !empty($article['update_existing']));
}

/** Child side (--publish-article): saves one article. Geeklog ends this process on success. */
function importerArticleChildMain($packageName, $index)
{
    global $_USER;
    try {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', (string) $packageName) || !preg_match('/^\d$/', (string) $index)) {
            throw new RuntimeException('Invalid package name or article index.');
        }
        $processingPath = HAPPA_IMPORT_ROOT . '/inbox/processing/' . $packageName;
        if (!is_dir($processingPath)) {
            throw new RuntimeException('The claimed package is not in inbox/processing.');
        }
        $manifest = validateManifest($processingPath);
        if (!isset($manifest['articles'][(int) $index])) {
            throw new RuntimeException('The package has no article at index ' . $index . '.');
        }
        $article = $manifest['articles'][(int) $index];
        $credentials = readCredentials();
        bootstrapGeeklogSafely();
        authenticateGeeklog($credentials);
        preflightArticle($article, $processingPath, $_USER['uid']);
        publishArticleThroughGeeklog($article, $processingPath, $_USER['uid']);
        fwrite(STDOUT, PHP_EOL . importerChildMarker('returned', 'Geeklog returned without redirecting.') . PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDOUT, PHP_EOL . importerChildMarker('failed', $error->getMessage()) . PHP_EOL);
        exit(3);
    }
}

/** Top-level package flag: announce the published articles on X after the import. */
function importerAnnouncementRequested(array $manifest)
{
    return isset($manifest['announce_on_x']) && $manifest['announce_on_x'] === true;
}

/** The x_announcement value written to import-result.json. */
function importerAnnouncementResult($state, $url, $reason)
{
    return array('state' => $state, 'url' => (string) $url, 'reason' => (string) $reason);
}

/**
 * What the importer's task identity cannot do in X-Post's private data folder.
 * The announcement reads credentials.json and history.json, and writes history.json,
 * api-budget.json (through a temporary file in the folder, then a rename) and the
 * posting/budget lock files. Returns an empty list when all of that is possible.
 */
function importerXPostAccessProblems($directory)
{
    if (!is_dir($directory)) {
        return array('data/xpost フォルダが見つからない');
    }
    $problems = array();
    $credentials = $directory . DIRECTORY_SEPARATOR . 'credentials.json';
    if (!is_file($credentials) || !is_readable($credentials)) {
        $problems[] = 'credentials.json を読み取れない';
    }
    if (!is_writable($directory)) {
        $problems[] = 'data/xpost フォルダにファイルを作成できない（history.json・api-budget.json の保存に必要）';
    }
    foreach (array('history.json', 'api-budget.json', 'posting.lock', 'budget.lock') as $name) {
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($path) && (!is_readable($path) || !is_writable($path))) {
            $problems[] = $name . ' を読み書きできない';
        }
    }
    return $problems;
}

/**
 * Announces the package's published articles on X once, through X-Post's own Store, Client
 * and Publisher, so the text check, the minimum announcement interval, duplicate protection,
 * the durable "sending" record, no resend after an unknown outcome and the API budget all apply
 * as in the X-Post administration screen. Never throws; the result is posted, skipped, failed
 * or uncertain. $options replaces collaborators in tests only (enabled, store_dir,
 * xpost_classes, articles, client); the importer passes none.
 */
function importerAnnounceOnX(array $manifest, $uid, array $options = array())
{
    global $_CONF;
    $sids = array();
    foreach ($manifest['articles'] as $article) {
        if (!empty($article['publish'])) {
            $sids[] = $article['sid'];
        }
    }
    if (count($sids) === 0) {
        return importerAnnouncementResult('skipped', '', 'publish=true の記事がないため投稿しませんでした。');
    }
    $classes = isset($options['xpost_classes']) ? $options['xpost_classes'] : $_CONF['path'] . 'plugins/xpost/classes';
    if (!is_file($classes . '/Core.php') || !is_file($classes . '/Articles.php')) {
        return importerAnnouncementResult('failed', '', 'X-Post プラグイン（plugins/xpost/classes）が見つかりません。');
    }
    require_once $classes . '/Core.php';
    require_once $classes . '/Articles.php';

    $allowed = array_key_exists('enabled', $options)
        ? \Happa\XPost\Policy::inboxAnnouncementAllowed($options['enabled'] === null ? false : $options['enabled'])
        : \Happa\XPost\Policy::inboxAnnouncementAllowed();
    if (!$allowed) {
        return importerAnnouncementResult('skipped', '', 'X-Post の投稿設定「記事取り込みからの告知を許可する」が無効のため投稿しませんでした。');
    }

    try {
        $directory = isset($options['store_dir']) ? $options['store_dir'] : \Happa\XPost\Store::privateDirectory($_CONF['path_data'], $_CONF['path_html']);
    } catch (RuntimeException $error) {
        return importerAnnouncementResult('failed', '', $error->getMessage());
    }
    $problems = importerXPostAccessProblems($directory);
    if ($problems) {
        return importerAnnouncementResult('failed', '', '取り込みタスクの実行アカウントの data/xpost への権限が足りません: ' . implode('、', $problems) . '。');
    }

    try {
        $store = new \Happa\XPost\Store($directory);
        // Re-read the publication state from the database immediately before sending.
        try {
            $articles = isset($options['articles']) ? call_user_func($options['articles'], $sids) : \Happa\XPost\Articles::selected($sids);
        } catch (RuntimeException $error) {
            return importerAnnouncementResult('skipped', '', '記事を公開中として確認できないため投稿しませんでした（' . $error->getMessage() . '）');
        }
        $text = isset($manifest['x_text']) ? str_replace(array("\r\n", "\r"), "\n", $manifest['x_text']) : \Happa\XPost\Text::draft($articles);
        try {
            \Happa\XPost\Text::validate($text, $articles);
        } catch (RuntimeException $error) {
            return importerAnnouncementResult('failed', '', '投稿文を確認してください: ' . $error->getMessage());
        }

        // The interval and duplicate rules are checked again by Publisher under its lock;
        // checking them here first reports them as skipped rather than failed.
        $history = $store->read('history');
        try {
            \Happa\XPost\Policy::announcementDue($history);
        } catch (RuntimeException $error) {
            return importerAnnouncementResult('skipped', '', $error->getMessage());
        }
        foreach ($history as $entry) {
            if (isset($entry['sids'], $entry['state']) && is_array($entry['sids']) && array_intersect($sids, $entry['sids']) && $entry['state'] !== 'failed') {
                return importerAnnouncementResult('skipped', '', 'この記事は投稿済み、または送信結果の確認待ちのため投稿しませんでした。');
            }
        }

        $client = isset($options['client']) ? $options['client'] : new \Happa\XPost\Client($store->configuration(), null, $store);
        $before = array_flip(array_keys($history));
        try {
            $entry = (new \Happa\XPost\Publisher($store))->publish($articles, $text, $uid, $client);
            return importerAnnouncementResult('posted', isset($entry['url']) ? $entry['url'] : '', '');
        } catch (Throwable $error) {
            $reason = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
            // Publisher records "sending" before the request and "uncertain" when the outcome
            // is unknown; neither is resent. Without a new record nothing was sent.
            try {
                $after = $store->read('history');
            } catch (Throwable $readError) {
                return importerAnnouncementResult('uncertain', '', $reason . ' 投稿履歴を読み直せないため、X 側の公開状態を確認してください。');
            }
            foreach ($after as $key => $item) {
                if (!isset($before[$key]) && isset($item['sids'], $item['state']) && is_array($item['sids']) && array_intersect($sids, $item['sids'])) {
                    if ($item['state'] === 'posted') {
                        return importerAnnouncementResult('posted', isset($item['url']) ? $item['url'] : '', '');
                    }
                    if ($item['state'] === 'failed') {
                        return importerAnnouncementResult('failed', '', $reason);
                    }
                    if ($item['state'] === 'uncertain') {
                        return importerAnnouncementResult('uncertain', '', $reason);
                    }
                    return importerAnnouncementResult('uncertain', '', $reason . ' 送信中の記録が残っています。X 側の公開状態を確認してください（再送しません）。');
                }
            }
            return importerAnnouncementResult('failed', '', $reason);
        }
    } catch (Throwable $error) {
        return importerAnnouncementResult('failed', '', $error instanceof RuntimeException ? $error->getMessage() : get_class($error));
    }
}

/** Runs the announcement for a completed package and logs its outcome. */
function importerAnnounceAndLog(array $manifest, $uid, $packageName)
{
    importerLog('X_ANNOUNCEMENT_STARTED: package ' . $packageName . ' requested announce_on_x.');
    $result = importerAnnounceOnX($manifest, $uid);
    $detail = $result['state'] === 'posted' ? $result['url'] : $result['reason'];
    importerLog('X_ANNOUNCEMENT_' . strtoupper($result['state']) . ': ' . $detail);
    return $result;
}

function uniqueDestination($base, $name)
{
    $destination = $base . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($destination)) {
        return $destination;
    }
    return $base . DIRECTORY_SEPARATOR . $name . '-' . date('Ymd-His');
}

function movePackage($source, $destination)
{
    if (!@rename($source, $destination)) {
        throw new RuntimeException('Could not move package to its terminal queue folder.');
    }
}

function main()
{
    global $_CONF, $_TABLES, $_USER, $argv;
    $arguments = array_values(array_filter(array_slice($argv ?? array(), 1), function ($value) { return $value !== '--'; }));
    if (isset($arguments[0]) && $arguments[0] === '--publish-article') {
        // Started by the parent importer, which already holds the runner lock.
        importerArticleChildMain(isset($arguments[1]) ? $arguments[1] : '', isset($arguments[2]) ? $arguments[2] : '');
    }
    $lockPath = HAPPA_IMPORT_ROOT . '/inbox/runner.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        importerLog('Another importer process is already running; this run was skipped.');
        exit(0);
    }

    foreach (array('ready', 'processing', 'done', 'failed', 'logs') as $folder) {
        $path = HAPPA_IMPORT_ROOT . '/inbox/' . $folder;
        if ($folder === 'logs') {
            $path = HAPPA_IMPORT_ROOT . '/logs';
        }
        if (!is_dir($path) && !@mkdir($path, 0750, true)) {
            importerFail('Required folder could not be created: ' . $folder);
        }
    }

    if (isset($argv[1]) && $argv[1] === '--check-credentials') {
        try {
            runCredentialCheck();
            exit(0);
        } catch (Throwable $error) {
            importerLog('CREDENTIAL_CHECK_FAILED: ' . $error->getMessage());
            fwrite(STDERR, 'Credential check failed: ' . $error->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    $readyRoot = HAPPA_IMPORT_ROOT . '/inbox/ready';
    $packages = array();
    foreach (new DirectoryIterator($readyRoot) as $entry) {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
            continue;
        }
        $name = $entry->getFilename();
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $name)) {
            $packages[] = $name;
        }
    }
    sort($packages, SORT_STRING);
    if (count($packages) === 0) {
        importerLog('QUEUE_EMPTY: no article package was found in inbox/ready; credentials/credentials.ini was not read and Geeklog login was not attempted. Run PHP with --check-credentials to verify credentials without importing an article.');
        exit(0);
    }

    $packageName = $packages[0];
    $sourcePath = $readyRoot . '/' . $packageName;
    $processingPath = HAPPA_IMPORT_ROOT . '/inbox/processing/' . $packageName;
    if (!@rename($sourcePath, $processingPath)) {
        importerLog('PACKAGE_CLAIM_FAILED: package could not be moved from ready to processing; it may have been moved by another run.');
        exit(0);
    }
    importerLog('PACKAGE_CLAIMED: package moved from ready to processing: ' . $packageName . '.');

    $successes = array();
    try {
        importerLog('MANIFEST_VALIDATION_STARTED: validating article.json and image files for package ' . $packageName . '.');
        $manifest = validateManifest($processingPath);
        importerLog('MANIFEST_VALIDATION_SUCCEEDED: articles=' . count($manifest['articles']) . '; package=' . $packageName . '.');
        $credentials = readCredentials();
        bootstrapGeeklogSafely();
        authenticateGeeklog($credentials);
        foreach ($manifest['articles'] as $article) {
            preflightArticle($article, $processingPath, $_USER['uid']);
        }
        foreach ($manifest['articles'] as $index => $article) {
            $successes[] = importerPublishArticleInChild($article, $index, $packageName);
        }
        $importResult = array('status' => 'completed', 'articles' => $successes);
        if (importerAnnouncementRequested($manifest)) {
            // Every article is saved and verified (including draft=0 for publish=true) at this
            // point. The announcement's outcome never fails the import.
            $importResult['x_announcement'] = importerAnnounceAndLog($manifest, $_USER['uid'], $packageName);
        }
        file_put_contents($processingPath . '/import-result.json', json_encode($importResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        movePackage($processingPath, uniqueDestination(HAPPA_IMPORT_ROOT . '/inbox/done', $packageName));
        importerLog('Imported package ' . $packageName . '; articles=' . count($successes) . '; published=' . count(array_filter($successes, function ($item) { return $item['publish']; })) . '.');
    } catch (Throwable $error) {
        $safeMessage = $error->getMessage();
        @file_put_contents($processingPath . '/import-error.txt', $safeMessage . PHP_EOL . 'Completed before failure: ' . json_encode($successes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $failurePath = uniqueDestination(HAPPA_IMPORT_ROOT . '/inbox/failed', $packageName);
        if (is_dir($processingPath)) {
            @rename($processingPath, $failurePath);
        }
        importerLog('Package ' . $packageName . ' failed after ' . count($successes) . ' successful article(s): ' . $safeMessage);
        fwrite(STDERR, 'Package failed: ' . $safeMessage . PHP_EOL);
        exit(1);
    }
}

if (!defined('HAPPA_ARTICLE_LIBRARY_ONLY')) {
    $backlogRunner = __DIR__ . '/run-backlog.php';
    if (count($argv ?? array()) === 1 && is_file($backlogRunner) && glob(__DIR__ . '/backlog/ready/*.json')) {
        define('HAPPA_BACKLOG_LIBRARY_ONLY', true);
        require_once $backlogRunner;
        backlogMain();
    }
    main();
}
