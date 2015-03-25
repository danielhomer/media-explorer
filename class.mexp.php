<?php
/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

class Media_Explorer extends MEXP_Plugin {

	/**
	 * Array of Service objects.
	 */
	public $services = array();

	/**
	 * Class constructor. Set up some actions and filters.
	 *
	 * @return null
	 */
	protected function __construct( $file ) {

		# Filters:
		add_filter( 'mexp_twitter_credentials', array( $this, 'get_twitter_credentials' ) );
		add_filter( 'mexp_youtube_developer_key', array( $this, 'get_youtube_credentials' ) );

		# Actions:
		add_action( 'plugins_loaded',        array( $this, 'action_plugins_loaded' ) );
		add_action( 'init',                  array( $this, 'action_init' ) );
		add_action( 'wp_enqueue_media',      array( $this, 'action_enqueue_media' ) );
		add_action( 'print_media_templates', array( $this, 'action_print_media_templates' ) );
		add_action( 'admin_init',            array( $this, 'add_settings' ) );

		# AJAX actions:
		add_action( 'wp_ajax_mexp_request',   array( $this, 'ajax_request' ) );

		# Parent setup:
		parent::__construct( $file );

	}

	/**
	 * Retrieve a registered Service object by its ID.
	 *
	 * @param string $service_id A service ID.
	 * @return Service|WP_Error A Service object on success, a WP_Error object on failure.
	 */
	public function get_service( $service_id ) {

		if ( isset( $this->services[$service_id] ) )
			return $this->services[$service_id];

		return new WP_Error(
			'invalid_service',
			sprintf( __( 'Media service "%s" was not found', 'mexp' ), esc_html( $service_id ) )
		);

	}

	/**
	 * Retrieve all the registered services.
	 *
	 * @return array An array of registered Service objects.
	 */
	public function get_services() {
		return $this->services;
	}

	/**
	 * Load the Backbone templates for each of our registered services.
	 *
	 * @action print_media_templates
	 * @return null
	 */
	public function action_print_media_templates() {

		foreach ( $this->get_services() as $service_id => $service ) {

			if ( ! $template = $service->get_template() )
				continue;

			// apply filters for tabs
			$tabs = apply_filters( 'mexp_tabs', array() );

			# @TODO this list of templates should be somewhere else. where?
			foreach ( array( 'search', 'item' ) as $t ) {

				foreach ( $tabs[$service_id] as $tab_id => $tab ) {

					$id = sprintf( 'mexp-%s-%s-%s',
						esc_attr( $service_id ),
						esc_attr( $t ),
						esc_attr( $tab_id )
					);

					$template->before_template( $id, $tab_id );
					call_user_func( array( $template, $t ), $id, $tab_id );
					$template->after_template( $id, $tab_id );

				}

			}

			foreach ( array( 'thumbnail' ) as $t ) {

				$id = sprintf( 'mexp-%s-%s',
					esc_attr( $service_id ),
					esc_attr( $t )
				);

				$template->before_template( $id );
				call_user_func( array( $template, $t ), $id );
				$template->after_template( $id );

			}

		}

	}

	/**
	 * Process an AJAX request and output the resulting JSON.
	 *
	 * @action wp_ajax_mexp_request
	 * @return null
	 */
	public function ajax_request() {

		if ( !isset( $_POST['_nonce'] ) or !wp_verify_nonce( $_POST['_nonce'], 'mexp_request' ) )
			die( '-1' );

		$service = $this->get_service( stripslashes( $_POST['service'] ) );

		if ( is_wp_error( $service ) ) {
			do_action( 'mexp_ajax_request_error', $service );
			wp_send_json_error( array(
				'error_code'    => $service->get_error_code(),
				'error_message' => $service->get_error_message()
			) );
		}

		foreach ( $service->requires() as $file => $class ) {

			if ( class_exists( $class ) )
				continue;

			require_once sprintf( '%s/class.%s.php',
				dirname( __FILE__ ),
				$file
			);

		}

		$request = wp_parse_args( stripslashes_deep( $_POST ), array(
			'params'  => array(),
			'tab'     => null,
			'min_id'  => null,
			'max_id'  => null,
			'page'    => 1,
		) );
		$request['page'] = absint( $request['page'] );
		$request['user_id'] = absint( get_current_user_id() );
		$request = apply_filters( 'mexp_ajax_request_args', $request, $service );

		$response = $service->request( $request );

		if ( is_wp_error( $response ) ) {
			do_action( 'mexp_ajax_request_error', $response );
			wp_send_json_error( array(
				'error_code'    => $response->get_error_code(),
				'error_message' => $response->get_error_message()
			) );

		} else if ( is_a( $response, 'MEXP_Response' ) ) {
			do_action( 'mexp_ajax_request_success', $response );
			wp_send_json_success( $response->output() );

		} else {
			do_action( 'mexp_ajax_request_success', false );
			wp_send_json_success( false );

		}

	}

	/**
	 * Enqueue and localise the JS and CSS we need for the media manager.
	 *
	 * @action enqueue_media
	 * @return null
	 */
	public function action_enqueue_media() {

		$mexp = array(
			'_nonce'    => wp_create_nonce( 'mexp_request' ),
			'labels'    => array(
				'insert'   => __( 'Insert into post', 'mexp' ),
				'loadmore' => __( 'Load more', 'mexp' ),
			),
			'base_url'  => untrailingslashit( $this->plugin_url() ),
			'admin_url' => untrailingslashit( admin_url() ),
		);

		foreach ( $this->get_services() as $service_id => $service ) {
			$service->load();

			$tabs = apply_filters( 'mexp_tabs', array() );
			$labels = apply_filters( 'mexp_labels', array() );

			$mexp['services'][$service_id] = array(
				'id'     => $service_id,
				'labels' => $labels[$service_id],
				'tabs'   => $tabs[$service_id],
			);
		}

		// this action enqueues all the statics for each service
		do_action( 'mexp_enqueue' );

		wp_enqueue_script(
			'mexp',
			$this->plugin_url( 'js/mexp.js' ),
			array( 'jquery', 'media-views' ),
			$this->plugin_ver( 'js/mexp.js' ),
			true
		);

		wp_localize_script(
			'mexp',
			'mexp',
			$mexp
		);

		wp_enqueue_style(
			'mexp',
			$this->plugin_url( 'css/mexp.css' ),
			array( /*'wp-admin'*/ ),
			$this->plugin_ver( 'css/mexp.css' )
		);

	}

	/**
	 * Fire the `mexp_init` action for plugins to hook into.
	 *
	 * @action plugins_loaded
	 * @return null
	 */
	public function action_plugins_loaded() {
		do_action( 'mexp_init' );
	}

	/**
	 * Load text domain and localisation files.
	 * Populate the array of Service objects.
	 *
	 * @action init
	 * @return null
	 */
	public function action_init() {

		load_plugin_textdomain( 'mexp', false, dirname( $this->plugin_base() ) . '/languages/' );

		foreach ( apply_filters( 'mexp_services', array() ) as $service_id => $service ) {
			if ( $service_id == 'instagram' ) {
				continue; // Instagram doesn't work properly at the moment
			}

			if ( is_a( $service, 'MEXP_Service' ) )
				$this->services[$service_id] = $service;
		}

	}

	/**
	 * Singleton instantiator.
	 *
	 * @param string $file The plugin file (usually __FILE__) (optional)
	 * @return Media_Explorer
	 */
	public static function init( $file = null ) {

		static $instance = null;

		if ( !$instance )
			$instance = new Media_Explorer( $file );

		return $instance;

	}

	/**
	 * Add MEXP settings sections to the Settings -> Media screen
	 * 
	 * @return void
	 */
	public function add_settings() {

		# YouTube
		# - Settings
		
		register_setting( 
			'mexp', 
			'mexp_youtube_api_key', 
			array( $this, 'sanitize_option' )
			);
		
		# - Section
		
		add_settings_section( 
			'mexp_youtube_section', 
			'YouTube', 
			array( $this, 'section_callback' ), 
			'media' 
			);
		
		# - Fields
		
		add_settings_field( 
			'mexp_youtube_api_key', 
			'YouTube API Key', 
			array( $this, 'field_callback' ), 
			'media', 
			'mexp_youtube_section', 
			array( 'mexp_youtube_api_key' ) 
			);

		# Twitter
		# - Settings

		register_setting( 
			'mexp',
			'mexp_twitter_consumer_key', 
			array( $this, 'sanitize_option' )
			);

		register_setting( 
			'mexp',
			'mexp_twitter_consumer_secret', 
			array( $this, 'sanitize_option' )
			);

		register_setting( 
			'mexp',
			'mexp_twitter_oauth_token', 
			array( $this, 'sanitize_option' )
			);

		register_setting( 
			'mexp',
			'mexp_twitter_oauth_token_secret', 
			array( $this, 'sanitize_option' )
			);

		# - Section

		add_settings_section( 
			'mexp_twitter_section', 
			'Twitter', 
			array( $this, 'section_callback' ), 
			'media' 
			);

		# - Fields

		add_settings_field( 
			'mexp_twitter_consumer_key', 
			'Twitter Consumer Key', 
			array( $this, 'field_callback' ), 
			'media', 
			'mexp_twitter_section',
			array( 'mexp_twitter_consumer_key' ) 
			);

		add_settings_field( 
			'mexp_twitter_consumer_secret', 
			'Twitter Consumer Secret', 
			array( $this, 'field_callback' ), 
			'media', 
			'mexp_twitter_section',
			array( 'mexp_twitter_consumer_secret' ) 
			);

		add_settings_field( 
			'mexp_twitter_oauth_token', 
			'Twitter OAuth Token', 
			array( $this, 'field_callback' ), 
			'media', 
			'mexp_twitter_section',
			array( 'mexp_twitter_oauth_token' ) 
			);

		add_settings_field( 
			'mexp_twitter_oauth_token_secret', 
			'Twitter OAuth Secret', 
			array( $this, 'field_callback' ), 
			'media', 
			'mexp_twitter_section',
			array( 'mexp_twitter_oauth_token_secret' ) 
			);

	}

	/**
	 * Echo the settings fields, along with their nonces with a short blurb at the top
	 * 
	 * @param  array $args The callback arguments
	 * @return void
	 */
	public function section_callback( $args ) {

		preg_match( '/(mexp_(\S+))_section/' , $args['id'], $matches );

		if ( isset( $matches[1] ) ) {
			echo sprintf( '<p>Please enter your %s credentials below.</p>', $args['title'] );
		}

		settings_fields( 'mexp' );

	}

	/**
	 * Filter the Twitter credentials. If the fields haven't been set, set them using the
	 * options from the database. If the options have been set via a filter, then don't 
	 * change the value.
	 * 
	 * @param  array $credentials The current credential array
	 * @return array              The filtered credential array
	 */
	public function get_twitter_credentials( $credentials ) {

		if ( ! is_array( $credentials ) || empty( $credentials ) ) {
			$credentials = array(
				'consumer_key'       => '',
				'consumer_secret'    => '',
				'oauth_token'        => '',
				'oauth_token_secret' => ''
				);
		}

		foreach ( $credentials as $key => $value ) {
			if ( ! $value ) {
				$credentials[ $key ] = get_option( 'mexp_twitter_' . $key, '' );
			}
		}

		return $credentials;

	}

	/**
	 * Filter the YouTube credentials. If the API key has already been set by a filter,
	 * do nothing. Otherwise, try and get the value from the database.
	 * 
	 * @param  string $credentials The original API key
	 * @return string              The filtered API key
	 */
	public function get_youtube_credentials( $credentials ) {

		if ( ! $credentials ) {
			$credentials = get_option( 'mexp_youtube_api_key', '' );
		}

		return $credentials;

	}

	/**
	 * Get the current values and render the settings fields
	 * 
	 * @param  array  $args Array of args, first element is the id of 
	 *                      the field being rendered
	 * @return void
	 */
	public function field_callback( array $args ) {

		$id = isset( $args[0] ) ? $args[0] : false;

		if ( ! $id ) {
			return;
		}

		$option = get_option( $id, '' );

		echo sprintf( '<input type="text" id="%s" name="%s" value="%s" />', $id, $id, $option );

	}

	/**
	 * Sanitize the Media Explorer options submitted in Settings -> Media
	 * 
	 * @param  string $value The unsanitized input
	 * @return string        The sanitized output
	 */
	public function sanitize_option( $value ) {
		
		return esc_attr( $value );

	}

}
