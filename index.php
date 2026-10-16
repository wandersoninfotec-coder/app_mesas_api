<?php
    date_default_timezone_set('America/Cuiaba');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 86400');

    // Responde OPTIONS (preflight) imediatamente
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    session_start();
    require 'config.php';

    spl_autoload_register(function ($class){
    if(file_exists('controllers/'.$class.'.php')) {
            require_once 'controllers/'.$class.'.php';
    }elseif(file_exists('models/'.$class.'.php')) {
                require_once 'models/'.$class.'.php';
        } elseif(file_exists('core/'.$class.'.php')) {
                require_once 'core/'.$class.'.php';
        }
    });

    $core = new Core();
    $core->run();
?>
