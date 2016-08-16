<?php

namespace Cadulis\Sdk;

/**
 * @property Service\Api $services
 * @property Model\Api $models
 */
class InterventionApi
{

    use \Bixev\LightLogger\LoggerTrait;
    use \Cadulis\Sdk\Traits\ClientTrait;

    // SINGLETON

    /**
     * @var InterventionApi
     */
    static protected $_instance;

    /**
     * @param string|null $autoconfigUrl
     * @return InterventionApi
     */
    static public function init($autoconfigUrl = null, \Bixev\LightLogger\LoggerInterface $logger = null)
    {
        static::$_instance = new static($autoconfigUrl, $logger);

        return static::getInstance();
    }

    /**
     * @return InterventionApi
     */
    static public function getInstance()
    {
        return static::$_instance;
    }

    // INSTANCE

    /**
     * @var array local cache
     */
    protected $_helpers;

    public function __construct($autoconfigUrl = null, \Bixev\LightLogger\LoggerInterface $logger = null)
    {
        $this->setLogger($logger);
        $this->setClient(new Client\Client($autoconfigUrl, $logger));
    }

    public function setAutoconfigUrl($autoconfigUrl)
    {
        $this->_client->setAutoconfigUrl($autoconfigUrl);
    }

    public function __get($name)
    {
        if (!isset($this->_helpers[$name])) {
            switch ($name) {
                case 'services':
                    $this->_helpers[$name] = new Service\Api($this->_client, $this->_logger);
                    break;
                case 'models':
                    $this->_helpers[$name] = new Model\Api($this->_client, $this->_logger);
                    break;
                default:
                    throw new Exception('Unknown helper name "' . $name . '"');
                    break;
            }
        }

        return $this->_helpers[$name];
    }
}