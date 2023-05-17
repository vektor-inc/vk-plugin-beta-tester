<?php
/**
 * Class SampleTest
 *
 * @package Vk_Plugin_Beta_Tester
 */

/**
 * Sample test case.
 */
class SampleTest extends WP_UnitTestCase {


	/**
	 * Run only once at beginning.
	 */
	public static function setUpBeforeClass() {
//		shell_exec( "wp plugin install vk-blocks" );
//		shell_exec( "wp plugin activate vk-blocks" );
	}

	function setUp() {
//		update_option( 'vkpbt_active_plugin_for_beta_notice', false );
	}

	public function test_01() {

		$expected = [ 'akismet','' ]; //No text-domain for Hello dolly.
		$result   = VK_Plugin_Beta_Tester::get_plugins_slug();
		$this->assertSame( $result, $expected );

	}

	public function test_02() {

		$expected = [
			'akismet' => false,
			''        => false //No text-domain for Hello dolly.
		];
		$result   = VK_Plugin_Beta_Tester::set_default_active_plugin_for_beta_notice();

		$this->assertSame( $result, $expected );
	}

	public function test_03() {

		$expected = [
			'akismet' => false,
			''        => false //No text-domain for Hello dolly.
		];
		VK_Plugin_Beta_Tester::update_active_plugin_for_beta_notice();
		$result = get_option( 'vkpbt_active_plugin_for_beta_notice' );


		$this->assertSame( $result, $expected );
	}

//	public function test_04() {
//
//		$expected = [
//			'akismet' => true,
//			''        => false //No text-domain for Hello dolly.
//		];
//		update_option( 'vkpbt_active_plugin_for_beta_notice',$expected );
//
//		VK_Plugin_Beta_Tester::update_active_plugin_for_beta_notice();
//		$result = get_option( 'vkpbt_active_plugin_for_beta_notice' );
//
//		$this->assertSame( $result, $expected );
//	}
}
