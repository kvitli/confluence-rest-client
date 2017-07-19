<?php
namespace Kvitli;

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

	function get_child_pages($dummy) {
		return false;
	}
}
