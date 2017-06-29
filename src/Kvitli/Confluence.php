<?php

namespace Kvitli;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 *
 **/
class Confluence {
	private $base_url = false;
	private $username = false;
	private $password = false;
	private $ch = false;

	private $debug = false;

	private $request_log = false;

	const MAX_PAGES = 10000;

	/**
	 * Confluence constructor. Leave options empty to load from Environment variables (.env-file, ENV or SERVER)
	 * @param bool $base_url
	 * @param bool $username
	 * @param bool $password
	 */
	public function __construct($base_url = false, $username = false, $password = false) {
		if(class_exists('\Dotenv\Dotenv')) {
			$dotenv = new \Dotenv\Dotenv('./');
			$dotenv->load();
		}

		$this->base_url = $base_url ? $base_url : getenv('CONFLUENCE_BASEURL');
		$this->username = $username ? $username : getenv('CONFLUENCE_USERNAME');
		$this->password = $password ? $password : getenv('CONFLUENCE_PASSWORD');

		// create a log channel
		$this->request_log = new Logger('name');
		$this->request_log->pushHandler(new StreamHandler('/tmp/confluence-request.log', Logger::INFO));
	}

	public function set_debug($debug_level) {
		$this->debug = $debug_level;
	}

	function get_base_url() {
		return $this->base_url;
	}

	/**
	 * Get all pages for a specific space.
	 * Returns array with $page_id => $title
	 **/
	public function get_all_pages_for_space($space) {
		// TODO Weakness for really large spaces. Limit number of pages to self::MAX_PAGES
		$res = $this->execute_get_request('/rest/api/content/', array('type' => 'page', 'spaceKey' => $space, 'limit' => self::MAX_PAGES));
		if($res === false) {
			return false;
		}

		$ret = array();

		foreach($res->results as $result) {
			$ret[$result->id] = $result->title;
		}

		return $ret;
	}

	/**
	 * Returns recursive array containg tree structure; title, page_id, children, space
	 **/
	public function get_page_tree($space, $page_id, $exclude_labels = array()) {
		$ret = array();

		$page = $this->get_page($page_id);

		$des = $page->get_child_pages($exclude_labels);
		foreach($des as $child_page) {
			$children = $this->get_page_tree($space, $child_page->get_id(), $exclude_labels);

			#if(trim($child_page->get_body()) == "") {
			#	echo "Skipping ".$child_page->get_title()." since body is empty\n";
			#	continue;
			#}

			$ret[] = array(
				'title' => $child_page->get_title(),
				'page_id' => $child_page->get_id(),
				'children' => $children,
				#'space' => $child_page->space->key,
				#'content' => $child_page->content,
			);
		}

		return $ret;
	}

	function create_page_tree($space, $new_parent_id, $page_tree, $transformation = false) {

		// Check if any current child pages should be deleted
		$new_parent = $this->get_page($new_parent_id);
		$pages_to_delete = array();
		foreach($new_parent->get_child_pages() as $curr_child_page) {
			$delete_curr_child_page = true;
			foreach($page_tree as $new_child_page) {
				#echo $new_child_page['title'] . $curr_child_page->get_title()."\n";
				if($new_child_page['title'] == $curr_child_page->get_title()) {
					$delete_curr_child_page = false;
				}
			}

			if($delete_curr_child_page) {
				$pages_to_delete[] = $curr_child_page->get_id();
			}
		}

		foreach($pages_to_delete as $delete_id) {
			$this->delete_page($delete_id);
		}

		foreach($page_tree as $page) {
			$orig_page = $this->get_page($page['page_id']);

			$page_id = $this->copy_page($orig_page->get_id(), $space, $new_parent_id, $transformation);
			if($page_id !== false) {
				$this->create_page_tree($space, $page_id, $page['children'], $transformation);
			} else {
				#echo "Failed to create page\n";
			}
		}
	}

	function copy_page($page_id, $space, $new_parent_id, $transformations = false) {
		$page = $this->get_page($page_id);
		if($page === false) {
			return false;
		}

		$new_page_id = $this->get_page_id_by_title($space, $page->get_title());

		if($new_page_id !== false) {
			$new_page_id = $this->update_page($space, $new_page_id, $page->get_title(), $page->get_body(), $new_parent_id);
		} else {
			$new_page_id = $this->create_page($space, $page->get_title(), $page->get_body(), $new_parent_id);
		}

		if($new_page_id === false) {
			return false;
		}

		if($transformations == false) {
			$transformations = ContentTransformation::defaults('copy_page');
		}

		$ret = $transformations->transform($this, $page_id, $new_page_id);

		if($ret === false) {
			return $ret;
		}

		return $new_page_id;
	}

	/**
	 * Copy all attachments from source page to target page
	 * @param $source_page_id
	 * @param $target_page_id
	 * @return bool
	 */
	function copy_attachments($source_page_id, $target_page_id) {
		$source_page = $this->get_page($source_page_id);
		$target_page = $this->get_page($target_page_id);

		$target_attachs = $target_page->get_attachments();
		foreach($target_attachs as $attach) {
			$this->delete_attachment($attach->get_id());
		}

		$source_attachs = $source_page->get_attachments();
		foreach($source_attachs as $attach) {
			$data = $this->execute_download_request($attach->get_attachment_url());
			$image_path = sys_get_temp_dir().'/'.$attach->get_filename();

			file_put_contents($image_path, $data);
			$this->upload_attachment($image_path, $target_page_id);

			unlink($image_path);
		}

		return true;
	}

	public function search($cql, $space = false) {
		$cqlcontext = array();
		if($space !== false) {
			$cqlcontext['spaceKey'] = $space;
		}

		$res = $this->execute_get_request('/rest/api/search', array(
			'cql' => $cql,
			'cqlcontext' => count($cqlcontext) > 0 ? ($cqlcontext) : '',
			'limit' => self::MAX_PAGES,
			'expand' => 'ancestors',
		));

		if($res === false) {
			return false;
		}

		$ret = array();
		foreach($res->results as $res) {
			$ret[] = $res;
		}

		return $ret;
	}

	public function get_child_pages($page_id) {
		$type = 'page';
		$res = $this->execute_get_request('/rest/api/content/'.$page_id.'/child/'.$type, array(
			'limit' => self::MAX_PAGES
		));

		if($res === false) {
			return false;
		}

		$ret = array();
		foreach($res->results as $res) {
			$ret[] = $res;
		}

		return $ret;
	}

	public function get_page($page_id) {
		$content = $this->execute_get_request('/rest/api/content/'.$page_id, array('expand' => 'body.storage'));
		return new Content($content, $this);
	}

	public function get_next_version_for_page($page_id) {
		$history = $this->execute_get_request('/rest/api/content/'.$page_id.'/history');
		if($history === false) {
			return false;
		}

		return $history->lastUpdated->number + 1;
	}

	public function get_page_id_by_title($space, $title) {
		$res = $this->execute_get_request('/rest/api/content/', array('type' => 'page', 'title' => $title, 'spaceKey' => $space));

		if($res === false || !property_exists($res, 'results') || count($res->results) == 0) {
			return false;
		}

		return $res->results[0]->id;
	}

	public function get_attachments_for_page($page_id) {
		$res = $this->execute_get_request('/rest/api/content/'.$page_id.'/child/attachment', array(
		));

		if($res === false) {
			return false;
		}

		$ret = array();

		foreach($res->results as $attch) {
			#var_dump($attch);die();
			$attch = new Attachment($attch, $this);
			$ret[$attch->get_id()] = $attch;
		}

		return $ret;
	}

	public function get_attachment_id_by_filename($image_filaneme, $page_id) {
		$res = $this->execute_get_request('/rest/api/content/'.$page_id.'/child/attachment', array(
			'filename' => $image_filaneme
		));

		if($res !== false && isset($res->results[0]->id)) {
			return $res->results[0]->id;
		}

		return false;
	}

	public function delete_attachment($attachment_id) {
		return $this->delete_page($attachment_id);
	}

	/**
	 * Upload attachment to page. Updated existing attachment if already exists.
	 **/
	public function upload_attachment($image_path, $page_id) {
		$attach_id = $this->get_attachment_id_by_filename(basename($image_path), $page_id);

		if($attach_id !== false) {
			#$data = array("file" => '@'.$image_path);
			$data = array("file" => curl_file_create($image_path));
			$res = $this->execute_post_request(
				'/rest/api/content/'.$page_id.'/child/attachment/'.$attach_id.'/data',
				$data,
				array(
					'X-Atlassian-Token: nocheck',
					'Content-Type: multipart/form-data'
				));

			if($res !== false) {
				$attach_id = $res->id;
			} else {
				$attach_id = false;
			}

		} else {
			#$data = array("file" => '@'.$image_path);
			$data = array("file" => curl_file_create($image_path));
			$res = $this->execute_post_request(
				'/rest/api/content/'.$page_id.'/child/attachment',
				$data,
				array(
					'X-Atlassian-Token: nocheck',
					'Content-Type: multipart/form-data'
				));

			if($res !== false) {
				$attach_id = $res->results[0]->id;
			} else {
				$attach_id = false;
			}
		}

		return $attach_id;
	}

	public function delete_page($page_id) {
		$res = $this->execute_delete_request('/rest/api/content/'.$page_id);
		if($res !== false) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Update or create a page. Will update if an existing page with same title already exists.
	 *
	 * @param $space
	 * @param $title
	 * @param $body
	 * @param $parent_id
	 * @return bool
	 */
	public function update_or_create_page($space, $title, $body, $parent_id) {
		$page_id = $this->get_page_id_by_title($space, $title);
		if($page_id === false) {
			return $this->create_page($space, $title, $body, $parent_id);
		} else {
			return $this->update_page($space, $page_id, $title, $body	, $parent_id);
		}
	}

	public function update_page($space, $page_id, $title, $content, $parent_id = false) {
		$version = $this->get_next_version_for_page($page_id);

		$data = array(
			#"id" => $page_id,
			"type" => "page",
			"title" => $title,
			#"space" => array(
			#	"key" => $space
			#),
			"version" => array(
				"number" => $version
			),
			"body" => array(
				"storage" => array(
					"value" => $content,
					"representation" => "storage"
				)
			),
		);

		if($parent_id) {
			$data['ancestors'] = array(array(
				'id' => $parent_id
			));
		}

		$ret = $this->execute_put_request('/rest/api/content/'.$page_id, json_encode($data));

		if($ret !== false) {
			return $ret->id;
		} else {
			return false;
		}
	}

	/**
	 * Create page in a specific space
	 *
	 * @param $space Space Key
	 * @param $title Title of page
	 * @param $body Body of page
	 * @param $parent_id Page ID to parent
	 * @return bool
	 */
	public function create_page($space, $title, $body, $parent_id) {
		$data = array(
			"type" => "page",
			"title" => $title,
			"space" => array(
				"key" => $space
			),
			"body" => array(
				"storage" => array(
					"value" => $body,
					"representation" => "storage"
				)
			),
			"ancestors" => array(
				array("id" => $parent_id)
			),
		);

		$ret = $this->execute_post_request('/rest/api/content/', json_encode($data));

		if($ret !== false) {
			return $ret->id;
		} else {
			return false;
		}
	}

	/**
	 * Convert contentbody from one format to another. With page context if $page_id is set.
	 *
	 * @param $from
	 * @param $to
	 * @param $body
	 * @param bool $page_id
	 * @return bool
	 */
	function convert_contentbody($from, $to, $body, $page_id = false) {
		$data = array(
			'representation' => $from,
			'value' => $body,
		);

		if($page_id !== false) {
			$data["content"] = array( "id" => $page_id );;
		}

		return $this->execute_post_request('/rest/api/contentbody/convert/'.$to, json_encode($data));
	}

	/**
	 * Add labels to a page.
	 *
	 * @param $page_id
	 * @param $labels
	 * @return bool
	 */
	function add_labels($page_id, $labels) {
		$data = array();
		foreach($labels as $label) {
			$data[] = array(
				'prefix' => 'global',
				'name' => $label,
			);
		}

		return $this->execute_post_request('/rest/content/'.$page_id.'/label', $data);
	}

	/**
	 * Get a lables for the specific page id
	 *
	 * @param $page_id
	 * @return bool
	 */
	function get_labels($page_id) {
		return $this->execute_get_request('/rest/api/content/'.$page_id.'/label');
	}

	/**
	 * Delete labels from page id
	 *
	 * @param $page_id
	 * @param $labels
	 * @return bool
	 */
	function delete_labels($page_id, $labels) {
		$ret = true;
		foreach($labels as $label) {
			$res = $this->execute_delete_request('/rest/content/'.$page_id.'/label/'.$label);
			if($res === false) {
				$ret = false;
			}
		}

		return true;
	}

	private function get_curl($endpoint, $headers = array()) {
		#if($this->ch == false) {
			#echo "init curl\n";
			$this->ch = curl_init();
		#}

		curl_setopt($this->ch, CURLOPT_URL, $this->base_url.$endpoint);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
		#curl_setopt($this->ch, CURLOPT_VERBOSE, 1);
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, 0);

		//curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($this->ch, CURLOPT_USERPWD, $this->username.':'.$this->password);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);

		return $this->ch;
	}

	private function exec_curl($url, $method = 'GET', $request = false, $headers = array(), $decode_json = true) {
		$ch = $this->get_curl($url, $headers);

		if($this->debug) {
			echo "$method - $url\n";#var_dump($request);
		}

		switch($method) {
			case 'POST':
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
				break;
			case 'DELETE':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
				break;
			case 'PUT':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
				curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
				break;
		}

		$output = curl_exec($ch);

		if(curl_errno($ch)) {
			$this->add_request_log($url, $method, $request, curl_errno($ch), curl_error($ch));
			#echo "CURL ERROR: ".curl_error($ch)."\n";
			return false;
		}

		$return_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		switch($return_code) {
			case 204:
			case 200:
				break;
			case 400:
				$error = json_decode($output);
				$this->add_request_log($url, $method, $request, $return_code, $error->message);
				break;
			default:
				$this->add_request_log($url, $method, $request, $return_code, null);
				return false;
		}

		if($decode_json) {
			return json_decode($output);
		} else {
			return $output;
		}
	}

	public function get_request_log() {
		return $this->request_log;
	}

	private function add_request_log($url, $method, $request, $return_code, $error_message) {
		$this->request_log->addInfo('Confluence request', array(
			'url' => $url,
			'method' => $method,
			'request' => $request,
			'return_code' => $return_code,
			'error_message' => $error_message,
		));
	}

	private function execute_download_request($url) {
		$headers = array(
			'X-Atlassian-Token: nocheck',
		);

		return $this->exec_curl($url, 'GET', false, $headers, false);
	}

	private function execute_get_request($endpoint, $params = array(), $headers = array()) {
		$url = $endpoint;
		if(count($params)) {
			$url .= '?'.http_build_query($params);
		}

		return $this->exec_curl($url, 'GET', false, $headers);
	}

	private function execute_post_request($endpoint, $request, $headers = array('Content-Type: application/json')) {
		return $this->exec_curl($endpoint, 'POST', $request, $headers);
	}

	private function execute_put_request($endpoint, $request, $headers = array('Content-Type: application/json')) {
		return $this->exec_curl($endpoint, "PUT", $request, $headers);
	}

	private function execute_delete_request($endpoint, $headers = array()) {
		return $this->exec_curl($endpoint, "DELETE", false, $headers);
	}
}

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

class Content {
	protected $content = false;
	/**
	 * @var ConfluenceConnection
	 */
	protected $conn = false;

	public function __construct($content, &$conn) {
		$this->content = $content;
		$this->conn = $conn;
	}

	function get_id() {
		return $this->content->id;
	}

	function get_title() {
		return $this->content->title;
	}

	function get_type() {
		return $this->content->type;
	}

	function get_space() {
		return $this->content->space->key;
	}

	function get_body() {
		return $this->content->body->storage->value;
	}

	function get_attachments() {
		return $this->conn->get_attachments_for_page($this->get_id());
	}

	function get_child_pages($exclude_labels = array()) {
		$cql = 'type = page AND parent = '.$this->get_id().'';

		if(count($exclude_labels) > 0) {
			$cql .= ' AND label not in (\''.implode('\',\'', $exclude_labels).'\')';
		}

		var_dump($cql);

		$des = $this->conn->search($cql);
		#var_dump($des);
		$ret = array();
		foreach($des as $child_page) {
			$child_page = new Content($child_page->content, $this->conn);
			$ret[$child_page->get_id()] = $child_page;
		}

		return $ret;
	}

	/**
	 * Convert body from storage to view.
	 * Useful when rendering a page is required (e.g. when having add labels macro).
	 *
	 * @return bool
	 */
	function convert_storage_to_view() {
		return $this->conn->convert_contentbody('storage', 'view', $this->get_body(), $this->get_id());
	}

	/**
	 * Get labels for page.
	 *
	 * @return bool
	 */
	function get_labels() {
		return $this->conn->get_labels($this->get_id());
	}

	/**
	 * Add labels to page
	 *
	 * @param $labels
	 * @return bool
	 */
	function add_labels($labels) {
		return $this->conn->add_labels($this->get_id(), $labels);
	}

	/**
	 * Delete labels from page
	 *
	 * @param $labels
	 * @return bool
	 */
	function delete_labels($labels) {
		return $this->conn->delete_labels($this->get_id(), $labels);
	}
}

class Attachment extends Content {
	function get_attachment_url() {
		return $this->content->_links->download;
	}

	function get_filename() {
		return $this->get_title();
	}

	function get_attachments() {
		return false;
	}

	function get_child_pages() {
		return false;
	}
}

abstract class StorageFormatEntity {
	abstract function get_storage_format();

	function toString() {
		return $this->get_storage_format();
	}

	function __toString() {
		return $this->get_storage_format();
	}

	function pretty_print() {
		// attribution http://stackoverflow.com/a/7453922
		$xml = $this->get_storage_format();
		$xml = preg_replace('/(>)(<)(\/*)/', "$1\n$2$3", $xml);
		$token      = strtok($xml, "\n");
		$result     = '';
		$pad        = 0;
		$matches    = array();
		while ($token !== false) :
			if (preg_match('/.+<\/\w[^>]*>$/', $token, $matches)) :
				$indent=0;
			elseif (preg_match('/^<\/\w/', $token, $matches)) :
				$pad--;
				$indent = 0;
			elseif (preg_match('/^<\w[^>]*[^\/]>.*$/', $token, $matches)) :
				$indent=1;
			else :
				$indent = 0;
			endif;
			$line    = str_pad($token, strlen($token)+$pad, ' ', STR_PAD_LEFT);
			$result .= $line . "\n";
			$token   = strtok("\n");
			$pad    += $indent;
		endwhile;
		return $result;
	}
}

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

class Link extends StorageFormatEntity {
	private $link_type;
	private $link_parameter;
	private $link_text;

	function __construct($type, $parameter, $text) {
		$this->link_type = $type;
		$this->link_text = $text;
		$this->link_parameter = $parameter;
	}

	static function add($type, $parameter, $text) {
		return new Link($type, $parameter, $text);
	}

	function get_storage_format() {
		$ret = '<ac:link>'
			.'<ri:shortcut ri:key="'.$this->link_type.'" ri:parameter="'.$this->link_parameter.'" />'
			.'<ac:plain-text-link-body><![CDATA['.$this->link_text.']]></ac:plain-text-link-body>'
		.'</ac:link>';

		return $ret;
	}
}

/*
<ac:structured-macro ac:name="scroll-ignore" ac:schema-version="1">
<ac:rich-text-body>
<ac:structured-macro ac:name="hide_for_external" ac:schema-version="1">
<ac:parameter ac:name="atlassian-macro-output-type">INLINE</ac:parameter>
<ac:rich-text-body>
<ac:link>
<ri:shortcut ri:key="JIRA" ri:parameter="'.$jira_key.'" />
<ac:plain-text-link-body><![CDATA['.$jira_key.']]></ac:plain-text-link-body>
</ac:link>
</ac:rich-text-body>
</ac:structured-macro>
</ac:rich-text-body>
</ac:structured-macro>
}
*/
