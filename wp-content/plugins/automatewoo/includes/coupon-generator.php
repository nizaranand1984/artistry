<?php

namespace AutomateWoo;

/**
 * Generates new coupons based on existing coupons
 *
 * @class Coupon_Generator
 */
class Coupon_Generator {

	/** @var string : Coupon code to be cloned */
	public $template_coupon_code;

	/** @var integer */
	public $template_coupon_id;

	/** @var string */
	public $code;

	/** @var string */
	public $prefix = '';

	/** @var string */
	public $suffix = '';

	/*** @var string : Number of days till coupon expires */
	public $expires;

	/** @var int */
	public $usage_limit;

	/** @var string */
	public $email_restriction;

	/** @var string */
	public $description;



	function __construct() {
		// set default values
		$this->prefix = apply_filters( 'automatewoo_generate_coupon_default_prefix', 'aw-' );
		$this->description = __( 'Generated by AutomateWoo', 'automatewoo' );
		$this->usage_limit = 1;
	}


	/**
	 * @param $code string
	 */
	function set_template_coupon_code( $code ) {
		if ( ! $code ) return;

		global $wpdb;
		$this->template_coupon_code = $code;

		$this->template_coupon_id = absint( $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'shop_coupon'", $this->template_coupon_code )
		));
	}


	/**
	 * @return integer
	 */
	function get_template_coupon_id() {
		return absint( $this->template_coupon_id );
	}


	/**
	 * @param $prefix string
	 */
	function set_prefix( $prefix ) {
		$this->prefix = $prefix;
	}


	/**
	 * @param $code string
	 */
	function set_code( $code ) {
		$this->code = $code;
	}


	/**
	 * @param $email
	 */
	function set_email_restriction( $email ) {
		$this->email_restriction = is_email( $email );
	}


	/**
	 * @param $days
	 */
	function set_expires( $days ) {
		$this->expires = absint( $days );
	}


	/**
	 * @param $suffix
	 */
	function set_suffix( $suffix ) {
		$this->suffix = $suffix;
	}


	/**
	 * @param $usage_limit
	 */
	function set_usage_limit( $usage_limit ) {
		$this->usage_limit = absint( $usage_limit );
	}


	/**
	 * @param $description
	 */
	function set_description( $description ) {
		$this->description = $description;
	}


	/**
	 * Generates a unique coupon code
	 * @return string
	 */
	function generate_code() {
		$length = absint( apply_filters( 'automatewoo/coupon_generator/key_length', 12, $this ) );
		$code = trim( $this->prefix ) . aw_generate_key( $length, false, true ) . trim( $this->suffix );
		$code = apply_filters( 'automatewoo/coupon_generator/code', $code, $this );

		if ( $this->is_existing_coupon_code( $code ) ) {
			return $this->generate_code();
		}

		return $code;
	}


	/**
	 * @param string $code
	 * @return bool
	 */
	function is_existing_coupon_code( $code ) {
		return (bool) Compat\Coupon::get_coupon_id_by_code( $code );
	}


	/**
	 * @return \WC_Coupon|bool
	 */
	function generate_coupon() {

		if ( ! $this->get_template_coupon_id() )
			return false;

		$coupon = [
			'post_title' => $this->code,
			'post_content' => '',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'shop_coupon',
			'post_excerpt' => $this->description
		];

		$new_coupon_id = wp_insert_post( $coupon );

		$coupon = new \WC_Coupon( $this->code ); // must load with code for < 3.0


		if ( $this->email_restriction ) {
			Compat\Coupon::set_email_restriction( $coupon, [ $this->email_restriction ] );
		}


		if ( version_compare( WC()->version, '3.0', '<' ) ) {

			$excluded_fields = [
				'usage_limit',
				'customer_email',
				'_recorded_coupon_usage_counts',
				'usage_count',
				'_used_by',
				'_edit_lock',
				'_edit_last'
			];

			$meta_fields = get_post_meta( $this->get_template_coupon_id(), '', true );

			foreach ( $meta_fields as $key => $value ) {

				if ( in_array( $key, $excluded_fields ) )
					continue;

				if ( is_array( $value ) )
					$value = $value[0];

				$value = maybe_unserialize($value);

				if ( ! empty( $value ) ) {
					update_post_meta( $new_coupon_id, $key, $value );
				}
			}

		}
		else {

			// WC 3.0
			$template_coupon = new \WC_Coupon( $this->get_template_coupon_id() );

			$coupon->set_discount_type( $template_coupon->get_discount_type() );
			$coupon->set_amount( $template_coupon->get_amount() );
			$coupon->set_individual_use( $template_coupon->get_individual_use() );
			$coupon->set_product_ids( $template_coupon->get_product_ids() );
			$coupon->set_excluded_product_ids( $template_coupon->get_excluded_product_ids() );
			$coupon->set_usage_limit_per_user( $template_coupon->get_usage_limit_per_user() );
			$coupon->set_limit_usage_to_x_items( $template_coupon->get_limit_usage_to_x_items() );
			$coupon->set_free_shipping( $template_coupon->get_free_shipping() );
			$coupon->set_exclude_sale_items( $template_coupon->get_exclude_sale_items() );
			$coupon->set_product_categories( $template_coupon->get_product_categories() );
			$coupon->set_excluded_product_categories( $template_coupon->get_excluded_product_categories() );
			$coupon->set_minimum_amount( $template_coupon->get_minimum_amount() );
			$coupon->set_maximum_amount( $template_coupon->get_maximum_amount() );
			$coupon->set_date_expires( $template_coupon->get_date_expires() );

			$coupon->save();
		}


		if ( $this->expires ) {
			$date = new \DateTime();
			$date->modify( "+$this->expires days" );
			Compat\Coupon::set_date_expires( $coupon, $date );
		}


		Compat\Coupon::set_usage_limit( $coupon, $this->usage_limit );

		Compat\Coupon::update_meta( $coupon, '_is_aw_coupon', true );

		return $coupon;
	}

}
