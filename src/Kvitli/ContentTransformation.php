<?php
namespace Kvitli;

class ContentTransformation {
	private static $defaults = false;

	private $transformations = array();

	private function __construct($transformations) {
		$this->transformations = $transformations;
	}

	public function transform($connection, $page_id, $new_page_id) {
		$ret = true;
		foreach($this->transformations as $transformation) {
			if(is_callable($transformation) && !$transformation($connection, $page_id, $new_page_id)) {
				$ret = false;
			} else {
				if(call_user_func_array($transformation, array($connection, $page_id, $new_page_id)) === false) {
					$ret = false;
				}
			}
		}

		return $ret;
	}

	public function add($transformation_function) {
		$this->transformations[] = $transformation_function;
	}

	public static function transform_copy_attachments($connection, $page_id, $new_page_id) {
		if ($connection->copy_attachments($page_id, $new_page_id) === false) {
			return false;
		}

		return true;
	}

	public static function defaults($default) {
		if(self::$defaults === false) {
			self::$defaults = array(
				'copy_page' => array(
					array('Kvitli\ContentTransformation', 'transform_copy_attachments')
				),
			);
		}
		return new ContentTransformation(self::$defaults[$default]);
	}
}
