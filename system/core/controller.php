<?php
class cmsController {

    public static $options_cache = array();

    public $name;
    public $title;
	public $model = null;
    public $request;
    public $current_action;
    public $current_params;

    protected $useOptions = false;

    function __construct($request){

        $config = cmsConfig::getInstance();

        $this->name = mb_strtolower(get_called_class());

        $this->root_url = $this->name;

        $this->root_path = $config->root_path . 'system/controllers/' . $this->name . '/';

        $this->request = $request;

        cmsCore::loadControllerLanguage($this->name);

        $title_constant = 'LANG_'.strtoupper($this->name).'_CONTROLLER';

        $this->title = defined($title_constant) ? constant($title_constant) : $this->name;

        if (cmsCore::isModelExists($this->name)){
            $this->model = cmsCore::getModel($this->name);
        }

        if ($this->useOptions){
            $this->options = $this->getOptions();
        }

    }

    public function setRootURL($root_url){
        $this->root_url = $root_url;
    }

//============================================================================//
//============================================================================//

    /**
     * Загружает и возвращает опции контроллера,
     * заполняя отсутсвующие из них значениями по-умолчанию
     * @return array
     */
    public function getOptions(){

        $options = self::loadOptions($this->name);

        $form = $this->getForm('options', false, 'backend/');
        
        if (!$form) { return array(); }

        $options = $form->parse(new cmsRequest($options));

        return $options;

    }

    /**
     * Загружает опции контроллера
     * @param string $controller_name
     * @return array
     */
    static function loadOptions($controller_name){

        if (isset(self::$options_cache[$controller_name])){
            return self::$options_cache[$controller_name];
        }

        $model = new cmsModel();

        $model->filterEqual('name', $controller_name);

        $options = $model->getFieldFiltered('controllers', 'options');

        if ($options){
            $options = cmsModel::yamlToArray($options);
            self::$options_cache[$controller_name] = $options;
        }

        return $options;

    }

    /**
     * Сохраняет опции контроллера
     * @param string $controller_name
     * @param array $options
     * @return boolean
     */
    static function saveOptions($controller_name, $options){

        $model = new cmsModel();

        $model->filterEqual('name', $controller_name);

        return $model->updateFiltered('controllers', array('options' => $options));

    }

//============================================================================//
//============================================================================//

    //
    // ХУКИ
    //

    /**
     * Вызывается до начала работы экшена
     */
    public function before($action_name){

        cmsTemplate::getInstance()->setContext($this);

        return true;

    }

    /**
     * Вызывается после работы экшена
     */
    public function after($action_name){

        cmsTemplate::getInstance()->restoreContext();

        return true;

    }

    /**
     * Вызывается до начала работы хука
     */
    public function beforeHook($event_name){

        if ($this->useOptions){
            $this->options = $this->getOptions();
        }

        return true;

    }

    /**
     * Вызывается после работы хука
     */
    public function afterHook($event_name){

        return true;

    }

//============================================================================//
//============================================================================//

    /**
     * Проверяет существование экшена
     * @param string $action_name
     * @return boolean
     */
    public function isActionExists($action_name){

        $method_name = 'action' . string_to_camel('_', $action_name);

        if(method_exists($this, $method_name)){
            return true;
        }

        $action_file = $this->root_path . 'actions/' . $action_name.'.php';

        if (file_exists($action_file)){
            return true;
        }

        return false;

    }

    /**
     * Находит и запускает требуемый экшен
     * @param string $action_name
     * @param array $params
     */
    public function runAction($action_name, $params = array()){

        if ($this->before($action_name) === false) { return false; }

        $this->current_params = $params;

        $action_name = $this->routeAction($action_name);

        $method_name = 'action' . string_to_camel('_', $action_name);

        // епроверяем наличие экшена его в отдельном файле
        $action_file = $this->root_path . 'actions/' . $action_name.'.php';

        if(file_exists($action_file)){

            // вызываем экшен из отдельного файла
            $result = $this->runExternalAction($action_name, $this->current_params);

        } else {

            // Если файла нет, ищем метод класса
            if (method_exists($this, $method_name)){

                // проверяем максимальное число аргументов экшена
                if ($this->name != 'admin'){
                    $rf = new ReflectionMethod($this, $method_name);
                    $max_params = $rf->getNumberOfParameters();
                    if ($max_params < count($this->current_params)) { cmsCore::error404(); }
                }
                
                // если есть нужный экшен, то вызываем его
                $result = call_user_func_array(array($this, $method_name), $this->current_params);

            } else {

                // если нет экшена в отдельном файле,
                // проверяем метод route()
                if(method_exists($this, 'route')){

                    $route_uri = $action_name;
                    if ($this->current_params) { $route_uri .= '/' . implode('/', $this->current_params); }
                    $result = call_user_func(array($this, 'route'), $route_uri);

                } else {

                    // если метода route() тоже нет,
                    // то 404
                    cmsCore::error404();

                }

            }

        }

        $this->after($action_name);
				
        return $result;

    }

//============================================================================//
//============================================================================//

    /**
     * Выполняет экшен, находящийся в отдельном файле ./actions/$action_name.php
     * @param str $action_name
     */
    public function runExternalAction($action_name, $params = array()){

        $action_file = $this->root_path . 'actions/'.$action_name.'.php';

        $class_name = 'action' . string_to_camel('_', $this->name) . string_to_camel('_', $action_name);

        include($action_file);

        // проверяем максимальное число аргументов экшена
        if ($this->name != 'admin'){
            $rf = new ReflectionMethod($class_name, 'run');
            $max_params = $rf->getNumberOfParameters();
            if ($max_params < count($params)) { cmsCore::error404(); }
        }
        
        $action_object = new $class_name($this, $params);
        
        $result = call_user_func_array(array($action_object, 'run'), $params);

        return $result;

    }

//============================================================================//
//============================================================================//

    /**
     * Находит и запускает хук для указанного события
     * @param string $event_name
     */
    public function runHook($event_name, $params = array()){

        if ($this->beforeHook($event_name) === false) { return false; }

        $method_name = 'on' . string_to_camel('_', $event_name);

        if(method_exists($this, $method_name)){

            // если есть нужный хук, то вызываем его
            $result = call_user_func_array(array($this, $method_name), $params);

        } else {

            // если метода хука нет, проверяем наличие его в отдельном файле
            $hook_file = $this->root_path . 'hooks/' . $event_name . '.php';

            if (file_exists($hook_file)){

                // вызываем хук из отдельного файла
                $result = $this->runExternalHook($event_name, $params);

            } else {

                // хука нет вообще, возвращаем данные запроса без изменений
                return $this->request->getData();

            }

        }

        $this->afterHook($event_name);

        return $result;

    }

//============================================================================//
//============================================================================//

    /**
     * Выполняет хук, находящийся в отдельном файле ./hooks/$event_name.php
     * @param str $event_name
     */
    public function runExternalHook($event_name, $params = array()){

        $class_name = 'on' . string_to_camel('_', $this->name) . string_to_camel('_', $event_name);

        if (!class_exists($class_name)){

            $hook_file = $this->root_path . 'hooks/' . $event_name . '.php';

            include_once $hook_file;

        }

        $hook_object = new $class_name($this);

        $result = call_user_func_array(array($hook_object, 'run'), $params);

        return $result;

    }

//============================================================================//
//============================================================================//

    /**
     * Загружает и возвращает описание структуры формы
     * @param type $form_name
     * @param type $params
     * @return cmsForm
     */
    public function getForm($form_name, $params=false, $path_prefix=''){

        $form_file = $this->root_path . $path_prefix . 'forms/form_' . $form_name . '.php';
        $form_name = $this->name . $form_name;

        return cmsForm::getForm($form_file, $form_name, $params);

    }

//============================================================================//
//============================================================================//

    /**
     * Загружает и возвращает описание структуры таблицы
     * @param string $grid_name
     */
    public function loadDataGrid($grid_name, $params = false){

        $default_options = array(
            'order_by' => 'id',
            'order_to' => 'asc',
            'show_id' => true,
            'is_auto_init' => true,
            'is_sortable' => true,
            'is_filter' => true,
            'is_actions' => true,
            'is_pagination' => true,
            'is_toolbar' => true,
            'is_draggable' => false,
            'is_selectable' => false
        );

        $grid_file = $this->root_path . 'grids/grid_' . $grid_name . '.php';

        if (!file_exists($grid_file)){ return false; }

        include($grid_file);

        $args = array($this);
        if ($params) {
            if (is_array($params)){ $args = array($this) + $params; }
            else { $args[] = $params; }
        }

        $grid = call_user_func_array('grid_'.$grid_name, $args);

        if (!isset($grid['options'])) {
            $grid['options'] = $default_options;
        } else {
            $grid['options'] = array_merge($default_options, $grid['options']);
        }

		$grid = cmsEventsManager::hook('grid_'.$this->name.'_'.$grid_name, $grid);
		
        return $grid;

    }

//============================================================================//
//============================================================================//

    public function loadRoutes(){

        $file = $this->root_path . 'routes.php';

        if (!file_exists($file)){ return array(); }

        include_once($file);

        $routes_func = 'routes_' . $this->name;

        $routes = call_user_func($routes_func);

        if (!is_array($routes)) { return array(); }

        return $routes;

    }

//============================================================================//
//============================================================================//

    public function halt() {
        die();
    }

//============================================================================//
//============================================================================//

    /**
     * Позволяет переопределить экшен перед вызовом
     * @param string $action_name
     * @return string
     */
    public function routeAction($action_name){

        return $action_name;

    }

//============================================================================//
//============================================================================//

    /**
     * Определяет экшен, по списку маршрутов из файла routes.php контроллера
     * @param string $uri
     * @return boolean
     */
    public function parseRoute($uri){

        $routes = $this->loadRoutes();

        // Флаг удачного перебора
		$is_found = false;

        // Название найденного экшена
        $action_name = false;

        //перебираем все маршруты
		if($routes){
			foreach($routes as $route){

				//сравниваем шаблон маршрута с текущим URI
				preg_match($route['pattern'], $uri, $matches);

				//Если найдено совпадение
				if ($matches){

                    $action_name = $route['action'];

					// удаляем шаблон и экшен из параметров маршрута,
                    // чтобы не мешали при переборе параметров запроса
					unset($route['pattern']);
					unset($route['action']);

					//перебираем параметры маршрута в виде ключ=>значение
					foreach($route as $key=>$value){
						if (is_integer($key)){

                            //Если ключ - целое число, то значением является сегмент URI
                            $this->request->set($value, $matches[$key]);

						} else {

							//иначе, значение берется из маршрута
                            $this->request->set($key, $value);

						}
					}

					// совпадение есть
					$is_found = true;

					//раз найдено совпадение, прерываем цикл
					break;

				}

			}
		}

		// Если в маршруте нет совпадений
		if(!$is_found) { return false; }

        return $action_name;

    }

//============================================================================//
//============================================================================//

    /**
     * Редирект на указанный адрес
     * @param str $url
     */
    public function redirect($url, $code=303){
        if ($code == 301){
            header('HTTP/1.1 301 Moved Permanently');
        } else {
            header('HTTP/1.1 303 See Other');
        }
        header('Location: '.$url);
        $this->halt();
    }

    /**
     * Редирект на главную страницу
     */
    public function redirectToHome(){
        $this->redirect(href_to_home());
    }


    /**
     * Редирект на другой контроллер
     * @param str $controller
     * @param str $action
     * @param array $params
     * @param array $query
     */
    public function redirectTo($controller, $action='', $params=array(), $query=array()){

        $config = cmsConfig::getInstance();
        $location = $config->root . $controller . '/' . $action;

        if ($params){ $location .= '/' . implode('/', $params); }
        if ($query){ $location .= '?' . http_build_query($query); }

        $this->redirect($location);

    }

    /**
     * Редирект на собственный экшен
     * @param str $controller
     * @param str $action
     * @param array $params
     * @param array $query
     */
    public function redirectToAction($action, $params=array(), $query=array()){

        if ($action=='index') {
            $location = $this->root_url;
        } else {
            $location = $this->root_url . '/' . $action;
        }

		if ($params){
			if (is_array($params)) { $location .= '/' . implode('/', $params); }
			else { $location .= '/' . $params; }
		}
		
        if ($query){ $location .= '?' . http_build_query($query); }

        $this->redirect(href_to($location));

    }

    /**
     * Возвращает предыдущий URL
     * @return str
     */
    public function getBackURL() {
        $config = cmsConfig::getInstance();
        if (!isset($_SERVER['HTTP_REFERER'])) { return $config->root; }
        return strlen($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
    }

    /**
     * Редирект на предыдущий URL
     */
    public function redirectBack(){
        $url = $this->getBackURL();
        header('Location: '.$url);
        $this->halt();
    }

//============================================================================//
//============================================================================//

    /**
     * Возвращает список субъектов к которым применяются права пользователей
     * @return array
     */
    public function getPermissionsSubjects(){
        return array(
            array(
                'name' => $this->name,
                'title' => $this->title
            )
        );
    }

//============================================================================//
//============================================================================//

    public function validate_required($value){
        if (empty($value)) { return ERR_VALIDATE_REQUIRED; }
        return true;
    }

    public function validate_min($min, $value){
        if ((int)$value < $min) { return sprintf(ERR_VALIDATE_MIN, $min); }
        return true;
    }

    public function validate_max($max, $value){
        if ((int)$value > $max) { return sprintf(ERR_VALIDATE_MAX, $max); }
        return true;
    }

    public function validate_min_length($length, $value){
        if (empty($value)) { return true; }
        if (mb_strlen($value)<$length) { return sprintf(ERR_VALIDATE_MIN_LENGTH, $length); }
        return true;
    }

    public function validate_max_length($length, $value){
        if (empty($value)) { return true; }
        if (mb_strlen($value)>$length) { return sprintf(ERR_VALIDATE_MAX_LENGTH, $length); }
        return true;
    }

    public function validate_array_key($array, $value){
        if (!isset($array[$value])) { return ERR_VALIDATE_INVALID; }
        return true;
    }

    public function validate_array_keys($array, $values){
		if (empty($values)) { return true; }
		foreach($values as $value){
			if (!isset($array[$value])) { return ERR_VALIDATE_INVALID; }
		}
        return true;
    }

    public function validate_in_array($array, $value){
        if (empty($value)) { return true; }
        if (!in_array($value, $array)) { return ERR_VALIDATE_INVALID; }
        return true;
    }

    public function validate_email($value){
        if (empty($value)) { return true; }
        if (!preg_match("/^([a-zA-Z0-9\._-]+)@([a-zA-Z0-9\._-]+)\.([a-zA-Z]{2,6})$/i", $value)){ return ERR_VALIDATE_EMAIL; }
        return true;
    }

    public function validate_alphanumeric($value){
        if (empty($value)) { return true; }
        if (!preg_match("/^([a-zA-Z0-9]*)$/i", $value)){ return ERR_VALIDATE_ALPHANUMERIC; }
        return true;
    }

    public function validate_sysname($value){
        if (empty($value)) { return true; }
        if (!preg_match("/^([a-zA-Z0-9\_]*)$/i", $value)){ return ERR_VALIDATE_SYSNAME; }
        return true;
    }

    public function validate_digits($value){
        if (empty($value)) { return true; }
        if (!preg_match("/^([0-9]+)$/i", $value)){ return ERR_VALIDATE_DIGITS; }
        return true;
    }

    public function validate_number($value){
        if (empty($value)) { return true; }
        if (!preg_match("/^([\-]?)([0-9\.,]+)$/i", $value)){ return ERR_VALIDATE_NUMBER; }
        return true;
    }

    public function validate_regexp($regexp, $value){
        if (empty($value)) { return true; }
        if (!preg_match($regexp, $value)){ return ERR_VALIDATE_REGEXP; }
        return true;
    }

    public function validate_unique($table_name, $field_name, $value){
        if (empty($value)) { return true; }
        $core = cmsCore::getInstance();
        $result = $core->db->isFieldUnique($table_name, $field_name, $value);
        if (!$result) { return ERR_VALIDATE_UNIQUE; }
        return true;
    }

    public function validate_unique_exclude($table_name, $field_name, $exclude_row_id, $value){
        if (empty($value)) { return true; }
        $core = cmsCore::getInstance();
        $result = $core->db->isFieldUnique($table_name, $field_name, $value, $exclude_row_id);
        if (!$result) { return ERR_VALIDATE_UNIQUE; }
        return true;
    }

    public function validate_unique_ctype_field($ctype_name, $value){
        if (empty($value)) { return true; }
        $core = cmsCore::getInstance();
        $content_model = cmsCore::getModel('content');
        $table_name = $content_model->table_prefix . $ctype_name;
        $result = !$core->db->isFieldExists($table_name, $value);
        if (!$result) { return ERR_VALIDATE_UNIQUE; }
        return true;        
    }

    public function validate_unique_ctype_dataset($ctype_id, $value){
        if (empty($value)) { return true; }
        $core = cmsCore::getInstance();
        $ctype_id = (int)$ctype_id;
        $value = $core->db->escape($value);
        $result = !$core->db->getRow('content_datasets', "ctype_id='{$ctype_id}' AND name='{$value}'");
        if (!$result) { return ERR_VALIDATE_UNIQUE; }
        return true;
    }

//============================================================================//
//============================================================================//

}