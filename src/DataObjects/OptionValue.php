<?php
namespace DNJ\PHPVMOMI\DataObjects;

/**
 * @todo recheck and complete
 * @see https://vdc-download.vmware.com/vmwb-repository/dcr-public/b50dcbbf-051d-4204-a3e7-e1b618c1e384/538cf2ec-b34f-4bae-a332-3820ef9e7773/vim.option.OptionValue.html
 */
class OptionValue extends DynamicData
{
	
	/** @var string $key */
	public $key;

	/** @var mixed $value */
	public $value;
}