<?php

class db_mysqli extends mysqli
{
	private $config = array();
	private $database_buffer = array();
	private $current_database = array();
	private $query_log = array();
	
	public $log_level = 1;
	
	private $prepared = array();
	private $prepared_query = array();
	
	public function __construct($config)
	{
		$this->config = &$config;
		
		$extra = "";
		$this->config['user_persistant'] = true;
		if($this->config['user_persistant'] == true)
		{
			$this->config['db_host'] = "p:".$this->config['db_host'];
		}
		
		parent::__construct($this->config['db_host'], $this->config['db_username'], $this->config['db_password'], $this->config['database']);
	}
	
	public function connect()
	{
		parent::real_connect($this->config['db_host'], $this->config['db_username'], $this->config['db_password']);
		if(!empty($this->current_database))
			parent::select_db($database_name);
		else
			parent::select_db($this->config['database']);
	}
	
	
	public function change_database($database_name)
	{
		$this->database_buffer[] = $this->current_database;
		$this->current_database = $database_name;
		parent::select_db($database_name);
	}
	
	public function revert()
	{
		$this->change_database(array_pop($this->database_buffer));
	}
	
	public function ping()
	{
		if(!parent::ping())
		{
			$this->connect();
		}
	}
	
	public function query($query_string, $dispose=false, $count_query="")
	{
		if($this->log_level > 0)
			$start_time = $this->make_microtime();
		
		if(!empty($this->result))
		{
			// buffer the current result;
			$this->buffered_results[] = $this->result;
		}
		
		$this->single_result_cache = NULL;
		$result = parent::query($query_string);
		if(is_bool($result) || !$result)
		{
			$error_message = (!$result) ? $this->parse_error_message($this->error) : "";
			$this->parent_query();
		}
		else
		{
			$this->result = $result;
		}
		
		if($this->log_level > 0)
			$this->query_log[] = array("query_string"=>$query_string, "run_time"=>($this->make_microtime() - $start_time), "errors"=>$error_message);
		
		if($count_query == "make_count")
		{
			$count = $this->single_result("all_rows");
			$this->parent_query();
			return $count;
		}
		else if(!empty($count_query))
		{
			return $this->query($count_query, false, "make_count");
		}
	}
	
	public function single_result($field)
	{
		if(!empty($this->single_result_cache))
			return $this->single_result_cache[$field];
			
		$this->single_result_cache = $this->fetch_row();
		return $this->single_result_cache[$field];
	}
	
	private function parse_error_message($error_message)
	{
		return $error_message;
	}
	
	public function parent_query()
	{
		if(sizeof($this->buffered_results) != 0)
		{	
			$this->result = array_pop($this->buffered_results);
		}
		else
		{
			$this->result = false;
		}
	}
	
	public function fetch_row($field="")
	{
		if(!is_object($this->result))
		{
			$this->parent_query();
			return false;
		}
			
		if((@$fetched = $this->result->fetch_assoc()) !== NULL)
		{
			if(!empty($field))
				return $fetched[$field];
			return $fetched;
		}

		$this->parent_query();
	}
	
	public function rows()
	{
		return $this->result->num_rows;
	}
	
	public function output_query_log()
	{
		$make_log_output = "";
		foreach($this->query_log as $query)
		{
			$make_log_output .= "<B>QUERY:</B>&nbsp;".$query['query_string']."<br />";
			if(!empty($query['store_values']))
				$make_log_output .= "<B>VALUES:</B>&nbsp;".implode(", ", $query['store_values'])."<br />";
			if(!empty($query['store_where']))
				$make_log_output .= "<B>WHERE:</B>&nbsp;".implode(", ", $query['store_where'])."<br />";
			$make_log_output .= "<B>RUNTIME:</B>&nbsp;".$query['run_time']."<br />";
			
			if(!empty($query['errors']))
				$make_log_output .= "<B>ERROR!!:</B>&nbsp;".$query['errors']."<br />";
			
			$make_log_output .= "<br /><br />";
		}
		
		return $make_log_output;
	}
	
	private function make_microtime()
	{
		$mtime = microtime();
		$mtime = explode(" ",$mtime);
		$mtime = $mtime[1] + $mtime[0];
		
		return $mtime; 
	}
	
	public function prepare_select_query($table, $where)
	{
		
	}
	
	public function ref_values(&$arr, &$arr2=NULL)
	{
		$refs = array();
		$i=0;
        foreach($arr as $key => $value)
		{
            $refs[$i] = &$arr[$key][1];
			$i++;
		}
		
		if($arr2 !== NULL)
		{
			foreach($arr2 as $key => $value)
			{
				$refs[$i] = &$arr2[$key][1];
				$i++;
			}
		}
		
		return $refs;
	}
	
	public function prepare_update_query($id, $table, &$fields, &$where_fields)
	{	
		$refs = $this->ref_values($fields, $where_fields);
		
		$query_part = array();
		$types_build = array();
		foreach($fields as $field=>$val)
		{
			$types_build[] = $val[0];
			$query_part[] = "`".$field."`=?";
		}
		
		$where_part = array();
		foreach($where_fields as $field=>$val)
		{
			$types_build[] = $val[0];
			$where_part[] = "`".$field."`=?";
		}
		
		if(sizeof($where_part) == 0)
			$where_part[0] = 1;
		
		$this->array_unshift_ref($refs, implode("", $types_build));
		
		$this->prepared[$id] = parent::prepare("UPDATE `".$table."` SET ".implode(", ", $query_part)." WHERE ".implode(" AND ", $where_part));
		$this->prepared_query[$id] = "UPDATE `".$table."` SET ".implode(", ", $query_part)." WHERE ".implode(" AND ", $where_part);
		call_user_func_array(array($this->prepared[$id],'bind_param'), $refs);  
	}
	
	public function prepared_insert_id($id)
	{
		return $this->prepared[$id]->insert_id;
	}
	
	public function prepare_insert_query($id, $table, &$fields)
	{
		$param_build = array();
		$types_build = array();
		$keys = array_keys($fields);
		for($i=0; $i<sizeof($fields); $i++)
		{	
			$param_build[] = "?";
			$types_build[] = $fields[$keys[$i]][0];
		}	
		
		$refs = $this->ref_values($fields);
		$this->array_unshift_ref($refs, implode("", $types_build));
		$this->prepared[$id] = parent::prepare("INSERT INTO `".$table."` (`".implode("`,`", array_keys($fields))."`) VALUES (".implode(",", $param_build).")");
		$this->prepared_query[$id] = "INSERT INTO `".$table."` (`".implode("`,`", array_keys($fields))."`) VALUES (".implode(",", $param_build).")";
		call_user_func_array(array($this->prepared[$id],'bind_param'), $refs);  
	}
	
	public function clean_execute_insert($id, &$values, &$where_fields=NULL)
	{
		if($this->log_level > 0)
			$start_time = $this->make_microtime();
			
		$store_values = array();
		$keys = array_keys($values);
		for($i=0; $i<sizeof($values); $i++)
		{
			$values[$keys[$i]][1] = parent::real_escape_string($values[$keys[$i]][1]);
			$store_values[] = $values[$keys[$i]][1];
		}
		
		if($where_fields !== NULL)
		{
			$store_where = array();
			$keys = array_keys($where_fields);
			for($i=0; $i<sizeof($where_fields); $i++)
			{
				$where_fields[$keys[$i]][1] = parent::real_escape_string($where_fields[$keys[$i]][1]);
				$store_where[] = $where_fields[$keys[$i]][1];
			}
		}
		
		$error_check = $this->prepared[$id]->execute();
		if(!$error_check)
			$error_message = (!$result) ? $this->parse_error_message($this->error) : "";
		
		if($this->log_level > 0)
			$this->query_log[] = array("query_string"=>$this->prepared_query[$id], "store_values"=>$store_values, "store_where"=>$store_where, "run_time"=>($this->make_microtime() - $start_time), "errors"=>$error_message);
	}
	
	private function array_unshift_ref(&$array, &$value)
	{
		array_unshift($array,'');
		$array[0] =& $value;
	}
	
	public function stmt_bind_assoc (&$stmt, &$out) 
	{
		$data = mysqli_stmt_result_metadata($stmt);
		$fields = array();
		$out = array();

		$fields[0] = $stmt;
		$count = 1;

		while($field = mysqli_fetch_field($data)) 
		{
			$fields[$count] = &$out[$field->name];
			$count++;
		}   
		call_user_func_array(mysqli_stmt_bind_result, $fields);
	}
}

?>