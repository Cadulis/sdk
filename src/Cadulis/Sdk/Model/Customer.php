<?php

namespace Cadulis\Sdk\Model;

class Customer extends AbstractModel
{

    const CUSTOMER_TYPE_COMPANY = 'company';
    const CUSTOMER_TYPE_INDIVIDUAL = 'individual';

    public $id;
    public $reference;
    public $type;
    public $name;
    public $address;
    public $address_additional;
    public $phone;
    public $mobile;
    public $comment;
    public $email;
    protected $_properties = array('id', 'reference', 'type', 'name', 'address', 'address_additional', 'phone', 'mobile', 'comment', 'email');

    /**
     * @var CustomerCustomFields
     */
    public $custom_fields;

    /**
     * @return array
     */
    public function toArray()
    {
        $return = parent::toArray();
        if (!empty($this->custom_fields)) {
            if (!($this->custom_fields instanceof CustomerCustomFields)) {
                throw new \Cadulis\Sdk\Exception('invalid custom_fields instance (must be instanceof \Cadulis\Sdk\CustomerCustomFields');
            }
            $return['custom_fields'] = $this->custom_fields->toArray();
        }

        return $return;
    }

    public function hydrate(array $data = [])
    {
        parent::hydrate($data);
        if (isset($data['custom_fields'])) {
            $this->custom_fields = new CustomerCustomFields;
            if (!is_array($data['custom_fields'])) {
                throw new \Exception('Invalid parameter "custom_fields"');
            }
            $this->custom_fields->hydrate($data['custom_fields']);
        }
    }

}