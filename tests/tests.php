<?php

include(dirname(__FILE__) . '/../vendor/autoload.php');
include(dirname(__FILE__) . '/../vendor/simpletest/simpletest/autorun.php');

class TestConfluence extends UnitTestCase {
  private $base_url = "http://localhost:8080/";
  private $username = "test";
  private $password = "test";

  function testCreatingConfluenceWOEnvFile() {
    $this->expectException();
    $conf = new Kvitli\Confluence();
  }

  function testCreatingConfluenceWAllParams() {
    $conf = new Kvitli\Confluence($this->base_url, $this->username, $this->password);
    $this->assertIsA($conf, 'Kvitli\Confluence');
  }

  function testCreatingConfluenceWBaseUrlAsParams() {
    $this->expectException();
    $conf = new Kvitli\Confluence($this->base_url);
    $this->assertIsA($conf, 'Kvitli\Confluence');
  }
}

class TestLink extends UnitTestCase {
  private $type = 'mytype';
  private $link_parameter = 'mylink_parameter';
  private $link_text = 'mylink_text';

  function testCreatingLink() {
    $link = Kvitli\Link::add($this->type, $this->link_parameter, $this->link_text);

    $this->assertIsA($link, 'Kvitli\StorageFormatEntity');
    $storage = $link->get_storage_format();
    $this->assertPattern("/{$this->type}/", $storage);
    $this->assertPattern("/{$this->link_parameter}/", $storage);
    $this->assertPattern("/{$this->link_text}/", $storage);
  }
}

class TestMacro extends UnitTestCase {
  private $macro_name = 'mymacro';
	private $parameters = array('myparam1' => 'myvalue1', 'myparam2' => 'myvalue2');
	private $body = 'mybody';

  function testCreatingMacro() {
    $macro = Kvitli\Macro::add($this->macro_name);
    $macro->set_body($this->body);
    foreach($this->parameters as $key => $val) {
      $macro->add_parameter($key, $val);
    }

    $this->assertIsA($macro, 'Kvitli\StorageFormatEntity');
    $storage = $macro->get_storage_format();
    $this->assertPattern("/{$this->macro_name}/", $storage);
    $this->assertPattern("/{$this->body}/", $storage);
    foreach($this->parameters as $key => $val) {
      $this->assertPattern("/{$key}/", $storage);
      $this->assertPattern("/{$val}/", $storage);
    }
  }
}
