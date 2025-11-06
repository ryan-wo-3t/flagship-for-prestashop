<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '1.7.8.0');
}

if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', sys_get_temp_dir());
}

if (!defined('_PS_IMG_DIR_')) {
    define('_PS_IMG_DIR_', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!class_exists('Context')) {
    class Context
    {
        public $cookie;
        public $controller;
        public $shop;
        public $language;
        public $link;
        public $cart;

        private static $instance;

        public static function getContext(): Context
        {
            if (!static::$instance instanceof Context) {
                static::$instance = new Context();
                static::$instance->cookie = new Cookie();
                static::$instance->controller = new Controller();
                static::$instance->shop = new Shop();
                static::$instance->language = (object)['id' => 1];
                static::$instance->link = new Link();
                static::$instance->cart = new Cart();
            }

            return static::$instance;
        }
    }
}

if (!class_exists('Cookie')) {
    class Cookie
    {
        public function __unset($name): void
        {
            if (property_exists($this, $name)) {
                unset($this->$name);
            }
        }
    }
}

if (!class_exists('Controller')) {
    class Controller
    {
        public $php_self = 'index';

        public function getLanguages(): array
        {
            return [['id_lang' => 1]];
        }
    }
}

if (!class_exists('Shop')) {
    class Shop
    {
        public $name = 'Flagship Test Shop';
    }
}

if (!class_exists('Link')) {
    class Link
    {
        public function getAdminLink($controller, $withToken = false): string
        {
            return '/admin/' . $controller;
        }
    }
}

if (!class_exists('Cart')) {
    class Cart
    {
        public $products = [];

        public function getProducts(): array
        {
            return $this->products;
        }
    }
}

if (!class_exists('Module')) {
    class Module
    {
        public $context;

        public function __construct()
        {
            $this->context = Context::getContext();
        }

        protected function l($string)
        {
            return $string;
        }

        protected function display($file, $template)
        {
            return $file . ':' . $template;
        }

        protected function displayConfirmation($string)
        {
            return $string;
        }

        protected function displayError($string)
        {
            return $string;
        }

        protected function displayWarning($string)
        {
            return $string;
        }

        public function registerHook($hook)
        {
            return true;
        }
    }
}

if (!class_exists('CarrierModule')) {
    class CarrierModule extends Module
    {
    }
}

if (!class_exists('FileLogger')) {
    class FileLogger
    {
        public function __construct($level)
        {
        }

        public function setFilename($filename)
        {
        }

        public function logDebug($message)
        {
        }

        public function logError($message)
        {
        }
    }
}

if (!class_exists('Configuration')) {
    class Configuration
    {
        private static $values = [];

        public static function get($key)
        {
            return static::$values[$key] ?? null;
        }

        public static function updateValue($key, $value)
        {
            static::$values[$key] = $value;
            return true;
        }

        public static function deleteByName($key)
        {
            unset(static::$values[$key]);
            return true;
        }
    }
}

if (!class_exists('Cache')) {
    class Cache
    {
        private static $store = [];

        public static function store($key, $value): void
        {
            static::$store[$key] = $value;
        }

        public static function retrieve($key)
        {
            return static::$store[$key] ?? null;
        }

        public static function isStored($key): bool
        {
            return array_key_exists($key, static::$store);
        }

        public static function clean($key): void
        {
            if (isset(static::$store[$key])) {
                unset(static::$store[$key]);
            }
        }
    }
}

if (!class_exists('Tools')) {
    class Tools
    {
        private static $values = [];

        public static function substr($string, $start, $length = null)
        {
            return $length === null ? substr($string, $start) : substr($string, $start, $length);
        }

        public static function strtolower($value)
        {
            return strtolower($value);
        }

        public static function isSubmit($name)
        {
            return false;
        }

        public static function getValue($key, $default = null)
        {
            return static::$values[$key] ?? $default;
        }

        public static function setValue($key, $value): void
        {
            static::$values[$key] = $value;
        }

        public static function clearValues(): void
        {
            static::$values = [];
        }
    }
}

if (!class_exists('Carrier')) {
    class Carrier
    {
        public $name;
        public $id;
        public $delay = [];

        public function __construct($id = null)
        {
            $this->id = $id;
        }

        public function add()
        {
            if ($this->id === null) {
                $this->id = random_int(1, 1000);
            }
            return true;
        }

        public function setGroups($groups)
        {
        }

        public function addZone($zoneId)
        {
        }
    }
}

if (!class_exists('Address')) {
    class Address
    {
        public $id_country = 1;
        public $id_state = 1;
        public $city = 'Montreal';
        public $postcode = 'H2B1A0';
        public $address1 = '123 Main';
        public $address2 = 'Suite 100';
        public $phone = '555-0100';
        public $firstname = 'Jane';
        public $lastname = 'Doe';
        public $company = '';

        public function __construct($id = null)
        {
        }
    }
}

if (!class_exists('Customer')) {
    class Customer
    {
        public $email = 'customer@example.com';

        public function __construct($id)
        {
        }
    }
}

if (!class_exists('Order')) {
    class Order
    {
        public $id;
        public $id_customer = 1;
        public $id_address_delivery = 1;
        public $products = [];

        public function __construct($id)
        {
            $this->id = $id;
        }

        public function getProductsDetail(): array
        {
            return $this->products;
        }
    }
}

if (!class_exists('Language')) {
    class Language
    {
        public static function getLanguages($active = true): array
        {
            return [['id_lang' => 1]];
        }
    }
}

if (!class_exists('Group')) {
    class Group
    {
        public static function getGroups($idLang): array
        {
            return [['id_group' => 1]];
        }
    }
}

if (!class_exists('RangePrice')) {
    class RangePrice
    {
        public $id_carrier;
        public $delimiter1;
        public $delimiter2;

        public function add()
        {
            return true;
        }
    }
}

if (!class_exists('RangeWeight')) {
    class RangeWeight
    {
        public $id_carrier;
        public $delimiter1;
        public $delimiter2;

        public function add()
        {
            return true;
        }
    }
}

if (!class_exists('Zone')) {
    class Zone
    {
        public static function getZones(): array
        {
            return [['id_zone' => 1]];
        }
    }
}

if (!class_exists('Db')) {
    class Db
    {
        private static $instance;

        public static function getInstance(): Db
        {
            if (!static::$instance instanceof Db) {
                static::$instance = new Db();
            }
            return static::$instance;
        }

        public function execute($sql)
        {
            return true;
        }

        public function executeS($query)
        {
            return [];
        }

        public function insert($table, array $data)
        {
            return true;
        }
    }
}

if (!class_exists('DbQuery')) {
    class DbQuery
    {
        public function select($fields)
        {
            return $this;
        }

        public function from($table, $alias = null)
        {
            return $this;
        }

        public function where($condition)
        {
            return $this;
        }
    }
}

if (!class_exists('Country')) {
    class Country
    {
        public static function getIsoById($id)
        {
            return 'CA';
        }
    }
}

Configuration::updateValue('flagship_fee', 0.0);
Configuration::updateValue('flagship_markup', 0.0);
Configuration::updateValue('flagship_residential', 0);
Configuration::updateValue('flagship_test_env', 0);
Configuration::updateValue('flagship_email_on_label', 0);
Configuration::updateValue('flagship_tracking_email', 0);
Configuration::updateValue('flagship_packing_api', 0);
Configuration::updateValue('PS_DIMENSION_UNIT', 'in');
Configuration::updateValue('PS_WEIGHT_UNIT', 'lb');
Configuration::updateValue('PS_SHOP_NAME', 'Flagship Test Shop');
Configuration::updateValue('PS_SHOP_ADDR1', '123 Main');
Configuration::updateValue('PS_SHOP_ADDR2', 'Suite 100');
Configuration::updateValue('PS_SHOP_CITY', 'Montreal');
Configuration::updateValue('PS_SHOP_COUNTRY_ID', 1);
Configuration::updateValue('PS_SHOP_STATE_ID', 1);
Configuration::updateValue('PS_SHOP_CODE', 'H2B1A0');
Configuration::updateValue('PS_SHOP_PHONE', '555-0100');
Configuration::updateValue('PS_SHOP_EMAIL', 'shop@example.com');

require_once __DIR__ . '/../flagshipshipping.php';
