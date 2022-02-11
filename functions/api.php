<?php

function dfrapi_api_get_status() {
	$api = dfrapi_api( dfrapi_get_transport_method() );
	try {
		$status = $api->getStatus();
		dfrapi_api_update_status( $api );

		return $status;
	} catch ( Exception $err ) {
		return dfrapi_api_error( $err );
	}
}

/**
 * Removed configuration. Always returns 'wordpress'. 2017-02-21 10:23:10
 */
function dfrapi_get_transport_method() {
	return 'wordpress';
	// $configuration = (array) get_option( 'dfrapi_configuration' );
	// $transport = ( isset( $configuration['transport_method'] ) ) ? $configuration['transport_method'] : $transport;
	// return $transport;
}

/**
 * This instantiates the Datafeedr API Library and returns the $api object.
 */
function dfrapi_api( $transport = 'curl', $timeout = 0, $returnObjects = false ) {

	$configuration = (array) get_option( 'dfrapi_configuration' );

	if ( isset( $configuration['disable_api'] ) && ( $configuration['disable_api'] == 'yes' ) ) {
		$configuration['disable_api'] = 'no';
		update_option( 'dfrapi_configuration', $configuration );
	}

	$access_id  = false;
	$secret_key = false;
	$transport  = dfrapi_get_transport_method();

	if ( isset( $configuration['access_id'] ) && ( $configuration['access_id'] != '' ) ) {
		$access_id = $configuration['access_id'];
	}

	if ( isset( $configuration['secret_key'] ) && ( $configuration['secret_key'] != '' ) ) {
		$secret_key = $configuration['secret_key'];
	}

	if ( $access_id && $secret_key ) {

		$options = array(
			'transport'     => 'wordpress',
			'timeout'       => 60,
			'returnObjects' => false,
			'retry'         => 3, // The number of retries if an API request times-out.
			'retryTimeout'  => 5, // The number of seconds to wait between retries.
		);

		$options = apply_filters( 'dfrapi_api_options', $options );

		$api = new DatafeedrApi( $access_id, $secret_key, $options );

		return $api;

	} else {
		return false;
	}
}

/**
 * Creates an associate array with the API's error details.
 */
function dfrapi_api_error( $error, $params = false ) {

	// Change "request_count" to "max_requests" because sometimes there's
	// not even enough API requests left to update the Account info with 
	// the most update to date information.
	if ( $error->getCode() == 301 ) {
		$account                  = get_option( 'dfrapi_account', array() );
		$account['request_count'] = $account['max_requests'];
		update_option( 'dfrapi_account', $account );
	}

	return array(
		'dfrapi_api_error' => array(
			'class'  => get_class( $error ),
			'code'   => $error->getCode(),
			'msg'    => $error->getMessage(),
			'params' => $params,
		)
	);
}

/**
 * Creates the proper API request from the $query.
 */
function dfrapi_api_query_to_filters( $query, $useSelected = true ) {
	$sform = new Dfrapi_SearchForm();

	return $sform->makeFilters( $query, $useSelected );
}

/**
 * Returns a parameter value from the $query array.
 */
function dfrapi_api_get_query_param( $query, $param ) {
	if ( is_array( $query ) && ! empty( $query ) ) {
		foreach ( $query as $k => $v ) {
			if ( $v['field'] == $param ) {
				return array(
					'field'    => isset( $v['field'] ) ? $v['field'] : '',
					'operator' => isset( $v['operator'] ) ? $v['operator'] : '',
					'value'    => isset( $v['value'] ) ? $v['value'] : '',
				);
			}
		}
	}

	return false;
}

/**
 * This updates the "dfrapi_account" option with the most recent
 * API status information for this user.
 */
function dfrapi_api_update_status( &$api ) {
	if ( $status = $api->lastStatus() ) {
		$account                   = get_option( 'dfrapi_account', array() );
		$account['user_id']        = $status['user_id'];
		$account['plan_id']        = $status['plan_id'];
		$account['bill_day']       = $status['bill_day'];
		$account['max_total']      = $status['max_total'];
		$account['max_length']     = $status['max_length'];
		$account['max_requests']   = $status['max_requests'];
		$account['request_count']  = $status['request_count'];
		$account['network_count']  = $status['network_count'];
		$account['product_count']  = $status['product_count'];
		$account['merchant_count'] = $status['merchant_count'];
		update_option( 'dfrapi_account', $account );
	}
}

/**
 * This returns all affiliate networks' information.
 * This accepts an array of source_ids (network ids)
 * to return a subset of networks.
 */
function dfrapi_api_get_all_networks( $nids = array() ) {
	$option_name = 'dfrapi_all_networks';
	$use_cache   = wp_using_ext_object_cache( false );
	$networks    = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );
	if ( false === $networks || empty ( $networks ) ) {
		$api = dfrapi_api( dfrapi_get_transport_method() );
		try {
			$networks = $api->getNetworks( $nids, true );
			dfrapi_api_set_network_types( $networks );
			dfrapi_api_update_status( $api );
		} catch ( Exception $err ) {
			return dfrapi_api_error( $err );
		}
		$use_cache = wp_using_ext_object_cache( false );
		set_transient( $option_name, $networks, DAY_IN_SECONDS );
		wp_using_ext_object_cache( $use_cache );
	}
	dfrapi_update_transient_whitelist( $option_name );

	return $networks;
}

/**
 * Returns a Zanox zmid value.
 */
function dfrapi_api_get_zanox_zmid( $merchant_id, $adspace_id ) {

	$option_name = 'zmid_' . $merchant_id . '_' . $adspace_id;
	$use_cache   = wp_using_ext_object_cache( false );
	$zmid        = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );

	if ( $zmid ) {
		return $zmid;
	}

	$keys = dfrapi_get_zanox_keys();
	$api  = dfrapi_api();

	try {
		$zmid = $api->getZanoxMerchantIds(
			$merchant_id,
			$adspace_id,
			$keys['connection_key']
		);
	} catch ( Exception $err ) {
		$zmid = 'dfrapi_unapproved_zanox_merchant';
	}

	$use_cache = wp_using_ext_object_cache( false );
	set_transient( $option_name, $zmid, WEEK_IN_SECONDS );
	wp_using_ext_object_cache( $use_cache );

	dfrapi_update_transient_whitelist( $option_name );

	return $zmid;
}

/**
 * Returns a Partnerize camref value.
 */
function dfrapi_api_get_ph_camref( $merchant_id ) {

	$option_name = 'camref_' . $merchant_id;
	$use_cache   = wp_using_ext_object_cache( false );
	$camref      = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );

	if ( $camref ) {
		return $camref;
	}

	$keys = dfrapi_get_ph_keys();
	$api  = dfrapi_api();

	try {
		$camref = $api->getPerformanceHorizonCamrefs(
			$merchant_id,
			$keys['application_key'],
			$keys['user_api_key'],
			$keys['publisher_id']
		);
	} catch ( Exception $err ) {
		$camref = 'dfrapi_unapproved_ph_merchant';
	}

	$use_cache = wp_using_ext_object_cache( false );
	set_transient( $option_name, $camref, WEEK_IN_SECONDS );
	wp_using_ext_object_cache( $use_cache );

	dfrapi_update_transient_whitelist( $option_name );

	return $camref;
}

/**
 * Returns a Effiliation affiliate ID.
 *
 * @since 1.0.81
 */
function dfrapi_api_get_effiliation_affiliate_id( $merchant_id ) {

	$option_name  = 'effiliation_' . $merchant_id;
	$use_cache    = wp_using_ext_object_cache( false );
	$affiliate_id = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );

	if ( $affiliate_id ) {
		return $affiliate_id;
	}

	try {
		$affiliate_id = dfrapi_get_affiliate_id_for_effiliation_merchant( $merchant_id );
	} catch ( Exception $err ) {
		$affiliate_id = 'dfrapi_unapproved_effiliation_merchant';
	}

	$use_cache = wp_using_ext_object_cache( false );
	set_transient( $option_name, $affiliate_id, WEEK_IN_SECONDS );
	wp_using_ext_object_cache( $use_cache );

	dfrapi_update_transient_whitelist( $option_name );

	return $affiliate_id;
}

/**
 * This creates 2 options in the options table each time the option
 * "dfrapi_all_networks" is updated with new network information from the API.
 *
 * - dfrapi_product_networks
 * - dfrapi_coupon_networks
 *
 * These are just helper options to figure out if a network is a "product"
 * network or a "coupon" network.
 */
function dfrapi_api_set_network_types( $networks ) {
	$product_networks = array();
	$coupon_networks  = array();
	foreach ( $networks as $network ) {
		if ( $network['type'] == 'products' ) {
			$product_networks[ $network['_id'] ] = $network;
		} elseif ( $network['type'] == 'coupons' ) {
			$coupon_networks[ $network['_id'] ] = $network;
		}
	}
	update_option( 'dfrapi_product_networks', $product_networks );
	update_option( 'dfrapi_coupon_networks', $coupon_networks );
}

/**
 * This stores all merchants for a given source_id ($nid).
 *
 * It is possible to pass "all" to this function however this creates
 * memory_limit errors when memory is set to less than 64MB.
 */
function dfrapi_api_get_all_merchants( $nid ) {
	$option_name = 'dfrapi_all_merchants_for_nid_' . $nid;
	$use_cache   = wp_using_ext_object_cache( false );
	$merchants   = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );
	if ( false === $merchants || empty ( $merchants ) ) {
		$api = dfrapi_api( dfrapi_get_transport_method() );
		try {
			$merchants = $api->getMerchants( array( intval( $nid ) ), true );
			dfrapi_api_update_status( $api );
		} catch ( Exception $err ) {
			return dfrapi_api_error( $err );
		}
		$use_cache = wp_using_ext_object_cache( false );
		set_transient( $option_name, $merchants, DAY_IN_SECONDS );
		wp_using_ext_object_cache( $use_cache );
	}
	dfrapi_update_transient_whitelist( $option_name );

	return $merchants;
}

/**
 * This returns merchant or merchants' information by merchant_id or
 * an array of merchant IDs.
 */
function dfrapi_api_get_merchants_by_id( $ids, $includeEmpty = false ) {
	$name = false;
	if ( is_array( $ids ) ) {
		sort( $ids, SORT_NUMERIC );
		$id_string = implode( ",", $ids );
		$name      = md5( $id_string );
	} elseif ( $ids != '' ) {
		$name = trim( $ids );
	}
	if ( ! $name ) {
		return;
	}
	$name        = substr( $name, 0, 20 );
	$option_name = 'dfrapi_merchants_byid_' . $name;
	$use_cache   = wp_using_ext_object_cache( false );
	$merchants   = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );
	if ( false === $merchants || empty ( $merchants ) ) {
		$api = dfrapi_api( dfrapi_get_transport_method() );
		try {
			$merchants = $api->getMerchantsById( $ids, $includeEmpty );
			dfrapi_api_update_status( $api );
		} catch ( Exception $err ) {
			return dfrapi_api_error( $err );
		}
		$use_cache = wp_using_ext_object_cache( false );
		set_transient( $option_name, $merchants, DAY_IN_SECONDS );
		wp_using_ext_object_cache( $use_cache );
	}
	dfrapi_update_transient_whitelist( $option_name );

	return $merchants;
}

/**
 * Returns a $response array containing:
 * - ids: the query passed to the function.
 * - products: array of products.
 * - last_status: value of $api->lastStatus().
 * - found_count: value of $search->getFoundCount().
 *
 * If the API throws an exception, that will return dfrapi_api_error( $err );
 *
 * @param array $ids An array of product IDs.
 * @param int $ppp The number of products to return in 1 API request. Max is dictated by API, not plugin.
 * @param int $page The page number for returning products. This is used to figure the offset.
 */
function dfrapi_api_get_products_by_id( $ids, $ppp = 20, $page = 1 ) {

	$response = array();

	// Return false if no $ids or no $postid
	if ( empty( $ids ) ) {
		return $response;
	}

	// Make sure $page is a positive integer.
	$page = intval( abs( $page ) );

	// Make sure $ppp is a positive integer.
	$ppp = intval( abs( $ppp ) );

	// Make sure $ppp is not greater than "max_length".
	$account = (array) get_option( 'dfrapi_account' );
	if ( $ppp > $account['max_length'] ) {
		$ppp = $account['max_length'];
	}

	// The maximum number of results a request to the API can return.
	// Changing this will only break your site. It's not overridable.
	$max_total = $account['max_total'];

	// Determine offset.
	$offset = ( ( $page - 1 ) * $ppp );

	// Make sure $limit doesn't go over 10,000.
	if ( ( $offset + $ppp ) > $max_total ) {
		$ppp = ( $max_total - $offset );
	}

	// If $ppp is negative, return empty array();
	if ( $ppp < 1 ) {
		return array();
	}

	// If offset is greater than 10,000 return empty array();
	if ( $offset >= ( $max_total - $ppp ) ) {
		return array();
	}

	try {

		// Initialize API.
		$api = dfrapi_api( dfrapi_get_transport_method() );
		if ( ! $api ) {
			return $response;
		}

		// Get a range of product IDs to query.
		$id_range = array_slice( $ids, $offset, $ppp );

		// Return immediately if $id_range is empty.
		if ( empty( $id_range ) ) {
			$response['ids']         = array();
			$response['products']    = array();
			$response['last_status'] = $api->lastStatus();
			$response['found_count'] = 0;

			return $response;
		}

		// Begin query
		$search = $api->searchRequest();

		// Get filters
		$filters = dfrapi_api_query_to_filters( array() );
		if ( isset( $filters['error'] ) ) {
			throw new DatafeedrError( $filters['error'], 0 );
		}

		// Loop thru filters.
		foreach ( $filters as $filter ) {
			$search->addFilter( $filter );
		}

		$search->addFilter( 'id IN ' . implode( ",", $id_range ) );
		$search->setLimit( $ppp );
		$products = $search->execute();

		// Keep track of IDs which were returned via the API to compare with $id_range (unreturned)
		$included_ids = array();
		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				$included_ids[] = $product['_id'];
			}
		}

		// Excluded product IDs.
		$excluded_ids = array_diff( $id_range, $included_ids );

		// Add "message" values to excluded IDs if there are some.
		$excluded_products = array();
		if ( ! empty( $included_ids ) && ! empty( $excluded_ids ) ) {
			foreach ( $excluded_ids as $excluded_id ) {

				$wc_url = add_query_arg(
					array(
						's'           => $excluded_id,
						'post_status' => 'trash',
						'post_type'   => 'product',
					),
					admin_url( 'edit.php' )
				);

				// Do not add a 'url' field to this array or the unavailable product WILL be imported.
				// See /datafeedr-product-sets/classes/class-dfrps-update.php:73
				$excluded_products[] = array(
					'_id'         => $excluded_id,
					'_wc_url'     => $wc_url,
					'name'        => $excluded_id . ' - ' . __( 'Unavailable', 'datafeedr-api' ),
					'price'       => 0,
					'finalprice'  => 0,
					'description' => __( 'This product is either temporarily or permanently unavailable.', 'datafeedr-api' ),
					'image'       => DFRAPI_URL . 'images/icons/noimage.png',
					'merchant'    => 'n/a',
					'source'      => 'n/a',
				);
			}
		}

		// Update API status
		dfrapi_api_update_status( $api );

		// Build $response array().
		$response['ids']         = $ids;
		$response['products']    = array_merge( $products, $excluded_products );
		$response['last_status'] = $api->lastStatus();
		$response['found_count'] = count( $ids );
		$response['params']      = $search->getParams();
		$response['score']       = $search->getQueryScore();

		// Return it!
		return $response;

	} catch ( Exception $err ) {
		return dfrapi_api_error( $err );
	}
}

/**
 * Returns a $response array containing:
 * - query: the query passed to the function.
 * - excluded: ids of excluded products.
 * - products: array of products.
 * - last_status: value of $api->lastStatus().
 * - found_count: value of $search->getFoundCount().
 * - params: value of $search->getParams().
 *
 * Example of $query array():
 *
 *
 *  $query[] = array(
 *        'value' => 'shoes',
 *        'field' => 'any',
 *        'operator' => 'contain'
 *  );
 *
 *  $query[] = array(
 *        'value' => 'image',
 *        'field' => 'duplicates',
 *        'operator' => 'is'
 *  );
 *
 *  $query[] = array(
 *        'field' => 'sort',
 *        'operator' => '+saleprice'
 *  );
 *
 *
 * If the API throws an exception, that will return dfrapi_api_error( $err, $params );
 *
 * @param array $query The complete query to pass to the API.
 * @param int $ppp The number of products to return in 1 API request. Max is dictated by API, not plugin.
 * @param int $page The page number for returning products. This is used to figure the offset.
 * @param array $excluded An array of product IDs to exclude from being returned.
 */
function dfrapi_api_get_products_by_query( $query, $ppp = 20, $page = 1, $excluded = array() ) {

	$response = array();

	// Return false if no $query.
	if ( empty( $query ) ) {
		return $response;
	}

	// Make sure $page is a positive integer.
	$page = intval( abs( $page ) );

	// Make sure $ppp is a positive integer.
	$ppp = intval( abs( $ppp ) );

	// Make sure $ppp is not greater than "max_length".
	$account = (array) get_option( 'dfrapi_account' );
	if ( $ppp > $account['max_length'] ) {
		$ppp = $account['max_length'];
	}

	// The maximum number of results a request to the API can return.
	// Changing this will only break your site. It's not overridable.
	$max_total = $account['max_total'];

	// Detemine query limit (if exists).
	$query_limit = dfrapi_api_get_query_param( $query, 'limit' );
	$query_limit = ( $query_limit )
		? $query_limit['value']
		: false;

	// No query shall try to return more than 10,000 products.
	if ( $query_limit && ( $query_limit > $max_total ) ) {
		$query_limit = $max_total;
	}

	// Detemine merchant limit (if exists).
	$merchant_limit = dfrapi_api_get_query_param( $query, 'merchant_limit' );
	$merchant_limit = ( $merchant_limit )
		? absint( $merchant_limit['value'] )
		: 0;

	// Determine offset.
	$offset = ( ( $page - 1 ) * $ppp );

	// If offset is greater than 10,000 return empty array();
	if ( $offset >= $max_total ) {
		return array();
	}

	// Factor in query limit 
	if ( $query_limit ) {
		if ( ( $ppp + $offset ) > $query_limit ) {
			$ppp = ( $query_limit - $offset );
		}
	}

	// Make sure $limit doesn't go over 10,000.
	if ( ( $offset + $ppp ) > $max_total ) {
		$ppp = ( $max_total - $offset );
	}

	// If $ppp is negative, return empty array();
	if ( $ppp < 1 ) {
		return $response;
	}

	try {

		// Initialize API.
		$api = dfrapi_api( dfrapi_get_transport_method() );
		if ( ! $api ) {
			return $response;
		}

		$search = $api->searchRequest();

		// Get filters
		$filters = dfrapi_api_query_to_filters( $query );
		if ( isset( $filters['error'] ) ) {
			throw new DatafeedrError( $filters['error'], 0 );
		}

		// Loop thru filters.
		foreach ( $filters as $filter ) {
			$search->addFilter( $filter );
		}

		// Exclude duplicates.
		$duplicates = dfrapi_api_get_query_param( $query, 'duplicates' );
		if ( $duplicates ) {
			$excludes = $duplicates['value'];
			$search->excludeDuplicates( $excludes );
		}

		// Exclude blocked products.
		$excluded = (array) $excluded;
		if ( ! empty( $excluded ) ) {
			$search->addFilter( 'id !IN ' . implode( ",", $excluded ) );
		}

		// Sort products.
		$sort = dfrapi_api_get_query_param( $query, 'sort' );
		if ( $sort && strlen( $sort['operator'] ) ) {
			$search->addSort( $sort['operator'] );
		}

		// Set Merchant Limit
		$search->setMerchantLimit( $merchant_limit );

		// Set limits and offset.	
		$search->setLimit( $ppp );
		$search->setOffset( $offset );

		// Execute query.
		$products = $search->execute();

		// Update API status
		dfrapi_api_update_status( $api );

		// Build $response array().
		$response['query']       = $query;
		$response['excluded']    = $excluded;
		$response['products']    = $products;
		$response['last_status'] = $api->lastStatus();
		$response['found_count'] = $search->getResultCount();
		$response['params']      = $search->getParams();
		$response['score']       = $search->getQueryScore();

		// Return it!
		return $response;

	} catch ( Exception $err ) {
		$params = $search->getParams();

		return dfrapi_api_error( $err, $params );

	}
}


function dfrapi_get_effiliation_product_feeds_url( $api_key ) {
	return sprintf( 'http://apiv2.effiliation.com/apiv2/productfeeds.xml?key=%s&filter=mines&type=33&fields=0001010000110001', $api_key );
}

function dfrapi_request_effiliation_affiliate_ids( $api_key = null ) {

	$option_name   = 'effiliation_affiliate_ids';
	$use_cache     = wp_using_ext_object_cache( false );
	$affiliate_ids = get_transient( $option_name );
	wp_using_ext_object_cache( $use_cache );

	if ( $affiliate_ids ) {
		return $affiliate_ids;
	}

	$keys    = dfrapi_get_effiliation_keys();
	$api_key = $api_key ?: $keys['effiliation_key'];
	$method  = 'GET';
	$url     = dfrapi_get_effiliation_product_feeds_url( $api_key );

	$xml = dfrapi_get_xml_response( $url, $method, [ 'timeout' => 30 ] );

	if ( is_wp_error( $xml ) ) {
		return $xml;
	}

	$affiliate_ids = [];

	foreach ( $xml->feed as $e ) {
		$item = json_decode( json_encode( $e ), true );
		$suid = sanitize_text_field( $item['id_affilieur'] );

		$affiliate_ids[ $suid ]['suid']         = ( $suid );
		$affiliate_ids[ $suid ]['affiliate_id'] = sanitize_text_field( $item['id_compteur'] );
	}

	$use_cache = wp_using_ext_object_cache( false );
	set_transient( $option_name, $affiliate_ids, ( MINUTE_IN_SECONDS * 20 ) );
	wp_using_ext_object_cache( $use_cache );
	dfrapi_update_transient_whitelist( $option_name );

	return $affiliate_ids;
}

/**
 * @param $merchant_id
 *
 * @return mixed|string
 * @throws Exception
 */
function dfrapi_get_affiliate_id_for_effiliation_merchant( $merchant_id ) {
	$merchants     = dfrapi_api_get_merchants_by_id( $merchant_id );
	$merchant      = isset( $merchants[0] ) ? $merchants[0] : [ 'suids' => '' ];
	$affiliate_ids = dfrapi_request_effiliation_affiliate_ids();

	if ( is_wp_error( $affiliate_ids ) ) {
		throw new Exception( 'Unable to query Effiliation at this time. Please try again in 15 minutes.' );
	}

	if ( ! isset( $affiliate_ids[ $merchant['suids'] ]['affiliate_id'] ) ) {
		throw new Exception( 'Suid does not exist for affiliate ID.' );
	}

	return $affiliate_ids[ $merchant['suids'] ]['affiliate_id'];
}

/**
 * An array of data about the user's Datafeedr account. Formatted like:
 *
 *  Array (
 *      [network_count] => 227
 *      [plan_id] => 30600000
 *      [user_id] => 70123
 *      [max_total] => 10000
 *      [merchant_count] => 84031
 *      [max_requests] => 100000
 *      [bill_day] => 25
 *      [request_count] => 11061
 *      [product_count] => 797373259
 *      [max_length] => 100
 *  )
 *
 * @return array
 */
function dfrapi_get_user_account_data(): array {
	return (array) get_option( 'dfrapi_account', [] );
}

/**
 * Returns the total number of networks in the Datafeedr API.
 *
 * @return int
 */
function dfrapi_get_network_count(): int {
	$data = dfrapi_get_user_account_data();

	return absint( $data['network_count'] ?? 0 );
}

/**
 * Returns the total number of merchants in the Datafeedr API.
 *
 * @return int
 */
function dfrapi_get_merchant_count(): int {
	$data = dfrapi_get_user_account_data();

	return absint( $data['merchant_count'] ?? 0 );
}

/**
 * Returns the total number of products in the Datafeedr API.
 *
 * @return int
 */
function dfrapi_get_product_count(): int {
	$data = dfrapi_get_user_account_data();

	return absint( $data['product_count'] ?? 0 );
}

/**
 * The maximum number of API requests the user is allowed to make during a single subscription period (i.e. 30 days).
 *
 * @return int
 */
function dfrapi_get_max_requests(): int {
	$data = dfrapi_get_user_account_data();

	return absint( $data['max_requests'] ?? 0 );
}

/**
 * The current number of API requests the user has made during the current subscription period (i.e. 30 days).
 *
 * @return int
 */
function dfrapi_get_request_count(): int {
	$data = dfrapi_get_user_account_data();

	return absint( $data['request_count'] ?? 0 );
}

/**
 * @param int $precision
 *
 * @return float|int
 */
function dfrapi_get_api_usage_as_percentage( int $precision = 2 ) {
	$max_requests  = dfrapi_get_max_requests();
	$request_count = dfrapi_get_request_count();

	return $max_requests > 0 ? round( ( $request_count / $max_requests * 100 ), $precision ) : 0;
}

/**
 * Returns an array of network IDs for the Partnerize affiliate network.
 *
 * @return int[]
 */
function dfrapi_get_partnerize_network_ids(): array {
	return [ 801, 811, 812, 813, 814, 815, 816, 817, 818, 819, 820 ];
}

/**
 * Returns an array of network IDs for the Effiliation affiliate network.
 *
 * @return int[]
 */
function dfrapi_get_effiliation_network_ids(): array {
	return [ 805, 806, 807 ];
}