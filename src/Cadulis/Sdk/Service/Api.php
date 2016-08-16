<?php

namespace Cadulis\Sdk\Service;

class Api
{
    use \Bixev\LightLogger\LoggerTrait;
    use \Cadulis\Sdk\Traits\ClientTrait;

    public function __construct($client = null, \Bixev\LightLogger\LoggerInterface $logger = null)
    {
        $this->setClient($client);
        $this->setLogger($logger);
    }

    public function newServiceIntervention()
    {
        return new Intervention($this->_client);
    }

    public function newServiceInterventionType()
    {
        return new InterventionType($this->_client);
    }

    public function newServiceScheduleWizard()
    {
        return new ScheduleWizard($this->_client);
    }

}