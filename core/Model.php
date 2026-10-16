<?php
class model {

	protected $db;

	public function __construct() {
		global $config;
		$this->db = new PDO("mysql:dbname=".$config['dbname'].";host=".$config['host'], $config['dbuser'], $config['dbpass'],
		array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ". $config['char_set']));
	}
}
?>
