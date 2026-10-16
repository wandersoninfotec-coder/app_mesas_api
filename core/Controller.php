<?php
class controller {

	protected $db;

	public function __construct() {
		global $config;
		$this->db = new PDO(
			"mysql:dbname=" . $config['dbname'] . ";host=" . $config['host'] . ";charset=latin1",
			$config['dbuser'],
			$config['dbpass']
		);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Garante que a sessão use latin1 (compatível com o legado VB6)
		$this->db->exec("SET NAMES 'latin1'");
	}

	public function loadView($viewName, $viewData = array()) {
		extract($viewData);
		include 'views/'.$viewName.'.php';
	}

	public function loadTemplate($viewName, $viewData = array()) {
		include 'views/template.php';
	}

	public function loadViewInTemplate($viewName, $viewData) {
		extract($viewData);
		include 'views/'.$viewName.'.php';
	}

}
