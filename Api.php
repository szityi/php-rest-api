<?php
/**
 * Created by PhpStorm.
 * User: szityi
 * Date: 12/12/2016
 * Time: 11:09 AM
 */

class Api extends Controller {

    private static $dbconfig;

    public static function _remap($class, $args = []) {
        $dbname = configValue('database')['__default__'];

        if (isset($dbname))
            static::$dbconfig = configValue('database')[$dbname];

        header('Content-Type: application/json');
        $resp = null;
        if (!static::_validate($resp)) {
            header('WWW-Authenticate: Basic realm="MPK"');
            http_response_code(401);
            echo $resp;
            return;
        }

        if (isset($args[0]))
            $args[0] = strtolower($_SERVER['REQUEST_METHOD']).ucwords($args[0]);

        parent::_remap($class, $args);
    }

    public static function _validate(&$resp) {
        $user = $_SERVER['PHP_AUTH_USER'];
        $pass = $_SERVER['PHP_AUTH_PW'];

        $db = SQL::get();
        $db->prepare('SELECT username, password FROM user WHERE username=:username AND role=:role');
        $db->bind(':username', $user);
        $db->bind(':role', 'admin');
        $row = $db->getRow();

        $message = null;

        if (isset($row['username'])) {
            if (md5($pass) == $row['password']) {
                http_response_code(200);
                return true;
            } else
                $message = 'Wrong password!';
        } else
            $message = 'User does not exists!';

        $resp = json_encode([
            'success' => false,
            'message' => $message
        ]);

        return false;
    }

    public static function getTables() {
        $db = SQL::get();

        $db->prepare('SELECT TABLE_NAME from INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = \'BASE TABLE\' AND TABLE_SCHEMA = \''.static::$dbconfig['db'].'\'');

        $tables = [];

        foreach ($db->getResultSet() as $table) {
            $tables[] = $table['TABLE_NAME'];
        }

        return $tables;
    }

    public static function getAllTableFields($table, $mandatory=false) {
        $allowed = static::getTables();
        $key = array_search($table, $allowed);
        $table = $allowed[$key];

        $db = SQL::get();

        $db->prepare('SHOW fields FROM '.$table);

        $fields = [];

        foreach ($db->getResultSet() as $field) {
            if (!$mandatory)
                $fields[] = $field['Field'];
            else
                if ($field['Null'] == 'NO' and $field['Default'] == NULL and $field['Extra'] != 'auto_increment')
                    $fields[] = $field['Field'];
        }

        return $fields;
    }

    public static function validateField($allowed, $key) {
        $id = array_search($key, $allowed);
        if ($id !== false)
            return $allowed[$id];
        else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No such field like \''.$key.'\'!'
            ]);
            return false;
        }
    }

    public static function validateFields($allowed, $keys) {
        $allowed_keys = [];
        foreach ($keys as $key) {
            $id = array_search($key, $allowed);
            if ($id !== false)
                $allowed_keys[] = $allowed[$id];
        }

        if (count($allowed_keys) == count($keys))
            return $allowed_keys;
        else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No such fields like \'' . implode('\', \'', array_diff($keys, $allowed_keys)) . '\'!'
            ]);
            return false;
        }
    }

    public static function getUser() {
        if (func_num_args() == 2) {
            self::getOneUser(func_get_arg(0), func_get_arg(1));
            return;
        }

        $db = SQL::get();

        $db->prepare('SELECT * FROM user');
        $res = $db->getResultSet();

        echo json_encode($res);
    }

    public static function getOneUser($key, $value) {
        if (($key = static::validateField(static::getAllTableFields('user'), $key)) === false)
            return;

        $db = SQL::get();

        $db->prepare('SELECT * FROM user WHERE '.$key.' LIKE :value');

        if ($key == 'id') {
            $db->bind(':value', $value);
        }
        else
            $db->bind(':value', $value . '%');

        $res = $db->getResultSet();

        echo json_encode($res);
    }

    public static function postUser() {
        if (empty($_POST)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No post data provided!'
            ]);
            return;
        }

        $missing_fields = [];

        foreach (static::getAllTableFields('user', true) as $field)
            if (!isset($_POST[$field]))
                $missing_fields[] = $field;

        if (!empty($missing_fields)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'The following fields are missing: '.implode(', ', $missing_fields)
            ]);
            return;
        }

        if (($keys = static::validateFields(static::getAllTableFields('user'), array_keys($_POST))) === false)
            return;

        $db = SQL::get();

        $db->prepare('INSERT INTO user ('.implode(', ', $keys).') VALUES ('.implode(', ', array_map(
                function ($k) { return sprintf(':%s', $k); },
                $keys
            )).')');

        array_map(function ($key, $value) use ($db) {
            $db->bind(':'.$key, $value);
        }, $keys, array_values($_POST));

        if ($db->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'POST was successful!'
            ]);
        }
    }
}