<?php

class cmsCore {

    private static $instance;

	public $uri            = '';
    public $uri_absolute   = '';
    public $uri_controller = '';
    public $uri_action     = '';
    public $uri_params     = array();
    public $uri_query      = array();

    public $controller = '';

	public $link;
	public $request;

    public $db;

    private static $includedFiles = array();

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function __construct(){

        $this->request = new cmsRequest($_REQUEST);

    }

//============================================================================//
//============================================================================//

    /**
     * Возвращает информацию о версии ядра
     * @return type
     */
    public static function getVersion($show_date=false){

        $version = self::getVersionArray();

        if (!$show_date && isset($version['date'])) { unset($version['date']); }

        return implode('.', $version);

    }

    /**
     * Возвращает информацию о версии ядра
     * в виде массива с ключами:
     *  - major
     *  - minor
     *  - build
     *  - date
     * @return type
     */
    public static function getVersionArray(){

        $file = cmsConfig::get('root_path') . 'system/config/version.ini';

        if (!file_exists($file)){ die('version.ini not found'); }

        $version = parse_ini_file($file);

        return $version;

    }

//============================================================================//
//============================================================================//

    public static function isWritable($path, $is_force_mkdir=true) {

        if ($is_force_mkdir && (!file_exists($path))) { @mkdir($path); }

        return (is_writable($path));

    }

//============================================================================//
//============================================================================//

    /**
     * Подключает файл
     * @param string $file Путь относительно корня сайта без начального слеша
     * @return boolean
     */
    public static function includeFile($file) {

        $file = cmsConfig::get('root_path') . $file;

        if (isset(self::$includedFiles[$file])){
            return self::$includedFiles[$file];
        }

        if (!file_exists($file)){
            self::$includedFiles[$file] = false;
            return false;
        }

        $result = include_once $file;

        if (is_null($result)) { $result = true; }

        self::$includedFiles[$file] = $result;

        return $result;

    }

    public static function requireFile($file) {

        $file = cmsConfig::get('root_path') . $file;

        if (!file_exists($file)){ return false; }

        $result = require $file;

        if (is_null($result)) { $result = true; }

        return $result;

    }

    public static function includeAndCall($file, $function_name, $params=array()){

        if (!self::includeFile($file)){ return false; }

        if (!function_exists($function_name)){ return false; }

        return call_user_func_array($function_name, $params);

    }

//============================================================================//
//============================================================================//

    /**
     * Загружает внешнюю библиотеку из папки /system/libs
     *
     * @param string $library Название библиотеки в /system/libs (без расширения)
     * @param string $class Название загружаемого класса (для предотвращения повторной загрузки)
     */
     public static function loadLib($library, $class=false){

        if ($class && class_exists($class, false)){ return true; }

        $config = cmsConfig::getInstance();

        $lib_file = $config->root_path.'system/libs/'.$library.'.php';

        if (!file_exists($lib_file)){ self::error(ERR_LIBRARY_NOT_FOUND . ': '. $library); }

        include_once $lib_file;

        return true;

    }

//============================================================================//
//============================================================================//

    /**
     * Загружает класс ядра из папки /system/core
     * @param string $class
     */
    public static function loadCoreClass($class){

        $class_file = cmsConfig::get('root_path') . 'system/core/'.$class.'.class.php';

        if (!file_exists($class_file)){
            self::error(ERR_CLASS_NOT_FOUND . ': '. $class);
        }

        include_once $class_file;

        return true;

    }

//============================================================================//
//============================================================================//

    /**
     * Проверяет существование модели
     * @param str $controller Название контроллера
     * @return bool
     */
    public static function isModelExists($controller){

        $model_file = cmsConfig::get('root_path').'system/controllers/'.$controller.'/model.php';

        return file_exists($model_file);

    }

    /**
     * Возвращает объект модели из указанного файла (без расширения)
     * @param string $controller Контроллер модели
     * @param string $delimitter Разделитель слов в названии класса
     */
    public static function getModel($controller, $delimitter='_'){

        $model_class = 'model' . string_to_camel($delimitter, $controller);

        if (!class_exists($model_class)) {

            $model_file = cmsConfig::get('root_path').'system/controllers/'.$controller.'/model.php';

            if (file_exists($model_file)){
                include_once($model_file);
            } else {
                self::error(ERR_MODEL_NOT_FOUND . ': '. $model_file);
            }

        }

        $model = new $model_class();

        return $model;

    }

//============================================================================//
//============================================================================//

    /**
     * Проверяет существования компонента
     * @param type $controller_name
     * @return type
     */
    public static function isControllerExists($controller_name){

        $ctrl_file = cmsConfig::get('root_path') . 'system/controllers/'.$controller_name;

        return file_exists($ctrl_file);

    }

    /**
     * Создает и возвращает объект контроллера
     * @param str $controller_name
     * @param cmsRequest $request
     * @return controller_class
     */
    public static function getController($controller_name, $request=null){

        $config = cmsConfig::getInstance();

        $ctrl_file = $config->root_path . 'system/controllers/'.$controller_name.'/frontend.php';

        if(!file_exists($ctrl_file)){
            $controller_name = $config->ct_default;
            self::getInstance()->controller = $config->ct_default;
            $ctrl_file = $config->root_path . 'system/controllers/'.$controller_name.'/frontend.php';
        }

        if (!class_exists($controller_name)) {
            include_once($ctrl_file);
        }

        $custom_file = $config->root_path . 'system/controllers/'.$controller_name.'/custom.php';

        if(!file_exists($custom_file)){
            $controller_class = $controller_name;
        } else {
            $controller_class = $controller_name . '_custom';
            if (!class_exists($controller_class)){
                include_once($custom_file);
            }
        }

        if (!$request) { $request = new cmsRequest(array(), cmsRequest::CTX_INTERNAL); }

        $controller = new $controller_class($request);

        return $controller;

    }

    public static function getControllerNameByAlias($controller_alias){

        $mapping = cmsConfig::getControllersMapping();

        if (!$mapping) { return false; }

        foreach($mapping as $name=>$alias){
            if ($alias == $controller_alias) { return $name; }
        }

        return false;

    }

    public static function getControllerAliasByName($controller_name){

        $mapping = cmsConfig::getControllersMapping();

        if (!$mapping || !isset($mapping[$controller_name])){ return false; }

        return $mapping[$controller_name];

    }

    public static function getControllersManifests(){

        $manifests = array();

        $controllers = self::getDirsList('system/controllers');

        foreach($controllers as $controller_name){

            $manifest_file = cmsConfig::get('root_path') . 'system/controllers/' . $controller_name . '/manifest.php';

            if (!file_exists($manifest_file)){ continue; }

            $manifest = include $manifest_file;

            if (!$manifest) { continue; }

            $manifests[ $controller_name ] = $manifest;

        }

        return $manifests;

    }

//============================================================================//
//============================================================================//

    public static function getWidgetPath($widget_name, $controller_name=false){

        if ($controller_name){
            $path = "controllers/{$controller_name}/widgets/{$widget_name}";
        } else {
            $path = "widgets/{$widget_name}";
        }

        return $path;

    }

    public static function getWidgetOptionsForm($widget_name, $controller_name=false, $options=false){

		$widget_path = self::getWidgetPath($widget_name, $controller_name);

        $path = cmsConfig::get('system_path') . $widget_path;

        $form_file = $path . '/options.form.php';

        $form_name = 'widget' . ($controller_name ? "_{$controller_name}_" : '_') . "{$widget_name}_options";

        $form = cmsForm::getForm($form_file, $form_name, array($options));

        if (!$form) { $form = new cmsForm(); }

        $form->is_tabbed = true;

		//
		// Опции внешнего вида
		//
		$design_fieldset_id = $form->addFieldset(LANG_DESIGN);

            $form->addField($design_fieldset_id, new fieldString('class_wrap', array(
                'title' => LANG_CSS_CLASS_WRAP,
            )));

            $form->addField($design_fieldset_id, new fieldString('class_title', array(
                'title' => LANG_CSS_CLASS_TITLE,
            )));

            $form->addField($design_fieldset_id, new fieldString('class', array(
                'title' => LANG_CSS_CLASS_BODY,
            )));

            $form->addField($design_fieldset_id, new fieldString('tpl_wrap', array(
                'title' => LANG_WIDGET_WRAPPER_TPL,
				'hint' => LANG_WIDGET_WRAPPER_TPL_HINT
            )));

            $form->addField($design_fieldset_id, new fieldString('tpl_body', array(
                'title' => LANG_WIDGET_BODY_TPL,
				'hint' => sprintf(LANG_WIDGET_BODY_TPL_HINT, $widget_path)
            )));

        //
        // Опции доступа
        //
        $access_fieldset_id = $form->addFieldset(LANG_PERMISSIONS);

            // Показывать группам
            $form->addField($access_fieldset_id, new fieldListGroups('groups_view', array(
                'title' => LANG_SHOW_TO_GROUPS,
                'show_all' => true,
                'show_guests' => true,
            )));

            // Не показывать группам
            $form->addField($access_fieldset_id, new fieldListGroups('groups_hide', array(
                'title' => LANG_HIDE_FOR_GROUPS,
                'show_all' => false,
                'show_guests' => true,
            )));

        //
        // Опции заголовка
        //
        $title_fieldset_id = $form->addFieldsetToBeginning(LANG_BASIC_OPTIONS);

            // ID виджета
            $form->addField($title_fieldset_id, new fieldNumber('id', array(
                'is_hidden'=>true
            )));

            // Заголовок виджета
            $form->addField($title_fieldset_id, new fieldString('title', array(
                'title' => LANG_TITLE,
                'rules' => array(
                    array('required'),
                    array('min_length', 3),
                    array('max_length', 128),
                )
            )));

            // Флаг показа заголовка
            $form->addField($title_fieldset_id, new fieldCheckbox('is_title', array(
                'title' => LANG_SHOW_TITLE,
                'default' => true
            )));

            // Флаг объединения с предыдущим виджетом
            $form->addField($title_fieldset_id, new fieldCheckbox('is_tab_prev', array(
                'title' => LANG_WIDGET_TAB_PREV,
            )));

            // Ссылки в заголовке
            $form->addField($title_fieldset_id, new fieldText('links', array(
                'title' => LANG_WIDGET_TITLE_LINKS,
                'hint' => LANG_WIDGET_TITLE_LINKS_HINT,
            )));

		return $form;

    }

//============================================================================//
//============================================================================//

    /**
     * Подключает указанный языковой файл.
     * Если файл не указан, то подключаются все PHP-файлы из папки текущего языка
     *
     * @param string $file
     * @return bool
     */
    public static function loadLanguage($file=false){

        $config = cmsConfig::getInstance();

        $lang_dir = 'system/languages/'. $config->language;

        if (!$file){

            // Если файл не указан, то подключаем все php-файлы
            // из папки с текущим языком
            return self::getFilesList($lang_dir, '*.php', true, true);

        } else {

            // Если файл указан, то подключаем только его
            $lang_file = $lang_dir .'/'.$file.'.php';
            return self::includeFile($lang_file);

        }

    }

    /**
     * Возвращает содержимое текстового файла из папки с текущим языком
     * @param string $file
     * @return string
     */
    public static function getLanguageTextFile($file){

        $config = cmsConfig::getInstance();

        if (!isset($config->language)){	$config->language = 'ru'; }

        $lang_dir = $config->root_path . 'system/languages/'. $config->language;

        $lang_file = $lang_dir .'/' . $file . '.txt';

        if (!file_exists($lang_file)) { return false; }

        return file_get_contents($lang_file);

    }

    /**
     * Подключает языковой файл контроллера
     * @param string $controller_name
     * @return bool
     */
    public static function loadControllerLanguage($controller_name){
        return self::loadLanguage("controllers/{$controller_name}/{$controller_name}");
    }

    /**
     * Подключает языковой файл виджета
     * @param string $widget_name
     * @param string $controller_name
     * @return bool
     */
    public static function loadWidgetLanguage($widget_name, $controller_name=false){

        $path = self::getWidgetPath($widget_name, $controller_name);

        return self::loadLanguage($path);

    }

    /**
     * Подключает языковой файл шаблона
     * @param string $template_name
     * @return bool
     */
    public static function loadTemplateLanguage($template_name){
        return self::loadLanguage("templates/{$template_name}");
    }

    /**
     * Подключает языковые файлы всех контроллеров
     *
     * @param string $file
     * @return bool
     */
    public static function loadAllControllersLanguages(){

        $controllers = self::getDirsList('system/controllers');

        foreach($controllers as $controller_name){
            self::loadControllerLanguage($controller_name);
        }

    }

//============================================================================//
//============================================================================//

    /**
     * Определяет контроллер, действие и параметры для запуска по полученному URI
     * @param string $uri
     */
    public function route($uri){

		$config = cmsConfig::getInstance();

        $uri = trim(urldecode($uri));
		$uri = mb_substr($uri, mb_strlen( $config->root ));

        if (!$uri) { return; }

        // если в URL присутствует знак вопроса, значит есть
        // в нем есть GET-параметры которые нужно распарсить
        // и добавить в массив $_REQUEST
        $pos_que  = mb_strpos($uri, '?');
        if ($pos_que !== false){

            // получаем строку запроса
            $query_data = array();
            $query_str  = mb_substr($uri, $pos_que+1);

            // удаляем строку запроса из URL
            $uri = mb_substr($uri, 0, $pos_que);

            // парсим строку запроса
            parse_str($query_str, $query_data);

            $this->uri_query = $query_data;

            // добавляем к полученным данным $_REQUEST
            // именно в таком порядке, чтобы POST имел преимущество над GET
            $_REQUEST = array_merge($query_data, $_REQUEST);

        }

        $this->uri = $uri;
        $this->uri_absolute = $config->root . $uri;

        // разбиваем URL на сегменты
        $segments = explode('/', $uri);

        // Определяем контроллер из первого сегмента
        if (isset($segments[0])) { $this->uri_controller = $segments[0]; }

        // Определяем действие из второго сегмента
        if (isset($segments[1])) { $this->uri_action = $segments[1]; }

        // Определяем параметры действия из всех остальных сегментов
        if (sizeof($segments)>2){
            $this->uri_params = array_slice($segments, 2);
        }

        return true;

    }

//============================================================================//
//============================================================================//

    /**
     * Запускает выбранное действие контроллера
     */
    public function runController(){

        $config = cmsConfig::getInstance();

        // контроллер и экшен по-умолчанию
        if (!$this->uri_controller){ $this->uri_controller = $config->ct_autoload;	}
        if (!$this->uri_action) { $this->uri_action = 'index'; }

        // проверяем ремаппинг контроллера
        $remap_to = self::getControllerNameByAlias($this->uri_controller);
        if ($remap_to) { $this->uri_controller = $remap_to; }

        $this->controller = $this->uri_controller;

        // загружаем контроллер
        $controller = self::getController($this->uri_controller, $this->request);

        // сохраняем в контроллере название текущего экшена
        $controller->current_action = $this->uri_action;

        // запускаем действие
        $controller->runAction($this->uri_action, $this->uri_params);

    }

//============================================================================//
//============================================================================//

    /**
     * Запускает все виджеты, привязанные к текущей странице
     */
    public function runWidgets(){

        $widgets_model = cmsCore::getModel('widgets');
        $pages = $widgets_model->getPages();

        $matched_pages = $this->detectMatchedWidgetPages($pages);

        if (!is_array($matched_pages)) { return; }
        if (sizeof($matched_pages)==0) { return; }

        $widgets_list = $widgets_model->getWidgetsForPages($matched_pages);

        if (is_array($widgets_list)){
            foreach ($widgets_list as $widget){
                $this->runWidget($widget);
            }
        }

    }

    public function runWidget($widget){

        $user = cmsUser::getInstance();

        $is_user_view = $user->isInGroups($widget['groups_view']);
        $is_user_hide = !empty($widget['groups_hide']) && $user->isInGroups($widget['groups_hide']) && !$user->is_admin;

        if ($is_user_hide) { return false; }
        if (!$is_user_view) { return false; }

        $path = 'system/' . cmsCore::getWidgetPath( $widget['name'], $widget['controller'] );
        $file = $path . '/widget.php';

        cmsCore::includeFile($file);
        cmsCore::loadWidgetLanguage($widget['name'], $widget['controller']);

        $class = 'widget' .
                    ($widget['controller'] ? string_to_camel('_', $widget['controller']) : '') .
                    string_to_camel('_', $widget['name']);

        $widget_object = new $class($widget);

        $cache_key = "widgets.{$widget['id']}";
        $cache = cmsCache::getInstance();

        if (!$widget_object->isCacheable() || false === ($result = $cache->get($cache_key))){
            $result = call_user_func_array(array($widget_object, 'run'), array());
            if ($result){
                // Отдельно кешируем имя шаблона виджета, поскольку оно могло быть
                // изменено внутри виджета, а в кеш у нас попадает только тот массив
                // который возвращается кодом виджета (без самих свойств $widget_object)
                $result['_wd_template'] = $widget_object->getTemplate();
            }
            $cache->set($cache_key, $result);
        }

        if ($result===false) { return false; }

        if (isset($result['_wd_template'])) { $widget_object->setTemplate($result['_wd_template']); }

        cmsTemplate::getInstance()->renderWidget($widget_object, $result);

    }

    /**
     * Определяет какие из списка страниц виджетов
     * совпадают по маске с текущей страницей
     *
     * @param type $pages
     * @return type
     */
    private function detectMatchedWidgetPages($pages){

        if ($this->uri == '') {
            return array(0, 1);
        }

        $matched_pages = array(0);

        //
        // Перебираем все точки привязок и проверяем совпадение
        // маски URL с текущим URL
        //
        foreach($pages as $page){

            if (empty($page['url_mask'])) { continue; }

            $is_mask_match = false;
            $is_stop_match = false;

            foreach($page['url_mask'] as $mask){
                $regular = string_mask_to_regular($mask);
                $regular = "/^{$regular}$/iu";
                $is_mask_match = $is_mask_match || preg_match($regular, $this->uri);
            }

            if (!empty($page['url_mask_not'])) {
                foreach($page['url_mask_not'] as $mask){
                    $regular = string_mask_to_regular($mask);
                    $regular = "/^{$regular}$/iu";
                    $is_stop_match = $is_stop_match || preg_match($regular, $this->uri);
                }
            }

            if ($is_mask_match && !$is_stop_match){
                $matched_pages[] = $page['id'];
            }

        }

        return $matched_pages;

    }

//============================================================================//
//============================================================================//

    /**
     * Показывает сообщение об ошибке и завершает работу
     * @param string $message
     */
    public static function error($message, $details=''){

        $config = cmsConfig::getInstance();

        if ($config->debug){
            cmsTemplate::getInstance()->renderAsset('errors/error', array(
                'message'=>$message,
                'details'=>$details
            ));
        }

        die();

    }

    /**
     * Показывает сообщение об ошибке 404 и завершает работу
     */
    public static function error404(){

		cmsEventsManager::hook('error_404', self::getInstance()->uri);

        header("HTTP/1.0 404 Not Found");
        header("HTTP/1.1 404 Not Found");
        header("Status: 404 Not Found");

        if(ob_get_length()) { ob_end_clean(); }

        cmsTemplate::getInstance()->renderAsset('errors/notfound');
        die();

    }

    /**
     * Показывает сообщение о том что сайт отключен и завершает работу
     */
    public static function errorMaintenance(){

        if(ob_get_length()) { ob_end_clean(); }

        cmsTemplate::getInstance()->renderAsset('errors/offline', array(
            'reason' => cmsConfig::get('off_reason')
        ));
        die();

    }

    /**
     * Показывает сообщение об ошибке 403 и завершает работу
     */
    public static function errorForbidden(){

        $config = cmsConfig::getInstance();

        header("HTTP/1.0 403 Forbidden");
        header("Status: 403 Forbidden");

        include	($config->root_path . 'templates/' . $config->template .'/system/forbidden.tpl.php');
        die();

    }

//============================================================================//
//============================================================================//

    /**
     * Возвращает массив со списком всех шаблонов
     * @return array
     */
    public static function getTemplates(){

        return self::getDirsList('templates');

    }

    /**
     * Возвращает массив со списком всех языков
     * @return array
     */
    public static function getLanguages(){

        return self::getDirsList('system/languages');

    }

    /**
     * Возвращает массив со списком всех визуальных редакторов
     * @return array
     */
    public static function getWysiwygs(){

        return self::getDirsList('wysiwyg');

    }

//============================================================================//
//============================================================================//

    /**
     * Возвращает список директорий внутри указанной
     * @param string $root_dir
     * @return array
     */
    public static function getDirsList($root_dir){

        $dir = cmsConfig::get('root_path') . $root_dir;
        $dir_context = opendir($dir);

        $list = array();

        while ($next = readdir($dir_context)){

            if (in_array($next, array('.', '..'))){ continue; }
            if (strpos($next, '.') === 0){ continue; }
            if (!is_dir($dir.'/'.$next)) { continue; }

            $list[] = $next;

        }

        return $list;

    }

    /**
     * Возвращает список файл из указанной директории по нужной маске
     * @param string $root_dir Директория
     * @param string $pattern Маска файлов
     * @param bool $is_strip_ext Отрезать расширения?
     * @param bool $is_include Подключать каждый файл?
     * @return array
     */
    public static function getFilesList($root_dir, $pattern='*.*', $is_strip_ext=false, $is_include=false){

        $config = cmsConfig::getInstance();

        $directory = $config->root_path . $root_dir;
        $pattern = $directory . '/' . $pattern;

        $list = array();

        $files = @glob($pattern);

        if (!$files) { return $list; }

        foreach ($files as $file) {

            if ($is_include && !isset(self::$includedFiles[$file])) {
                include_once $file;
                self::$includedFiles[$file] = true;
            }

            $file = basename($file);

            if ($is_strip_ext){ $file = pathinfo($file, PATHINFO_FILENAME); }

            $list[] = $file;

        }

        return $list;

    }

//============================================================================//
//============================================================================//

    /**
     * Устанавливает соединение с БД
     *
     */
    public function connectDB(){
        $this->db = cmsDatabase::getInstance();
    }

//============================================================================//
//============================================================================//

    public static function getTimeZones(){
        self::loadLib('timezones');
        $zones = array();
        foreach(getTimeZones() as $zone){
            $zones[ $zone ] = $zone;
        }
        return $zones;
    }

//============================================================================//
//============================================================================//

}
