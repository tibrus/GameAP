<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Dedicated_servers extends CI_Model {
	
	var $ds_list = array();				// Список удаленных серверов
	
	//~ var $commands; 							// Команды, которые отправлялись на сервер
    var $errors; 							// Строка с ошибкой (если имеются)
    
    private $_commands = array();
    private $_errors	= false;
    
    //-----------------------------------------------------------

    public function __construct()
	{
		parent::__construct();
	}
	
	//-----------------------------------------------------------
	
	/**
     * Шифровка паролей
     * 
     * @param array
     * @return bool
     *
    */
	function _encrypt_passwords($data) {
		
		$this->load->library('encrypt');
		
		if (isset($data['ssh_login'])) {
			$data['ssh_login']	= $this->encrypt->encode($data['ssh_login']);
			if ($data['ssh_password'] == '') {
				unset($data['ssh_password']);
			} else {
				$data['ssh_password']	= $this->encrypt->encode($data['ssh_password']);
			}
		}

		if (isset($data['telnet_login'])) {
			$data['telnet_login']	= $this->encrypt->encode($data['telnet_login']);
			if ($data['telnet_password'] == '') {
				unset($data['telnet_password']);
			} else {
				$data['telnet_password']	= $this->encrypt->encode($data['telnet_password']);
			}
		}
		
		if (isset($data['ftp_login'])) {
			$data['ftp_login']	= $this->encrypt->encode($data['ftp_login']);
			if ($data['ftp_password'] == '') {
				unset($data['ftp_password']);
			} else {
				$data['ftp_password']	= $this->encrypt->encode($data['ftp_password']);
			}
		}
		
		return $data;
	}
	
	//-----------------------------------------------------------
    
    /*
     * Проверяет директорию на необходимые права
    */
	private function _check_path($path) {
		
		if (!is_dir($path)) {
			/* Это не директория */
			$this->errors = "Dir " . $path . " not found";
			return false;
		}

		return true;
	}
	
	//-----------------------------------------------------------
	
	/**
     * Добавление выделенного сервера
     * 
     * @param array
     * @return bool
     *
    */
	function add_dedicated_server($data)
	{
		$data = $this->_encrypt_passwords($data);
		
		if ($this->db->insert('dedicated_servers', $data)) {
			return true;
		} else {
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение списка отправленных команд
     * 
     * УСТАРЕЛА! В 1.0 версии будет удалена, используйте хелпер ds
    */
	function get_sended_commands($last_command = false)
	{
		$this->load->helper('ds');
		return get_sended_commands($last_command);
	}
	
	
	//-----------------------------------------------------------
	
	/**
     * Удаление выделенного сервера
     * 
     * @param array
     * @return bool
     *
    */
	function del_dedicated_server($id)
	{
		if($this->db->delete('dedicated_servers', array('id' => $id))){
			return true;
		}else{
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение списка удаленных сервров (машин)
     * 
     * @param array - условия для выборки
     * @param int
     * 
     * @return array
     *
    */
    function get_ds_list($where = false, $limit = 99999)
    {
		$this->load->library('encrypt');

		/*
		 * В массиве $where храняться данные для выборки.
		 * Например:
		 * 		$where = array('id' => 1);
		 * в этом случае будет выбран сервер id которого = 1
		 * 
		*/

		if(is_array($where)){
			$query = $this->db->get_where('dedicated_servers', $where, $limit);
		}else{
			$query = $this->db->get('dedicated_servers');
		}

		if($query->num_rows > 0) {
			
			$this->ds_list = $query->result_array();
			
			/* Выполняем необходимые действия с данными
			 * Расшифровываем пароли, преобразуем списки из json в понятный массив */
			$i = 0;
			$count_ds_list = count($this->ds_list);
			while($i < $count_ds_list) {
				
				$ds_ip = $this->ds_list[$i]['ip'];
				if (!$this->ds_list[$i]['ip'] = json_decode($ds_ip, true)) {
					/* Строка с данными не является json, в этом случае присваиваем первому
					 * массиву значение этой строки
					 * Сделано для совместимости со старыми версиями после обновления
					*/
					$this->ds_list[$i]['ip'] = array();
					$this->ds_list[$i]['ip'][] = $ds_ip;
				}
				unset($ds_ip);
				
				$this->ds_list[$i]['control_ip'] 		= 'localhost';
				$this->ds_list[$i]['control_port'] 		= 0;
				$this->ds_list[$i]['control_login']		= '';
				$this->ds_list[$i]['control_password']	= '';
				
				$this->ds_list[$i]['ssh_login']			= $this->encrypt->decode($this->ds_list[$i]['ssh_login']);
				$this->ds_list[$i]['ssh_password']		= $this->encrypt->decode($this->ds_list[$i]['ssh_password']);
				
				$this->ds_list[$i]['telnet_login']		= $this->encrypt->decode($this->ds_list[$i]['telnet_login']);
				$this->ds_list[$i]['telnet_password']	= $this->encrypt->decode($this->ds_list[$i]['telnet_password']);
				
				$this->ds_list[$i]['ftp_login']			= $this->encrypt->decode($this->ds_list[$i]['ftp_login']);
				$this->ds_list[$i]['ftp_password']		= $this->encrypt->decode($this->ds_list[$i]['ftp_password']);
				
				if (!in_array(strtolower($this->ds_list[$i]['control_protocol']), array('ssh', 'telnet', 'local'))) {
					switch($this->ds_list[$i]['os']) {
						case 'windows':
							$this->ds_list[$i]['control_protocol'] = 'telnet';
							break;
						
						default:
							$this->ds_list[$i]['control_protocol'] = 'ssh';
							break;
					}
				}
				
				switch(strtolower($this->ds_list[$i]['control_protocol'])) {
					case 'ssh':
						$this->ds_list[$i]['local_server'] 	= false;
						$this->ds_list[$i]['script_path'] = $this->ds_list[$i]['ssh_path'];
						
						$explode = explode(':', $this->ds_list[$i]['ssh_host']);
						$this->ds_list[$i]['control_ip'] 		= $explode[0];
						$this->ds_list[$i]['control_port'] 		= isset($explode[1]) ? $explode[1] : 22;

						$this->ds_list[$i]['control_login']			= $this->ds_list[$i]['ssh_login'];
						$this->ds_list[$i]['control_password'] 		= $this->ds_list[$i]['ssh_password'];
						
						break;
						
					case 'telnet':
						$this->ds_list[$i]['local_server'] 	= false;
						$this->ds_list[$i]['script_path'] = $this->ds_list[$i]['telnet_path'];
						
						$explode = explode(':', $this->ds_list[$i]['telnet_host']);
						$this->ds_list[$i]['control_ip'] 		= $explode[0];
						$this->ds_list[$i]['control_port'] 		= isset($explode[1]) ? $explode[1] : 23;

						$this->ds_list[$i]['control_login']			= $this->ds_list[$i]['telnet_login'];
						$this->ds_list[$i]['control_password'] 		= $this->ds_list[$i]['telnet_password'];

						break;
					
					default:
						$this->ds_list[$i]['local_server'] 	= true;
						$this->ds_list[$i]['script_path'] 	= $this->ds_list[$i]['ssh_path'];
						break;
				}

				$i ++;
			}
			
			return $this->ds_list;
			
		}else{
			return array();
		}
	}
	
	// ----------------------------------------------------------------
    
    /**
     * Проверяет, существует ли выделенный сервер с данным id
     * Параметру id может быть передан id сервера, либо массив where
     * 
     * @return bool
    */  
    function ds_live($id = false) 
    {
		if (false == $id) {
			return false;
		}

		if (is_array($id)) {
			$this->db->where($id);
		} else {
			$this->db->where(array('id' => $id));
		}

        if ($this->db->count_all_results('dedicated_servers') > 0) {
            return true;
        } else {
            return false;
        }
        
    }
    
    // ----------------------------------------------------------------
    
    /**
     * Получает данные выделенного сервера
     * 
     * @return bool
    */  
    function get_ds_data($id = false) 
    {
		if (false == $id) {
			return false;
		}
		
		$where = array('id' => $id);
		$this->get_ds_list($where, 1);
		
		if (isset($this->ds_list[0])) {
			return $this->ds_list[0];
		} else {
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Редактирование выделенного сервера
     * 
     * @param id - id сервера
     * @param array - новые данные
     * @return bool
     *
    */
	function edit_dedicated_server($id, $data)
	{
		$data = $this->_encrypt_passwords($data);
		
		$this->db->where('id', $id);

		if($this->db->update('dedicated_servers', $data)){
			return true;
		}else{
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Обновляет поле с данными для модулей
     * 
     * @param id 	 	id сервера
     * @param array 	новые данные
     * @param string	имя модуля
     * @return bool
     *
    */
	function update_modules_data($id, $data, $module_name)
	{
		$ds_data = $this->get_ds_data($id);
		
		$modules_data_array = json_decode($ds_data['modules_data'], true);
		$modules_data_array[$module_name] = $data;
		$modules_data_json = json_encode($modules_data_array);
		
		$sql_data['modules_data'] = $modules_data_json;
		
		if ($this->edit_dedicated_server($id, $sql_data)) {
			return true;
		} else {
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение данных выделенного сервера для шаблона
     * (вырезаны ненужные данные - пароли и пр.)
     * 
     *
    */
	function tpl_data_ds()
    {
		$num = -1;
		
		if(!$this->ds_list){
			$this->get_ds_list();
		}
		
		if ($this->ds_list) {
		
			foreach ($this->ds_list as $dedicated_servers) {
				$num++;
				
				$tpl_data[$num]['ds_name'] = $dedicated_servers['name'];
				$tpl_data[$num]['ds_location'] = $dedicated_servers['location'];
				$tpl_data[$num]['ds_provider'] = $dedicated_servers['provider'];
				$tpl_data[$num]['ds_os'] = $dedicated_servers['os'];
				$tpl_data[$num]['ds_ram'] = $dedicated_servers['ram'];
				$tpl_data[$num]['ds_cpu'] = $dedicated_servers['cpu'];
				$tpl_data[$num]['ds_id'] = $dedicated_servers['id'];
				
				/* Список IP адресов */
				$tpl_data[$num]['ds_ip'] = implode(', ', $dedicated_servers['ip']);
				
				/* Количество игровых серверов */
				$this->db->count_all();
				
				$this->db->where('ds_id', $dedicated_servers['id']);
				$this->db->from('servers');
				$tpl_data[$num]['servers_count'] = $this->db->count_all_results();
				
			}
			
			return $tpl_data;
			
		} else {
			return array();
		}
	}
	
	// ----------------------------------------------------------------
	
	/*
	 * Проверка занятости портов 
	 * 
	 * @param str, array
	*/
	function check_ports($ds_id, $ports)
	{
		$this->db->where('ds_id', $ds_id);
		
		$this->db->where_in('server_port', $ports);
		$this->db->or_where_in('query_port', $ports);
		$this->db->or_where_in('rcon_port', $ports);
		
		$query = $this->db->get('servers');
		
		if($query->num_rows > 0) {
			return false;
		} else {
			return true;
		}
		
	}

	//-----------------------------------------------------------
	
	/*
	 * Функция отправляет команду на сервер
	 * 
	 * УСТАРЕЛА! В 1.0 версии будет удалена, используйте хелпер ds
	*/
	function command($command, $server_data = false, $path = false)
    {
		$this->load->helper('ds');
		return send_command($command, $server_data, $path);
	}

}
