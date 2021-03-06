<?php

namespace mycryptocheckout;

use \Exception;

/**
	@brief		Handles admin things such as settings and currencies.
	@since		2017-12-09 07:05:04
**/
trait admin_trait
{
	/**
		@brief		Do some activation.
		@since		2017-12-09 07:12:19
	**/
	public function activate()
	{
		global $wpdb;

		// Rename the wallets key.
		if ( $this->is_network )
			$wpdb->update( $wpdb->sitemeta, [ 'meta_key' => 'mycryptocheckout\MyCryptoCheckout_wallets' ], [ 'meta_key' => 'mycryptocheckout\MyCryptoCheckout_' ] );
		else
			$wpdb->update( $wpdb->options, [ 'option_name' => 'MyCryptoCheckout_wallets' ], [ 'option_name' => 'MyCryptoCheckout_' ] );

		wp_schedule_event( time(), 'hourly', 'mycryptocheckout_hourly' );

		// We need to run this as soon as the plugin is active.
		$next = wp_next_scheduled( 'mycryptocheckout_retrieve_account' );
		wp_unschedule_event( $next, 'mycryptocheckout_retrieve_account' );
		wp_schedule_single_event( time(), 'mycryptocheckout_retrieve_account' );
	}

	/**
		@brief		Admin the account.
		@since		2017-12-11 14:20:17
	**/
	public function admin_account()
	{
		$form = $this->form();
		$form->id( 'account' );
		$r = '';

		$public_listing = $form->checkbox( 'public_listing' )
			->checked( $this->get_site_option( 'public_listing' ) )
			->description( __( 'Check the box and refresh your account if you want your webshop listed in the upcoming store directory on mycryptocheckout.com. Your store name and URL will be listed.', 'mycryptocheckout' ) )
			->label( __( 'Be featured in the MCC store directory?', 'mycryptocheckout' ) );

		if ( isset( $_POST[ 'retrieve_account' ] ) )
		{
			$form->post();
			$form->use_post_values();
			if ( $public_listing->is_checked() )
				MyCryptoCheckout()->update_site_option( 'public_listing', true );
			else
				MyCryptoCheckout()->delete_site_option( 'public_listing' );

			$result = $this->mycryptocheckout_retrieve_account();
			if ( $result )
			{
				$r .= $this->info_message_box()->_( __( 'Account data refreshed!', 'mycryptocheckout' ) );
				// Another safeguard to ensure that unsent payments are sent as soon as possible.
				MyCryptoCheckout()->api()->payments()->send_unsent_payments();
			}
			else
				$r .= $this->error_message_box()->_( __( 'Error refreshing your account data. Please enable debug mode to find the error.', 'mycryptocheckout' ) );
		}

		$account = $this->api()->account();

		if ( ! $account->is_valid() )
			$r .= $this->admin_account_invalid();
		else
			$r .= $this->admin_account_valid( $account );

		$save = $form->secondary_button( 'retrieve_account' )
			->value( __( 'Refresh your account data', 'mycryptocheckout' ) );

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Show the invalid account text.
		@since		2017-12-12 11:07:42
	**/
	public function admin_account_invalid()
	{
		$r = '';
		$r .= wpautop( __( 'It appears as if MyCrytpoCheckout was unable to retrieve your account data from the MyCryptoCheckout server.', 'mycryptocheckout' ) );
		$r .= wpautop( __( 'Use the button below to try and retrieve your account data again.', 'mycryptocheckout' ) );
		return $r;
	}

	/**
		@brief		Show the valid account text.
		@since		2017-12-12 11:07:42
	**/
	public function admin_account_valid( $account )
	{
		$r = '';

		try
		{
			$this->api()->account()->is_available_for_payment();
		}
		catch ( Exception $e )
		{
			$message = sprintf( '%s: %s',
				__( 'Payments using MyCryptoCheckout are currently not available', 'mycryptocheckout' ),
				$e->getMessage()
			);
			$r .= $this->error_message_box()->_( $message );
		}

		$table = $this->table();
		$table->caption()->text( __( 'Your MyCryptoCheckout account details', 'mycryptocheckout' ) );

		$row = $table->head()->row()->hidden();
		// Table column name
		$row->th( 'key' )->text( __( 'Key', 'mycryptocheckout' ) );
		// Table column name
		$row->td( 'details' )->text( __( 'Details', 'mycryptocheckout' ) );

		if ( $this->debugging() )
		{
			$row = $table->head()->row();
			$row->th( 'key' )->text( __( 'API key', 'mycryptocheckout' ) );
			$row->td( 'details' )->text( $account->get_domain_key() );

			$row = $table->head()->row();
			$row->th( 'key' )->text( __( 'Server name', 'mycryptocheckout' ) );
			$row->td( 'details' )->text( $this->get_server_name() );
		}

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Account data refreshed', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( static::wordpress_ago( $account->data->updated ) );

		if ( $account->has_license() )
		{
			$row = $table->head()->row();
			$text =  __( 'Your license expires', 'mycryptocheckout' );
			$row->th( 'key' )->text( $text );
			$time = $account->get_license_valid_until();
			$text = sprintf( '%s (%s)',
				$this->local_date( $time ),
				human_time_diff( $time )
			);
			$row->td( 'details' )->text( $text );
		}

		$row = $table->head()->row();
		if ( $account->has_license() )
			$text =  __( 'Extend your license', 'mycryptocheckout' );
		else
			$text =  __( 'Purchase a license for unlimited payments', 'mycryptocheckout' );
		$row->th( 'key' )->text( $text );
		$url = $this->api()->get_purchase_url();
		$url = sprintf( '<a href="%s">%s</a> &rArr;',
			$url,
			__( 'MyCryptoCheckout.com pricing page', 'mycryptocheckout' )
		);
		$row->td( 'details' )->text( $url );

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Payments remaining this month', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( $account->get_payments_left_text() );

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Payments processed', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( $account->get_payments_used() );

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Physical currency exchange rates updated', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( static::wordpress_ago( $account->data->physical_exchange_rates->timestamp ) );

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Cryptocurrency exchange rates updated', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( static::wordpress_ago( $account->data->virtual_exchange_rates->timestamp ) );

		$wallets = $this->wallets();
		if ( count( $wallets ) > 0 )
		{
			$currencies = $this->currencies();
			$exchange_rates = [];
			foreach( $wallets as $index => $wallet )
			{
				$id = $wallet->get_currency_id();
				if ( isset( $exchange_rates[ $id ] ) )
					continue;
				$currency = $currencies->get( $id );
				if ( $currency )
					$exchange_rates[ $id ] = sprintf( '1 USD = %s %s', $currency->convert( 'USD', 1 ), $id );
				else
					$exchange_rates[ $id ] = sprintf( 'Currency %s is no longer available!', $id );
			}
			ksort( $exchange_rates );
			$exchange_rates = implode( "\n", $exchange_rates );
			$exchange_rates = wpautop( $exchange_rates );
		}
		else
			$exchange_rates = 'n/a';

		$row = $table->head()->row();
		$row->th( 'key' )->text( __( 'Exchange rates for your currencies', 'mycryptocheckout' ) );
		$row->td( 'details' )->text( $exchange_rates );

		if ( $this->debugging() )
		{
			if ( count( (array)$account->data->payment_amounts ) > 0 )
			{
				$row = $table->head()->row();
				$row->th( 'key' )->text( __( 'Reserved amounts', 'mycryptocheckout' ) );
				$text = '';
				foreach( $account->data->payment_amounts as $currency_id => $amounts )
				{
					$text .= sprintf( '<p>%s<ul>', $currency_id );
					$amounts = (array)$amounts;
					ksort( $amounts );
					foreach( $amounts as $amount => $ignore )
						$text .= sprintf( '<li>%s</li>', $amount );
					$text .= '</ul>';
				}
				$row->td( 'details' )->text( $text );
			}
		}

		$r .= $table;



		return $r;
	}

	/**
		@brief		Admin the currencies.
		@since		2017-12-09 07:06:56
	**/
	public function admin_currencies()
	{
		$form = $this->form();
		$form->id( 'currencies' );
		$r = '';

		$account = $this->api()->account();
		if ( ! $account->is_valid() )
		{
			$r .= $this->error_message_box()->_( __( 'You cannot modify your currencies until you have a valid account. Please see the Accounts tab.', 'mycryptocheckout' ) );
			echo $r;
			return;
		}

		$table = $this->table();

		$table->bulk_actions()
			->form( $form )
			// Bulk action for wallets
			->add( __( 'Delete', 'mycryptocheckout' ), 'delete' )
			// Bulk action for wallets
			->add( __( 'Disable', 'mycryptocheckout' ), 'disable' )
			// Bulk action for wallets
			->add( __( 'Enable', 'mycryptocheckout' ), 'enable' )
			// Bulk action for wallets
			->add( __( 'Mark as used', 'mycryptocheckout' ), 'mark_as_used' );

		// Assemble the current wallets into the table.
		$row = $table->head()->row();
		$table->bulk_actions()->cb( $row );
		// Table column name
		$row->th( 'currency' )->text( __( 'Currency', 'mycryptocheckout' ) );
		// Table column name
		$row->th( 'wallet' )->text( __( 'Wallet', 'mycryptocheckout' ) );
		// Table column name
		$row->th( 'details' )->text( __( 'Details', 'mycryptocheckout' ) );

		$wallets = $this->wallets();

		foreach( $wallets as $index => $wallet )
		{
			$row = $table->body()->row();
			$table->bulk_actions()->cb( $row, $index );
			$currency = $this->currencies()->get( $wallet->get_currency_id() );

			// If the currency is no longer available, delete the wallet.
			if ( ! $currency )
			{
				$wallets->forget( $index );
				$wallets->save();
				continue;
			}

			$currency_text = sprintf( '%s %s', $currency->get_name(), $currency->get_id() );
			$row->td( 'currency' )->text( $currency_text );

			// Address
			$url = add_query_arg( [
				'tab' => 'edit_wallet',
				'wallet_id' => $index,
			] );
			$url = sprintf( '<a href="%s" title="%s">%s</a>',
				$url,
				__( 'Edit this currency', 'mycryptocheckout' ),
				$wallet->get_address()
			);
			$row->td( 'wallet' )->text( $url );

			// Details
			$details = $wallet->get_details();
			$details = implode( "\n", $details );
			$row->td( 'details' )->text( wpautop( $details ) );
		}

		$fs = $form->fieldset( 'fs_add_new' );
		// Fieldset legend
		$fs->legend->label( __( 'Add new currency / wallet', 'mycryptocheckout' ) );

		$wallet_currency = $fs->select( 'currency' )
			->css_class( 'currency_id' )
			->description( __( 'Which currency shall the new wallet belong to?', 'mycryptocheckout' ) )
			// Input label
			->label( __( 'Currency', 'mycryptocheckout' ) );
		$this->currencies()->add_to_select_options( $wallet_currency );

		$text = __( 'The address of your wallet to which you want to receive funds.', 'mycryptocheckout' );
		$text .= ' ';
		$text .= __( 'If your currency has HD wallet support, you can add your public key when editing the wallet.', 'mycryptocheckout' );
		$wallet_address = $fs->text( 'wallet_address' )
			->description( $text )
			// Input label
			->label( __( 'Address', 'mycryptocheckout' ) )
			->required()
			->size( 64, 128 )
			->trim();

		// This is an ugly hack for Monero. Ideally it would be hidden away in the wallet settings, but for the user it's much nicer here.
		$wallet_address = $fs->text( 'wallet_address' )
			->description( $text )
			// Input label
			->label( __( 'Address', 'mycryptocheckout' ) )
			->required()
			->size( 64, 128 )
			->trim();

		$monero_private_view_key = $fs->text( 'monero_private_view_key' )
			->css_class( 'only_for_currency XMR' )
			->description( __( 'Your private view key that is used to see the amounts in private transactions to your wallet.', 'mycryptocheckout' ) )
			// Input label
			->label( __( 'Monero private view key', 'mycryptocheckout' ) )
			->placeholder( '157e74dc4e2961c872f87aaf43461f6d0f596f2f116a51fbace1b693a8e3020a' )
			->size( 64, 64 )
			->trim();

		$save = $form->primary_button( 'save' )
			->value( __( 'Save settings', 'mycryptocheckout' ) );

		if ( $form->is_posting() )
		{
			$form->post();
			$form->use_post_values();

			$reshow = false;

			if ( $table->bulk_actions()->pressed() )
			{
				switch ( $table->bulk_actions()->get_action() )
				{
					case 'delete':
						$ids = $table->bulk_actions()->get_rows();
						foreach( $ids as $id )
							$wallets->forget( $id );
						$wallets->save();
						$r .= $this->info_message_box()->_( __( 'The selected wallets have been deleted.', 'mycryptocheckout' ) );
					break;
					case 'disable':
						$ids = $table->bulk_actions()->get_rows();
						foreach( $ids as $id )
						{
							$wallet = $wallets->get( $id );
							$wallet->set_enabled( false );
						}
						$wallets->save();
						$r .= $this->info_message_box()->_( __( 'The selected wallets have been disabled.', 'mycryptocheckout' ) );
					break;
					case 'enable':
						$ids = $table->bulk_actions()->get_rows();
						foreach( $ids as $id )
						{
							$wallet = $wallets->get( $id );
							$wallet->set_enabled( true );
						}
						$wallets->save();
						$r .= $this->info_message_box()->_( __( 'The selected wallets have been disabled.', 'mycryptocheckout' ) );
					break;
					case 'mark_as_used':
						$ids = $table->bulk_actions()->get_rows();
						foreach( $ids as $id )
						{
							$wallet = $wallets->get( $id );
							$wallet->use_it();
						}
						$wallets->save();
						$r .= $this->info_message_box()->_( __( 'The selected wallets have been marked as used.', 'mycryptocheckout' ) );
					break;
				}
				$reshow = true;
			}

			if ( $save->pressed() )
			{
				try
				{
					$wallet = $wallets->new_wallet();
					$wallet->address = $wallet_address->get_filtered_post_value();

					$chosen_currency = $wallet_currency->get_filtered_post_value();
					$currency = $this->currencies()->get( $chosen_currency );
					$currency->validate_address( $wallet->address );

					if ( $currency->supports( 'monero_private_view_key' ) )
						$wallet->set( 'monero_private_view_key', $form->input( 'monero_private_view_key' )->get_filtered_post_value() );

					$wallet->currency_id = $chosen_currency;

					$index = $wallets->add( $wallet );
					$wallets->save();

					$r .= $this->info_message_box()->_( __( 'Settings saved!', 'mycryptocheckout' ) );
					$reshow = true;
				}
				catch ( Exception $e )
				{
					$r .= $this->error_message_box()->_( $e->getMessage() );
				}
			}

			if ( $reshow )
			{
				echo $r;
				$_POST = [];
				$function = __FUNCTION__;
				echo $this->$function();
				return;
			}
		}

		$r .= wpautop( __( 'The table below shows the currencies and wallets you have set up in the plugin. To edit a wallet, click the address.', 'mycryptocheckout' ) );

		$r .= wpautop( __( 'You can have several wallets in the same currency. The wallets will be used in sequential order.', 'mycryptocheckout' ) );

		$wallets_text = sprintf(
			// perhaps <a>we can ...you</a>
			__( "If you don't have a wallet address to use, perhaps %swe can recommend some wallets for you%s?", 'mycryptocheckout' ),
			'<a href="https://mycryptocheckout.com/doc/recommended-wallets-exchanges/" target="_blank">',
			'</a>'
		);

		if ( count( $wallets ) < 1 )
			$wallets_text = '<strong>' . $wallets_text . '</strong>';
		$r .= wpautop( $wallets_text );

		$r .= $this->h2( __( 'Current currencies / wallets', 'mycryptocheckout' ) );

		$r .= $form->open_tag();
		$r .= $table;
		$r .= $form->close_tag();
		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Edit this wallet.
		@since		2017-12-09 20:44:32
	**/
	public function admin_edit_wallet( $wallet_id )
	{
		$wallets = $this->wallets();
		if ( ! $wallets->has( $wallet_id ) )
		{
			echo 'Invalid wallet ID!';
			return;
		}
		$wallet = $wallets->get( $wallet_id );

		$currencies = $this->currencies();
		$currency = $currencies->get( $wallet->get_currency_id() );
		$form = $this->form();
		$form->id( 'edit_wallet' );
		$r = '';

		$length = $currency->get_address_length();
		if ( is_array( $length ) )
		{
			// Figure out the max length.
			$max = 0;
			foreach( $length as $int )
				$max = max( $max, $int );
			$length = $max;
		}

		$fs = $form->fieldset( 'fs_basic' );
		// Fieldset legend
		$fs->legend->label( __( 'Basic settings', 'mycryptocheckout' ) );

		$wallet_address = $fs->text( 'wallet_address' )
			->description( __( 'The address of your wallet to which you want to receive funds.', 'mycryptocheckout' ) )
			// Input label
			->label( __( 'Address', 'mycryptocheckout' ) )
			->required()
			->size( $length, $length )
			->trim()
			->value( $wallet->get_address() );

		$wallet_enabled = $fs->checkbox( 'wallet_enabled' )
			->checked( $wallet->enabled )
			->description( __( 'Is this wallet enabled and ready to receive funds?', 'mycryptocheckout' ) )
			// Input label
			->label( __( 'Enabled', 'mycryptocheckout' ) );

		$preselected = $fs->checkbox( 'preselected' )
			->checked( $wallet->get( 'preselected', false ) )
			->description( __( 'Make this the default currency that is selected during checkout.', 'mycryptocheckout' ) )
			// Input label
			->label( __( 'Select as default', 'mycryptocheckout' ) );

		if ( $currency->supports( 'confirmations' ) )
			$confirmations = $fs->number( 'confirmations' )
				->description( __( 'How many confirmations needed to regard orders as paid. 1 is the default. More confirmations take longer.', 'mycryptocheckout' ) )
				// Input label
				->label( __( 'Confirmations', 'mycryptocheckout' ) )
				->min( 1, 100 )
				->value( $wallet->confirmations );

		if ( $currency->supports( 'btc_hd_public_key' ) )
		{
			if ( ! function_exists( 'gmp_abs' ) )
				$form->markup( 'm_btc_hd_public_key' )
					->markup( __( 'This wallet supports HD public keys, but your system is missing the required PHP GMP libary.', 'mycryptocheckout' ) );
			else
			{
				$fs = $form->fieldset( 'fs_btc_hd_public_key' );
				// Fieldset legend
				$fs->legend->label( __( 'HD wallet settings', 'mycryptocheckout' ) );

				$btc_hd_public_key = $fs->text( 'btc_hd_public_key' )
					->description( __( 'If you have an HD wallet and want to generate a new address after each purchase, enter your XPUB / YPUB / ZPUB public key here.', 'mycryptocheckout' ) )
					// Input label
					->label( __( 'HD public key', 'mycryptocheckout' ) )
					->trim()
					->size( 128 )
					->value( $wallet->get( 'btc_hd_public_key' ) );

				$path = $wallet->get( 'btc_hd_public_key_generate_address_path', 0 );
				$btc_hd_public_key_generate_address_path = $fs->number( 'btc_hd_public_key_generate_address_path' )
					->description( __( "The index of the next public wallet address to use. The default is 0 and gets increased each time the wallet is used. This is related to your wallet's gap length.", 'mycryptocheckout' ) )
					// Input label
					->label( __( 'Wallet index', 'mycryptocheckout' ) )
					->min( 0 )
					->value( $path );

				$new_address = $currency->btc_hd_public_key_generate_address( $wallet );
				$fs->markup( 'm_btc_hd_public_key_generate_address_path' )
					->p( __( 'The address at index %d is %s.', 'mycryptocheckout' ), $path, $new_address );
			}
		}

		if ( $currency->supports( 'monero_private_view_key' ) )
		{
			$monero_private_view_key = $fs->text( 'monero_private_view_key' )
				->description( __( 'Your private view key that is used to see the amounts in private transactions to your wallet.', 'mycryptocheckout' ) )
				// Input label
				->label( __( 'Monero private view key', 'mycryptocheckout' ) )
				->required()
				->size( 64, 64 )
				->trim()
				->value( $wallet->get( 'monero_private_view_key' ) );
		}

		if ( $this->is_network && is_super_admin() )
		{
			$fs = $form->fieldset( 'fs_network' );
			// Fieldset legend
			$fs->legend->label( __( 'Network settings', 'mycryptocheckout' ) );

			$wallet_on_network = $fs->checkbox( 'wallet_on_network' )
				->checked( $wallet->network )
				->description( __( 'Do you want the wallet to be available on the whole network?', 'mycryptocheckout' ) )
				// Input label
				->label( __( 'Network wallet', 'mycryptocheckout' ) );

			$sites = $fs->select( 'site_ids' )
				->description( __( 'If not network enabled, on which sites this wallet should be available.', 'mycryptocheckout' ) )
				// Input label
				->label( __( 'Sites', 'mycryptocheckout' ) )
				->multiple()
				->value( $wallet->sites );

			foreach( $this->get_sorted_sites() as $site_id => $site_name )
				$sites->option( $site_name, $site_id );

			$sites->autosize();
		}

		$save = $form->primary_button( 'save' )
			->value( __( 'Save settings', 'mycryptocheckout' ) );

		if ( $form->is_posting() )
		{
			$form->post();
			$form->use_post_values();

			$reshow = false;

			if ( $save->pressed() )
			{
				try
				{
					$wallet->address = $wallet_address->get_filtered_post_value();

					if ( $this->is_network )
						$wallet->network = $wallet_on_network->is_checked();

					$currency = $this->currencies()->get( $wallet->get_currency_id() );
					$currency->validate_address( $wallet->address );

					$wallet->enabled = $wallet_enabled->is_checked();
					$wallet->set( 'preselected', $preselected->is_checked() );
					if ( $currency->supports( 'confirmations' ) )
						$wallet->confirmations = $confirmations->get_filtered_post_value();

					if ( $currency->supports( 'btc_hd_public_key' ) )
						if ( function_exists( 'gmp_abs' ) )
						{
							$wallet->set( 'btc_hd_public_key', $btc_hd_public_key->get_filtered_post_value() );
							$wallet->set( 'btc_hd_public_key_generate_address_path', $btc_hd_public_key_generate_address_path->get_filtered_post_value() );
						}

					if ( $this->is_network && is_super_admin() )
					{
						$wallet->network = $wallet_on_network->is_checked();
						$wallet->sites = $sites->get_post_value();
					}

					if ( $currency->supports( 'monero_private_view_key' ) )
					{
						foreach( [ 'monero_private_view_key' ] as $key )
							$wallet->set( $key, $$key->get_filtered_post_value() );
					}

					$wallets->save();

					$r .= $this->info_message_box()->_( __( 'Settings saved!', 'mycryptocheckout' ) );
					$reshow = true;
				}
				catch ( Exception $e )
				{
					$r .= $this->error_message_box()->_( $e->getMessage() );
				}
			}

			if ( $reshow )
			{
				echo $r;
				$_POST = [];
				$function = __FUNCTION__;
				echo $this->$function( $wallet_id );
				return;
			}
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Local settings.
		@since		2018-04-26 16:14:39
	**/
	public function admin_local_settings()
	{
		$form = $this->form();
		$form->css_class( 'plainview_form_auto_tabs' );
		$form->local_settings = true;
		$r = '';

		$fs = $form->fieldset( 'fs_qr_code' );
		// Label for fieldset
		$fs->legend->label( __( 'QR code', 'mycryptocheckout' ) );

		$this->add_qr_code_inputs( $fs );

		$fs = $form->fieldset( 'fs_payment_timer' );
		// Label for fieldset
		$fs->legend->label( __( 'Payment timer', 'mycryptocheckout' ) );

		$this->add_payment_timer_inputs( $fs );

		$save = $form->primary_button( 'save' )
			->value( __( 'Save settings', 'mycryptocheckout' ) );

		if ( $form->is_posting() )
		{
			$form->post();
			$form->use_post_values();

			$this->save_qr_code_inputs( $form );
			$this->save_payment_timer_inputs( $form );

			$r .= $this->info_message_box()->_( __( 'Settings saved!', 'mycryptocheckout' ) );

			echo $r;
			$_POST = [];
			$function = __FUNCTION__;
			echo $this->$function();
			return;
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Show the settings.
		@since		2017-12-09 07:14:33
	**/
	public function admin_global_settings()
	{
		$form = $this->form();
		$form->css_class( 'plainview_form_auto_tabs' );
		$r = '';

		$fs = $form->fieldset( 'fs_gateway_fees' );
		// Label for fieldset
		$fs->legend->label( __( 'Gateway fees', 'mycryptocheckout' ) );

		$fs->markup( 'm_gateway_fees' )
			->p( __( 'If you wish to charge (or discount) visitors for using MyCryptoCheckout as the payment gateway, you can enter the fixed or percentage amounts in the boxes below. The cryptocurrency checkout price will be modified in accordance with the combined values below. These are applied to the original currency.', 'mycryptocheckout' ) );

		$markup_amount = $fs->number( 'markup_amount' )
			// Input description.
			->description( __( 'If you wish to mark your prices up (or down) when using cryptocurrency, enter the fixed amount in this box.', 'mycryptocheckout' ) )
			// Input label.
			->label( __( 'Markup amount', 'mycryptocheckout' ) )
			->max( 1000 )
			->min( -1000 )
			->step( 0.01 )
			->size( 6, 6 )
			->value( $this->get_site_option( 'markup_amount' ) );

		$markup_percent = $fs->number( 'markup_percent' )
			// Input description.
			->description( __( 'If you wish to mark your prices up (or down) when using cryptocurrency, enter the percentage in this box.', 'mycryptocheckout' ) )
			// Input label.
			->label( __( 'Markup %', 'mycryptocheckout' ) )
			->max( 1000 )
			->min( -100 )
			->step( 0.01 )
			->size( 6, 6 )
			->value( $this->get_site_option( 'markup_percent' ) );

		$fs = $form->fieldset( 'fs_qr_code' );
		// Label for fieldset
		$fs->legend->label( __( 'QR code', 'mycryptocheckout' ) );

		if ( $this->is_network )
			$form->global_settings = true;
		else
			$form->local_settings = true;

		$this->add_qr_code_inputs( $fs );

		$fs = $form->fieldset( 'fs_payment_timer' );
		// Label for fieldset
		$fs->legend->label( __( 'Payment timer', 'mycryptocheckout' ) );

		if ( $this->is_network )
			$form->global_settings = true;
		else
			$form->local_settings = true;

		$this->add_payment_timer_inputs( $fs );

		$this->add_debug_settings_to_form( $form );

		$save = $form->primary_button( 'save' )
			->value( __( 'Save settings', 'mycryptocheckout' ) );

		if ( $form->is_posting() )
		{
			$form->post();
			$form->use_post_values();

			$this->update_site_option( 'markup_amount', $markup_amount->get_filtered_post_value() );
			$this->update_site_option( 'markup_percent', $markup_percent->get_filtered_post_value() );

			$this->save_payment_timer_inputs( $form );
			$this->save_qr_code_inputs( $form );

			$this->save_debug_settings_from_form( $form );
			$r .= $this->info_message_box()->_( __( 'Settings saved!', 'mycryptocheckout' ) );

			echo $r;
			$_POST = [];
			$function = __FUNCTION__;
			echo $this->$function();
			return;
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Tools
		@since		2017-12-30 23:02:12
	**/
	public function admin_tools()
	{
		$form = $this->form();
		$form->css_class( 'plainview_form_auto_tabs' );
		$r = '';

		$form->markup( 'm_hourly_cron' )
			->p(  __( 'The hourly run cron job will do things like update the account information, exchange rates, send unsent data to the API server, etc.', 'mycryptocheckout' ) );

		$hourly_cron = $form->secondary_button( 'hourly_cron' )
			->value( __( 'Run hourly cron job', 'mycryptocheckout' ) );

		if ( $form->is_posting() )
		{
			$form->post();
			$form->use_post_values();

			if ( $hourly_cron->pressed() )
			{
				do_action( 'mycryptocheckout_hourly' );
				$r .= $this->info_message_box()->_( __( 'MyCryptoCheckout hourly cron job run.', 'mycryptocheckout' ) );
			}

			echo $r;
			$_POST = [];
			$function = __FUNCTION__;
			echo $this->$function();
			return;
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	/**
		@brief		Deactivation.
		@since		2017-12-14 08:36:14
	**/
	public function deactivate()
	{
		wp_clear_scheduled_hook( 'mycryptocheckout_hourly' );
	}

	/**
		@brief		init_admin_trait
		@since		2017-12-25 18:25:53
	**/
	public function init_admin_trait()
	{
		$this->add_action( 'mycryptocheckout_hourly' );

		// The plugin table.
		$this->add_filter( 'network_admin_plugin_action_links', 'plugin_action_links', 10, 4 );
		$this->add_filter( 'plugin_action_links', 'plugin_action_links', 10, 4 );
	}

	/**
		@brief		Our hourly cron.
		@since		2017-12-22 07:49:38
	**/
	public function mycryptocheckout_hourly()
	{
		// Schedule an account retrieval sometime.
		// The timestamp shoule be anywhere between now and 45 minutes later.
		$timestamp = rand( 0, 45 ) * 60;
		$timestamp = time() + $timestamp;
		$this->debug( 'Scheduled for %s', $this->local_datetime( $timestamp ) );
		wp_schedule_single_event( $timestamp, 'mycryptocheckout_retrieve_account' );
	}

	/**
		@brief		Modify the plugin links in the plugins table.
		@since		2017-12-30 20:49:13
	**/
	public function plugin_action_links( $links, $plugin_name )
	{
		if ( $plugin_name != 'mycryptocheckout/MyCryptoCheckout.php' )
			return $links;
		if ( is_network_admin() )
			$url = network_admin_url( 'settings.php?page=mycryptocheckout' );
		else
			$url = admin_url( 'options-general.php?page=mycryptocheckout' );
		$links []= sprintf( '<a href="%s">%s</a>',
			$url,
			__( 'Settings', 'mycryptocheckout' )
		);
		return $links;
	}
}
