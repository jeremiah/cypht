<?php


/**
 * tests for Hm_Format_JSON and Hm_Format_HTML5
 */
class Hm_Test_Format extends PHPUnit_Framework_TestCase {

    public function setUp() {
        require 'bootstrap.php';
        $config = new Hm_Mock_Config();
        $this->json = new Hm_Format_JSON($config);
        $this->html5 = new Hm_Format_HTML5($config);
        Hm_Output_Modules::add('test', 'date', false, false, 'after', true, 'core');
        Hm_Output_Modules::add('test', 'blah', false, false, 'after', true, 'core');
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_content() {
        $this->assertEquals('{"date":"today"}', $this->json->content(array('router_page_name' => 'test', 'language' => 'en', 'date' => 'today'), array('date' => array(FILTER_UNSAFE_RAW, false))));
        $this->assertEquals('testtoday', $this->html5->content(array('router_page_name' => 'test', 'date' => 'today'), array()));
        $config = new Hm_Mock_Config();
        $config->set('encrypt_ajax_requests', true);
        $this->json = new Hm_Format_JSON($config);
        $res = $this->json->content(array('router_page_name' => 'test', 'language' => 'en', 'date' => 'today'), array('date' => array(FILTER_UNSAFE_RAW, false)));
        $this->assertTrue((bool) preg_match('/^{"payload/', $res));
    }
    /**
     * @preserveGlobalState disabled
     * @runInSeparateProcess
     */
    public function test_filter_output() {
        $this->assertEquals(array('date' => 'today'), $this->json->filter_output(array('date' => '<b>today</b>'), array('date' => array(FILTER_SANITIZE_STRING, false))));
        $this->assertEquals(array(), $this->json->filter_output(array('date' => array()), array('date' => array(FILTER_SANITIZE_STRING, false))));
        $this->assertEquals(array('test_array' => array('test')), $this->json->filter_output(array('test_array' => array('test')), array('test_array' => array(FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY))));
    }
    public function tearDown() {
        Hm_Output_Modules::del('test', 'blah');
    }
}

?>
