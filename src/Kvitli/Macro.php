<?php
namespace Kvitli;

class Macro extends StorageFormatEntity {
	private $macro_name = false;
	private $parameters = array();
	private $body = false;

	function __construct($macro_name) {
		$this->macro_name = $macro_name;
	}

	static function add($macro_name) {
		return new Macro($macro_name);
	}

	function get_storage_format() {
		$params = '';
		foreach($this->parameters as $key => $value) {
			$params .= '<ac:parameter ac:name="'.$key.'">'.$value.'</ac:parameter>';
		}

		$body = '';
		if($this->body !== false) {
			$body = '<ac:rich-text-body>'.$this->body.'</ac:rich-text-body>';
		}

		$ret = '<ac:structured-macro ac:name="'.$this->macro_name.'" ac:schema-version="1">'
			.$params
			.$body
		.'</ac:structured-macro>';

		return $ret;
	}

	function add_parameter($name, $value) {
		$this->parameters[ $name ] = $value;

		return $this;
	}

	function set_body($value) {
		if(is_subclass_of($value, 'Kvitli\StorageFormatEntity')) {
			$value = $value->get_storage_format();
		}

		$this->body = $value;

		return $this;
	}
}
